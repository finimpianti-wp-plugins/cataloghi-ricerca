<?php
defined('ABSPATH') || exit;

// =============================================================================
// STILE E TESTI — impostazioni personalizzabili del frontend
//
// Ogni impostazione diventa una variabile CSS (--cr-nome-impostazione) usata da
// assets/style.css. I valori predefiniti riproducono lo stile originale, quindi
// senza modifiche l'aspetto non cambia.
//
// Portabilità: colori e caratteri possono riferirsi agli stili globali del sito
// (Elementor, tema) con un valore di riserva, es.
//   var(--e-global-color-primary, #012611)
// così seguono il sito su cui il plugin è installato.
// =============================================================================

const CR_OPTION_STILE = 'cr_stile';
const CR_STILE_BOZZA  = 'cr_stile_bozza_';   // + id utente: modifiche non salvate, per l'anteprima

function cr_stile_gruppi() {
    return [
        'generale'   => ['Colori e generale',   'La palette del plugin: gli altri colori si riferiscono a questi, quindi cambiandoli qui cambia tutto.'],
        'caratteri'  => ['Caratteri e titoli',  'Font dei testi e aspetto dei titoli delle sezioni.'],
        'nav'        => ['Pulsanti in alto',    'I tre pulsanti che portano alle sezioni della pagina.'],
        'carosello'  => ['Carosello',           'La striscia di immagini (le immagini si scelgono nel pannello Cataloghi Fin.).'],
        'riquadri'   => ['Riquadri e campi',    'I riquadri bianchi delle due ricerche, i campi di ricerca e i filtri.'],
        'risultati'  => ['Risultati',           'Le righe dei risultati di ricerca.'],
        'fascia'     => ['Fascia cataloghi',    'La fascia a tutta larghezza con i pulsanti dei cataloghi.'],
        'viewer'     => ['Visualizzatore PDF',  'La finestra che mostra i cataloghi alla pagina giusta (usa anche i colori principale e di accento).'],
        'testi'      => ['Testi e sezioni',     'Titoli, etichette e suggerimenti mostrati ai visitatori, e quali sezioni mostrare.'],
    ];
}

/**
 * Schema delle impostazioni.
 * tipo: colore | font | numero | scelta | si_no | testo
 * r:    [tablet, mobile] per i valori che possono cambiare per dispositivo ('' = come il dispositivo più grande)
 * ricarica: l'anteprima va ricaricata (cambia il contenuto, non solo lo stile)
 */
function cr_stile_schema() {
    $peso = ['300' => 'Leggero', '400' => 'Normale', '500' => 'Medio', '600' => 'Semi grassetto', '700' => 'Grassetto'];
    $px = fn($etichetta, $def, $min, $max, $extra = []) => array_merge(['tipo' => 'numero', 'default' => $def, 'min' => $min, 'max' => $max, 'passo' => 1, 'unita' => 'px', 'etichetta' => $etichetta], $extra);
    $col = fn($etichetta, $def, $extra = []) => array_merge(['tipo' => 'colore', 'default' => $def, 'etichetta' => $etichetta], $extra);

    $s = [
        // --- Colori e generale ------------------------------------------------
        'colore_principale'  => $col('Colore principale', '#012611', ['aiuto' => 'Testi in evidenza, pulsanti al passaggio del mouse, sfondo della fascia cataloghi.']),
        'colore_accento'     => $col('Colore di accento', '#ADD9C0', ['aiuto' => 'Pulsanti in alto, filtri, evidenziazioni. Le tinte chiare (sfondi dei risultati, dei filtri) sono ricavate da questo colore.']),
        'colore_testo'       => $col('Testo secondario', 'rgba(1,38,17,.52)', ['aiuto' => 'Titoli delle sezioni, suggerimenti, testi dei campi.']),
        'colore_testo_medio' => $col('Testo dei dettagli', 'rgba(1,38,17,.72)', ['aiuto' => 'Dettagli dei risultati (cilindri, catalogo...).']),
        'colore_superfici'   => $col('Sfondo di riquadri e campi', '#FFFFFF'),
        'colore_bordi'       => $col('Bordi dei campi', 'rgba(1,38,17,.18)'),
        'colore_linee'       => $col('Linee divisorie', 'rgba(1,38,17,.10)'),
        'colore_errore'      => $col('Messaggi di errore', '#B3261E'),
        'sfondo_blocco'      => $col('Sfondo dell\'intero blocco', 'transparent', ['aiuto' => '"transparent" = si vede lo sfondo della pagina o della sezione Elementor.']),
        'larghezza_max'      => $px('Larghezza massima del contenuto', 1280, 600, 2400, ['passo' => 10]),
        'margine_lati'       => $px('Margine ai lati', 40, 0, 200, ['r' => ['', 12]]),
        'margine_sopra'      => $px('Margine sopra', 40, 0, 300, ['r' => ['', 24]]),

        // --- Caratteri e titoli -----------------------------------------------
        'font_base'          => ['tipo' => 'font', 'default' => 'inherit', 'etichetta' => 'Carattere dei testi', 'aiuto' => '"Font del sito" usa il carattere della pagina in cui si trova lo shortcode.'],
        'stile_titoli'       => ['tipo' => 'scelta', 'default' => 'plugin', 'opzioni' => ['plugin' => 'Stile impostato qui sotto', 'sito' => 'Stile dei titoli (H2) del sito'],
                                 'etichetta' => 'Stile dei titoli delle sezioni', 'ricarica' => true,
                                 'aiuto' => '"Stile dei titoli del sito" lascia ai titoli l\'aspetto degli H2 del tema / di Elementor: le impostazioni qui sotto vengono ignorate (tranne i colori della fascia).'],
        'font_titoli'        => ['tipo' => 'font', 'default' => 'inherit', 'etichetta' => 'Carattere dei titoli'],
        'dim_titoli'         => $px('Dimensione dei titoli', 44, 12, 160, ['r' => [36, 28]]),
        'peso_titoli'        => ['tipo' => 'scelta', 'default' => '400', 'opzioni' => $peso, 'etichetta' => 'Peso dei titoli'],
        'colore_titoli'      => $col('Colore dei titoli', 'var(--cr-colore-testo)'),
        'interlinea_titoli'  => ['tipo' => 'numero', 'default' => 1.15, 'min' => 0.8, 'max' => 2.5, 'passo' => 0.05, 'unita' => '', 'etichetta' => 'Interlinea dei titoli'],
        'spazio_titoli'      => $px('Spazio sotto i titoli', 24, 0, 120, ['r' => ['', 18]]),

        // --- Pulsanti in alto ---------------------------------------------------
        'mostra_nav'         => ['tipo' => 'si_no', 'default' => 1, 'etichetta' => 'Mostra i pulsanti in alto', 'ricarica' => true],
        'font_nav'           => ['tipo' => 'font', 'default' => 'inherit', 'etichetta' => 'Carattere'],
        'nav_dim'            => $px('Dimensione del testo', 15, 8, 40, ['r' => ['', '']]),
        'nav_peso'           => ['tipo' => 'scelta', 'default' => '500', 'opzioni' => $peso, 'etichetta' => 'Peso del testo'],
        'nav_sfondo'         => $col('Sfondo', 'var(--cr-colore-accento)'),
        'nav_testo'          => $col('Testo', 'var(--cr-colore-testo)'),
        'nav_sfondo_hover'   => $col('Sfondo al passaggio del mouse', 'var(--cr-colore-principale)'),
        'nav_testo_hover'    => $col('Testo al passaggio del mouse', '#FFFFFF'),
        'nav_raggio'         => $px('Arrotondamento angoli', 6, 0, 60),
        'nav_altezza'        => $px('Altezza minima', 48, 20, 120, ['r' => ['', 44]]),
        'nav_larghezza'      => $px('Larghezza minima', 300, 0, 800, ['r' => [0, 0], 'aiuto' => 'Su tablet e mobile i pulsanti si dividono comunque lo spazio disponibile.']),
        'nav_spazio'         => $px('Spazio tra i pulsanti', 48, 0, 200, ['r' => ['', 10]]),
        'nav_margine'        => $px('Spazio sotto i pulsanti', 40, 0, 200, ['r' => ['', 28]]),

        // --- Carosello ----------------------------------------------------------
        'car_altezza'        => $px('Altezza delle immagini', 140, 20, 600, ['r' => ['', 90]]),
        'car_spazio'         => $px('Spazio tra le immagini', 72, 0, 300, ['r' => ['', 40]]),
        'car_margine'        => $px('Spazio sotto il carosello', 48, 0, 300, ['r' => ['', 32]]),
        'car_dissolvenza'    => ['tipo' => 'si_no', 'default' => 1, 'etichetta' => 'Dissolvenza ai bordi', 'ricarica' => true],

        // --- Riquadri e campi ---------------------------------------------------
        'riq_sfondo'         => $col('Sfondo dei riquadri', 'var(--cr-colore-superfici)'),
        'riq_raggio'         => $px('Arrotondamento angoli dei riquadri', 16, 0, 80),
        'riq_ombra'          => ['tipo' => 'scelta', 'default' => 'leggera', 'opzioni' => ['nessuna' => 'Nessuna', 'leggera' => 'Leggera', 'media' => 'Media', 'forte' => 'Forte'], 'etichetta' => 'Ombra dei riquadri'],
        'riq_bordo'          => $px('Spessore del bordo dei riquadri', 0, 0, 10),
        'riq_bordo_colore'   => $col('Colore del bordo dei riquadri', 'var(--cr-colore-linee)'),
        'riq_pad_orizz'      => $px('Spazio interno ai lati', 56, 0, 200, ['r' => [40, 22]]),
        'riq_pad_vert'       => $px('Spazio interno sopra e sotto', 48, 0, 200, ['r' => ['', 28]]),
        'riq_spazio'         => $px('Spazio tra i riquadri', 28, 0, 200, ['r' => ['', 20]]),
        'campo_altezza'      => $px('Altezza dei campi di ricerca', 46, 24, 100),
        'campo_raggio'       => $px('Arrotondamento dei campi', 8, 0, 50),
        'campo_larghezza'    => $px('Larghezza massima dei campi', 520, 120, 2000, ['passo' => 10]),
        'campo_dim'          => $px('Dimensione del testo nei campi', 15, 10, 30, ['r' => ['', 16], 'aiuto' => 'Su iPhone sotto i 16 px il browser ingrandisce la pagina quando si tocca il campo.']),
        'campo_sfondo'       => $col('Sfondo dei campi', 'var(--cr-colore-superfici)'),
        'campo_bordo'        => $col('Bordo dei campi', 'var(--cr-colore-bordi)'),
        'aiuto_dim'          => $px('Dimensione dei suggerimenti sotto i campi', 13, 9, 24),
        'chip_raggio'        => $px('Arrotondamento dei filtri costruttore', 18, 0, 40),
        'chip_dim'           => $px('Testo dei filtri costruttore', 13, 9, 24),

        // --- Risultati ----------------------------------------------------------
        'ris_dim'            => $px('Testo principale dei risultati', 16, 10, 32, ['r' => ['', '']]),
        'ris_codice_dim'     => $px('Codice del componente', 17, 10, 36, ['r' => ['', '']]),
        'ris_dettagli_dim'   => $px('Testo dei dettagli', 14, 9, 24),
        'ris_hover'          => $col('Sfondo della riga al passaggio del mouse', 'var(--cr-tinta)'),
        'ris_evidenzia'      => $col('Evidenziazione del testo cercato', 'var(--cr-colore-accento)'),
        'badge_sfondo'       => $col('Etichetta tipo componente: sfondo', 'var(--cr-colore-accento)'),
        'badge_testo'        => $col('Etichetta tipo componente: testo', 'var(--cr-colore-principale)'),
        'btn_raggio'         => $px('Arrotondamento del pulsante "Carica altri"', 8, 0, 40),

        // --- Fascia cataloghi ---------------------------------------------------
        'mostra_fascia'      => ['tipo' => 'si_no', 'default' => 1, 'etichetta' => 'Mostra la fascia dei cataloghi', 'ricarica' => true],
        'fascia_sfondo'      => $col('Sfondo della fascia', 'var(--cr-colore-principale)'),
        'fascia_titolo'      => $col('Colore del titolo', '#FFFFFF'),
        'fascia_altezza'     => $px('Altezza minima', 395, 0, 1200, ['r' => ['', 0]]),
        'fascia_pad'         => $px('Spazio interno sopra', 64, 0, 300, ['r' => ['', 44]]),
        'fascia_margine'     => $px('Spazio sopra la fascia', 72, 0, 300, ['r' => ['', 48]]),
        'fascia_colonne'     => ['tipo' => 'numero', 'default' => 4, 'min' => 1, 'max' => 8, 'passo' => 1, 'unita' => 'colonne', 'etichetta' => 'Colonne di pulsanti', 'r' => [3, 2],
                                 'aiuto' => 'Con il valore mobile 2, sui telefoni più stretti di 420 px i pulsanti vanno comunque su una colonna.'],
        'fascia_spazio_col'  => $px('Spazio tra le colonne', 40, 0, 200, ['r' => [24, 10]]),
        'fascia_spazio_righe'=> $px('Spazio tra le righe', 14, 0, 100, ['r' => [12, 10]]),
        'cat_sfondo'         => $col('Pulsanti: sfondo', '#FFFFFF'),
        'cat_testo'          => $col('Pulsanti: testo', 'var(--cr-colore-testo)'),
        'cat_sfondo_hover'   => $col('Pulsanti: sfondo al passaggio del mouse', 'var(--cr-colore-accento)'),
        'cat_testo_hover'    => $col('Pulsanti: testo al passaggio del mouse', 'var(--cr-colore-principale)'),
        'cat_raggio'         => $px('Pulsanti: arrotondamento', 6, 0, 40),
        'cat_dim'            => $px('Pulsanti: dimensione del testo', 13, 9, 30, ['r' => ['', '']]),
        'cat_altezza'        => $px('Pulsanti: altezza minima', 38, 20, 120),

        // --- Visualizzatore PDF -------------------------------------------------
        'vis_barra'          => $col('Sfondo della barra', '#FFFFFF'),
        'vis_sfondo'         => $col('Sfondo dietro le pagine', '#E9EEEB'),

        // --- Testi e sezioni ----------------------------------------------------
        'mostra_componenti'  => ['tipo' => 'si_no', 'default' => 1, 'etichetta' => 'Mostra la ricerca per codice componente', 'ricarica' => true],
        'mostra_motori'      => ['tipo' => 'si_no', 'default' => 1, 'etichetta' => 'Mostra la ricerca per tipologia di motore', 'ricarica' => true],
        'testo_nav_comp'     => ['tipo' => 'testo', 'default' => 'Ricerca per codice componente', 'etichetta' => 'Pulsante in alto: ricerca componenti'],
        'testo_nav_mot'      => ['tipo' => 'testo', 'default' => 'Ricerca per tipologia di motore', 'etichetta' => 'Pulsante in alto: ricerca motori'],
        'testo_nav_cat'      => ['tipo' => 'testo', 'default' => 'Consulta i cataloghi interi', 'etichetta' => 'Pulsante in alto: cataloghi'],
        'titolo_comp'        => ['tipo' => 'testo', 'default' => 'Ricerca per codice componente', 'etichetta' => 'Titolo della ricerca componenti'],
        'segnaposto_codice'  => ['tipo' => 'testo', 'default' => 'es. FIN147PC00 oppure 6500.095', 'etichetta' => 'Testo nel campo codice'],
        'aiuto_codice'       => ['tipo' => 'testo', 'default' => 'Inserisci un codice FIN o OEM: il tipo viene riconosciuto in automatico', 'etichetta' => 'Suggerimento sotto il campo codice'],
        'titolo_motori'      => ['tipo' => 'testo', 'default' => 'Ricerca per tipologia di motore', 'etichetta' => 'Titolo della ricerca motori'],
        'testo_filtri'       => ['tipo' => 'testo', 'default' => 'Filtra per costruttore', 'etichetta' => 'Pulsante dei filtri'],
        'segnaposto_motore'  => ['tipo' => 'testo', 'default' => 'Inserisci il nome del motore', 'etichetta' => 'Testo nel campo motore'],
        'aiuto_motore'       => ['tipo' => 'testo', 'default' => 'Scrivi almeno 3 caratteri o seleziona uno o più costruttori', 'etichetta' => 'Suggerimento sotto il campo motore'],
        'titolo_cataloghi'   => ['tipo' => 'testo', 'default' => 'Consulta i cataloghi interi', 'etichetta' => 'Titolo della fascia cataloghi'],
        'testo_nessun_cat'   => ['tipo' => 'testo', 'default' => 'Nessun catalogo disponibile al momento.', 'etichetta' => 'Messaggio se non ci sono cataloghi'],
    ];

    // Gruppo di appartenenza (in ordine di dichiarazione)
    $gruppi = [
        'generale'  => ['colore_principale', 'colore_accento', 'colore_testo', 'colore_testo_medio', 'colore_superfici', 'colore_bordi', 'colore_linee', 'colore_errore', 'sfondo_blocco', 'larghezza_max', 'margine_lati', 'margine_sopra'],
        'caratteri' => ['font_base', 'stile_titoli', 'font_titoli', 'dim_titoli', 'peso_titoli', 'colore_titoli', 'interlinea_titoli', 'spazio_titoli'],
        'nav'       => ['mostra_nav', 'font_nav', 'nav_dim', 'nav_peso', 'nav_sfondo', 'nav_testo', 'nav_sfondo_hover', 'nav_testo_hover', 'nav_raggio', 'nav_altezza', 'nav_larghezza', 'nav_spazio', 'nav_margine'],
        'carosello' => ['car_altezza', 'car_spazio', 'car_margine', 'car_dissolvenza'],
        'riquadri'  => ['riq_sfondo', 'riq_raggio', 'riq_ombra', 'riq_bordo', 'riq_bordo_colore', 'riq_pad_orizz', 'riq_pad_vert', 'riq_spazio', 'campo_altezza', 'campo_raggio', 'campo_larghezza', 'campo_dim', 'campo_sfondo', 'campo_bordo', 'aiuto_dim', 'chip_raggio', 'chip_dim'],
        'risultati' => ['ris_dim', 'ris_codice_dim', 'ris_dettagli_dim', 'ris_hover', 'ris_evidenzia', 'badge_sfondo', 'badge_testo', 'btn_raggio'],
        'fascia'    => ['mostra_fascia', 'fascia_sfondo', 'fascia_titolo', 'fascia_altezza', 'fascia_pad', 'fascia_margine', 'fascia_colonne', 'fascia_spazio_col', 'fascia_spazio_righe', 'cat_sfondo', 'cat_testo', 'cat_sfondo_hover', 'cat_testo_hover', 'cat_raggio', 'cat_dim', 'cat_altezza'],
        'viewer'    => ['vis_barra', 'vis_sfondo'],
        'testi'     => ['mostra_componenti', 'mostra_motori', 'testo_nav_comp', 'testo_nav_mot', 'testo_nav_cat', 'titolo_comp', 'segnaposto_codice', 'aiuto_codice', 'titolo_motori', 'testo_filtri', 'segnaposto_motore', 'aiuto_motore', 'titolo_cataloghi', 'testo_nessun_cat'],
    ];
    foreach ($gruppi as $g => $chiavi) foreach ($chiavi as $k) $s[$k]['gruppo'] = $g;
    return $s;
}

/** Suffissi dei valori per dispositivo: chiave_t (tablet), chiave_m (mobile) */
const CR_STILE_DISPOSITIVI = ['t' => 1024, 'm' => 767];

function cr_stile_default() {
    $d = [];
    foreach (cr_stile_schema() as $k => $s) {
        $d[$k] = $s['default'];
        if (!empty($s['r'])) { $d[$k . '_t'] = $s['r'][0]; $d[$k . '_m'] = $s['r'][1]; }
    }
    return $d;
}

// -----------------------------------------------------------------------------
// Validazione
// -----------------------------------------------------------------------------

/**
 * Colore CSS ammesso: esadecimale, rgb(a)/hsl(a), transparent, oppure un
 * riferimento a una variabile (globali di Elementor / del tema / del plugin)
 * con eventuale colore di riserva: var(--e-global-color-primary, #012611).
 */
function cr_colore_valido($v, $profondita = 0) {
    $v = trim((string) $v);
    if ($v === '' || $profondita > 3) return null;
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v)) return $v;
    if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg-]+\)$/i', $v)) return $v;
    if (in_array(strtolower($v), ['transparent', 'currentcolor', 'inherit'], true)) return strtolower($v);
    if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*(.+))?\)$/', $v, $m)) {
        if (!isset($m[2])) return 'var(' . $m[1] . ')';
        $riserva = cr_colore_valido($m[2], $profondita + 1);
        return $riserva === null ? null : 'var(' . $m[1] . ', ' . $riserva . ')';
    }
    return null;
}

/** Carattere ammesso: inherit, variabile (globali), oppure nomi di font separati da virgole. */
function cr_font_valido($v) {
    $v = trim((string) $v);
    if ($v === '' || strtolower($v) === 'inherit') return 'inherit';
    if (preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*(,\s*[A-Za-z0-9 ,"\'_-]+)?\)$/', $v)) return $v;
    if (preg_match('/^[A-Za-z0-9 ,"\'_-]{1,200}$/', $v)) return $v;
    return null;
}

function cr_stile_sanifica($in) {
    $in  = is_array($in) ? $in : [];
    $out = [];
    foreach (cr_stile_schema() as $k => $s) {
        $chiavi = [$k => $s['default']];
        if (!empty($s['r'])) { $chiavi[$k . '_t'] = ''; $chiavi[$k . '_m'] = ''; }
        foreach ($chiavi as $chiave => $def) {
            $v = $in[$chiave] ?? null;
            $vuoto = $v === null || $v === '';
            switch ($s['tipo']) {
                case 'numero':
                    if ($chiave !== $k && $vuoto) { $out[$chiave] = ''; break; } // tablet/mobile: vuoto = come il dispositivo più grande
                    $out[$chiave] = is_numeric($v) ? max($s['min'], min($s['max'], (float) $v)) : ($chiave === $k ? $s['default'] : '');
                    break;
                case 'colore':
                    $out[$chiave] = cr_colore_valido($v) ?? $s['default'];
                    break;
                case 'font':
                    $out[$chiave] = cr_font_valido($v) ?? $s['default'];
                    break;
                case 'scelta':
                    $out[$chiave] = array_key_exists((string) $v, $s['opzioni']) ? (string) $v : $s['default'];
                    break;
                case 'si_no':
                    // assente (es. stile importato da una versione precedente) = valore predefinito
                    $out[$chiave] = $v === null ? (int) $s['default'] : (!empty($v) ? 1 : 0);
                    break;
                case 'testo':
                    $out[$chiave] = $vuoto ? '' : sanitize_text_field((string) $v);
                    break;
            }
        }
    }
    return $out;
}

/** Stile salvato, completato con i valori predefiniti. */
function cr_stile_salvato() {
    $s = get_option(CR_OPTION_STILE, []);
    return is_array($s) ? $s + cr_stile_default() : cr_stile_default();
}

/**
 * Stile in uso: quello salvato, oppure la bozza non ancora salvata quando la
 * pagina viene aperta dall'anteprima del pannello.
 */
function cr_stile() {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (cr_in_anteprima_stile()) {
        $bozza = get_transient(CR_STILE_BOZZA . get_current_user_id());
        if (is_array($bozza)) return $cache = $bozza + cr_stile_default();
    }
    return $cache = cr_stile_salvato();
}

/** Testo da mostrare: quello impostato o, se lasciato vuoto, quello predefinito. */
function cr_testo($chiave) {
    $s = cr_stile();
    $v = trim((string) ($s[$chiave] ?? ''));
    return $v !== '' ? $v : cr_stile_schema()[$chiave]['default'];
}

// -----------------------------------------------------------------------------
// CSS
// -----------------------------------------------------------------------------

function cr_stile_var($k) {
    return '--cr-' . str_replace('_', '-', $k);
}

function cr_stile_valore_css($s, $v) {
    if ($s['tipo'] === 'numero') {
        $n = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
        return $s['unita'] === 'px' ? $n . 'px' : $n;
    }
    return (string) $v;
}

/** Ombre dei riquadri: tinte del colore principale, così seguono la palette. */
function cr_stile_ombra($livello) {
    $c = 'var(--cr-colore-principale)';
    $ombre = [
        'nessuna' => 'none',
        'leggera' => "0 10px 40px color-mix(in srgb, $c 8%, transparent)",
        'media'   => "0 14px 48px color-mix(in srgb, $c 14%, transparent)",
        'forte'   => "0 20px 60px color-mix(in srgb, $c 24%, transparent)",
    ];
    return $ombre[$livello] ?? $ombre['leggera'];
}

/**
 * CSS delle impostazioni: variabili su #cr-app e #cr-viewer, con i valori per
 * tablet e mobile in media query (vuoto = come il dispositivo più grande).
 */
function cr_stile_css($stile = null) {
    $stile  = $stile ?? cr_stile();
    $schema = cr_stile_schema();
    $base = $tablet = $mobile = [];

    foreach ($schema as $k => $s) {
        if (in_array($s['tipo'], ['testo', 'si_no'], true)) continue;
        if ($k === 'stile_titoli') continue;
        $var = cr_stile_var($k);
        $v   = $k === 'riq_ombra' ? cr_stile_ombra($stile[$k]) : cr_stile_valore_css($s, $stile[$k]);
        $base[] = "$var: $v;";
        if (!empty($s['r'])) {
            if (($stile[$k . '_t'] ?? '') !== '') $tablet[] = $var . ': ' . cr_stile_valore_css($s, $stile[$k . '_t']) . ';';
            if (($stile[$k . '_m'] ?? '') !== '') $mobile[] = $var . ': ' . cr_stile_valore_css($s, $stile[$k . '_m']) . ';';
        }
    }

    $sel = '#cr-app, #cr-viewer';
    $css  = "/* Cataloghi Ricerca — stile personalizzato */\n$sel {\n    " . implode("\n    ", $base) . "\n}\n";
    if ($tablet) $css .= "@media (max-width: " . CR_STILE_DISPOSITIVI['t'] . "px) {\n    $sel { " . implode(' ', $tablet) . " }\n}\n";
    if ($mobile) $css .= "@media (max-width: " . CR_STILE_DISPOSITIVI['m'] . "px) {\n    $sel { " . implode(' ', $mobile) . " }\n}\n";
    // Comportamento originale: sui telefoni molto stretti i cataloghi vanno su una colonna
    // (solo se il valore mobile delle colonne è rimasto quello predefinito)
    if ((string) ($stile['fascia_colonne_m'] ?? '') === '2') $css .= "@media (max-width: 420px) {\n    #cr-app { --cr-fascia-colonne: 1; }\n}\n";
    return $css;
}

/** Classi del contenitore per le opzioni che non sono semplici valori. */
function cr_stile_classi() {
    $s = cr_stile();
    $c = [];
    if ($s['stile_titoli'] === 'sito') $c[] = 'cr-titoli-sito';
    if (empty($s['car_dissolvenza']))  $c[] = 'cr-carosello-netto';
    return implode(' ', $c);
}

// -----------------------------------------------------------------------------
// Stili globali del sito (Elementor e tema), per i menu del pannello
// -----------------------------------------------------------------------------

/**
 * Colori e caratteri globali disponibili.
 * Ritorna ['colori' => [ [gruppo, nome, var, valore], ... ], 'font' => [ [gruppo, nome, var, valore], ... ]]
 */
function cr_stili_globali() {
    $colori = [];
    $font   = [];

    // Elementor: colori e tipografie globali del kit attivo
    try {
        if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
            $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
            if ($kit) {
                foreach (['system_colors', 'custom_colors'] as $chiave) {
                    foreach ((array) $kit->get_settings_for_display($chiave) as $c) {
                        if (empty($c['_id'])) continue;
                        $colori[] = ['Elementor', $c['title'] ?? $c['_id'], '--e-global-color-' . $c['_id'], $c['color'] ?? ''];
                    }
                }
                foreach (['system_typography', 'custom_typography'] as $chiave) {
                    foreach ((array) $kit->get_settings_for_display($chiave) as $t) {
                        if (empty($t['_id'])) continue;
                        $font[] = ['Elementor', $t['title'] ?? $t['_id'], '--e-global-typography-' . $t['_id'] . '-font-family', $t['typography_font_family'] ?? ''];
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Elementor assente o API cambiata: si usano solo gli altri globali
    }

    // Tema (theme.json): palette e famiglie di caratteri
    if (function_exists('wp_get_global_settings')) {
        $palette = wp_get_global_settings(['color', 'palette']);
        foreach (['theme', 'custom'] as $origine) {
            foreach ((array) ($palette[$origine] ?? []) as $c) {
                if (empty($c['slug'])) continue;
                $colori[] = ['Tema', $c['name'] ?? $c['slug'], '--wp--preset--color--' . _wp_to_kebab_case($c['slug']), $c['color'] ?? ''];
            }
        }
        $famiglie = wp_get_global_settings(['typography', 'fontFamilies']);
        foreach (['theme', 'custom'] as $origine) {
            foreach ((array) ($famiglie[$origine] ?? []) as $f) {
                if (empty($f['slug'])) continue;
                $font[] = ['Tema', $f['name'] ?? $f['slug'], '--wp--preset--font-family--' . _wp_to_kebab_case($f['slug']), $f['fontFamily'] ?? ''];
            }
        }
    }

    return ['colori' => $colori, 'font' => $font];
}

/**
 * CSS che definisce le variabili globali con i loro valori: usato nella pagina
 * di anteprima isolata (senza tema), così i colori/font globali si vedono uguali.
 */
function cr_css_globali() {
    $g = cr_stili_globali();
    $righe = [];
    foreach (array_merge($g['colori'], $g['font']) as [$gruppo, $nome, $var, $valore]) {
        if ($valore === '' || $gruppo !== 'Elementor') continue; // quelle del tema arrivano già dal foglio globale di WordPress
        $righe[] = $var . ': ' . (strpos($var, 'font-family') ? '"' . str_replace('"', '', $valore) . '"' : $valore) . ';';
    }
    return $righe ? ':root { ' . implode(' ', $righe) . " }\n" : '';
}

// -----------------------------------------------------------------------------
// Anteprima: la pagina viene aperta nel pannello con ?cr_anteprima_stile=1
// -----------------------------------------------------------------------------

function cr_in_anteprima_stile() {
    return isset($_GET['cr_anteprima_stile']) && is_user_logged_in() && current_user_can('manage_options');
}

add_action('init', function () {
    if (!cr_in_anteprima_stile()) return;
    show_admin_bar(false);
    // la pagina di anteprima non deve finire in cache
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
});

/**
 * Esempi per "Mostra risultati di esempio": un codice e un motore presenti negli indici.
 */
function cr_esempi_anteprima() {
    global $wpdb;
    $codice = $wpdb->get_var($wpdb->prepare(
        "SELECT fin_code FROM " . cr_tabella('componenti') . " WHERE lotto = %d AND fin_code <> '' ORDER BY id LIMIT 1", cr_lotto_attivo('componenti')));
    $motore = $wpdb->get_var($wpdb->prepare(
        "SELECT motore FROM " . cr_tabella('motori') . " WHERE lotto = %d AND CHAR_LENGTH(motore) >= 3 ORDER BY id LIMIT 1", cr_lotto_attivo('motori')));
    return ['codice' => (string) $codice, 'motore' => (string) $motore];
}

/** Script dell'anteprima: riceve dal pannello il nuovo CSS e i comandi (risultati di esempio). */
function cr_script_anteprima() {
    $esempi = wp_json_encode(cr_esempi_anteprima());
    // il pannello può avere un indirizzo diverso dal sito (es. www / senza www)
    $admin  = wp_json_encode(untrailingslashit(preg_replace('#^(https?://[^/]+).*#', '$1', admin_url())));
    return <<<JS
(function () {
    var esempi = $esempi;
    var origini = [location.origin, $admin];
    function stile() {
        var s = document.getElementById('cr-style-inline-css');
        if (!s) { s = document.createElement('style'); s.id = 'cr-style-inline-css'; document.head.appendChild(s); }
        return s;
    }
    function scrivi(id, valore) {
        var el = document.getElementById(id);
        if (!el || !valore) return;
        el.value = valore;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }
    window.addEventListener('message', function (e) {
        if (origini.indexOf(e.origin) === -1 || !e.data || !e.data.cr) return;
        if (e.data.cr === 'css') stile().textContent = e.data.css;
        if (e.data.cr === 'esempi') {
            scrivi('cr-input-codice', esempi.codice);
            scrivi('cr-input-motore', esempi.motore);
            var t = document.getElementById('cr-filtri-toggle');
            if (t && t.getAttribute('aria-expanded') !== 'true') t.click();
        }
        if (e.data.cr === 'vai' && e.data.id) {
            var el = document.getElementById(e.data.id);
            if (el) el.scrollIntoView({ block: 'start' });
        }
    });
    // il pannello sa quando l'anteprima è pronta (per ripetere i comandi dopo un ricaricamento)
    window.addEventListener('load', function () {
        if (window.parent !== window) origini.forEach(function (o) { try { window.parent.postMessage({ cr: 'pronta' }, o); } catch (err) {} });
    });
})();
JS;
}

/**
 * Anteprima isolata (nessuna pagina del sito contiene lo shortcode): una pagina
 * minima con il solo blocco dei cataloghi, i fogli globali di WordPress e le
 * variabili globali di Elementor.
 */
add_action('template_redirect', function () {
    if (!cr_in_anteprima_stile() || empty($_GET['cr_isolata'])) return;
    $html = cr_shortcode();
    $kit  = 0;
    try {
        if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
            $kit = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
        }
    } catch (\Throwable $e) { $kit = 0; }
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anteprima stile cataloghi</title>
    <?php if (function_exists('wp_get_global_stylesheet')): ?><style><?= wp_get_global_stylesheet() ?></style><?php endif; ?>
    <style><?= cr_css_globali() ?> body { margin: 0; font-family: var(--e-global-typography-text-font-family, system-ui, -apple-system, "Segoe UI", sans-serif); background: #fff; }</style>
    <?php wp_print_styles(['cr-style']); ?>
</head>
<body class="<?= $kit ? 'elementor-kit-' . $kit : '' ?>">
    <?= $html ?>
    <?php wp_print_scripts(['cr-script']); ?>
    <script><?= cr_script_anteprima() ?></script>
</body>
</html><?php
    exit;
}, 1);

// Anteprima su una pagina vera del sito: aggiunge solo lo script di comunicazione col pannello
add_action('wp_footer', function () {
    if (!cr_in_anteprima_stile() || !empty($_GET['cr_isolata'])) return;
    echo '<script>' . cr_script_anteprima() . '</script>';
}, 100);

// Bozza dell'anteprima: salva le impostazioni non ancora confermate e ritorna il CSS
add_action('wp_ajax_cr_stile_bozza', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('cr_stile', 'nonce', false)) wp_send_json_error('Accesso negato.', 403);
    $stile = cr_stile_sanifica(wp_unslash((array) ($_POST['cr_stile'] ?? [])));
    set_transient(CR_STILE_BOZZA . get_current_user_id(), $stile, HOUR_IN_SECONDS);
    wp_send_json_success(['css' => cr_stile_css($stile)]);
});
