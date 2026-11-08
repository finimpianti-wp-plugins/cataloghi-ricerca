<?php
/**
 * Plugin Name: Cataloghi Ricerca
 * Description: Ricerca motori e componenti nei cataloghi PDF
 * Version: 3.4
 * Author: Finimpianti
 * Text Domain: cataloghi-ricerca
 * Domain Path: /languages
 * Update URI: https://github.com/apgr01/cataloghi-ricerca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('CR_VERSION',          '3.4');
define('CR_DB_VERSION',       '2');
define('CR_FILE',             __FILE__);
define('CR_TABLE_MOTORI',     'cataloghi_motori');
define('CR_TABLE_COMPONENTI', 'cataloghi_componenti');
define('CR_QUERY_TIMEOUT',    5);
define('CR_DATA_DIR',         WP_CONTENT_DIR . '/uploads/cataloghi-data/');
define('CR_PDF_DIR',          WP_CONTENT_DIR . '/uploads/cataloghi-pdf/');
define('CR_PDF_URL',          WP_CONTENT_URL  . '/uploads/cataloghi-pdf/');
define('CR_CSV_MOTORI',       CR_DATA_DIR . 'indice_motori.csv');
define('CR_CSV_COMPONENTI',   CR_DATA_DIR . 'indice_componenti_all.csv');
define('CR_OPTION_LABELS',    'cr_cataloghi_labels');   // [ 'filename.pdf' => 'Label' ]
define('CR_OPTION_CAROSELLO', 'cr_carosello');          // [ 'ids'=>[..], 'direzione'=>'dx|sx', 'velocita'=>'lenta|media|veloce' ]
define('CR_OPTION_IMPORT',    'cr_import_');            // + tipo: versioni dell'indice e stato dell'ultimo import
define('CR_IMPORT_BLOCCO',    500);                     // righe per singola INSERT

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/pdf.php';
require_once __DIR__ . '/includes/lingue.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/diagnostica.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/stile.php';
require_once __DIR__ . '/includes/stile-admin.php';

register_activation_hook(__FILE__, 'cr_attiva');

// Aggiornamenti da GitHub: ogni release del repository (con lo zip del plugin) compare in
// Bacheca -> Aggiornamenti e si installa con un clic. Il repository si può cambiare definendo
// CR_UPDATE_REPO in wp-config.php; APGR_GITHUB_TOKEN (facoltativo) serve solo per repository
// privati o per superare i limiti di richieste di GitHub.
if ( ! defined( 'CR_UPDATE_REPO' ) ) {
	define( 'CR_UPDATE_REPO', 'https://github.com/apgr01/cataloghi-ricerca/' );
}
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
$cr_aggiornamenti = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( CR_UPDATE_REPO, __FILE__, 'cataloghi-ricerca' );
$cr_aggiornamenti->getVcsApi()->enableReleaseAssets( '/^cataloghi\-ricerca-[0-9][0-9.]*\.zip$/' );
if ( defined( 'APGR_GITHUB_TOKEN' ) && APGR_GITHUB_TOKEN ) {
	$cr_aggiornamenti->setAuthentication( APGR_GITHUB_TOKEN );
}
