<?php
defined('ABSPATH') || exit;

// =============================================================================
// HELPERS GENERICI
// =============================================================================

/**
 * Versione "pulita" di un codice o nome motore, usata per la ricerca tollerante:
 * maiuscolo, senza accenti e senza separatori (spazi, punti, trattini, barre...).
 * Es. "6500.095" → "6500095", "LDA 80" → "LDA80", "FIN001MB STD" → "FIN001MBSTD".
 * Lo stesso algoritmo è replicato in app.js (crNormalizza).
 */
function cr_normalizza($s) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(remove_accents((string) $s)));
}

function cr_format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 0)    . ' KB';
    return $bytes . ' B';
}

function cr_format_data($ts) {
    return $ts ? wp_date('d/m/Y H:i', (int) $ts) : '—';
}

/**
 * Messaggio leggibile per i codici di errore di upload PHP.
 */
function cr_errore_upload($codice) {
    $limite = size_format(wp_max_upload_size());
    switch ((int) $codice) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "Il file supera il limite di upload del server ($limite). Per i PDF usa l'upload FTP + \"Scansiona cartella\".";
        case UPLOAD_ERR_PARTIAL:
            return 'Il file è stato caricato solo in parte (connessione interrotta). Riprova.';
        case UPLOAD_ERR_NO_FILE:
            return 'Nessun file selezionato.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'Il server non riesce a salvare il file temporaneo (cartella temporanea mancante o disco pieno). Contatta l\'hosting.';
        default:
            return 'Errore di upload (codice ' . (int) $codice . ').';
    }
}

/**
 * Impostazioni del carosello immagini, con valori di default.
 * direzione: 'dx' = le immagini scorrono da sinistra verso destra, 'sx' il contrario.
 */
function cr_get_carosello() {
    $opt = get_option(CR_OPTION_CAROSELLO, []);
    $opt = is_array($opt) ? $opt : [];
    return [
        'ids'       => array_values(array_filter(array_map('absint', (array) ($opt['ids'] ?? [])))),
        'direzione' => in_array($opt['direzione'] ?? '', ['dx', 'sx'], true) ? $opt['direzione'] : 'dx',
        'velocita'  => in_array($opt['velocita'] ?? '', ['lenta', 'media', 'veloce'], true) ? $opt['velocita'] : 'lenta',
    ];
}

/**
 * Imposta il timeout delle query per la sessione corrente.
 * MySQL (>= 5.7.8) usa MAX_EXECUTION_TIME in ms, MariaDB max_statement_time in secondi.
 */
function cr_imposta_timeout_query() {
    global $wpdb;
    $info = method_exists($wpdb, 'db_server_info')
        ? (string) $wpdb->db_server_info()
        : (string) $wpdb->get_var('SELECT VERSION()');
    $sql = (stripos($info, 'mariadb') !== false)
        ? 'SET SESSION max_statement_time=' . CR_QUERY_TIMEOUT
        : 'SET SESSION MAX_EXECUTION_TIME=' . (CR_QUERY_TIMEOUT * 1000);
    $prev = $wpdb->suppress_errors(true);
    $wpdb->query($sql);
    $wpdb->suppress_errors($prev);
}

/**
 * Prefisso dei codici interni dell'azienda (es. "FIN"): i codici che iniziano così sono
 * riconosciuti come codici interni (almeno 3 cifre dopo il prefisso), gli altri sono
 * cercati tra tutti i codici. Vuoto = nessun prefisso. Si imposta nel pannello di controllo.
 */
define('CR_OPTION_PREFISSO', 'cr_prefisso_codici');

function cr_prefisso() {
    $p = get_option(CR_OPTION_PREFISSO, 'FIN');
    return cr_normalizza_prefisso(is_string($p) ? $p : 'FIN');
}

function cr_normalizza_prefisso($p) {
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(remove_accents((string) $p))), 0, 10);
}
