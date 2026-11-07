<?php
defined('ABSPATH') || exit;

// =============================================================================
// ENDPOINT REST (cataloghi/v1)
// =============================================================================

add_action('rest_api_init', 'cr_registra_endpoint');

function cr_registra_endpoint() {

    register_rest_route('cataloghi/v1', '/costruttori', [
        'methods'             => 'GET',
        'callback'            => 'cr_get_costruttori',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('cataloghi/v1', '/motori', [
        'methods'             => 'GET',
        'callback'            => 'cr_get_motori',
        'permission_callback' => '__return_true',
        'args' => ['costruttore' => ['sanitize_callback' => 'sanitize_text_field']],
    ]);

    register_rest_route('cataloghi/v1', '/motori/cerca', [
        'methods'             => 'GET',
        'callback'            => 'cr_cerca_motori',
        'permission_callback' => '__return_true',
        // costruttori: array (costruttori[]=A&costruttori[]=B) o stringa separata da virgole
        'args' => ['q' => ['sanitize_callback' => 'sanitize_text_field']],
    ]);

    register_rest_route('cataloghi/v1', '/componenti', [
        'methods'             => 'GET',
        'callback'            => 'cr_get_componenti',
        'permission_callback' => '__return_true',
        'args' => [
            'q'    => ['sanitize_callback' => 'sanitize_text_field'],
            'tipo' => ['sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    register_rest_route('cataloghi/v1', '/cataloghi', [
        'methods'             => 'GET',
        'callback'            => 'cr_rest_get_cataloghi',
        'permission_callback' => '__return_true',
    ]);
}

function cr_rest_get_cataloghi() {
    $out = [];
    foreach (cr_get_cataloghi_da_disco() as $c) {
        $out[] = ['file' => $c['file'], 'label' => $c['label'], 'url' => $c['url']];
    }
    return rest_ensure_response($out);
}

/**
 * Aggiunge a ogni risultato i dati per aprire il catalogo alla pagina giusta:
 * - pdf_url / url_pdf: file reale abbinato a File_origine (url_pdf con #page=N, per compatibilità)
 * - pdf_disponibile: false se il PDF citato non è nella cartella
 * - catalogo_label: etichetta impostata dall'admin
 */
function cr_arricchisci_risultati($rows) {
    $labels = get_option(CR_OPTION_LABELS, []);
    $rows   = $rows ?: [];
    foreach ($rows as &$r) {
        $pdf  = cr_risolvi_pdf($r['file_origine']);
        $file = $pdf['file'];
        $r['pagina_pdf']      = (int) $r['pagina_pdf'];
        $r['pdf_disponibile'] = $file !== null;
        $r['pdf_url']         = $file ? cr_url_pdf($file) : '';
        $r['url_pdf']         = $file ? cr_url_pdf($file) . '#page=' . max(1, $r['pagina_pdf']) : '';
        $r['catalogo_label']  = $file ? cr_label_pdf($file, $labels) : '';
    }
    unset($r);
    return $rows;
}

function cr_risposta_errore_query() {
    return new WP_Error('query_timeout', 'Ricerca interrotta: il server ha impiegato troppo tempo. Riprova.', ['status' => 504]);
}

function cr_get_costruttori() {
    global $wpdb;
    $table = cr_tabella('motori');
    $rows  = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT costruttore FROM $table WHERE lotto = %d ORDER BY costruttore ASC", cr_lotto_attivo('motori')
    ));
    return rest_ensure_response($rows);
}

/** Motori di un costruttore (endpoint della versione precedente, mantenuto per compatibilità) */
function cr_get_motori(WP_REST_Request $request) {
    $costruttore = $request->get_param('costruttore');
    if (empty($costruttore)) {
        return new WP_Error('param_missing', 'Parametro costruttore mancante.', ['status' => 400]);
    }
    $request->set_param('costruttori', [$costruttore]);
    $request->set_param('q', '');
    return cr_cerca_motori($request);
}

/**
 * Normalizza un parametro lista (array oppure stringa separata da virgole).
 */
function cr_param_lista($val) {
    if (is_string($val)) $val = explode(',', $val);
    if (!is_array($val)) return [];
    $val = array_filter(array_map(fn($v) => sanitize_text_field((string) $v), $val), 'strlen');
    return array_slice(array_values(array_unique($val)), 0, 50);
}

/**
 * Ricerca motori: testo libero (motore o costruttore) e/o filtro su uno o più costruttori.
 * Con entrambi attivi il motore deve corrispondere al testo E appartenere ad almeno
 * uno dei costruttori selezionati. Con i soli costruttori restituisce tutti i loro motori.
 * Il testo viene confrontato anche in forma normalizzata: "LDA80" trova "LDA 80".
 */
function cr_cerca_motori(WP_REST_Request $request) {
    global $wpdb;
    $table       = cr_tabella('motori');
    $q           = trim((string) $request->get_param('q'));
    $costruttori = cr_param_lista($request->get_param('costruttori'));
    $offset      = max(0, intval($request->get_param('offset') ?? 0));
    $limit       = 10;

    if ($q === '' && !$costruttori) {
        return new WP_Error('param_missing', 'Inserisci il nome di un motore o seleziona almeno un costruttore.', ['status' => 400]);
    }
    if ($q !== '' && mb_strlen($q) < 3) {
        return new WP_Error('param_too_short', 'Inserisci almeno 3 caratteri.', ['status' => 400]);
    }

    $where = ['lotto = %d'];
    $args  = [cr_lotto_attivo('motori')];
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $qn   = cr_normalizza($q);
        if ($qn !== '') {
            $where[] = '(motore_norm LIKE %s OR motore LIKE %s OR costruttore LIKE %s)';
            array_push($args, '%' . $wpdb->esc_like($qn) . '%', $like, $like);
        } else {
            $where[] = '(motore LIKE %s OR costruttore LIKE %s)';
            array_push($args, $like, $like);
        }
    }
    if ($costruttori) {
        $where[] = 'costruttore IN (' . implode(',', array_fill(0, count($costruttori), '%s')) . ')';
        $args    = array_merge($args, $costruttori);
    }
    $where_sql = implode(' AND ', $where);

    cr_imposta_timeout_query();

    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where_sql", $args));
    if ($wpdb->last_error) return cr_risposta_errore_query();

    // id come ultimo criterio: ordine stabile tra una pagina e l'altra di "Carica altri"
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT costruttore, motore, nr_cilindri, alesaggio, corsa, versione, pagina_pdf, file_origine
         FROM $table WHERE $where_sql
         ORDER BY costruttore ASC, motore ASC, id ASC LIMIT %d OFFSET %d",
        array_merge($args, [$limit, $offset])
    ), ARRAY_A);
    if ($wpdb->last_error) return cr_risposta_errore_query();

    return rest_ensure_response([
        'total'       => intval($total),
        'offset'      => $offset,
        'limit'       => $limit,
        'costruttori' => $costruttori,
        'results'     => cr_arricchisci_risultati($rows),
    ]);
}

/**
 * Ricerca componenti per codice.
 * tipo = auto (default): se il codice inizia con FIN è un codice FIN, altrimenti
 *        viene cercato sia tra gli OEM sia tra i FIN (es. FIN digitato senza prefisso).
 * tipo = fin | oem: ricerca su una sola colonna (compatibilità con la versione precedente).
 * Il confronto avviene sui codici normalizzati: "6500095" trova "6500.095".
 * I codici identici a quanto cercato compaiono per primi.
 */
function cr_get_componenti(WP_REST_Request $request) {
    global $wpdb;
    $table  = cr_tabella('componenti');
    $q      = trim((string) $request->get_param('q'));
    $qn     = cr_normalizza($q);
    $tipo   = $request->get_param('tipo') ?: 'auto';
    $offset = max(0, intval($request->get_param('offset') ?? 0));
    $limit  = 10;

    if ($q === '') {
        return new WP_Error('param_missing', 'Parametro q mancante.', ['status' => 400]);
    }

    if (!in_array($tipo, ['fin', 'oem'], true)) {
        $tipo = preg_match('/^FIN/', $qn) ? 'fin' : 'codice';
    }

    if ($tipo === 'fin') {
        if (!preg_match('/^FIN.*\d{3}/', $qn)) {
            return new WP_Error('invalid_code', 'Il codice FIN deve iniziare con FIN seguito da almeno 3 cifre.', ['status' => 400]);
        }
    } elseif (strlen($qn) < 4) {
        return new WP_Error('invalid_code', 'Il codice deve avere almeno 4 caratteri (lettere o cifre).', ['status' => 400]);
    }

    $like  = '%' . $wpdb->esc_like($q) . '%';
    $likeN = '%' . $wpdb->esc_like($qn) . '%';
    if ($tipo === 'fin') {
        $cond = '(fin_norm LIKE %s OR fin_code LIKE %s)';
        $args = [$likeN, $like];
    } elseif ($tipo === 'oem') {
        $cond = '(oem_norm LIKE %s OR oem_code LIKE %s)';
        $args = [$likeN, $like];
    } else {
        $cond = '(oem_norm LIKE %s OR fin_norm LIKE %s OR oem_code LIKE %s OR fin_code LIKE %s)';
        $args = [$likeN, $likeN, $like, $like];
    }
    array_unshift($args, cr_lotto_attivo('componenti'));
    $where_sql = "lotto = %d AND $cond";

    cr_imposta_timeout_query();

    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where_sql", $args));
    if ($wpdb->last_error) return cr_risposta_errore_query();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT costruttore, motore, nr_cilindri, alesaggio, corsa, versione,
                tipo_componente, fin_code, oem_code, pagina_pdf, file_origine
         FROM $table WHERE $where_sql
         ORDER BY CASE WHEN fin_norm = %s OR oem_norm = %s THEN 0 ELSE 1 END,
                  file_origine ASC, pagina_pdf ASC, id ASC
         LIMIT %d OFFSET %d",
        array_merge($args, [$qn, $qn, $limit, $offset])
    ), ARRAY_A);
    if ($wpdb->last_error) return cr_risposta_errore_query();

    return rest_ensure_response([
        'total'   => intval($total),
        'offset'  => $offset,
        'limit'   => $limit,
        'tipo'    => $tipo,
        'results' => cr_arricchisci_risultati($rows),
    ]);
}
