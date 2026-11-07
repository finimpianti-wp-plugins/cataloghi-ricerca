<?php
defined('ABSPATH') || exit;

// =============================================================================
// SHORTCODE + FRONTEND
// =============================================================================

add_shortcode('cataloghi_ricerca', 'cr_shortcode');

/** Icone SVG inline usate nel frontend */
function cr_icona($nome) {
    $icone = [
        'lente'  => '<circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.2" y2="16.2"/>',
        'filtri' => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="6" r="2" fill="currentColor"/><circle cx="15" cy="12" r="2" fill="currentColor"/><circle cx="7" cy="18" r="2" fill="currentColor"/>',
        'pdf'    => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'giu'    => '<polyline points="6 9 12 15 18 9"/>',
    ];
    return '<svg class="cr-icona cr-icona-' . esc_attr($nome) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($icone[$nome] ?? '') . '</svg>';
}

function cr_shortcode() {
    $dir = plugin_dir_path(CR_FILE) . 'assets/';
    $url = plugin_dir_url(CR_FILE) . 'assets/';
    wp_enqueue_style('cr-style',   $url . 'style.css', [], @filemtime($dir . 'style.css') ?: null);
    // Stile personalizzato (pannello "Stile e testi"): una sola volta anche con più shortcode
    static $css_aggiunto = false;
    if (!$css_aggiunto) { wp_add_inline_style('cr-style', cr_stile_css()); $css_aggiunto = true; }
    wp_enqueue_script('cr-viewer', $url . 'viewer.js', [], @filemtime($dir . 'viewer.js') ?: null, true);
    wp_enqueue_script('cr-script', $url . 'app.js', ['cr-viewer'], @filemtime($dir . 'app.js') ?: null, true);
    wp_localize_script('cr-viewer', 'CR', [
        'rest_url' => esc_url_raw(rest_url('cataloghi/v1')),
        'pdfjs'    => esc_url_raw($url . 'pdfjs/'),
        'lang'     => cr_multilingua() ? cr_lingua_corrente() : '',
        't'        => cr_testi_js(),
    ]);

    // Legge i cataloghi dal disco (fonte unica di verità)
    $esclusi   = cr_pdf_esclusi(); // altre lingue dello stesso catalogo
    $cataloghi = array_values(array_filter(cr_get_cataloghi_da_disco(), fn($c) => !in_array($c['file'], $esclusi, true)));
    $carosello = cr_get_carosello();
    $stile     = cr_stile();
    $mostra    = [
        'nav'        => !empty($stile['mostra_nav']),
        'componenti' => !empty($stile['mostra_componenti']),
        'motori'     => !empty($stile['mostra_motori']),
        'fascia'     => !empty($stile['mostra_fascia']),
    ];

    ob_start(); ?>
    <div id="cr-app" class="<?= esc_attr(cr_stile_classi()) ?>">

        <!-- NAVIGAZIONE RAPIDA -->
        <?php if ($mostra['nav'] && ($mostra['componenti'] || $mostra['motori'] || $mostra['fascia'])): ?>
            <nav class="cr-nav" aria-label="<?= esc_attr__('Sezioni della ricerca cataloghi', 'cataloghi-ricerca') ?>">
                <?php if ($mostra['componenti']): ?><a class="cr-nav-btn" href="#cr-componenti"><?= esc_html(cr_testo('testo_nav_comp')) ?></a><?php endif; ?>
                <?php if ($mostra['motori']): ?><a class="cr-nav-btn" href="#cr-motori"><?= esc_html(cr_testo('testo_nav_mot')) ?></a><?php endif; ?>
                <?php if ($mostra['fascia']): ?><a class="cr-nav-btn" href="#cr-cataloghi"><?= esc_html(cr_testo('testo_nav_cat')) ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>

        <!-- CAROSELLO IMMAGINI -->
        <?php if ($carosello['ids']): ?>
            <div class="cr-carosello cr-fullbleed" aria-hidden="true"
                 data-direzione="<?= esc_attr($carosello['direzione']) ?>"
                 data-velocita="<?= esc_attr($carosello['velocita']) ?>">
                <div class="cr-carosello-track">
                    <div class="cr-carosello-gruppo">
                        <?php foreach ($carosello['ids'] as $id) {
                            echo wp_get_attachment_image($id, 'medium', false, [
                                'class'     => 'cr-carosello-img',
                                'alt'       => '',
                                'loading'   => 'eager',
                                'draggable' => 'false',
                            ]);
                        } ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- SEZIONE 1: Ricerca per codice componente -->
        <?php if ($mostra['componenti']): ?>
        <section class="cr-card" id="cr-componenti">
            <h2 class="cr-titolo"><?= esc_html(cr_testo('titolo_comp')) ?></h2>
            <div class="cr-searchbox">
                <?= cr_icona('lente') ?>
                <input type="search" id="cr-input-codice" placeholder="<?= esc_attr(cr_testo('segnaposto_codice')) ?>"
                       autocomplete="off" spellcheck="false" aria-label="<?= esc_attr__('Codice componente FIN o OEM', 'cataloghi-ricerca') ?>"
                       aria-describedby="cr-input-hint" />
            </div>
            <p class="cr-input-hint" id="cr-input-hint"><?= esc_html(cr_testo('aiuto_codice')) ?></p>
            <div id="cr-componenti-risultati" class="cr-risultati" aria-live="polite"></div>
        </section>
        <?php endif; ?>

        <!-- SEZIONE 2: Ricerca per tipologia di motore -->
        <?php if ($mostra['motori']): ?>
        <section class="cr-card" id="cr-motori">
            <h2 class="cr-titolo"><?= esc_html(cr_testo('titolo_motori')) ?></h2>

            <button type="button" class="cr-filtri-toggle" id="cr-filtri-toggle"
                    aria-expanded="false" aria-controls="cr-filtri-pannello">
                <?= cr_icona('filtri') ?>
                <span><?= esc_html(cr_testo('testo_filtri')) ?></span>
                <span class="cr-filtri-conteggio" id="cr-filtri-conteggio" hidden></span>
                <?= cr_icona('giu') ?>
            </button>

            <div class="cr-filtri-pannello" id="cr-filtri-pannello">
                <div class="cr-filtri-inner">
                    <div class="cr-filtri-chips" id="cr-filtri-chips" role="group" aria-label="<?= esc_attr__('Costruttori', 'cataloghi-ricerca') ?>">
                        <p class="cr-loading"><?= esc_html__('Caricamento costruttori...', 'cataloghi-ricerca') ?></p>
                    </div>
                    <div class="cr-filtri-azioni">
                        <button type="button" class="cr-link-btn" id="cr-filtri-azzera" disabled><?= esc_html__('Azzera filtri', 'cataloghi-ricerca') ?></button>
                        <button type="button" class="cr-link-btn" id="cr-filtri-chiudi"><?= esc_html__('Chiudi', 'cataloghi-ricerca') ?></button>
                    </div>
                </div>
            </div>

            <div class="cr-searchbox">
                <?= cr_icona('lente') ?>
                <input type="search" id="cr-input-motore" placeholder="<?= esc_attr(cr_testo('segnaposto_motore')) ?>"
                       autocomplete="off" spellcheck="false" aria-label="<?= esc_attr__('Nome del motore', 'cataloghi-ricerca') ?>"
                       aria-describedby="cr-motore-hint" />
            </div>
            <div class="cr-filtri-attivi" id="cr-filtri-attivi"></div>
            <p class="cr-input-hint" id="cr-motore-hint"><?= esc_html(cr_testo('aiuto_motore')) ?></p>
            <div id="cr-motori-risultati" class="cr-risultati" aria-live="polite"></div>
        </section>
        <?php endif; ?>

        <!-- SEZIONE 3: Cataloghi -->
        <?php if ($mostra['fascia']): ?>
        <section class="cr-band cr-fullbleed" id="cr-cataloghi">
            <div class="cr-band-inner">
                <h2 class="cr-titolo"><?= esc_html(cr_testo('titolo_cataloghi')) ?></h2>
                <?php if (empty($cataloghi)): ?>
                    <p class="cr-band-vuoto"><?= esc_html(cr_testo('testo_nessun_cat')) ?></p>
                <?php else: ?>
                    <div class="cr-cataloghi-grid">
                        <?php foreach ($cataloghi as $cat): ?>
                            <a class="cr-catalogo-btn" href="<?= esc_url($cat['url']) ?>" target="_blank" rel="noopener"
                               data-cr-pdf="<?= esc_url($cat['url']) ?>" data-cr-pagina="1" data-cr-titolo="<?= esc_attr($cat['label']) ?>">
                                <?= cr_icona('pdf') ?>
                                <span><?= esc_html($cat['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
