/* ============================================================
   Cataloghi Ricerca — pannello admin
   ============================================================ */

(function () {

    // Nome del file scelto, sotto l'area di upload
    function mostraNome(input) {
        const el = document.getElementById(input.dataset.nome);
        if (el) el.textContent = input.files.length ? '📄 ' + input.files[0].name : '';
    }

    document.querySelectorAll('.cr-file-area input[type="file"]').forEach(input => {
        input.addEventListener('change', () => mostraNome(input));
    });

    // Drag & drop
    document.querySelectorAll('.cr-file-area').forEach(area => {
        const input = area.querySelector('input[type="file"]');
        area.addEventListener('dragover', e => {
            e.preventDefault();
            area.classList.add('cr-drag');
        });
        area.addEventListener('dragleave', () => area.classList.remove('cr-drag'));
        area.addEventListener('drop', e => {
            // Senza preventDefault il browser aprirebbe il file al posto di caricarlo nel form
            e.preventDefault();
            area.classList.remove('cr-drag');
            if (!input || !e.dataTransfer || !e.dataTransfer.files.length) return;
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        });
        area.addEventListener('click', e => {
            // Il click sul label apre già il selettore: evita la doppia apertura
            if (e.target.closest('label, input')) return;
            input.click();
        });
    });

    // Modifica etichetta inline
    document.addEventListener('click', e => {
        const mod = e.target.closest('[data-modifica]');
        const ann = e.target.closest('[data-annulla]');
        if (mod) {
            const h = mod.dataset.modifica;
            document.getElementById('cr-label-text-' + h).hidden = true;
            const form = document.getElementById('cr-label-form-' + h);
            form.hidden = false;
            form.querySelector('input[name="cr_pdf_label"]').focus();
        }
        if (ann) {
            const h = ann.dataset.annulla;
            document.getElementById('cr-label-form-' + h).hidden = true;
            document.getElementById('cr-label-text-' + h).hidden = false;
        }
    });

    // Bottone in stato "in corso" durante l'invio
    function inCorso(form) {
        const btn = form.querySelector('.cr-btn-importa, button[type="submit"]');
        if (!btn) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="dashicons dashicons-update cr-gira"></span> In corso...';
    }

    // Conferme generiche (elimina, ripristina, reimporta)
    document.querySelectorAll('form[data-conferma]').forEach(form => {
        form.addEventListener('submit', e => {
            if (!window.confirm(form.dataset.conferma)) e.preventDefault();
        });
    });

    document.querySelectorAll('.cr-upload-form, .cr-form-elimina').forEach(form => {
        form.addEventListener('submit', e => {
            if (!e.defaultPrevented) inCorso(form);
        });
    });

    // ------------------------------------------------------------------
    // Upload PDF: limite di dimensione e conferma sovrascrittura
    // ------------------------------------------------------------------
    const formPdf = document.getElementById('cr-form-upload-pdf');
    const dialog  = document.getElementById('cr-dialog-sovrascrivi');

    // Stessa pulizia del nome fatta dal server (cr_nome_pdf_sicuro)
    function nomeSicuro(nome) {
        return nome.replace(/[\x00-\x1F\x7F\/\\:*?"<>|]/g, '').replace(/\s+/g, ' ').trim().replace(/^[. ]+/, '');
    }

    if (formPdf) {
        // capture: questo controllo viene eseguito prima del feedback "In corso..."
        formPdf.addEventListener('submit', e => {
            const input = formPdf.querySelector('input[type="file"]');
            const file  = input && input.files[0];
            if (!file) return;

            if (window.CR_ADMIN && file.size > CR_ADMIN.max_upload) {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.alert('Il file pesa ' + (file.size / 1048576).toFixed(1) + ' MB e supera il limite del server (' + CR_ADMIN.max_upload_h + ').\n\nCaricalo via FTP nella cartella dei cataloghi e poi usa "Scansiona cartella".');
                return;
            }

            if (document.getElementById('cr-pdf-sovrascrivi').value === '1') return;

            const nome = nomeSicuro(file.name).toLowerCase();
            const esistente = (window.CR_ADMIN_PDF || []).find(p => p.file.toLowerCase() === nome);
            if (!esistente) return;

            e.preventDefault();
            e.stopImmediatePropagation();
            document.getElementById('cr-dialog-file').textContent = esistente.file;
            document.getElementById('cr-dialog-info').textContent = '(' + esistente.size + ', modificato il ' + esistente.data + ')';
            if (dialog && dialog.showModal) {
                dialog.showModal();
            } else if (window.confirm('Esiste già "' + esistente.file + '". Vuoi sovrascriverlo?')) {
                conferma();
            }
        }, true);

        function conferma() {
            document.getElementById('cr-pdf-sovrascrivi').value = '1';
            if (dialog && dialog.open) dialog.close();
            inCorso(formPdf);
            formPdf.submit();
        }

        if (dialog) {
            document.getElementById('cr-dialog-conferma').addEventListener('click', conferma);
            document.getElementById('cr-dialog-annulla').addEventListener('click', () => dialog.close());
        }
    }

    // ------------------------------------------------------------------
    // Carosello: selezione dalla Libreria media, ordinamento e rimozione
    // ------------------------------------------------------------------
    if (!window.jQuery) return;
    const $ = window.jQuery;
    const lista = $('#cr-carosello-lista');
    const ids   = $('#cr-carosello-ids');
    if (!lista.length) return;

    function sincronizza() {
        ids.val(lista.children('li').map((i, li) => $(li).attr('data-id')).get().join(','));
        $('#cr-carosello-vuoto').prop('hidden', lista.children('li').length > 0);
    }

    if ($.fn.sortable) lista.sortable({ items: 'li', tolerance: 'pointer', update: sincronizza });

    lista.on('click', '.cr-thumb-rimuovi', function () {
        $(this).closest('li').remove();
        sincronizza();
    });

    let frame;
    $('#cr-carosello-aggiungi').on('click', function (e) {
        e.preventDefault();
        if (!window.wp || !wp.media) return;
        if (!frame) {
            frame = wp.media({
                title: 'Immagini del carosello',
                button: { text: 'Aggiungi al carosello' },
                library: { type: 'image' },
                multiple: true
            });
            frame.on('select', function () {
                frame.state().get('selection').each(function (att) {
                    const a = att.toJSON();
                    if (lista.find('li[data-id="' + a.id + '"]').length) return;
                    const size = a.sizes && (a.sizes.thumbnail || a.sizes.medium);
                    $('<li>').attr('data-id', a.id).append(
                        $('<img>').attr({ src: size ? size.url : a.url, alt: '' }),
                        $('<button type="button" class="cr-thumb-rimuovi" title="Rimuovi">×</button>')
                    ).appendTo(lista);
                });
                sincronizza();
            });
        }
        frame.open();
    });
})();
