/* ============================================================
   Cataloghi Ricerca — pannello admin
   ============================================================ */

(function () {

    // Nome del file scelto, sotto l'area di upload
    function mostraNome(input) {
        const el = document.getElementById(input.dataset.nome);
        if (el) el.textContent = input.files.length > 1 ? '📄 ' + input.files.length + ' file scelti' : (input.files.length ? '📄 ' + input.files[0].name : '');
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
    // Caricamento dei PDF: uno o più file, elenco da controllare, invio
    // uno alla volta (ogni richiesta resta sotto i limiti del server e un
    // errore su un file non ferma gli altri)
    // ------------------------------------------------------------------
    const formPdf = document.getElementById('cr-form-upload-pdf');
    const inputPdf = document.getElementById('cr-pdf-input');
    const blocco = document.getElementById('cr-blocco');
    const C = window.CR_ADMIN || {};

    // Stessa pulizia del nome fatta dal server (cr_nome_pdf_sicuro)
    function nomeSicuro(nome) {
        return nome.replace(/[\x00-\x1F\x7F\/\\:*?"<>|]/g, '').replace(/\s+/g, ' ').trim().replace(/^[. ]+/, '');
    }
    function mb(byte) {
        return byte >= 1048576 ? (byte / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(byte / 1024)) + ' KB';
    }
    function el(tag, attrs, testo) {
        const e = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => { if (v !== null && v !== undefined) e.setAttribute(k, v); });
        if (testo !== undefined) e.textContent = testo;
        return e;
    }

    if (formPdf && inputPdf && blocco && C.ajax) {
        // Con JavaScript il modulo serve solo a scegliere i file: etichetta e lingua si scelgono nell'elenco
        formPdf.querySelectorAll('.cr-solo-senza-js').forEach(n => { n.hidden = true; });
        inputPdf.removeAttribute('required');

        const righeEl = document.getElementById('cr-blocco-righe');
        const btnCarica = document.getElementById('cr-blocco-carica');
        const btnAnnulla = document.getElementById('cr-blocco-annulla');
        const btnRicarica = document.getElementById('cr-blocco-ricarica');
        const linguaTutti = document.getElementById('cr-blocco-lingua-tutti');
        const lingue = C.lingue || [];
        const esistenti = window.CR_ADMIN_PDF || [];
        let righe = [];
        let inCaricamento = false;

        function selectLingua(valore) {
            const sel = el('select', { 'aria-label': 'Lingua' });
            sel.appendChild(el('option', { value: '' }, '—'));
            lingue.forEach(l => {
                const o = el('option', { value: l.codice }, l.nome);
                if (l.codice === valore) o.selected = true;
                sel.appendChild(o);
            });
            return sel;
        }

        function prepara(files) {
            if (inCaricamento) return;
            righe = [];
            righeEl.textContent = '';
            const visti = {};
            Array.from(files).forEach(file => {
                const nome = nomeSicuro(file.name);
                const chiave = nome.toLowerCase();
                const r = { file, nome, stato: 'pronto', messaggio: '' };
                if (!/\.pdf$/i.test(nome)) { r.stato = 'saltato'; r.messaggio = 'Non è un PDF'; }
                else if (visti[chiave]) { r.stato = 'saltato'; r.messaggio = 'Già nell\'elenco'; }
                else if (C.max_upload && file.size > C.max_upload) {
                    r.stato = 'saltato';
                    r.messaggio = 'Troppo grande per il caricamento (limite ' + C.max_upload_h + '): copialo via FTP in ' + C.cartella + ' e usa "Scansiona cartella"';
                }
                visti[chiave] = true;
                r.esistente = esistenti.find(p => p.file.toLowerCase() === chiave) || null;
                righe.push(r);

                const tr = el('tr', { 'data-stato': r.stato });
                const tdFile = el('td', { class: 'cr-cell-file' });
                tdFile.appendChild(el('span', { class: 'dashicons dashicons-pdf' }));
                tdFile.appendChild(document.createTextNode(' ' + nome));
                tr.appendChild(tdFile);

                const tdLabel = el('td', { class: 'cr-cell-label' });
                r.inputLabel = el('input', { type: 'text', class: 'cr-label-input', 'aria-label': 'Etichetta di ' + nome });
                r.inputLabel.value = r.esistente ? r.esistente.label : nome.replace(/\.pdf$/i, '');
                tdLabel.appendChild(r.inputLabel);
                tr.appendChild(tdLabel);

                if (lingue.length) {
                    const tdLingua = el('td', { class: 'cr-cell-lingua' });
                    r.selLingua = selectLingua(r.esistente ? r.esistente.lingua : (linguaTutti ? linguaTutti.value : ''));
                    tdLingua.appendChild(r.selLingua);
                    tr.appendChild(tdLingua);
                }
                tr.appendChild(el('td', { class: 'cr-cell-size' }, mb(file.size)));

                r.tdStato = el('td', { class: 'cr-cell-stato' });
                tr.appendChild(r.tdStato);
                righeEl.appendChild(tr);
                r.tr = tr;
                mostraStato(r);
            });
            blocco.hidden = false;
            btnRicarica.hidden = true;
            btnCarica.hidden = false;
            btnAnnulla.hidden = false;
            document.getElementById('cr-blocco-avanzamento').hidden = true;
            aggiornaRiepilogo();
            blocco.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function mostraStato(r) {
            r.tr.setAttribute('data-stato', r.stato);
            r.tdStato.textContent = '';
            if (r.stato === 'pronto' && r.esistente) {
                const lab = el('label', { class: 'cr-blocco-sostituisci' });
                r.chkSostituisci = r.chkSostituisci || el('input', { type: 'checkbox' });
                r.chkSostituisci.onchange = aggiornaRiepilogo;
                lab.appendChild(r.chkSostituisci);
                lab.appendChild(document.createTextNode(' Già presente: sostituisci'));
                r.tdStato.appendChild(lab);
                r.tdStato.appendChild(el('span', { class: 'cr-field-hint' }, 'attuale: ' + r.esistente.size + ', ' + r.esistente.data));
                return;
            }
            const testi = { pronto: 'Pronto', saltato: r.messaggio, attesa: 'In attesa', invio: r.messaggio || 'Caricamento…', ok: r.messaggio, errore: r.messaggio };
            r.tdStato.appendChild(el('span', { class: 'cr-blocco-stato ' + r.stato }, testi[r.stato] || ''));
        }

        function daCaricare() {
            return righe.filter(r => r.stato === 'pronto' && (!r.esistente || (r.chkSostituisci && r.chkSostituisci.checked)));
        }

        function aggiornaRiepilogo() {
            const n = daCaricare().length;
            const presenti = righe.filter(r => r.stato === 'pronto' && r.esistente && !(r.chkSostituisci && r.chkSostituisci.checked)).length;
            const saltati = righe.filter(r => r.stato === 'saltato').length;
            const parti = [righe.length + (righe.length === 1 ? ' file scelto' : ' file scelti')];
            if (presenti) parti.push(presenti === 1 ? '1 già presente (non viene toccato se non spunti "sostituisci")' : presenti + ' già presenti (non vengono toccati se non spunti "sostituisci")');
            if (saltati) parti.push(saltati + ' da saltare');
            document.getElementById('cr-blocco-riepilogo').textContent = parti.join(' — ') + '.';
            document.getElementById('cr-blocco-carica-testo').textContent = n === 1 ? 'Carica 1 catalogo' : 'Carica ' + n + ' cataloghi';
            btnCarica.disabled = n === 0;
        }

        if (linguaTutti) {
            linguaTutti.addEventListener('change', () => {
                righe.forEach(r => { if (r.selLingua && !r.selLingua.disabled) r.selLingua.value = linguaTutti.value; });
            });
        }

        inputPdf.addEventListener('change', () => { if (inputPdf.files.length) prepara(inputPdf.files); });
        formPdf.addEventListener('submit', e => {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (inputPdf.files.length) prepara(inputPdf.files);
        }, true);

        btnAnnulla.addEventListener('click', () => {
            if (inCaricamento) return;
            blocco.hidden = true;
            righe = [];
            inputPdf.value = '';
            const nomeEl = document.getElementById('cr-nome-pdf');
            if (nomeEl) nomeEl.textContent = '';
        });
        btnRicarica.addEventListener('click', () => { window.location.reload(); });

        window.addEventListener('beforeunload', e => {
            if (inCaricamento) { e.preventDefault(); e.returnValue = ''; }
        });

        function invia(r) {
            return new Promise(resolve => {
                const fd = new FormData();
                fd.append('action', 'cr_pdf_carica');
                fd.append('nonce', C.nonce);
                fd.append('file', r.file, r.nome);
                fd.append('label', r.inputLabel.value);
                if (r.selLingua) fd.append('lingua', r.selLingua.value);
                fd.append('sovrascrivi', r.esistente ? '1' : '0');
                const xhr = new XMLHttpRequest();
                xhr.open('POST', C.ajax);
                xhr.upload.onprogress = ev => {
                    if (!ev.lengthComputable) return;
                    r.messaggio = 'Caricamento ' + Math.round(ev.loaded / ev.total * 100) + '%';
                    mostraStato(r);
                };
                xhr.onload = () => {
                    let risposta = null;
                    try { risposta = JSON.parse(xhr.responseText); } catch (err) { risposta = null; }
                    if (risposta && risposta.success) {
                        r.stato = 'ok';
                        r.messaggio = risposta.data.azione === 'sovrascritto' ? 'Sostituito ✓' : 'Caricato ✓';
                    } else {
                        r.stato = 'errore';
                        r.messaggio = risposta && risposta.data && risposta.data.messaggio
                            ? risposta.data.messaggio
                            : (xhr.status === 413 ? 'Il server ha rifiutato il file perché troppo grande: copialo via FTP.' : 'Errore del server (' + xhr.status + '): riprova.');
                    }
                    mostraStato(r);
                    resolve();
                };
                xhr.onerror = () => {
                    r.stato = 'errore';
                    r.messaggio = 'Connessione interrotta: riprova.';
                    mostraStato(r);
                    resolve();
                };
                xhr.send(fd);
            });
        }

        btnCarica.addEventListener('click', async () => {
            const coda = daCaricare();
            if (!coda.length || inCaricamento) return;
            inCaricamento = true;
            btnCarica.disabled = true;
            btnAnnulla.hidden = true;
            righe.forEach(r => {
                if (r.inputLabel) r.inputLabel.disabled = true;
                if (r.selLingua) r.selLingua.disabled = true;
                if (r.chkSostituisci) r.chkSostituisci.disabled = true;
                if (r.stato === 'pronto' && !coda.includes(r)) { r.stato = 'saltato'; r.messaggio = 'Già presente: non toccato'; mostraStato(r); }
            });
            if (linguaTutti) linguaTutti.disabled = true;
            coda.forEach(r => { r.stato = 'attesa'; mostraStato(r); });
            const av = document.getElementById('cr-blocco-avanzamento');
            const barra = document.getElementById('cr-blocco-progresso');
            const testo = document.getElementById('cr-blocco-progresso-testo');
            av.hidden = false;
            for (let i = 0; i < coda.length; i++) {
                testo.textContent = 'Caricamento ' + (i + 1) + ' di ' + coda.length + ': ' + coda[i].nome + ' — non chiudere la pagina.';
                barra.style.width = Math.round(i / coda.length * 100) + '%';
                coda[i].stato = 'invio';
                coda[i].messaggio = '';
                mostraStato(coda[i]);
                await invia(coda[i]);
            }
            barra.style.width = '100%';
            const ok = coda.filter(r => r.stato === 'ok').length;
            const err = coda.length - ok;
            testo.textContent = 'Fatto: ' + ok + (ok === 1 ? ' catalogo caricato' : ' cataloghi caricati')
                + (err ? ', ' + err + (err === 1 ? ' errore' : ' errori') + ' (vedi la colonna Stato).' : '.');
            av.classList.toggle('con-errori', err > 0);
            inCaricamento = false;
            btnCarica.hidden = true;
            btnRicarica.hidden = false;
            inputPdf.value = '';
        });
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
