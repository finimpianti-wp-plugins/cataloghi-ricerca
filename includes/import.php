<?php
defined('ABSPATH') || exit;

// =============================================================================
// IMPORT CSV — a lotti, con stato, avvisi e ripristino
//
// 1. il CSV viene letto e scritto in un lotto nuovo, a blocchi di 500 righe;
// 2. solo se l'import arriva in fondo il lotto nuovo diventa quello attivo;
// 3. il lotto precedente resta disponibile per il ripristino.
// Se PHP si interrompe a metà (timeout, errore) i dati online non cambiano e
// il pannello segnala l'import come interrotto.
// =============================================================================

/** Secondi senza aggiornamenti dopo i quali un import "in corso" è considerato interrotto */
const CR_IMPORT_STALLO = 180;

function cr_stato_import($tipo) {
    $s = get_option(CR_OPTION_IMPORT . $tipo, []);
    $s = (is_array($s) ? $s : []) + ['attivo' => 0, 'precedente' => null, 'seq' => 0, 'lotti' => [], 'ultimo' => null];
    if (!empty($s['ultimo']) && $s['ultimo']['stato'] === 'in_corso'
        && time() - (int) $s['ultimo']['aggiornato'] > CR_IMPORT_STALLO) {
        $s['ultimo']['stato'] = 'interrotto';
    }
    return $s;
}

function cr_salva_stato_import($tipo, $s) {
    update_option(CR_OPTION_IMPORT . $tipo, $s, false);
}

function cr_lotto_attivo($tipo) {
    return (int) cr_stato_import($tipo)['attivo'];
}

function cr_conta_righe($tipo, $lotto) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cr_tabella($tipo) . ' WHERE lotto = %d', $lotto));
}

function cr_percorso_csv($tipo) {
    return $tipo === 'motori' ? CR_CSV_MOTORI : CR_CSV_COMPONENTI;
}

/**
 * Colonne del database → intestazioni del CSV.
 */
function cr_colonne_csv($tipo) {
    $col = [
        'costruttore' => 'COSTRUTTORE',
        'motore'      => 'MOTORE',
        'nr_cilindri' => 'NR. CILINDRI',
        'alesaggio'   => 'ALESAGGIO',
        'corsa'       => 'CORSA',
        'versione'    => 'VERSIONE',
    ];
    if ($tipo === 'componenti') {
        $col += ['tipo_componente' => 'TIPO_COMPONENTE', 'fin_code' => 'FIN_CODE', 'oem_code' => 'OEM_CODE'];
    }
    return $col + ['pagina_pdf' => 'Pagina_PDF_Reale', 'file_origine' => 'File_origine'];
}

function cr_colonne_obbligatorie($tipo) {
    $obb = ['COSTRUTTORE', 'MOTORE', 'Pagina_PDF_Reale', 'File_origine'];
    return $tipo === 'componenti' ? array_merge($obb, ['FIN_CODE', 'OEM_CODE']) : $obb;
}

/**
 * Legge l'intestazione del CSV: rimuove il BOM UTF-8 (CSV salvati da Excel)
 * e rileva il separatore (',' oppure ';', tipico di Excel in italiano).
 */
function cr_leggi_header_csv($fh, &$sep) {
    $prima = fgets($fh);
    if ($prima === false) return null;
    $prima = preg_replace('/^\xEF\xBB\xBF/', '', $prima);
    $sep   = (substr_count($prima, ';') > substr_count($prima, ',')) ? ';' : ',';
    return array_map('trim', str_getcsv(rtrim($prima, "\r\n"), $sep, '"', '\\'));
}

/**
 * Pulisce un valore del CSV: converte in UTF-8 se serve (Excel salva spesso in
 * Windows-1252), rimuove i caratteri di controllo e gli spazi superflui.
 * Non usa sanitize_text_field, che trasformerebbe "4328 <--" in "4328 &lt;--":
 * l'escape avviene in output.
 */
function cr_pulisci_valore($v) {
    $v = (string) $v;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    return trim(preg_replace('/\s{2,}/u', ' ', $v));
}

/**
 * Inserisce un blocco di righe con una sola query. Se la query fallisce
 * riprova riga per riga per isolare le righe problematiche.
 * Ritorna [inserite, errori[] = ['riga' => n, 'motivo' => '...']].
 */
function cr_inserisci_blocco($tabella, $colonne, $righe, $numeri_riga) {
    global $wpdb;
    if (!$righe) return [0, []];

    $ph_riga = '(' . implode(',', array_map(fn($c) => in_array($c, ['lotto', 'pagina_pdf'], true) ? '%d' : '%s', $colonne)) . ')';
    $sql_base = "INSERT INTO $tabella (" . implode(',', $colonne) . ') VALUES ';

    $valori = [];
    foreach ($righe as $r) foreach ($colonne as $c) $valori[] = $r[$c];

    $prev = $wpdb->suppress_errors(true);
    $ok   = $wpdb->query($wpdb->prepare($sql_base . implode(',', array_fill(0, count($righe), $ph_riga)), $valori));

    $inserite = 0;
    $errori   = [];
    if ($ok !== false) {
        $inserite = count($righe);
    } else {
        foreach ($righe as $i => $r) {
            $vals = [];
            foreach ($colonne as $c) $vals[] = $r[$c];
            if ($wpdb->query($wpdb->prepare($sql_base . $ph_riga, $vals)) === false) {
                $errori[] = ['riga' => $numeri_riga[$i], 'motivo' => 'errore del database: ' . $wpdb->last_error];
            } else {
                $inserite++;
            }
        }
    }
    $wpdb->suppress_errors($prev);
    return [$inserite, $errori];
}

/**
 * Importa un CSV come nuovo lotto e, se va a buon fine, lo rende attivo.
 */
function cr_importa($tipo, $percorso, $nome_file) {
    global $wpdb;

    $stato = cr_stato_import($tipo);
    if (!empty($stato['ultimo']) && $stato['ultimo']['stato'] === 'in_corso') {
        return ['error' => 'C\'è già un import ' . $tipo . ' in corso (avviato alle ' . wp_date('H:i', $stato['ultimo']['inizio']) . '). Attendi qualche minuto e ricarica la pagina.'];
    }

    $fh = fopen($percorso, 'r');
    if ($fh === false) return cr_import_fallito($tipo, $stato, $nome_file, 'Impossibile aprire il file CSV.');

    // --- Intestazione --------------------------------------------------------
    $sep    = ',';
    $header = cr_leggi_header_csv($fh, $sep);
    $indici = [];
    foreach ((array) $header as $i => $h) $indici[strtoupper($h)] = $i;

    $mancanti = array_filter(cr_colonne_obbligatorie($tipo), fn($h) => !isset($indici[strtoupper($h)]));
    if (!$header || $mancanti) {
        fclose($fh);
        return cr_import_fallito($tipo, $stato, $nome_file,
            'Intestazione CSV non valida: mancano le colonne ' . implode(', ', $mancanti) . '. I dati online non sono stati modificati.');
    }

    // --- Preparazione lotto --------------------------------------------------
    $tabella = cr_tabella($tipo);
    $tieni   = array_map('intval', array_filter([$stato['attivo'], $stato['precedente']], fn($v) => $v !== null));
    // Elimina residui di import interrotti e versioni non più necessarie
    $wpdb->query("DELETE FROM $tabella WHERE lotto NOT IN (" . implode(',', $tieni) . ')');

    $lotto = max((int) $stato['seq'], (int) $stato['attivo'], (int) $stato['precedente']) + 1;
    $stato['seq']    = $lotto;
    $stato['ultimo'] = [
        'stato' => 'in_corso', 'inizio' => time(), 'aggiornato' => time(), 'fine' => null,
        'file' => $nome_file, 'lotto' => $lotto, 'righe' => 0, 'saltate' => 0,
        'dettagli' => [], 'avvisi' => [], 'messaggio' => '',
    ];
    cr_salva_stato_import($tipo, $stato);

    @set_time_limit(300);
    ignore_user_abort(true);

    // --- Lettura e inserimento a blocchi -----------------------------------
    $mappa      = cr_colonne_csv($tipo);
    $lunghezze  = cr_lunghezze_colonne();
    $colonne_db = array_merge(['lotto'], array_keys($mappa), $tipo === 'motori' ? ['motore_norm'] : ['fin_norm', 'oem_norm']);
    $n_colonne  = count($header);

    $blocco = []; $numeri = [];
    $righe = 0; $saltate = 0; $pagine_non_valide = 0; $senza_file = 0;
    $dettagli = [];
    $n_riga = 1;

    $annota = function ($riga, $motivo) use (&$dettagli) {
        if (count($dettagli) < 50) $dettagli[] = ['riga' => $riga, 'motivo' => $motivo];
    };

    $svuota = function () use (&$blocco, &$numeri, &$righe, &$saltate, &$dettagli, &$stato, $tabella, $colonne_db, $tipo) {
        [$ok, $err] = cr_inserisci_blocco($tabella, $colonne_db, $blocco, $numeri);
        $righe   += $ok;
        $saltate += count($err);
        foreach ($err as $e) if (count($dettagli) < 50) $dettagli[] = $e;
        $blocco = []; $numeri = [];
        $stato['ultimo']['aggiornato'] = time();
        $stato['ultimo']['righe']      = $righe;
        cr_salva_stato_import($tipo, $stato);
    };

    while (($row = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        $n_riga++;
        if ($row === [null] || implode('', array_map('trim', $row)) === '') continue; // riga vuota

        if (count($row) < $n_colonne) {
            $saltate++;
            $annota($n_riga, 'colonne mancanti (' . count($row) . ' invece di ' . $n_colonne . ')');
            continue;
        }

        $r = ['lotto' => $lotto];
        foreach ($mappa as $col => $intestazione) {
            $i = $indici[strtoupper($intestazione)] ?? null;
            $r[$col] = $i === null ? '' : cr_pulisci_valore($row[$i]);
        }

        if ($r['costruttore'] === '' || $r['motore'] === '') {
            $saltate++;
            $annota($n_riga, 'COSTRUTTORE o MOTORE vuoto');
            continue;
        }

        $pagina = $r['pagina_pdf'];
        $r['pagina_pdf'] = ctype_digit($pagina) ? (int) $pagina : (int) round((float) str_replace(',', '.', $pagina));
        if ($r['pagina_pdf'] < 1) {
            $pagine_non_valide++;
            $annota($n_riga, 'Pagina_PDF_Reale non valida ("' . $pagina . '"): il catalogo si aprirà dalla prima pagina');
            $r['pagina_pdf'] = 0;
        }
        if ($r['file_origine'] === '') {
            $senza_file++;
            $annota($n_riga, 'File_origine vuoto: il risultato non potrà aprire nessun catalogo');
        }

        if ($tipo === 'motori') {
            $r['motore_norm'] = cr_normalizza($r['motore']);
        } else {
            $r['fin_norm'] = cr_normalizza($r['fin_code']);
            $r['oem_norm'] = cr_normalizza($r['oem_code']);
        }
        foreach ($lunghezze as $col => $max) {
            if (isset($r[$col]) && is_string($r[$col]) && mb_strlen($r[$col]) > $max) $r[$col] = mb_substr($r[$col], 0, $max);
        }

        $blocco[] = $r;
        $numeri[] = $n_riga;
        if (count($blocco) >= CR_IMPORT_BLOCCO) $svuota();
    }
    $svuota();
    fclose($fh);

    // --- Esito ---------------------------------------------------------------
    if ($righe === 0) {
        $wpdb->query($wpdb->prepare("DELETE FROM $tabella WHERE lotto = %d", $lotto));
        $stato['ultimo']['dettagli'] = $dettagli;
        $stato['ultimo']['saltate']  = $saltate;
        return cr_import_fallito($tipo, $stato, $nome_file, 'Nessuna riga valida nel file. I dati online non sono stati modificati.');
    }

    $righe_prima = cr_conta_righe($tipo, (int) $stato['attivo']);

    $avvisi = [];
    if ($saltate)           $avvisi[] = "$saltate righe scartate (vedi dettaglio).";
    if ($pagine_non_valide) $avvisi[] = "$pagine_non_valide righe senza un numero di pagina valido: si apriranno dalla prima pagina del catalogo.";
    if ($senza_file)        $avvisi[] = "$senza_file righe senza File_origine: non potranno aprire nessun catalogo.";
    if ($righe_prima > 0 && $righe < $righe_prima * 0.7) {
        $avvisi[] = 'Il nuovo indice ha ' . number_format_i18n($righe) . ' righe contro ' . number_format_i18n($righe_prima)
                  . ' del precedente. Se non è voluto, usa "Ripristina versione precedente".';
    }

    // Attivazione del nuovo lotto: da qui la ricerca usa i dati nuovi
    $stato['precedente'] = (int) $stato['attivo'];
    $stato['attivo']     = $lotto;
    $info_prec           = $stato['lotti'][$stato['precedente']] ?? ['data' => null, 'file' => '', 'righe' => $righe_prima];
    $info_prec['righe']  = $righe_prima;
    $stato['lotti'] = [
        $lotto                => ['data' => time(), 'file' => $nome_file, 'righe' => $righe],
        $stato['precedente'] => $info_prec,
    ];
    $stato['ultimo'] = array_merge($stato['ultimo'], [
        'stato' => $avvisi ? 'avvisi' : 'completato', 'fine' => time(), 'aggiornato' => time(),
        'righe' => $righe, 'saltate' => $saltate, 'dettagli' => $dettagli, 'avvisi' => $avvisi,
    ]);
    cr_salva_stato_import($tipo, $stato);

    // Le versioni più vecchie della precedente non servono più
    $wpdb->query($wpdb->prepare("DELETE FROM $tabella WHERE lotto NOT IN (%d, %d)", $stato['attivo'], $stato['precedente']));

    return ['success' => true, 'azione' => 'import', 'tipo' => $tipo, 'righe' => $righe, 'saltate' => $saltate, 'avvisi' => $avvisi];
}

function cr_import_fallito($tipo, $stato, $nome_file, $messaggio) {
    $stato['ultimo'] = array_merge(
        ['inizio' => time(), 'righe' => 0, 'saltate' => 0, 'dettagli' => [], 'avvisi' => []],
        (array) $stato['ultimo'],
        ['stato' => 'fallito', 'fine' => time(), 'aggiornato' => time(), 'file' => $nome_file, 'messaggio' => $messaggio]
    );
    cr_salva_stato_import($tipo, $stato);
    return ['error' => $messaggio];
}

/**
 * Scambia la versione attiva con quella precedente (anche per annullare un ripristino).
 */
function cr_ripristina($tipo) {
    $stato = cr_stato_import($tipo);
    if ($stato['precedente'] === null) return ['error' => 'Non c\'è una versione precedente da ripristinare.'];
    if (cr_conta_righe($tipo, (int) $stato['precedente']) === 0) {
        return ['error' => 'La versione precedente non contiene dati: ripristino annullato.'];
    }

    [$stato['attivo'], $stato['precedente']] = [(int) $stato['precedente'], (int) $stato['attivo']];
    cr_salva_stato_import($tipo, $stato);

    // Scambia anche i CSV salvati, così "Reimporta il CSV salvato" resta coerente
    $csv  = cr_percorso_csv($tipo);
    $prec = $csv . '.precedente';
    if (file_exists($prec)) {
        $tmp = $csv . '.scambio';
        if (file_exists($csv)) @rename($csv, $tmp);
        @rename($prec, $csv);
        if (file_exists($tmp)) @rename($tmp, $prec);
    }

    return ['success' => true, 'azione' => 'ripristino', 'tipo' => $tipo];
}

// -----------------------------------------------------------------------------
// Azioni admin
// -----------------------------------------------------------------------------

function cr_verifica_permessi_import() {
    if (!current_user_can('manage_options')) return 'Accesso negato.';
    if (!check_admin_referer('cr_importa_nonce')) return 'Errore di sicurezza.';
    return null;
}

function cr_tipo_da_post() {
    $tipo = sanitize_key($_POST['cr_tipo'] ?? '');
    return in_array($tipo, ['motori', 'componenti'], true) ? $tipo : null;
}

/** Upload di un nuovo CSV e import */
function cr_gestisci_import() {
    if ($e = cr_verifica_permessi_import()) return ['error' => $e];
    if (!$tipo = cr_tipo_da_post()) return ['error' => 'Tipo non valido.'];

    $file = $_FILES['cr_csv_' . $tipo] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => cr_errore_upload($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') return ['error' => 'Il file deve essere in formato .csv'];
    if ($file['size'] > 50 * 1024 * 1024) return ['error' => 'Il file supera il limite di 50 MB.'];

    cr_crea_cartella_data();
    $csv     = cr_percorso_csv($tipo);
    $arrivo  = CR_DATA_DIR . 'in-arrivo-' . $tipo . '.csv';
    if (!move_uploaded_file($file['tmp_name'], $arrivo)) {
        return ['error' => 'Errore durante il salvataggio. Controlla i permessi della cartella ' . CR_DATA_DIR];
    }

    $ris = cr_importa($tipo, $arrivo, sanitize_file_name($file['name']));

    if (!empty($ris['success'])) {
        // Il CSV attuale diventa "precedente", quello nuovo prende il suo posto
        if (file_exists($csv)) @rename($csv, $csv . '.precedente');
        @rename($arrivo, $csv);
    } else {
        @unlink($arrivo);
    }
    return $ris;
}

/** Reimporta il CSV già salvato (es. dopo un aggiornamento del plugin) */
function cr_gestisci_reimporta() {
    if ($e = cr_verifica_permessi_import()) return ['error' => $e];
    if (!$tipo = cr_tipo_da_post()) return ['error' => 'Tipo non valido.'];

    $csv = cr_percorso_csv($tipo);
    if (!file_exists($csv)) return ['error' => 'Nessun CSV salvato per l\'indice ' . $tipo . '.'];

    $stato = cr_stato_import($tipo);
    $nome  = $stato['lotti'][$stato['attivo']]['file'] ?? basename($csv);
    return cr_importa($tipo, $csv, $nome);
}

function cr_gestisci_ripristina() {
    if ($e = cr_verifica_permessi_import()) return ['error' => $e];
    if (!$tipo = cr_tipo_da_post()) return ['error' => 'Tipo non valido.'];
    return cr_ripristina($tipo);
}
