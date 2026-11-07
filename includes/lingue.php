<?php
defined('ABSPATH') || exit;

// =============================================================================
// LINGUE — integrazione facoltativa con "Elementor Page Translator"
//
// Senza il traduttore il plugin funziona esattamente come prima: tutte le
// funzioni qui sotto ricadono sull'italiano e non filtrano nulla.
//
// - Ogni PDF può avere una lingua (pannello di controllo). Due PDF con la stessa
//   etichetta sono lo stesso catalogo in lingue diverse: sulla pagina di una
//   lingua si mostra la versione in quella lingua, altrimenti l'inglese,
//   altrimenti l'italiano (fascia cataloghi e risultati della ricerca).
// - Le richieste REST della ricerca portano la lingua della pagina (parametro
//   "lang"): messaggi e risultati seguono quella lingua.
// =============================================================================

define('CR_OPTION_LINGUE', 'cr_cataloghi_lingue'); // [ 'filename.pdf' => 'en' ]

add_action('init', function () {
    load_plugin_textdomain('cataloghi-ricerca', false, dirname(plugin_basename(CR_FILE)) . '/languages');
});

/** Il traduttore è attivo? */
function cr_multilingua() {
    return function_exists('ept_current_language') && function_exists('ept_languages');
}

/** Codice della lingua predefinita del sito ("it"). */
function cr_lingua_predefinita() {
    return cr_multilingua() ? (string) ept_default_language() : 'it';
}

/** Lingue attive: [ codice => nome ], la predefinita per prima. */
function cr_lingue() {
    if (!cr_multilingua()) return [];
    $out = [];
    foreach (ept_languages() as $codice => $l) $out[(string) $codice] = (string) $l['name'];
    return $out;
}

/** Locale WordPress di una lingua ('' se sconosciuta). */
function cr_locale_lingua($codice) {
    if (!cr_multilingua()) return '';
    $lingue = ept_languages();
    return isset($lingue[$codice]) ? (string) $lingue[$codice]['locale'] : '';
}

/**
 * Lingua corrente: quella della pagina, oppure quella indicata dalla richiesta
 * REST della ricerca (parametro "lang", solo se è una lingua attiva).
 */
function cr_lingua_corrente() {
    static $rest = null;
    if (!cr_multilingua()) return 'it';
    if (defined('REST_REQUEST') && REST_REQUEST) {
        if ($rest === null) {
            $lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parametro pubblico di sola lettura
            $rest = isset(ept_languages()[$lang]) ? $lang : cr_lingua_predefinita();
        }
        return $rest;
    }
    return (string) ept_current_language();
}

/**
 * Nelle richieste REST della ricerca: messaggi nella lingua della pagina.
 */
function cr_rest_lingua() {
    if (!cr_multilingua() || cr_lingua_corrente() === cr_lingua_predefinita()) return;
    $locale = cr_locale_lingua(cr_lingua_corrente());
    if ($locale === '' || $locale === determine_locale()) return;
    if (switch_to_locale($locale)) return; // pacchetto lingua installato: WordPress ricarica i testi

    // Senza pacchetto lingua di WordPress: carica comunque i testi del plugin nella lingua
    // richiesta (cartelle di Loco Translate, di WordPress e del plugin, in quest'ordine).
    $file = 'cataloghi-ricerca-' . $locale . '.mo';
    foreach ([WP_LANG_DIR . '/loco/plugins/', WP_LANG_DIR . '/plugins/', plugin_dir_path(CR_FILE) . 'languages/'] as $cartella) {
        if (is_readable($cartella . $file)) {
            unload_textdomain('cataloghi-ricerca');
            load_textdomain('cataloghi-ricerca', $cartella . $file, determine_locale());
            return;
        }
    }
}

// -----------------------------------------------------------------------------
// Lingua dei PDF
// -----------------------------------------------------------------------------

function cr_lingua_pdf($nome) {
    $l = get_option(CR_OPTION_LINGUE, []);
    return is_array($l) && isset($l[$nome]) ? (string) $l[$nome] : '';
}

function cr_salva_lingua_pdf($nome, $lingua) {
    $l = get_option(CR_OPTION_LINGUE, []);
    $l = is_array($l) ? $l : [];
    $lingua = sanitize_key((string) $lingua);
    if ($lingua === '') unset($l[$nome]);
    else $l[$nome] = $lingua;
    update_option(CR_OPTION_LINGUE, $l);
}

/** Codice di una lingua attiva ricevuto da un form ('' = non indicata). */
function cr_lingua_valida($valore) {
    $valore = sanitize_key(wp_unslash((string) $valore));
    return isset(cr_lingue()[$valore]) ? $valore : '';
}

function cr_rimuovi_lingua_pdf($nome) {
    cr_salva_lingua_pdf($nome, '');
}

/**
 * Priorità di una lingua per la lingua corrente (più bassa = migliore):
 * lingua della pagina, inglese, lingua predefinita, non indicata, altre.
 */
function cr_priorita_lingua($lingua, $corrente) {
    $ordine = array_values(array_unique([$corrente, 'en', cr_lingua_predefinita()]));
    $pos    = array_search($lingua, $ordine, true);
    if ($pos !== false) return $pos;
    return $lingua === '' ? count($ordine) : count($ordine) + 1;
}

/**
 * PDF su disco da NON mostrare nella lingua corrente: in ogni gruppo di PDF con la
 * stessa etichetta resta solo la versione nella lingua migliore.
 *
 * @return string[] nomi dei file
 */
function cr_pdf_esclusi() {
    static $cache = [];
    if (!cr_multilingua()) return [];
    $corrente = cr_lingua_corrente();
    if (isset($cache[$corrente])) return $cache[$corrente];

    $labels = get_option(CR_OPTION_LABELS, []);
    $gruppi = [];
    foreach (cr_elenco_pdf() as $nome) {
        $chiave = mb_strtolower(trim(cr_label_pdf($nome, $labels)));
        $gruppi[$chiave][] = $nome;
    }
    $esclusi = [];
    foreach ($gruppi as $files) {
        if (count($files) < 2) continue;
        usort($files, function ($a, $b) use ($corrente) {
            return cr_priorita_lingua(cr_lingua_pdf($a), $corrente) <=> cr_priorita_lingua(cr_lingua_pdf($b), $corrente)
                ?: strnatcasecmp($a, $b);
        });
        $esclusi = array_merge($esclusi, array_slice($files, 1));
    }
    return $cache[$corrente] = $esclusi;
}

/**
 * Valori di File_origine dell'indice che puntano a PDF esclusi nella lingua corrente
 * (il nome nell'indice può differire da quello su disco per maiuscole o spazi).
 *
 * @return string[]
 */
function cr_file_origine_esclusi($tipo) {
    static $cache = [];
    $esclusi = cr_pdf_esclusi();
    if (!$esclusi) return [];
    $chiave = $tipo . '|' . cr_lingua_corrente();
    if (isset($cache[$chiave])) return $cache[$chiave];

    global $wpdb;
    $valori = $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT file_origine FROM ' . cr_tabella($tipo) . ' WHERE lotto = %d', cr_lotto_attivo($tipo)
    ));
    $out = [];
    foreach ((array) $valori as $v) {
        $pdf = cr_risolvi_pdf($v);
        if ($pdf['file'] !== null && in_array($pdf['file'], $esclusi, true)) $out[] = $v;
    }
    return $cache[$chiave] = $out;
}

/**
 * Condizione SQL (con argomenti) che esclude le righe dei PDF esclusi.
 *
 * @return array{0:string,1:array}
 */
function cr_condizione_lingua($tipo) {
    $esclusi = cr_file_origine_esclusi($tipo);
    if (!$esclusi) return ['', []];
    return ['file_origine NOT IN (' . implode(',', array_fill(0, count($esclusi), '%s')) . ')', $esclusi];
}

/**
 * Testi dell'interfaccia usati dagli script (ricerca e visualizzatore).
 */
function cr_testi_js() {
    return [
        'timeout'          => __('Ricerca interrotta: il server ha impiegato troppo tempo. Riprova con un termine più specifico.', 'cataloghi-ricerca'),
        'errore'           => __('Si è verificato un errore. Riprova.', 'cataloghi-ricerca'),
        'risposta'         => __('Risposta del server non valida.', 'cataloghi-ricerca'),
        'caricamento'      => __('Caricamento', 'cataloghi-ricerca'),
        'cilindri'         => __('%s cil.', 'cataloghi-ricerca'),
        'corsa'            => __('Corsa %s mm', 'cataloghi-ricerca'),
        'non_disponibile'  => __('Catalogo non disponibile', 'cataloghi-ricerca'),
        'pagina_n'         => __('pag. %s', 'cataloghi-ricerca'),
        'pagina_assente'   => __('pagina non indicata', 'cataloghi-ricerca'),
        'carica_altri'     => __('Carica altri (%s rimanenti)', 'cataloghi-ricerca'),
        'caricamento_puntini' => __('Caricamento...', 'cataloghi-ricerca'),
        'rimuovi_filtro'   => __('Rimuovi filtro %s', 'cataloghi-ricerca'),
        'nessun_costruttore' => __('Nessun costruttore trovato.', 'cataloghi-ricerca'),
        'errore_costruttori' => __('Impossibile caricare i costruttori.', 'cataloghi-ricerca'),
        'min_3'            => __('Inserisci almeno 3 caratteri', 'cataloghi-ricerca'),
        'min_4'            => __('Inserisci almeno 4 lettere o cifre', 'cataloghi-ricerca'),
        'fin_incompleto'   => __('Codice FIN: continua a digitare (FIN + almeno 3 cifre, es. FIN147PC00)', 'cataloghi-ricerca'),
        'fin_ok'           => __('Codice FIN riconosciuto', 'cataloghi-ricerca'),
        'oem_fin'          => __('Ricerca tra i codici OEM e FIN (punti, spazi e trattini sono ignorati)', 'cataloghi-ricerca'),
        'nessun_motore'    => __('Nessun motore trovato.', 'cataloghi-ricerca'),
        'nessun_motore_q'  => __('Nessun motore trovato per %s.', 'cataloghi-ricerca'),
        'nessun_motore_c'  => __('Nessun motore trovato tra i costruttori selezionati.', 'cataloghi-ricerca'),
        'nessun_motore_qc' => __('Nessun motore trovato per %s tra i costruttori selezionati.', 'cataloghi-ricerca'),
        'motori_1'         => __('%s motore', 'cataloghi-ricerca'),
        'motori_n'         => __('%s motori', 'cataloghi-ricerca'),
        'costruttori_1'    => __('%s costruttore', 'cataloghi-ricerca'),
        'costruttori_n'    => __('%s costruttori', 'cataloghi-ricerca'),
        'risultati_1'      => __('%s risultato', 'cataloghi-ricerca'),
        'risultati_n'      => __('%s risultati', 'cataloghi-ricerca'),
        'per_q'            => __('per “%s”', 'cataloghi-ricerca'),
        'nessun_componente' => __('Nessun componente trovato per %s.', 'cataloghi-ricerca'),
        // Visualizzatore
        'v_prec'           => __('Pagina precedente', 'cataloghi-ricerca'),
        'v_succ'           => __('Pagina successiva', 'cataloghi-ricerca'),
        'v_numero'         => __('Numero di pagina', 'cataloghi-ricerca'),
        'v_riduci'         => __('Riduci', 'cataloghi-ricerca'),
        'v_adatta'         => __('Adatta alla larghezza', 'cataloghi-ricerca'),
        'v_adattato'       => __('Adattato alla larghezza', 'cataloghi-ricerca'),
        'v_ingrandisci'    => __('Ingrandisci', 'cataloghi-ricerca'),
        'v_esterno'        => __('Apri il PDF in una nuova scheda', 'cataloghi-ricerca'),
        'v_esterno_title'  => __('Apri in una nuova scheda', 'cataloghi-ricerca'),
        'v_chiudi'         => __('Chiudi', 'cataloghi-ricerca'),
        'v_apertura'       => __('Apertura del catalogo…', 'cataloghi-ricerca'),
        'v_caricamento'    => __('Caricamento del catalogo… %s%%', 'cataloghi-ricerca'),
        'v_impossibile'    => __('Impossibile mostrare il catalogo qui.', 'cataloghi-ricerca'),
        'v_apri_diretto'   => __('Puoi aprirlo direttamente: si aprirà alla pagina %s nei browser che lo supportano.', 'cataloghi-ricerca'),
        'v_apri_pdf'       => __('Apri il PDF', 'cataloghi-ricerca'),
        'v_dettaglio'      => __('Dettaglio tecnico', 'cataloghi-ricerca'),
        'v_pagina'         => __('Pagina %s', 'cataloghi-ricerca'),
    ];
}
