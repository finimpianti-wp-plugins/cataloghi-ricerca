<?php
defined('ABSPATH') || exit;

// =============================================================================
// PAGINA ADMIN — pannello di controllo
// =============================================================================

add_action('admin_menu', function () {
    add_menu_page(
        'Cataloghi',
        'Cataloghi',
        'manage_options',
        'cataloghi-finimpianti',
        'cr_pagina_admin',
        'dashicons-media-document',
        30
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_cataloghi-finimpianti') return;
    $url = plugin_dir_url(CR_FILE) . 'assets/';
    $dir = plugin_dir_path(CR_FILE) . 'assets/';
    wp_enqueue_media();
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_style('cr-admin', $url . 'admin.css', [], filemtime($dir . 'admin.css'));
    wp_enqueue_script('cr-admin', $url . 'admin.js', ['jquery'], filemtime($dir . 'admin.js'), true);
    wp_localize_script('cr-admin', 'CR_ADMIN', [
        'max_upload'   => wp_max_upload_size(),
        'max_upload_h' => size_format(wp_max_upload_size()),
    ]);
});

/**
 * Esegue l'azione inviata dal form e ritorna il risultato da mostrare.
 */
function cr_esegui_azione_admin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;

    // Se il file supera post_max_size PHP scarta l'intera richiesta: $_POST arriva vuoto
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return ['error' => 'Il file inviato (' . size_format((int) $_SERVER['CONTENT_LENGTH']) . ') supera il limite del server ('
            . size_format(wp_max_upload_size()) . ') ed è stato scartato. Per i PDF grandi usa l\'upload FTP + "Scansiona cartella".'];
    }

    if (isset($_POST['cr_import_action'])) {
        switch (sanitize_key($_POST['cr_import_action'])) {
            case 'importa':    return cr_gestisci_import();
            case 'reimporta':  return cr_gestisci_reimporta();
            case 'ripristina': return cr_gestisci_ripristina();
        }
    }
    if (isset($_POST['cr_pdf_action'])) {
        switch (sanitize_key($_POST['cr_pdf_action'])) {
            case 'upload':    return cr_gestisci_pdf_upload();
            case 'label':     return cr_gestisci_pdf_label();
            case 'elimina':   return cr_gestisci_pdf_elimina();
            case 'rinomina':  return cr_gestisci_pdf_rinomina();
            case 'scansiona': return cr_gestisci_pdf_scansiona();
            case 'lingua':    return cr_gestisci_pdf_lingua();
        }
    }
    if (isset($_POST['cr_carosello_action'])) return cr_gestisci_carosello();
    if (isset($_POST['cr_impostazioni_action'])) return cr_gestisci_impostazioni();
    return null;
}

function cr_messaggio_esito($ris) {
    $f = fn($k) => '<strong>' . esc_html($ris[$k] ?? '') . '</strong>';
    switch ($ris['azione'] ?? '') {
        case 'upload':      return "PDF {$f('file')} caricato.";
        case 'sovrascritto':return "PDF {$f('file')} sostituito con la nuova versione.";
        case 'label':       return "Etichetta aggiornata per {$f('file')}.";
        case 'lingua':      return "Lingua aggiornata per {$f('file')}.";
        case 'elimina':     return "PDF {$f('file')} eliminato.";
        case 'rinomina':    return "{$f('file')} rinominato in {$f('nuovo')}.";
        case 'scansiona':   return 'Scansione completata: <strong>' . (int) $ris['totale'] . '</strong> PDF trovati, <strong>' . (int) $ris['nuovi'] . '</strong> nuovi.';
        case 'carosello':   return 'Carosello salvato: <strong>' . (int) $ris['totale'] . '</strong> immagini.';
        case 'impostazioni':return $ris['prefisso'] !== '' ? 'Prefisso dei codici interni: <strong>' . esc_html($ris['prefisso']) . '</strong>.' : 'Nessun prefisso: la ricerca considera tutti i codici allo stesso modo.';
        case 'ripristino':  return 'Indice ' . esc_html($ris['tipo']) . ': ripristinata la versione precedente. Puoi tornare indietro con lo stesso pulsante.';
        case 'import':
            $msg = 'Import ' . esc_html($ris['tipo']) . ' completato: <strong>' . number_format_i18n($ris['righe']) . '</strong> righe ora online.';
            if (!empty($ris['avvisi'])) $msg .= '<br>' . esc_html(implode(' ', $ris['avvisi']));
            return $msg;
    }
    return 'Operazione completata.';
}

function cr_pagina_admin() {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');

    $ris = cr_esegui_azione_admin();

    $diag      = cr_diagnostica();
    $problemi  = $diag['problemi'];
    $uso_pdf   = $diag['uso_pdf'];
    $cataloghi = cr_get_cataloghi_da_disco();
    $carosello = cr_get_carosello();
    $indici    = [];
    foreach (['motori', 'componenti'] as $tipo) {
        $s = cr_stato_import($tipo);
        $indici[$tipo] = [
            'stato'      => $s,
            'righe'      => cr_conta_righe($tipo, (int) $s['attivo']),
            'info'       => $s['lotti'][$s['attivo']] ?? null,
            'prec_info'  => $s['precedente'] !== null ? ($s['lotti'][$s['precedente']] ?? null) : null,
            'prec_righe' => $s['precedente'] !== null ? cr_conta_righe($tipo, (int) $s['precedente']) : 0,
            'csv'        => file_exists(cr_percorso_csv($tipo)),
        ];
    }
    $n_errori = count(array_filter($problemi, fn($p) => $p['livello'] === 'errore'));
    $n_avvisi = count(array_filter($problemi, fn($p) => $p['livello'] === 'avviso'));
    $max_upload = size_format(wp_max_upload_size());
    ?>
    <div class="wrap" id="cr-admin-wrap">

        <div class="cr-admin-header">
            <span class="dashicons dashicons-media-document cr-admin-icon"></span>
            <div>
                <h1>Cataloghi</h1>
                <p class="cr-admin-subtitle">Pannello di controllo: cataloghi PDF, indici di ricerca e carosello</p>
            </div>
        </div>

        <?php if ($ris): ?>
            <?php if (!empty($ris['error'])): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Errore:</strong> <?= esc_html($ris['error']) ?></p></div>
            <?php else: ?>
                <div class="notice notice-<?= empty($ris['avvisi']) ? 'success' : 'warning' ?> is-dismissible"><p><?= cr_messaggio_esito($ris) ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STATO / DIAGNOSTICA                                              -->
        <!-- ================================================================ -->
        <div class="cr-diagnostica <?= $n_errori ? 'ha-errori' : ($n_avvisi ? 'ha-avvisi' : 'tutto-ok') ?>" id="cr-diagnostica">
            <div class="cr-diag-header">
                <span class="dashicons <?= $n_errori ? 'dashicons-warning' : ($n_avvisi ? 'dashicons-flag' : 'dashicons-yes-alt') ?>"></span>
                <div>
                    <h2><?php
                        if ($n_errori)      echo $n_errori === 1 ? '1 problema da risolvere' : "$n_errori problemi da risolvere";
                        elseif ($n_avvisi)  echo $n_avvisi === 1 ? '1 avviso' : "$n_avvisi avvisi";
                        else                echo 'Tutto in ordine';
                    ?></h2>
                    <p><?= $problemi ? 'Controlli eseguiti all\'apertura di questa pagina.' : 'Indici importati, cataloghi presenti e collegamenti verificati.' ?></p>
                </div>
            </div>
            <?php if ($problemi): ?>
                <ul class="cr-diag-lista">
                    <?php foreach ($problemi as $p): ?>
                        <li class="cr-diag-item <?= esc_attr($p['livello']) ?>">
                            <span class="cr-diag-livello"><?= ['errore' => 'Errore', 'avviso' => 'Avviso', 'info' => 'Info'][$p['livello']] ?></span>
                            <div class="cr-diag-corpo">
                                <strong class="cr-diag-titolo"><?= esc_html($p['titolo']) ?></strong>
                                <p><?= $p['testo'] ?></p>

                                <?php if (!empty($p['elenco'])): ?>
                                    <details <?= count($p['elenco']) <= 5 ? 'open' : '' ?>>
                                        <summary><?= esc_html($p['elenco_titolo'] ?? 'Dettaglio') ?> (<?= count($p['elenco']) ?>)</summary>
                                        <ul><?php foreach ($p['elenco'] as $voce): ?><li><?= $voce ?></li><?php endforeach; ?></ul>
                                    </details>
                                <?php endif; ?>

                                <?php if (!empty($p['rinomina'])): ?>
                                    <ul class="cr-diag-rinomina">
                                        <?php foreach ($p['rinomina'] as $d): ?>
                                            <li>
                                                <span>Su disco: <code><?= esc_html($d['file']) ?></code><br>Negli indici: <code><?= esc_html($d['citato']) ?></code></span>
                                                <form method="post">
                                                    <?php wp_nonce_field('cr_pdf_nonce'); ?>
                                                    <input type="hidden" name="cr_pdf_action" value="rinomina">
                                                    <input type="hidden" name="cr_pdf_filename" value="<?= esc_attr($d['file']) ?>">
                                                    <input type="hidden" name="cr_pdf_nuovo" value="<?= esc_attr($d['citato']) ?>">
                                                    <button type="submit" class="cr-btn-secondario piccolo">Rinomina come negli indici</button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- RIEPILOGO -->
        <div class="cr-admin-stato">
            <div class="cr-stato-card">
                <span class="cr-stato-label">Motori online</span>
                <span class="cr-stato-num <?= $indici['motori']['righe'] ? 'ok' : 'empty' ?>"><?= number_format_i18n($indici['motori']['righe']) ?></span>
            </div>
            <div class="cr-stato-card">
                <span class="cr-stato-label">Componenti online</span>
                <span class="cr-stato-num <?= $indici['componenti']['righe'] ? 'ok' : 'empty' ?>"><?= number_format_i18n($indici['componenti']['righe']) ?></span>
            </div>
            <div class="cr-stato-card">
                <span class="cr-stato-label">Cataloghi PDF</span>
                <span class="cr-stato-num <?= $cataloghi ? 'ok' : 'empty' ?>"><?= count($cataloghi) ?></span>
            </div>
            <div class="cr-stato-card">
                <span class="cr-stato-label">Immagini carosello</span>
                <span class="cr-stato-num <?= $carosello['ids'] ? 'ok' : 'empty' ?>"><?= count($carosello['ids']) ?></span>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- CATALOGHI PDF                                                    -->
        <!-- ================================================================ -->
        <div class="cr-section-title-bar" id="cr-sezione-pdf">
            <span class="dashicons dashicons-portfolio"></span>
            <h2>Cataloghi PDF</h2>
        </div>

        <div class="cr-pdf-layout">

            <div class="cr-admin-panel">
                <div class="cr-panel-header cataloghi">
                    <span class="dashicons dashicons-upload"></span>
                    <h2>Carica un catalogo</h2>
                </div>
                <div class="cr-panel-body">
                    <p class="cr-panel-desc">
                        Il file viene salvato <strong>con il suo nome originale</strong>, che deve coincidere con la colonna
                        <code>File_origine</code> degli indici. Se esiste già un catalogo con lo stesso nome ti verrà chiesto se sostituirlo.
                    </p>
                    <div class="cr-upload-limit-info">
                        <span class="dashicons dashicons-info-outline"></span>
                        <span>Limite upload del server: <strong><?= esc_html($max_upload) ?></strong>. Per file più grandi usa l'upload FTP + "Scansiona cartella".</span>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="cr-upload-form" id="cr-form-upload-pdf">
                        <?php wp_nonce_field('cr_pdf_nonce'); ?>
                        <input type="hidden" name="cr_pdf_action" value="upload">
                        <input type="hidden" name="cr_pdf_sovrascrivi" value="0" id="cr-pdf-sovrascrivi">
                        <div class="cr-form-field">
                            <label for="cr-pdf-label">Etichetta del catalogo</label>
                            <input type="text" id="cr-pdf-label" name="cr_pdf_label" placeholder="Es. Catalogo 1 — LOMBARDINI">
                            <span class="cr-field-hint">Il nome mostrato agli utenti. Se lo lasci vuoto viene usato il nome del file (o l'etichetta attuale, se sostituisci un catalogo).</span>
                        </div>
                        <?php if (cr_lingue()): ?>
                            <div class="cr-form-field">
                                <label for="cr-pdf-lingua">Lingua del catalogo</label>
                                <select id="cr-pdf-lingua" name="cr_pdf_lingua">
                                    <option value="">— Non indicata —</option>
                                    <?php foreach (cr_lingue() as $codice => $nome_lingua): ?>
                                        <option value="<?= esc_attr($codice) ?>"><?= esc_html($nome_lingua) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="cr-field-hint">Per le versioni in più lingue dello stesso catalogo usa la <strong>stessa etichetta</strong>: sulle pagine di ogni lingua si mostra la versione in quella lingua, altrimenti l'inglese, altrimenti l'italiano.</span>
                            </div>
                        <?php endif; ?>
                        <div class="cr-file-area" id="cr-drop-pdf">
                            <span class="dashicons dashicons-pdf cr-upload-icon"></span>
                            <p>Trascina qui il PDF oppure</p>
                            <label class="cr-file-label">
                                Seleziona PDF
                                <input type="file" name="cr_pdf_file" accept=".pdf,application/pdf" required data-nome="cr-nome-pdf">
                            </label>
                            <p class="cr-nome-file" id="cr-nome-pdf"></p>
                        </div>
                        <button type="submit" class="cr-btn-importa cataloghi">
                            <span class="dashicons dashicons-upload"></span>
                            Carica catalogo
                        </button>
                    </form>
                </div>
            </div>

            <div class="cr-admin-panel">
                <div class="cr-panel-header ftp">
                    <span class="dashicons dashicons-search"></span>
                    <h2>Scansione cartella (FTP)</h2>
                </div>
                <div class="cr-panel-body">
                    <p class="cr-panel-desc">
                        Se hai caricato PDF via FTP/SFTP nella cartella <code><?= esc_html(CR_PDF_DIR) ?></code>,
                        usa questa funzione per assegnare un'etichetta iniziale ai file nuovi. I file già presenti non vengono modificati.
                    </p>
                    <div class="cr-ftp-istruzioni">
                        <strong>Come fare:</strong>
                        <ol>
                            <li>Connettiti al server via FTP/SFTP</li>
                            <li>Naviga in <code>wp-content/uploads/cataloghi-pdf/</code></li>
                            <li>Carica i PDF senza rinominarli (il nome deve coincidere con <code>File_origine</code>)</li>
                            <li>Clicca il pulsante qui sotto</li>
                        </ol>
                    </div>
                    <form method="post" class="cr-upload-form">
                        <?php wp_nonce_field('cr_pdf_nonce'); ?>
                        <input type="hidden" name="cr_pdf_action" value="scansiona">
                        <button type="submit" class="cr-btn-importa ftp">
                            <span class="dashicons dashicons-search"></span>
                            Scansiona cartella
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <div class="cr-admin-panel cr-panel-lista">
            <div class="cr-panel-header lista">
                <span class="dashicons dashicons-list-view"></span>
                <h2>Cataloghi presenti (<?= count($cataloghi) ?>)</h2>
            </div>
            <div class="cr-panel-body">
                <?php if (!$cataloghi): ?>
                    <div class="cr-lista-vuota">
                        <span class="dashicons dashicons-media-document cr-vuota-icon"></span>
                        <p>Nessun catalogo PDF trovato.</p>
                        <p class="cr-vuota-sub">Carica il primo PDF usando il modulo qui sopra oppure via FTP.</p>
                    </div>
                <?php else: ?>
                    <div class="cr-cataloghi-table-wrap">
                        <table class="cr-cataloghi-table">
                            <thead>
                                <tr>
                                    <th>Nome file</th>
                                    <th>Etichetta frontend</th>
                                    <?php if (cr_lingue()): ?><th>Lingua</th><?php endif; ?>
                                    <th>Usato da</th>
                                    <th>Dimensione</th>
                                    <th>Modificato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cataloghi as $cat):
                                    $h   = md5($cat['file']);
                                    $uso = $uso_pdf[$cat['file']] ?? null;
                                    $avviso_elimina = $uso
                                        ? "Il catalogo è usato da " . cr_descrivi_righe($uso) . " negli indici: quei risultati non potranno più aprirlo. "
                                        : '';
                                    ?>
                                    <tr>
                                        <td class="cr-cell-file">
                                            <a href="<?= esc_url($cat['url']) ?>" target="_blank" class="cr-file-link">
                                                <span class="dashicons dashicons-pdf"></span>
                                                <?= esc_html($cat['file']) ?>
                                            </a>
                                        </td>
                                        <td class="cr-cell-label">
                                            <span class="cr-label-testo" id="cr-label-text-<?= $h ?>"><?= esc_html($cat['label']) ?></span>
                                            <form method="post" class="cr-form-label" id="cr-label-form-<?= $h ?>" hidden>
                                                <?php wp_nonce_field('cr_pdf_nonce'); ?>
                                                <input type="hidden" name="cr_pdf_action" value="label">
                                                <input type="hidden" name="cr_pdf_filename" value="<?= esc_attr($cat['file']) ?>">
                                                <div class="cr-label-edit-row">
                                                    <input type="text" name="cr_pdf_label" value="<?= esc_attr($cat['label']) ?>" class="cr-label-input" required>
                                                    <button type="submit" class="cr-btn-small salva" title="Salva"><span class="dashicons dashicons-yes"></span></button>
                                                    <button type="button" class="cr-btn-small annulla" title="Annulla" data-annulla="<?= $h ?>"><span class="dashicons dashicons-no"></span></button>
                                                </div>
                                            </form>
                                        </td>
                                        <?php if (cr_lingue()): $lingua_pdf = cr_lingua_pdf($cat['file']); ?>
                                            <td class="cr-cell-lingua">
                                                <form method="post" class="cr-form-lingua">
                                                    <?php wp_nonce_field('cr_pdf_nonce'); ?>
                                                    <input type="hidden" name="cr_pdf_action" value="lingua">
                                                    <input type="hidden" name="cr_pdf_filename" value="<?= esc_attr($cat['file']) ?>">
                                                    <select name="cr_pdf_lingua" aria-label="<?= esc_attr('Lingua di ' . $cat['file']) ?>" onchange="this.form.submit()">
                                                        <option value="">—</option>
                                                        <?php foreach (cr_lingue() as $codice => $nome_lingua): ?>
                                                            <option value="<?= esc_attr($codice) ?>" <?php selected($lingua_pdf, $codice); ?>><?= esc_html($nome_lingua) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <noscript><button type="submit" class="cr-btn-small">OK</button></noscript>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                        <td class="cr-cell-uso"><?= $uso ? esc_html(cr_descrivi_righe($uso)) : '<span class="cr-non-usato">non usato</span>' ?></td>
                                        <td class="cr-cell-size"><?= cr_format_bytes($cat['size']) ?></td>
                                        <td class="cr-cell-date"><?= cr_format_data($cat['modified']) ?></td>
                                        <td class="cr-cell-azioni">
                                            <button type="button" class="cr-btn-small modifica" title="Modifica etichetta" data-modifica="<?= $h ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </button>
                                            <form method="post" class="cr-form-elimina"
                                                  data-conferma="<?= esc_attr($avviso_elimina . 'Eliminare definitivamente "' . $cat['file'] . '"?') ?>">
                                                <?php wp_nonce_field('cr_pdf_nonce'); ?>
                                                <input type="hidden" name="cr_pdf_action" value="elimina">
                                                <input type="hidden" name="cr_pdf_filename" value="<?= esc_attr($cat['file']) ?>">
                                                <button type="submit" class="cr-btn-small elimina" title="Elimina"><span class="dashicons dashicons-trash"></span></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- CAROSELLO                                                        -->
        <!-- ================================================================ -->
        <div class="cr-section-title-bar" style="margin-top:40px">
            <span class="dashicons dashicons-images-alt2"></span>
            <h2>Carosello immagini</h2>
        </div>

        <div class="cr-admin-panel cr-panel-lista">
            <div class="cr-panel-header carosello">
                <span class="dashicons dashicons-format-gallery"></span>
                <h2>Immagini della striscia animata</h2>
            </div>
            <div class="cr-panel-body">
                <p class="cr-panel-desc">
                    Scegli le immagini dalla Libreria media e trascinale per cambiarne l'ordine.
                    Per un risultato uniforme usa PNG o WebP con sfondo trasparente e proporzioni simili.
                    Se non ci sono immagini la striscia non viene mostrata.
                </p>
                <form method="post" class="cr-upload-form" id="cr-form-carosello">
                    <?php wp_nonce_field('cr_carosello_nonce'); ?>
                    <input type="hidden" name="cr_carosello_action" value="salva">
                    <input type="hidden" name="cr_carosello_ids" id="cr-carosello-ids" value="<?= esc_attr(implode(',', $carosello['ids'])) ?>">

                    <ul class="cr-carosello-lista" id="cr-carosello-lista">
                        <?php foreach ($carosello['ids'] as $id):
                            $thumb = wp_get_attachment_image_url($id, 'thumbnail');
                            if (!$thumb) continue; ?>
                            <li data-id="<?= esc_attr($id) ?>">
                                <img src="<?= esc_url($thumb) ?>" alt="">
                                <button type="button" class="cr-thumb-rimuovi" title="Rimuovi">×</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="cr-carosello-vuoto" id="cr-carosello-vuoto" <?= $carosello['ids'] ? 'hidden' : '' ?>>Nessuna immagine selezionata.</p>

                    <div class="cr-carosello-opzioni">
                        <button type="button" class="cr-btn-secondario" id="cr-carosello-aggiungi">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            Aggiungi immagini
                        </button>
                        <div class="cr-form-field">
                            <label for="cr-carosello-direzione">Direzione</label>
                            <select name="cr_carosello_direzione" id="cr-carosello-direzione">
                                <option value="dx" <?php selected($carosello['direzione'], 'dx'); ?>>Da sinistra a destra</option>
                                <option value="sx" <?php selected($carosello['direzione'], 'sx'); ?>>Da destra a sinistra</option>
                            </select>
                        </div>
                        <div class="cr-form-field">
                            <label for="cr-carosello-velocita">Velocità</label>
                            <select name="cr_carosello_velocita" id="cr-carosello-velocita">
                                <option value="lenta"  <?php selected($carosello['velocita'], 'lenta'); ?>>Lenta</option>
                                <option value="media"  <?php selected($carosello['velocita'], 'media'); ?>>Media</option>
                                <option value="veloce" <?php selected($carosello['velocita'], 'veloce'); ?>>Veloce</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="cr-btn-importa carosello">
                        <span class="dashicons dashicons-saved"></span>
                        Salva carosello
                    </button>
                </form>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- INDICI DI RICERCA                                                -->
        <!-- ================================================================ -->
        <div class="cr-section-title-bar" style="margin-top:40px" id="cr-sezione-indici">
            <span class="dashicons dashicons-database"></span>
            <h2>Indici di ricerca</h2>
        </div>

        <div class="cr-admin-panels">
            <?php foreach (['motori' => 'Indice Motori', 'componenti' => 'Indice Componenti'] as $tipo => $titolo):
                $ix = $indici[$tipo];
                $u  = $ix['stato']['ultimo'];
                $colonne = implode(', ', array_values(cr_colonne_csv($tipo)));
                $badge = [
                    'completato' => ['ok', 'Completato'], 'avvisi' => ['avviso', 'Completato con avvisi'],
                    'fallito' => ['errore', 'Non riuscito'], 'interrotto' => ['errore', 'Interrotto'], 'in_corso' => ['info', 'In corso'],
                ][$u['stato'] ?? ''] ?? null;
                ?>
                <div class="cr-admin-panel">
                    <div class="cr-panel-header <?= $tipo ?>">
                        <span class="dashicons <?= $tipo === 'motori' ? 'dashicons-performance' : 'dashicons-admin-tools' ?>"></span>
                        <h2><?= esc_html($titolo) ?></h2>
                    </div>
                    <div class="cr-panel-body">

                        <dl class="cr-indice-stato">
                            <dt>Versione online</dt>
                            <dd>
                                <strong><?= number_format_i18n($ix['righe']) ?> righe</strong>
                                <?php if (!empty($ix['info']['data'])): ?>
                                    · <?= esc_html($ix['info']['file']) ?> · <?= cr_format_data($ix['info']['data']) ?>
                                <?php elseif ($ix['righe']): ?>
                                    · importata con la versione precedente del plugin
                                <?php endif; ?>
                            </dd>
                            <?php if ($badge): ?>
                                <dt>Ultimo import</dt>
                                <dd><span class="cr-badge-stato <?= $badge[0] ?>"><?= $badge[1] ?></span> <?= esc_html($u['file']) ?> · <?= cr_format_data($u['inizio']) ?></dd>
                            <?php endif; ?>
                            <?php if ($ix['stato']['precedente'] !== null && $ix['prec_righe']): ?>
                                <dt>Versione precedente</dt>
                                <dd>
                                    <?= number_format_i18n($ix['prec_righe']) ?> righe
                                    <?php if (!empty($ix['prec_info']['data'])): ?>
                                        · <?= esc_html($ix['prec_info']['file']) ?> · <?= cr_format_data($ix['prec_info']['data']) ?>
                                    <?php else: ?>
                                        · importata con la versione precedente del plugin
                                    <?php endif; ?>
                                </dd>
                            <?php endif; ?>
                        </dl>

                        <p class="cr-panel-desc">
                            Carica <code><?= $tipo === 'motori' ? 'indice_motori.csv' : 'indice_componenti_all.csv' ?></code>.
                            Il nuovo indice va online solo se l'import arriva in fondo; la versione attuale resta disponibile per il ripristino.
                        </p>
                        <p class="cr-panel-colonne">Colonne: <code><?= esc_html($colonne) ?></code></p>

                        <form method="post" enctype="multipart/form-data" class="cr-upload-form">
                            <?php wp_nonce_field('cr_importa_nonce'); ?>
                            <input type="hidden" name="cr_import_action" value="importa">
                            <input type="hidden" name="cr_tipo" value="<?= $tipo ?>">
                            <div class="cr-file-area">
                                <span class="dashicons dashicons-upload cr-upload-icon"></span>
                                <p>Trascina qui il CSV oppure</p>
                                <label class="cr-file-label">
                                    Seleziona file
                                    <input type="file" name="cr_csv_<?= $tipo ?>" accept=".csv,text/csv" required data-nome="cr-nome-<?= $tipo ?>">
                                </label>
                                <p class="cr-nome-file" id="cr-nome-<?= $tipo ?>"></p>
                            </div>
                            <button type="submit" class="cr-btn-importa <?= $tipo ?>">
                                <span class="dashicons dashicons-database-import"></span>
                                Importa <?= $tipo === 'motori' ? 'motori' : 'componenti' ?>
                            </button>
                        </form>

                        <div class="cr-indice-azioni">
                            <?php if ($ix['csv']): ?>
                                <form method="post" class="cr-upload-form" data-conferma="Reimportare il CSV salvato? La versione attuale resterà disponibile come precedente.">
                                    <?php wp_nonce_field('cr_importa_nonce'); ?>
                                    <input type="hidden" name="cr_import_action" value="reimporta">
                                    <input type="hidden" name="cr_tipo" value="<?= $tipo ?>">
                                    <button type="submit" class="cr-btn-secondario piccolo"><span class="dashicons dashicons-update"></span> Reimporta il CSV salvato</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($ix['stato']['precedente'] !== null && $ix['prec_righe']): ?>
                                <form method="post" class="cr-upload-form" data-conferma="Rimettere online la versione precedente dell'indice (<?= esc_attr(number_format_i18n($ix['prec_righe'])) ?> righe)? Quella attuale diventerà la precedente, quindi potrai tornare indietro.">
                                    <?php wp_nonce_field('cr_importa_nonce'); ?>
                                    <input type="hidden" name="cr_import_action" value="ripristina">
                                    <input type="hidden" name="cr_tipo" value="<?= $tipo ?>">
                                    <button type="submit" class="cr-btn-secondario piccolo"><span class="dashicons dashicons-backup"></span> Ripristina versione precedente</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ================================================================ -->
        <!-- IMPOSTAZIONI DELLA RICERCA                                       -->
        <!-- ================================================================ -->
        <div class="cr-section-title-bar" style="margin-top:40px" id="cr-sezione-impostazioni">
            <span class="dashicons dashicons-admin-settings"></span>
            <h2>Impostazioni della ricerca</h2>
        </div>
        <div class="cr-admin-panel cr-panel-lista">
            <div class="cr-panel-body">
                <form method="post" class="cr-upload-form">
                    <?php wp_nonce_field('cr_impostazioni_nonce'); ?>
                    <input type="hidden" name="cr_impostazioni_action" value="salva">
                    <div class="cr-form-field">
                        <label for="cr-prefisso">Prefisso dei codici interni</label>
                        <input type="text" id="cr-prefisso" name="cr_prefisso" value="<?= esc_attr(cr_prefisso()) ?>" maxlength="10" style="max-width:160px" placeholder="es. FIN">
                        <span class="cr-field-hint">
                            I codici che iniziano con questo prefisso sono riconosciuti come codici dell'azienda (es. <code>FIN147PC00</code>: il prefisso seguito da almeno 3 cifre); gli altri vengono cercati tra tutti i codici.
                            Lascia vuoto se l'azienda non usa un prefisso: si cerca sempre tra tutti i codici. Solo lettere e cifre.
                            Nel CSV la colonna dei codici interni resta <code>FIN_CODE</code>.
                        </span>
                    </div>
                    <button type="submit" class="cr-btn-importa">
                        <span class="dashicons dashicons-saved"></span>
                        Salva impostazioni
                    </button>
                </form>
            </div>
        </div>

        <div class="cr-admin-info">
            <h3>Note operative</h3>
            <ul>
                <li>I PDF sono salvati in <code><?= esc_html(CR_PDF_DIR) ?></code> con il loro nome originale; aggiornare il plugin non li cancella.</li>
                <li>I CSV sono salvati in <code><?= esc_html(CR_DATA_DIR) ?></code> (versione attuale e precedente).</li>
                <li>Un import interrotto o non riuscito non modifica i dati online: viene segnalato in cima a questa pagina e nel resto della bacheca.</li>
                <li>Le immagini del carosello restano nella Libreria media: rimuoverle dal carosello non le elimina dal sito.</li>
                <li>Shortcode da inserire nelle pagine: <code>[cataloghi_ricerca]</code></li>
            </ul>
        </div>

        <!-- PDF presenti, per il controllo dei duplicati prima dell'upload (dopo l'eventuale azione appena eseguita) -->
        <script>
            window.CR_ADMIN_PDF = <?= wp_json_encode(array_map(fn($c) => [
                'file' => $c['file'], 'size' => cr_format_bytes($c['size']), 'data' => cr_format_data($c['modified']),
            ], $cataloghi)) ?>;
        </script>

        <!-- Conferma sovrascrittura PDF -->
        <dialog class="cr-dialog" id="cr-dialog-sovrascrivi">
            <h3>Questo catalogo esiste già</h3>
            <p>Nella cartella c'è già <strong id="cr-dialog-file"></strong> <span id="cr-dialog-info"></span>.</p>
            <p>Vuoi sostituirlo con il file che stai caricando? I risultati di ricerca apriranno la nuova versione.</p>
            <div class="cr-dialog-azioni">
                <button type="button" class="cr-btn-secondario" id="cr-dialog-annulla">Annulla</button>
                <button type="button" class="cr-btn-importa" id="cr-dialog-conferma">Sovrascrivi</button>
            </div>
        </dialog>

    </div><!-- .wrap -->
    <?php
}

// =============================================================================
// CAROSELLO — salvataggio
// =============================================================================

function cr_gestisci_carosello() {
    if (!current_user_can('manage_options')) return ['error' => 'Accesso negato.'];
    if (!check_admin_referer('cr_carosello_nonce')) return ['error' => 'Errore di sicurezza.'];

    $ids = array_filter(array_map('absint', explode(',', (string) ($_POST['cr_carosello_ids'] ?? ''))));
    $ids = array_values(array_unique(array_filter($ids, 'wp_attachment_is_image')));

    $direzione = sanitize_key($_POST['cr_carosello_direzione'] ?? 'dx');
    $velocita  = sanitize_key($_POST['cr_carosello_velocita'] ?? 'lenta');

    update_option(CR_OPTION_CAROSELLO, [
        'ids'       => $ids,
        'direzione' => in_array($direzione, ['dx', 'sx'], true) ? $direzione : 'dx',
        'velocita'  => in_array($velocita, ['lenta', 'media', 'veloce'], true) ? $velocita : 'lenta',
    ]);

    return ['success' => true, 'azione' => 'carosello', 'totale' => count($ids)];
}

// =============================================================================
// IMPOSTAZIONI DELLA RICERCA — salvataggio
// =============================================================================

function cr_gestisci_impostazioni() {
    if (!current_user_can('manage_options')) return ['error' => 'Accesso negato.'];
    if (!check_admin_referer('cr_impostazioni_nonce')) return ['error' => 'Errore di sicurezza.'];

    $prefisso = cr_normalizza_prefisso(wp_unslash((string) ($_POST['cr_prefisso'] ?? '')));
    update_option(CR_OPTION_PREFISSO, $prefisso);

    return ['success' => true, 'azione' => 'impostazioni', 'prefisso' => $prefisso];
}
