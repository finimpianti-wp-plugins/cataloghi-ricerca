<?php
defined('ABSPATH') || exit;

// =============================================================================
// DIAGNOSTICA — tutto ciò che il pannello admin deve segnalare
// =============================================================================

/**
 * Quante righe di ciascun indice citano ogni PDF (solo versione attiva).
 * Formato: [ 'nome citato.pdf' => ['motori' => n, 'componenti' => n] ]
 */
function cr_riferimenti_pdf() {
    global $wpdb;
    $rif = [];
    foreach (['motori', 'componenti'] as $tipo) {
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT file_origine, COUNT(*) AS n FROM ' . cr_tabella($tipo) . ' WHERE lotto = %d GROUP BY file_origine',
            cr_lotto_attivo($tipo)
        ), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if ($r['file_origine'] === '') continue;
            $rif[$r['file_origine']] = ($rif[$r['file_origine']] ?? ['motori' => 0, 'componenti' => 0]);
            $rif[$r['file_origine']][$tipo] = (int) $r['n'];
        }
    }
    return $rif;
}

function cr_descrivi_righe($conteggi) {
    $parti = [];
    if (!empty($conteggi['motori']))     $parti[] = number_format_i18n($conteggi['motori']) . ' motori';
    if (!empty($conteggi['componenti'])) $parti[] = number_format_i18n($conteggi['componenti']) . ' componenti';
    return implode(' e ', $parti);
}

/**
 * Elenco dei problemi, dal più grave.
 * Ogni problema: ['livello' => errore|avviso|info, 'titolo', 'testo' (HTML), 'elenco' => [HTML...]]
 * Ritorna anche l'uso di ogni PDF su disco, per la tabella cataloghi.
 */
function cr_diagnostica() {
    global $wpdb;
    $problemi = [];
    $tipi     = ['motori' => 'Motori', 'componenti' => 'Componenti'];

    // --- Import ----------------------------------------------------------------
    foreach ($tipi as $tipo => $nome) {
        $u = cr_stato_import($tipo)['ultimo'];
        if (!$u) continue;
        $quando = cr_format_data($u['inizio']);
        $file   = esc_html($u['file']);

        if ($u['stato'] === 'interrotto') {
            $problemi[] = ['livello' => 'errore', 'titolo' => "Import $nome interrotto",
                'testo' => "L'import di <strong>$file</strong> del $quando si è interrotto dopo "
                         . number_format_i18n($u['righe']) . ' righe (probabilmente per un limite di tempo del server). '
                         . '<strong>La ricerca usa ancora i dati precedenti, che non sono stati toccati.</strong> Riprova l\'import; se si ripete contatta l\'hosting per aumentare <code>max_execution_time</code>.'];
        } elseif ($u['stato'] === 'fallito') {
            $problemi[] = ['livello' => 'errore', 'titolo' => "Import $nome non riuscito",
                'testo' => "Import di <strong>$file</strong> del $quando: " . esc_html($u['messaggio'])];
        } elseif ($u['stato'] === 'avvisi') {
            $elenco = array_map(fn($d) => 'Riga ' . (int) $d['riga'] . ': ' . esc_html($d['motivo']), $u['dettagli']);
            $problemi[] = ['livello' => 'avviso', 'titolo' => "Import $nome completato con avvisi",
                'testo' => "Import di <strong>$file</strong> del $quando: " . esc_html(implode(' ', $u['avvisi'])),
                'elenco' => $elenco, 'elenco_titolo' => 'Righe interessate' . (count($elenco) >= 50 ? ' (prime 50)' : '')];
        } elseif ($u['stato'] === 'in_corso') {
            $problemi[] = ['livello' => 'info', 'titolo' => "Import $nome in corso",
                'testo' => "Import di <strong>$file</strong> avviato alle " . wp_date('H:i', $u['inizio']) . ': '
                         . number_format_i18n($u['righe']) . ' righe finora. Ricarica la pagina tra poco.'];
        }
    }

    // --- Indici vuoti / da aggiornare ------------------------------------------
    foreach ($tipi as $tipo => $nome) {
        $attivo = cr_lotto_attivo($tipo);
        if (cr_conta_righe($tipo, $attivo) === 0) {
            $problemi[] = ['livello' => 'avviso', 'titolo' => "Indice $nome vuoto",
                'testo' => "La ricerca per " . strtolower($nome) . ' non darà risultati finché non importi il CSV.'];
            continue;
        }
        // Righe importate con versioni precedenti del plugin: manca la forma normalizzata dei codici
        $col  = $tipo === 'motori' ? "motore <> '' AND motore_norm = ''" : "((fin_code <> '' AND fin_norm = '') OR (oem_code <> '' AND oem_norm = ''))";
        $da_aggiornare = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cr_tabella($tipo) . " WHERE lotto = %d AND $col", $attivo));
        if ($da_aggiornare) {
            $problemi[] = ['livello' => 'avviso', 'titolo' => "Indice $nome da aggiornare",
                'testo' => 'L\'indice è stato importato con una versione precedente del plugin: la ricerca tollerante '
                         . '(es. "6500095" per "6500.095") non funziona su ' . number_format_i18n($da_aggiornare) . ' righe. '
                         . 'Usa <strong>"Reimporta il CSV salvato"</strong> nella sezione Indici di ricerca, oppure importa di nuovo il CSV.'];
        }
        $senza_pagina = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cr_tabella($tipo) . ' WHERE lotto = %d AND pagina_pdf = 0', $attivo));
        if ($senza_pagina) {
            $problemi[] = ['livello' => 'avviso', 'titolo' => "Righe $nome senza pagina",
                'testo' => number_format_i18n($senza_pagina) . ' righe non hanno un numero di pagina valido (Pagina_PDF_Reale): il catalogo si aprirà dalla prima pagina.'];
        }
    }

    // --- PDF citati dagli indici ------------------------------------------------
    $rif      = cr_riferimenti_pdf();
    $mancanti = [];
    $diversi  = [];
    $uso      = []; // nome reale su disco => conteggi
    foreach ($rif as $citato => $conteggi) {
        $pdf = cr_risolvi_pdf($citato);
        if ($pdf['file'] === null) {
            $mancanti[] = '<code>' . esc_html($citato) . '</code> — citato da ' . cr_descrivi_righe($conteggi);
            continue;
        }
        $u = $uso[$pdf['file']] ?? ['motori' => 0, 'componenti' => 0];
        $uso[$pdf['file']] = ['motori' => $u['motori'] + $conteggi['motori'], 'componenti' => $u['componenti'] + $conteggi['componenti']];
        if (!$pdf['esatto']) $diversi[] = ['citato' => $citato, 'file' => $pdf['file'], 'conteggi' => $conteggi];
    }
    if ($mancanti) {
        $problemi[] = ['livello' => 'errore', 'titolo' => count($mancanti) === 1 ? '1 catalogo PDF mancante' : count($mancanti) . ' cataloghi PDF mancanti',
            'testo' => 'Questi file sono citati negli indici ma non sono nella cartella dei cataloghi: i risultati collegati '
                     . 'vengono mostrati come "catalogo non disponibile". Caricali con <strong>esattamente</strong> questo nome.',
            'elenco' => $mancanti];
    }
    if ($diversi) {
        $problemi[] = ['livello' => 'avviso', 'titolo' => 'PDF con nome diverso da quello degli indici',
            'testo' => 'Questi file sono stati abbinati lo stesso (cambiano solo maiuscole, spazi o trattini) e i link funzionano. '
                     . 'Per evitare ambiguità puoi rinominarli come negli indici.',
            'rinomina' => $diversi];
    }

    // --- PDF presenti ma non usati ----------------------------------------------
    $non_usati = array_diff(cr_elenco_pdf(), array_keys($uso));
    if ($non_usati && $rif) {
        $problemi[] = ['livello' => 'info', 'titolo' => count($non_usati) . ' PDF non citati da nessun indice',
            'testo' => 'Compaiono tra i cataloghi consultabili ma nessun risultato di ricerca li apre. Se è voluto, ignora questo messaggio.',
            'elenco' => array_map(fn($f) => '<code>' . esc_html($f) . '</code>', array_values($non_usati))];
    }

    // --- Carosello --------------------------------------------------------------
    $car = cr_get_carosello();
    $rimosse = array_filter($car['ids'], fn($id) => !wp_attachment_is_image($id));
    if ($rimosse) {
        $problemi[] = ['livello' => 'avviso', 'titolo' => 'Immagini del carosello non più presenti',
            'testo' => count($rimosse) . ' immagini sono state eliminate dalla Libreria media e non vengono mostrate. '
                     . 'Premi "Salva carosello" per aggiornare l\'elenco.'];
    }

    $ordine = ['errore' => 0, 'avviso' => 1, 'info' => 2];
    usort($problemi, fn($a, $b) => $ordine[$a['livello']] <=> $ordine[$b['livello']]);

    return ['problemi' => $problemi, 'uso_pdf' => $uso];
}

// Avviso in tutto il pannello di WordPress se l'ultimo import non è andato a buon fine
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (($_GET['page'] ?? '') === 'cataloghi-finimpianti') return;
    foreach (['motori', 'componenti'] as $tipo) {
        $u = cr_stato_import($tipo)['ultimo'];
        if ($u && in_array($u['stato'], ['interrotto', 'fallito'], true)) {
            $url = admin_url('admin.php?page=cataloghi-finimpianti');
            echo '<div class="notice notice-error"><p><strong>Cataloghi:</strong> l\'ultimo import dell\'indice '
               . esc_html($tipo) . ' non è andato a buon fine. <a href="' . esc_url($url) . '">Apri il pannello</a></p></div>';
            return;
        }
    }
});
