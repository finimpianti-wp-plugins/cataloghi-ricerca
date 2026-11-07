<?php
defined('ABSPATH') || exit;

// =============================================================================
// PDF — elenco su disco, nomi, abbinamento con l'indice, etichette, azioni admin
//
// I nomi dei file NON vengono modificati: il CSV li cita col nome originale
// (es. "Catalogo 5 KUBOTA rel 1010.pdf") e devono restare identici.
// =============================================================================

/**
 * Nomi dei PDF presenti nella cartella (estensione .pdf in qualunque maiuscolo).
 */
function cr_elenco_pdf($ricarica = false) {
    static $cache = null;
    if ($cache !== null && !$ricarica) return $cache;

    $cache = [];
    if (!is_dir(CR_PDF_DIR)) return $cache;
    foreach (scandir(CR_PDF_DIR) ?: [] as $nome) {
        if ($nome[0] === '.') continue;
        if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') continue;
        if (is_file(CR_PDF_DIR . $nome)) $cache[] = $nome;
    }
    return $cache;
}

/**
 * Rende sicuro il nome di un file caricato SENZA cambiarne la forma:
 * mantiene spazi, maiuscole e punteggiatura, rimuove solo i caratteri
 * non validi per il filesystem e i percorsi. Ritorna '' se non è un PDF valido.
 */
function cr_nome_pdf_sicuro($nome) {
    $nome = wp_basename(str_replace('\\', '/', (string) $nome));
    $nome = preg_replace('/[\x00-\x1F\x7F\/:*?"<>|]/u', '', $nome);
    $nome = trim(preg_replace('/\s+/u', ' ', $nome));
    $nome = ltrim($nome, '. ');
    if ($nome === '' || strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') return '';
    if (strlen($nome) > 255) return '';
    return $nome;
}

/**
 * Verifica che un nome ricevuto da un form corrisponda esattamente a un file
 * presente nella cartella (whitelist): niente percorsi, niente file inventati.
 */
function cr_trova_pdf_su_disco($nome) {
    $nome = wp_unslash((string) $nome);
    return in_array($nome, cr_elenco_pdf(), true) ? $nome : null;
}

/** Chiave di confronto tollerante: minuscole, spazi/trattini/underscore unificati. */
function cr_chiave_pdf($nome) {
    $base = preg_replace('/\.pdf$/i', '', (string) $nome);
    return trim(preg_replace('/[\s_\-]+/u', ' ', mb_strtolower($base)));
}

/**
 * Abbina il nome citato nell'indice (File_origine) a un file su disco.
 * Ritorna ['file' => nome reale | null, 'esatto' => bool].
 * L'abbinamento non esatto (maiuscole o spazi/trattini diversi) copre i file
 * caricati in passato con il nome modificato da WordPress.
 */
function cr_risolvi_pdf($file_origine) {
    static $mappa = null, $firma = null;
    $elenco = cr_elenco_pdf();
    if (in_array($file_origine, $elenco, true)) return ['file' => $file_origine, 'esatto' => true];

    // La mappa viene ricostruita se nel frattempo l'elenco è cambiato (upload, rinomina...)
    if ($mappa === null || $firma !== implode('|', $elenco)) {
        $firma = implode('|', $elenco);
        $mappa = [];
        foreach ($elenco as $f) {
            $k = cr_chiave_pdf($f);
            if (!isset($mappa[$k])) $mappa[$k] = $f;
        }
    }
    $k = cr_chiave_pdf($file_origine);
    return ['file' => $mappa[$k] ?? null, 'esatto' => false];
}

/**
 * URL pubblico di un PDF (gli spazi e i caratteri speciali vengono codificati).
 * content_url() adatta http/https alla richiesta corrente: la costante
 * WP_CONTENT_URL no, e se l'indirizzo del sito è salvato come http:// il
 * browser bloccherebbe il PDF come "contenuto misto" nel visualizzatore.
 */
function cr_url_pdf($nome) {
    return content_url('uploads/cataloghi-pdf/') . rawurlencode($nome);
}

/**
 * Etichetta di un PDF. Le etichette salvate dalle versioni precedenti del plugin
 * possono avere la chiave "sanitizzata" (spazi → trattini): vengono riconosciute.
 */
function cr_label_pdf($nome, $labels = null) {
    $labels = $labels ?? get_option(CR_OPTION_LABELS, []);
    if (isset($labels[$nome])) return $labels[$nome];
    $vecchia = sanitize_file_name($nome);
    if (isset($labels[$vecchia])) return $labels[$vecchia];
    return pathinfo($nome, PATHINFO_FILENAME);
}

function cr_salva_label($nome, $label) {
    $labels = get_option(CR_OPTION_LABELS, []);
    unset($labels[sanitize_file_name($nome)]); // eventuale chiave vecchia
    $labels[$nome] = sanitize_text_field($label);
    update_option(CR_OPTION_LABELS, $labels);
}

function cr_rimuovi_label($nome) {
    $labels = get_option(CR_OPTION_LABELS, []);
    unset($labels[$nome], $labels[sanitize_file_name($nome)]);
    update_option(CR_OPTION_LABELS, $labels);
}

/**
 * Cataloghi presenti su disco, con etichetta.
 * Formato: [ ['file','label','size','modified','url'], ... ] ordinati per etichetta.
 */
function cr_get_cataloghi_da_disco() {
    $labels    = get_option(CR_OPTION_LABELS, []);
    $cataloghi = [];
    foreach (cr_elenco_pdf() as $nome) {
        $path = CR_PDF_DIR . $nome;
        $cataloghi[] = [
            'file'     => $nome,
            'label'    => cr_label_pdf($nome, $labels),
            'size'     => filesize($path),
            'modified' => filemtime($path),
            'url'      => cr_url_pdf($nome),
        ];
    }
    // Ordine naturale (Catalogo 2 prima di Catalogo 10)
    usort($cataloghi, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    return $cataloghi;
}

// -----------------------------------------------------------------------------
// Azioni admin
// -----------------------------------------------------------------------------

function cr_verifica_permessi_pdf() {
    if (!current_user_can('manage_options')) return 'Accesso negato.';
    if (!check_admin_referer('cr_pdf_nonce')) return 'Errore di sicurezza.';
    return null;
}

function cr_gestisci_pdf_upload() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    $file = $_FILES['cr_pdf_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => cr_errore_upload($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    }

    $nome = cr_nome_pdf_sicuro(wp_unslash($file['name']));
    if ($nome === '') return ['error' => 'Il file deve essere un PDF (estensione .pdf).'];

    // Controllo del contenuto: un PDF inizia sempre con "%PDF"
    $fh = fopen($file['tmp_name'], 'rb');
    $testa = $fh ? fread($fh, 1024) : '';
    if ($fh) fclose($fh);
    if (strpos((string) $testa, '%PDF') === false) {
        return ['error' => 'Il file "' . $nome . '" non sembra un PDF valido.'];
    }

    cr_crea_cartella_pdf();

    // Esiste già un file con lo stesso nome (anche con maiuscole diverse)?
    $esistente = null;
    foreach (cr_elenco_pdf() as $f) {
        if (strtolower($f) === strtolower($nome)) { $esistente = $f; break; }
    }
    $sovrascrivi = ($_POST['cr_pdf_sovrascrivi'] ?? '') === '1';
    if ($esistente && !$sovrascrivi) {
        return ['error' => 'Esiste già un catalogo chiamato "' . $esistente . '". Nessuna modifica effettuata: ricarica il file e scegli "Sovrascrivi" per sostituirlo.'];
    }

    // Salvataggio su file temporaneo e rinomina finale: se qualcosa va storto
    // il file esistente resta intatto
    $tmp = CR_PDF_DIR . '.upload-' . wp_generate_password(8, false) . '.tmp';
    if (!move_uploaded_file($file['tmp_name'], $tmp)) {
        return ['error' => 'Errore durante il salvataggio. Controlla i permessi della cartella ' . CR_PDF_DIR];
    }
    if ($esistente && $esistente !== $nome) @unlink(CR_PDF_DIR . $esistente);
    if (!@rename($tmp, CR_PDF_DIR . $nome)) {
        @unlink($tmp);
        return ['error' => 'Impossibile sostituire il file esistente. Controlla i permessi della cartella.'];
    }
    cr_elenco_pdf(true);

    $label = trim(sanitize_text_field(wp_unslash($_POST['cr_pdf_label'] ?? '')));
    if ($label === '') {
        $label = $esistente ? cr_label_pdf($esistente) : pathinfo($nome, PATHINFO_FILENAME);
    }
    if ($esistente && $esistente !== $nome) cr_rimuovi_label($esistente);
    cr_salva_label($nome, $label);

    // Lingua (con "Elementor Page Translator"): quella scelta, altrimenti quella del file sostituito
    if (isset($_POST['cr_pdf_lingua'])) {
        $lingua = cr_lingua_valida($_POST['cr_pdf_lingua']);
        if ($lingua === '' && $esistente) $lingua = cr_lingua_pdf($esistente);
        if ($esistente && $esistente !== $nome) cr_rimuovi_lingua_pdf($esistente);
        cr_salva_lingua_pdf($nome, $lingua);
    }

    return ['success' => true, 'azione' => $esistente ? 'sovrascritto' : 'upload', 'file' => $nome];
}

function cr_gestisci_pdf_label() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    $nome  = cr_trova_pdf_su_disco($_POST['cr_pdf_filename'] ?? '');
    $label = trim(sanitize_text_field(wp_unslash($_POST['cr_pdf_label'] ?? '')));

    if (!$nome)        return ['error' => 'File non trovato nella cartella dei cataloghi.'];
    if ($label === '') return ['error' => 'L\'etichetta non può essere vuota.'];

    cr_salva_label($nome, $label);
    return ['success' => true, 'azione' => 'label', 'file' => $nome];
}

function cr_gestisci_pdf_elimina() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    $nome = cr_trova_pdf_su_disco($_POST['cr_pdf_filename'] ?? '');
    if (!$nome) return ['error' => 'File non trovato nella cartella dei cataloghi.'];

    if (!@unlink(CR_PDF_DIR . $nome)) return ['error' => 'Impossibile eliminare il file. Controlla i permessi.'];
    cr_elenco_pdf(true);
    cr_rimuovi_label($nome);
    cr_rimuovi_lingua_pdf($nome);

    return ['success' => true, 'azione' => 'elimina', 'file' => $nome];
}

/**
 * Rinomina un PDF col nome citato nell'indice (dal pannello di diagnostica).
 */
function cr_gestisci_pdf_rinomina() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    $nome  = cr_trova_pdf_su_disco($_POST['cr_pdf_filename'] ?? '');
    $nuovo = wp_unslash((string) ($_POST['cr_pdf_nuovo'] ?? ''));

    if (!$nome) return ['error' => 'File non trovato nella cartella dei cataloghi.'];
    if (cr_nome_pdf_sicuro($nuovo) !== $nuovo) {
        return ['error' => 'Il nome "' . $nuovo . '" contiene caratteri non validi per un file: correggilo nel CSV.'];
    }
    if (strtolower($nuovo) !== strtolower($nome) && file_exists(CR_PDF_DIR . $nuovo)) {
        return ['error' => 'Esiste già un file chiamato "' . $nuovo . '".'];
    }

    $label  = cr_label_pdf($nome);
    $lingua = cr_lingua_pdf($nome);
    if (!@rename(CR_PDF_DIR . $nome, CR_PDF_DIR . $nuovo)) {
        return ['error' => 'Impossibile rinominare il file. Controlla i permessi.'];
    }
    cr_elenco_pdf(true);
    cr_rimuovi_label($nome);
    cr_salva_label($nuovo, $label);
    cr_rimuovi_lingua_pdf($nome);
    cr_salva_lingua_pdf($nuovo, $lingua);

    return ['success' => true, 'azione' => 'rinomina', 'file' => $nome, 'nuovo' => $nuovo];
}

function cr_gestisci_pdf_scansiona() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    cr_crea_cartella_pdf();
    $files  = cr_elenco_pdf(true);
    $labels = get_option(CR_OPTION_LABELS, []);
    $nuovi  = 0;

    foreach ($files as $nome) {
        if (isset($labels[$nome]) || isset($labels[sanitize_file_name($nome)])) continue;
        // Etichetta iniziale leggibile dal nome del file
        $labels[$nome] = sanitize_text_field(str_replace(['-', '_'], ' ', pathinfo($nome, PATHINFO_FILENAME)));
        $nuovi++;
    }
    update_option(CR_OPTION_LABELS, $labels);

    return ['success' => true, 'azione' => 'scansiona', 'totale' => count($files), 'nuovi' => $nuovi];
}

/** Lingua del PDF scelta nel pannello. */
function cr_gestisci_pdf_lingua() {
    if ($e = cr_verifica_permessi_pdf()) return ['error' => $e];

    $nome = cr_trova_pdf_su_disco($_POST['cr_pdf_filename'] ?? '');
    if (!$nome) return ['error' => 'File non trovato nella cartella dei cataloghi.'];

    cr_salva_lingua_pdf($nome, cr_lingua_valida($_POST['cr_pdf_lingua'] ?? ''));
    return ['success' => true, 'azione' => 'lingua', 'file' => $nome];
}
