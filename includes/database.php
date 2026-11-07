<?php
defined('ABSPATH') || exit;

// =============================================================================
// DATABASE — tabelle, aggiornamento schema, cartelle
//
// Ogni riga appartiene a un "lotto" (una versione dell'indice). Un import scrive
// un lotto nuovo e solo a fine import lo rende attivo: se si interrompe, i dati
// online restano quelli di prima. Il lotto precedente viene conservato per il
// ripristino, quelli più vecchi eliminati.
// =============================================================================

function cr_attiva() {
    cr_aggiorna_db();
    cr_crea_cartella_data();
    cr_crea_cartella_pdf();
}

// L'aggiornamento del plugin da zip non riesegue l'attivazione: lo schema
// viene aggiornato al primo caricamento con la nuova versione.
add_action('plugins_loaded', function () {
    if (get_option('cr_db_version') !== CR_DB_VERSION) cr_aggiorna_db();
});

function cr_tabella($tipo) {
    global $wpdb;
    return $wpdb->prefix . ($tipo === 'motori' ? CR_TABLE_MOTORI : CR_TABLE_COMPONENTI);
}

function cr_aggiorna_db() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $motori  = cr_tabella('motori');
    $comp    = cr_tabella('componenti');

    // Formato richiesto da dbDelta: un campo per riga, due spazi dopo PRIMARY KEY
    $sql_motori = "CREATE TABLE $motori (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lotto int(10) unsigned NOT NULL DEFAULT 0,
  costruttore varchar(100) NOT NULL DEFAULT '',
  motore varchar(150) NOT NULL DEFAULT '',
  motore_norm varchar(150) NOT NULL DEFAULT '',
  nr_cilindri varchar(20) NOT NULL DEFAULT '',
  alesaggio varchar(20) NOT NULL DEFAULT '',
  corsa varchar(20) NOT NULL DEFAULT '',
  versione varchar(100) NOT NULL DEFAULT '',
  pagina_pdf int(10) unsigned NOT NULL DEFAULT 0,
  file_origine varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY idx_lotto_costruttore (lotto,costruttore),
  KEY idx_costruttore (costruttore),
  KEY idx_motore (motore)
) $charset;";

    $sql_componenti = "CREATE TABLE $comp (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lotto int(10) unsigned NOT NULL DEFAULT 0,
  costruttore varchar(100) NOT NULL DEFAULT '',
  motore varchar(150) NOT NULL DEFAULT '',
  nr_cilindri varchar(20) NOT NULL DEFAULT '',
  alesaggio varchar(20) NOT NULL DEFAULT '',
  corsa varchar(20) NOT NULL DEFAULT '',
  versione varchar(100) NOT NULL DEFAULT '',
  tipo_componente varchar(100) NOT NULL DEFAULT '',
  fin_code varchar(100) NOT NULL DEFAULT '',
  fin_norm varchar(100) NOT NULL DEFAULT '',
  oem_code varchar(100) NOT NULL DEFAULT '',
  oem_norm varchar(100) NOT NULL DEFAULT '',
  pagina_pdf int(10) unsigned NOT NULL DEFAULT 0,
  file_origine varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY idx_lotto (lotto),
  KEY idx_fin_code (fin_code),
  KEY idx_oem_code (oem_code),
  KEY idx_motore (motore)
) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_motori);
    dbDelta($sql_componenti);

    update_option('cr_db_version', CR_DB_VERSION);
}

/**
 * Lunghezza massima delle colonne testuali, per troncare i valori del CSV
 * prima dell'insert (in modalità SQL strict un valore troppo lungo fa fallire
 * l'intero blocco).
 */
function cr_lunghezze_colonne() {
    return [
        'costruttore' => 100, 'motore' => 150, 'motore_norm' => 150,
        'nr_cilindri' => 20, 'alesaggio' => 20, 'corsa' => 20, 'versione' => 100,
        'tipo_componente' => 100, 'fin_code' => 100, 'fin_norm' => 100,
        'oem_code' => 100, 'oem_norm' => 100, 'file_origine' => 255,
    ];
}

function cr_crea_cartella_data() {
    if (!file_exists(CR_DATA_DIR)) wp_mkdir_p(CR_DATA_DIR);
    $htaccess = CR_DATA_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
    }
}

function cr_crea_cartella_pdf() {
    if (!file_exists(CR_PDF_DIR)) wp_mkdir_p(CR_PDF_DIR);
    // Directory listing disabilitato, ma i PDF devono essere accessibili
    $htaccess = CR_PDF_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n");
    }
}
