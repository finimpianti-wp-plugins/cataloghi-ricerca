<?php
defined('ABSPATH') || exit;

// =============================================================================
// PANNELLO "STILE E TESTI" — impostazioni con anteprima dal vivo
// =============================================================================

add_action('admin_menu', function () {
    add_submenu_page('cataloghi-finimpianti', 'Cataloghi: pannello di controllo', 'Pannello di controllo', 'manage_options', 'cataloghi-finimpianti');
    add_submenu_page('cataloghi-finimpianti', 'Cataloghi: stile e testi', 'Stile e testi', 'manage_options', 'cataloghi-stile', 'cr_pagina_stile');
}, 20);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'cataloghi-stile') === false) return;
    $url = plugin_dir_url(CR_FILE) . 'assets/';
    $dir = plugin_dir_path(CR_FILE) . 'assets/';
    wp_enqueue_style('cr-stile-admin', $url . 'stile-admin.css', [], filemtime($dir . 'stile-admin.css'));
    wp_enqueue_script('cr-stile-admin', $url . 'stile-admin.js', [], filemtime($dir . 'stile-admin.js'), true);
});

/**
 * Pagine del sito che contengono lo shortcode (anche dentro Elementor), per l'anteprima.
 */
function cr_pagine_con_shortcode() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
         WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision', 'nav_menu_item', 'attachment')
           AND (p.post_content LIKE '%[cataloghi\\_ricerca%' OR m.meta_value LIKE '%cataloghi\\_ricerca%')
         ORDER BY p.post_type = 'page' DESC, p.ID ASC LIMIT 20"
    );
    return array_map(fn($id) => ['id' => (int) $id, 'titolo' => get_the_title($id) ?: '(senza titolo)', 'url' => get_permalink($id)], $ids);
}

function cr_pagina_stile() {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');

    $avviso = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cr_stile_azione']) && check_admin_referer('cr_stile_salva')) {
        if ($_POST['cr_stile_azione'] === 'salva') {
            update_option(CR_OPTION_STILE, cr_stile_sanifica(wp_unslash((array) ($_POST['cr_stile'] ?? []))));
            $avviso = ['success', 'Stile salvato. Se il sito usa una cache (es. SiteGround), svuotala per vedere le modifiche anche da non collegato.'];
        } elseif ($_POST['cr_stile_azione'] === 'ripristina') {
            delete_option(CR_OPTION_STILE);
            $avviso = ['success', 'Tutte le impostazioni sono tornate ai valori originali del plugin.'];
        }
        delete_transient(CR_STILE_BOZZA . get_current_user_id());
    }

    $valori   = cr_stile_salvato();
    $schema   = cr_stile_schema();
    $globali  = cr_stili_globali();
    $pagine   = cr_pagine_con_shortcode();
    $isolata  = add_query_arg(['cr_anteprima_stile' => 1, 'cr_isolata' => 1], home_url('/'));

    // Colori del plugin selezionabili come riferimento dagli altri colori
    $palette = [];
    foreach (['colore_principale', 'colore_accento', 'colore_testo', 'colore_testo_medio', 'colore_superfici', 'colore_bordi', 'colore_linee', 'colore_errore'] as $k) {
        $palette[] = ['Plugin', $schema[$k]['etichetta'], cr_stile_var($k), ''];
    }
    $palette[] = ['Plugin', 'Tinta chiara (dall\'accento)', '--cr-tinta', ''];

    $dati = [
        'ajax'      => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('cr_stile'),
        'globali'   => $globali,
        'palette'   => $palette,
        'default'   => cr_stile_default(),
        'pagine'    => array_map(fn($p) => ['titolo' => $p['titolo'], 'url' => add_query_arg('cr_anteprima_stile', 1, $p['url'])], $pagine),
        'isolata'   => $isolata,
        'versione'  => CR_VERSION,
    ];
    ?>
    <div class="wrap cr-stile-wrap">
        <h1 class="cr-stile-h1">Cataloghi — stile e testi</h1>
        <?php if ($avviso): ?>
            <div class="notice notice-<?= esc_attr($avviso[0]) ?> is-dismissible"><p><?= esc_html($avviso[1]) ?></p></div>
        <?php endif; ?>

        <div class="cr-stile-layout">

            <!-- ============================ IMPOSTAZIONI ============================ -->
            <form method="post" class="cr-stile-form" id="cr-stile-form">
                <?php wp_nonce_field('cr_stile_salva'); ?>

                <div class="cr-stile-azioni-top">
                    <button type="submit" name="cr_stile_azione" value="salva" class="button button-primary button-large" id="cr-stile-salva">Salva lo stile</button>
                    <span class="cr-stile-stato" id="cr-stile-stato" aria-live="polite"></span>
                </div>

                <div class="cr-stile-intro">
                    <p>Ogni colore e carattere può riferirsi agli <strong>stili globali del sito</strong> (Elementor o tema) con il pulsante <span class="cr-stile-tasto">Globali</span>:
                        così il plugin segue il sito e, se lo sposti su un altro sito, ne usa gli stili.</p>
                    <p>Dimensioni e spazi hanno tre valori:
                        <span class="dashicons dashicons-desktop"></span> desktop,
                        <span class="dashicons dashicons-tablet"></span> tablet (fino a 1024 px),
                        <span class="dashicons dashicons-smartphone"></span> mobile (fino a 767 px).
                        Un valore vuoto usa quello del dispositivo più grande.</p>
                    <p>
                        <button type="button" class="button" id="cr-stile-globali" <?= empty($globali['colori']) && empty($globali['font']) ? 'disabled' : '' ?>>
                            <span class="dashicons dashicons-admin-site-alt3"></span> Usa gli stili globali del sito
                        </button>
                        <?php if (empty($globali['colori']) && empty($globali['font'])): ?>
                            <span class="cr-stile-nota">Nessuno stile globale trovato (Elementor o tema).</span>
                        <?php endif; ?>
                    </p>
                </div>

                <?php foreach (cr_stile_gruppi() as $g => [$nome, $descrizione]): ?>
                    <details class="cr-stile-gruppo" data-gruppo="<?= esc_attr($g) ?>" <?= $g === 'generale' ? 'open' : '' ?>>
                        <summary><span><?= esc_html($nome) ?></span></summary>
                        <p class="cr-stile-desc"><?= esc_html($descrizione) ?></p>
                        <?php foreach ($schema as $k => $s): if ($s['gruppo'] !== $g) continue; cr_campo_stile($k, $s, $valori); endforeach; ?>
                    </details>
                <?php endforeach; ?>

                <div class="cr-stile-azioni-fondo">
                    <button type="submit" name="cr_stile_azione" value="salva" class="button button-primary">Salva lo stile</button>
                    <button type="button" class="button" id="cr-stile-esporta"><span class="dashicons dashicons-download"></span> Esporta</button>
                    <label class="button" for="cr-stile-file"><span class="dashicons dashicons-upload"></span> Importa</label>
                    <input type="file" id="cr-stile-file" accept=".json,application/json" hidden>
                    <button type="submit" name="cr_stile_azione" value="ripristina" class="button-link cr-stile-ripristina"
                            onclick="return confirm('Riportare TUTTE le impostazioni di stile e i testi ai valori originali del plugin?');">Ripristina i valori originali</button>
                </div>
                <p class="cr-stile-nota">"Esporta" salva lo stile in un file, da conservare come copia di sicurezza o da importare su un altro sito. Dopo un'importazione controlla l'anteprima e premi "Salva lo stile".</p>
            </form>

            <!-- ============================== ANTEPRIMA ============================== -->
            <div class="cr-stile-anteprima">
                <div class="cr-stile-barra">
                    <div class="cr-stile-dispositivi" role="group" aria-label="Dispositivo">
                        <button type="button" class="button attivo" data-larghezza="1366" title="Desktop (1366 px)"><span class="dashicons dashicons-desktop"></span></button>
                        <button type="button" class="button" data-larghezza="820" title="Tablet (820 px)"><span class="dashicons dashicons-tablet"></span></button>
                        <button type="button" class="button" data-larghezza="390" title="Mobile (390 px)"><span class="dashicons dashicons-smartphone"></span></button>
                    </div>
                    <select id="cr-stile-pagina" aria-label="Pagina dell'anteprima">
                        <?php foreach ($dati['pagine'] as $i => $p): ?>
                            <option value="<?= esc_url($p['url']) ?>"><?= esc_html($p['titolo']) ?></option>
                        <?php endforeach; ?>
                        <option value="<?= esc_url($isolata) ?>">Solo il blocco (senza il tema)</option>
                    </select>
                    <label class="cr-stile-check"><input type="checkbox" id="cr-stile-esempi"> Risultati di esempio</label>
                    <select id="cr-stile-vai" aria-label="Vai alla sezione">
                        <option value="">Vai a…</option>
                        <option value="cr-componenti">Ricerca componenti</option>
                        <option value="cr-motori">Ricerca motori</option>
                        <option value="cr-cataloghi">Fascia cataloghi</option>
                    </select>
                    <a href="#" class="button" id="cr-stile-apri" target="_blank" rel="noopener" title="Apri l'anteprima in una nuova scheda"><span class="dashicons dashicons-external"></span></a>
                </div>
                <div class="cr-stile-cornice" id="cr-stile-cornice">
                    <iframe id="cr-stile-iframe" title="Anteprima dello stile" loading="eager"></iframe>
                </div>
                <p class="cr-stile-nota">
                    L'anteprima mostra le modifiche non ancora salvate solo a te.
                    <?= $pagine ? 'Usa la pagina vera del sito, con il tema e gli stili globali.' : 'Nessuna pagina contiene ancora lo shortcode [cataloghi_ricerca]: l\'anteprima mostra il solo blocco.' ?>
                </p>
            </div>
        </div>
    </div>
    <script>window.CR_STILE = <?= wp_json_encode($dati) ?>;</script>
    <?php
}

/** Un campo del pannello. */
function cr_campo_stile($k, $s, $valori) {
    $nome  = 'cr_stile[' . $k . ']';
    $id    = 'cr-st-' . $k;
    $v     = $valori[$k] ?? $s['default'];
    $extra = !empty($s['ricarica']) ? ' data-ricarica="1"' : '';
    ?>
    <div class="cr-stile-campo cr-tipo-<?= esc_attr($s['tipo']) ?>" data-chiave="<?= esc_attr($k) ?>">
        <?php if ($s['tipo'] === 'si_no'): ?>
            <label class="cr-stile-si-no">
                <input type="hidden" name="<?= esc_attr($nome) ?>" value="0">
                <input type="checkbox" id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>" value="1" <?php checked((int) $v, 1); ?><?= $extra ?>>
                <?= esc_html($s['etichetta']) ?>
            </label>

        <?php else: ?>
            <label class="cr-stile-etichetta" for="<?= esc_attr($id) ?>"><?= esc_html($s['etichetta']) ?></label>

            <?php if ($s['tipo'] === 'colore'): ?>
                <div class="cr-stile-colore">
                    <span class="cr-campione" aria-hidden="true"><span></span><input type="color" tabindex="-1" title="Scegli un colore"></span>
                    <input type="text" id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>" value="<?= esc_attr($v) ?>" spellcheck="false" data-default="<?= esc_attr($s['default']) ?>">
                    <button type="button" class="button cr-globali-btn" title="Scegli un colore globale del sito o del plugin">Globali</button>
                </div>
                <div class="cr-stile-riferimento" aria-live="polite"></div>

            <?php elseif ($s['tipo'] === 'font'): ?>
                <div class="cr-stile-font">
                    <input type="text" id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>" value="<?= esc_attr($v) ?>" spellcheck="false" data-default="<?= esc_attr($s['default']) ?>"
                           placeholder="inherit">
                    <button type="button" class="button cr-globali-btn" title="Scegli un carattere globale del sito">Globali</button>
                </div>
                <div class="cr-stile-riferimento" aria-live="polite"></div>

            <?php elseif ($s['tipo'] === 'numero'): ?>
                <div class="cr-stile-numero">
                    <?php if (!empty($s['r'])): ?><span class="dashicons dashicons-desktop" title="Desktop"></span><?php endif; ?>
                    <input type="range" min="<?= esc_attr($s['min']) ?>" max="<?= esc_attr($s['max']) ?>" step="<?= esc_attr($s['passo']) ?>" value="<?= esc_attr($v) ?>" tabindex="-1" aria-hidden="true">
                    <input type="number" id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>" value="<?= esc_attr($v) ?>" min="<?= esc_attr($s['min']) ?>" max="<?= esc_attr($s['max']) ?>" step="<?= esc_attr($s['passo']) ?>">
                    <span class="cr-unita"><?= esc_html($s['unita']) ?></span>
                </div>
                <?php if (!empty($s['r'])): ?>
                    <div class="cr-stile-dispositivi-val">
                        <?php foreach (['t' => ['tablet', 'Tablet', '= desktop'], 'm' => ['smartphone', 'Mobile', '= tablet']] as $suf => [$icona, $titolo, $segnaposto]): ?>
                            <label title="<?= esc_attr($titolo) ?>">
                                <span class="dashicons dashicons-<?= $icona ?>"></span>
                                <input type="number" name="cr_stile[<?= esc_attr($k . '_' . $suf) ?>]" value="<?= esc_attr($valori[$k . '_' . $suf] ?? '') ?>"
                                       min="<?= esc_attr($s['min']) ?>" max="<?= esc_attr($s['max']) ?>" step="<?= esc_attr($s['passo']) ?>" placeholder="<?= esc_attr($segnaposto) ?>"
                                       aria-label="<?= esc_attr($s['etichetta'] . ' — ' . $titolo) ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($s['tipo'] === 'scelta'): ?>
                <select id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>"<?= $extra ?>>
                    <?php foreach ($s['opzioni'] as $ov => $ol): ?>
                        <option value="<?= esc_attr($ov) ?>" <?php selected((string) $v, (string) $ov); ?>><?= esc_html($ol) ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($s['tipo'] === 'testo'): ?>
                <input type="text" class="widefat" id="<?= esc_attr($id) ?>" name="<?= esc_attr($nome) ?>" value="<?= esc_attr($v) ?>" placeholder="<?= esc_attr($s['default']) ?>" data-ricarica="1">
                <?php foreach (cr_lingue() as $lingua => $nome_lingua): if ($lingua === cr_lingua_predefinita()) continue; ?>
                    <label class="cr-stile-lingua">
                        <span><?= esc_html($nome_lingua) ?></span>
                        <input type="text" class="widefat" name="cr_stile[<?= esc_attr($k . '__' . $lingua) ?>]" value="<?= esc_attr($valori[$k . '__' . $lingua] ?? '') ?>"
                               placeholder="Vuoto = testo predefinito tradotto (Loco Translate)" aria-label="<?= esc_attr($s['etichetta'] . ' — ' . $nome_lingua) ?>">
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($s['aiuto'])): ?><p class="cr-stile-aiuto"><?= esc_html($s['aiuto']) ?></p><?php endif; ?>
    </div>
    <?php
}
