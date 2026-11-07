/* ============================================================
   Cataloghi Ricerca Finimpianti — visualizzatore PDF
   Apre i cataloghi dentro il sito, alla pagina indicata dall'indice.
   Basato su PDF.js (assets/pdfjs), caricato solo alla prima apertura.
   ============================================================ */

(function () {
    'use strict';

    const MAX_DPR       = 2;          // nitidezza massima (schermi retina)
    const MAX_PIXEL     = 16e6;       // limite area canvas (Safari iOS)
    const MAX_RENDERIZZ = 10;         // pagine tenute in memoria contemporaneamente
    const ZOOM_MIN      = 0.25;
    const ZOOM_MAX      = 4;
    const ZOOM_AUTO_MAX = 1.5;        // all'apertura la pagina non viene ingrandita oltre il 150%

    // Testi traducibili (CR.t, passati da PHP); %s = valori in ordine
    function t(chiave) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        const testo = (window.CR && CR.t && CR.t[chiave]) || chiave;
        return testo.replace(/%s/g, () => (i < args.length ? args[i++] : '')).replace(/%%/g, '%');
    }

    let pdfjsPromise = null;
    let el = null;                    // riferimenti al DOM del visualizzatore
    let st = null;                    // stato del documento aperto

    // -------------------------------------------------------------------------
    // Indirizzi: se un URL punta allo stesso sito della pagina ma con http/https
    // o "www" diversi (indirizzo del sito impostato diversamente in WordPress),
    // viene riportato all'indirizzo della pagina. Altrimenti il browser
    // bloccherebbe il download (contenuto misto o richiesta da un altro sito).
    // -------------------------------------------------------------------------
    function stessaOrigine(url) {
        try {
            const u = new URL(url, location.href);
            const host = h => h.replace(/^www\./i, '').toLowerCase();
            if (u.origin !== location.origin && host(u.hostname) === host(location.hostname)) {
                return location.origin + u.pathname + u.search + u.hash;
            }
            return u.href;
        } catch (e) {
            return url;
        }
    }

    // -------------------------------------------------------------------------
    // Caricamento PDF.js
    // -------------------------------------------------------------------------
    function caricaPdfjs() {
        if (!pdfjsPromise) {
            const base = stessaOrigine(CR.pdfjs);
            pdfjsPromise = import(base + 'pdf.min.js').then(lib => {
                lib.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.js';
                return lib;
            });
            pdfjsPromise.catch(() => { pdfjsPromise = null; });
        }
        return pdfjsPromise;
    }

    // -------------------------------------------------------------------------
    // DOM
    // -------------------------------------------------------------------------
    function icona(path) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + path + '</svg>';
    }

    function creaDom() {
        const v = document.createElement('div');
        v.id = 'cr-viewer';
        v.hidden = true;
        v.setAttribute('role', 'dialog');
        v.setAttribute('aria-modal', 'true');
        v.setAttribute('aria-labelledby', 'crv-titolo');
        v.innerHTML = `
            <div class="crv-barra">
                <div class="crv-titolo" id="crv-titolo"></div>
                <div class="crv-gruppo crv-nav">
                    <button type="button" class="crv-btn" data-crv="prec" aria-label="${esc(t('v_prec'))}">${icona('<polyline points="15 18 9 12 15 6"/>')}</button>
                    <label class="crv-pagina">
                        <span class="crv-sr">${esc(t('v_pagina', '').trim())}</span>
                        <input type="text" inputmode="numeric" class="crv-input" aria-label="${esc(t('v_numero'))}">
                        <span class="crv-totale">/ –</span>
                    </label>
                    <button type="button" class="crv-btn" data-crv="succ" aria-label="${esc(t('v_succ'))}">${icona('<polyline points="9 18 15 12 9 6"/>')}</button>
                </div>
                <div class="crv-gruppo crv-zoom">
                    <button type="button" class="crv-btn" data-crv="zoom-meno" aria-label="${esc(t('v_riduci'))}">${icona('<line x1="5" y1="12" x2="19" y2="12"/>')}</button>
                    <button type="button" class="crv-btn crv-btn-testo" data-crv="adatta" aria-label="${esc(t('v_adatta'))}"><span class="crv-zoom-val">100%</span></button>
                    <button type="button" class="crv-btn" data-crv="zoom-piu" aria-label="${esc(t('v_ingrandisci'))}">${icona('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>')}</button>
                </div>
                <div class="crv-gruppo crv-azioni">
                    <a class="crv-btn" data-crv="esterno" target="_blank" rel="noopener" aria-label="${esc(t('v_esterno'))}" title="${esc(t('v_esterno_title'))}">${icona('<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>')}</a>
                    <button type="button" class="crv-btn crv-chiudi" data-crv="chiudi" aria-label="${esc(t('v_chiudi'))}">${icona('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>')}</button>
                </div>
            </div>
            <div class="crv-area" tabindex="-1">
                <div class="crv-pagine"></div>
                <div class="crv-stato" role="status"></div>
            </div>`;
        document.body.appendChild(v);

        el = {
            root:    v,
            titolo:  v.querySelector('.crv-titolo'),
            input:   v.querySelector('.crv-input'),
            totale:  v.querySelector('.crv-totale'),
            zoomVal: v.querySelector('.crv-zoom-val'),
            esterno: v.querySelector('[data-crv="esterno"]'),
            area:    v.querySelector('.crv-area'),
            pagine:  v.querySelector('.crv-pagine'),
            stato:   v.querySelector('.crv-stato'),
        };

        v.addEventListener('click', e => {
            const b = e.target.closest('[data-crv]');
            if (!b || !st) return;
            switch (b.dataset.crv) {
                case 'prec':      vaiA(st.corrente - 1); break;
                case 'succ':      vaiA(st.corrente + 1); break;
                case 'zoom-meno': impostaZoom(st.scala / 1.25); break;
                case 'zoom-piu':  impostaZoom(st.scala * 1.25); break;
                case 'adatta':    impostaZoom('larghezza'); break;
                case 'chiudi':    chiudi(); break;
            }
        });

        el.input.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                vaiA(parseInt(el.input.value, 10));
                el.area.focus({ preventScroll: true }); // il focus resta nel visualizzatore (Esc, frecce)
            }
        });
        el.input.addEventListener('blur', () => { if (st) el.input.value = st.corrente; });

        // Tasti ascoltati su tutto il documento: funzionano ovunque sia il focus
        document.addEventListener('keydown', e => {
            if (!st || el.root.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); chiudi(); return; }
            if (e.target === el.input) return;
            if (e.key === 'ArrowRight' || (e.key === 'PageDown' && e.ctrlKey)) { e.preventDefault(); vaiA(st.corrente + 1); }
            if (e.key === 'ArrowLeft'  || (e.key === 'PageUp' && e.ctrlKey))   { e.preventDefault(); vaiA(st.corrente - 1); }
            if (e.key === '+' || e.key === '=') { e.preventDefault(); impostaZoom(st.scala * 1.25); }
            if (e.key === '-')                  { e.preventDefault(); impostaZoom(st.scala / 1.25); }
            // Tab resta dentro il visualizzatore
            if (e.key === 'Tab') {
                const f = Array.from(v.querySelectorAll('button, a[href], input')).filter(x => x.offsetParent !== null);
                if (!f.length) return;
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
            }
        });

        let raf = null;
        el.area.addEventListener('scroll', () => {
            if (raf) return;
            raf = requestAnimationFrame(() => { raf = null; aggiornaPaginaCorrente(); });
        });

        let timerResize = null;
        window.addEventListener('resize', () => {
            if (!st || st.modo === 'manuale') return;
            clearTimeout(timerResize);
            timerResize = setTimeout(() => impostaZoom(st.modo), 150);
        });

        window.addEventListener('popstate', () => {
            if (st && st.storia) { st.storia = false; chiudi(); }
        });
    }

    function mostraStato(html) {
        el.stato.innerHTML = html || '';
        el.stato.hidden = !html;
    }

    // -------------------------------------------------------------------------
    // Apertura / chiusura
    // -------------------------------------------------------------------------
    function apri(opz) {
        if (!el) creaDom();
        if (st) chiudi(true);

        opz = Object.assign({}, opz, { url: stessaOrigine(opz.url) });
        const pagina = Math.max(1, parseInt(opz.pagina, 10) || 1);
        st = {
            url: opz.url, pagina: pagina, corrente: pagina, totale: 0,
            doc: null, task: null, scala: 1, modo: 'auto',
            pagine: [], renderizzate: new Map(), osservatore: null,
            focusPrima: document.activeElement, storia: false, chiuso: false,
        };

        el.titolo.textContent = opz.titolo || '';
        el.titolo.title       = opz.titolo || '';
        el.esterno.href       = opz.url + '#page=' + pagina;
        el.input.value        = pagina;
        el.totale.textContent = '/ –';
        el.pagine.innerHTML   = '';
        mostraStato('<span class="crv-spinner"></span><span>' + esc(t('v_apertura')) + '</span>');

        el.root.hidden = false;
        document.documentElement.classList.add('crv-aperto');
        el.root.querySelector('.crv-chiudi').focus();

        // Il tasto "indietro" del telefono chiude il visualizzatore invece di lasciare la pagina
        try { history.pushState({ crViewer: true }, ''); st.storia = true; } catch (e) { /* ignorato */ }

        const corrente = st;
        const base     = stessaOrigine(CR.pdfjs);
        let fase       = 'caricamento del visualizzatore (pdf.min.js)';
        let libreria   = null;

        const avvia = sorgente => {
            corrente.task = libreria.getDocument(Object.assign({
                cMapUrl: base + 'cmaps/',
                cMapPacked: true,
                standardFontDataUrl: base + 'standard_fonts/',
                isEvalSupported: false,
            }, sorgente));
            corrente.task.onProgress = p => {
                if (corrente.chiuso || corrente.doc || !p.total) return;
                const perc = Math.min(100, Math.round(p.loaded / p.total * 100));
                mostraStato('<span class="crv-spinner"></span><span>' + esc(t('v_caricamento', perc)) + '</span>');
            };
            return corrente.task.promise;
        };

        caricaPdfjs()
            .then(lib => {
                if (corrente.chiuso) return;
                libreria = lib;
                fase = 'apertura del PDF';
                // scarica solo le parti del PDF che servono (richieste Range)
                return avvia({ url: opz.url, disableAutoFetch: true }).catch(err => {
                    if (corrente.chiuso) throw err;
                    // Secondo tentativo: scarica il file intero e lo passa a PDF.js
                    // (alcuni server o plugin gestiscono male le richieste parziali)
                    console.warn('[cataloghi] apertura parziale non riuscita, scarico il file intero', err);
                    fase = 'download del PDF intero';
                    return fetch(opz.url, { credentials: 'same-origin' })
                        .then(r => {
                            if (!r.ok) throw new Error('il server ha risposto ' + r.status + ' ' + r.statusText);
                            return r.arrayBuffer();
                        })
                        .then(buf => { fase = 'lettura del PDF'; return avvia({ data: new Uint8Array(buf) }); });
                });
            })
            .then(doc => {
                if (!doc || corrente.chiuso) return;
                corrente.doc    = doc;
                corrente.totale = doc.numPages;
                corrente.pagina = Math.min(corrente.pagina, doc.numPages);
                return doc.getPage(corrente.pagina).then(p => {
                    if (corrente.chiuso) return;
                    corrente.base = p.getViewport({ scale: 1 });
                    preparaPagine();
                });
            })
            .catch(err => {
                if (corrente.chiuso) return;
                console.error('[cataloghi] errore visualizzatore PDF', err);
                const dettaglio = fase + ': ' + ((err && (err.message || err.name)) || String(err))
                                + ' — ' + opz.url;
                mostraStato(
                    '<div class="crv-errore"><strong>' + esc(t('v_impossibile')) + '</strong>'
                    + '<span>' + esc(t('v_apri_diretto', pagina)) + '</span>'
                    + '<a class="crv-link" href="' + esc(opz.url + '#page=' + pagina) + '" target="_blank" rel="noopener">' + esc(t('v_apri_pdf')) + '</a>'
                    + '<details class="crv-dettaglio"><summary>' + esc(t('v_dettaglio')) + '</summary><code>' + esc(dettaglio) + '</code></details></div>'
                );
            });
    }

    function chiudi(senzaStoria) {
        if (!st) return;
        const s = st;
        s.chiuso = true;
        if (s.osservatore) s.osservatore.disconnect();
        s.renderizzate.forEach(r => r.task && r.task.cancel());
        if (s.task) s.task.destroy();
        st = null;

        el.root.hidden = true;
        el.pagine.innerHTML = '';
        document.documentElement.classList.remove('crv-aperto');
        if (s.storia && !senzaStoria) history.back();
        if (s.focusPrima && s.focusPrima.focus) s.focusPrima.focus({ preventScroll: true });
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Pagine: segnaposto per tutte, rendering solo di quelle vicine allo schermo
    // -------------------------------------------------------------------------
    function scalaLarghezza() {
        const disponibile = el.area.clientWidth - (window.innerWidth < 600 ? 16 : 48);
        return Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, disponibile / st.base.width));
    }

    // Scala iniziale: larghezza disponibile, ma senza ingrandire oltre ZOOM_AUTO_MAX
    // (su schermi larghi una pagina A4 a tutta larghezza sarebbe enorme)
    function scalaAuto() {
        return Math.min(scalaLarghezza(), ZOOM_AUTO_MAX);
    }

    function preparaPagine() {
        mostraStato('');
        el.totale.textContent = '/ ' + st.totale;
        st.scala = scalaAuto();
        aggiornaZoomUi();

        const frag = document.createDocumentFragment();
        for (let n = 1; n <= st.totale; n++) {
            const div = document.createElement('div');
            div.className = 'crv-pag';
            div.dataset.n = n;
            div.innerHTML = '<span class="crv-pag-num">' + n + '</span>';
            st.pagine[n] = { div: div, dim: null }; // dim: dimensioni reali a scala 1, note dopo il primo rendering
            frag.appendChild(div);
        }
        el.pagine.appendChild(frag);
        dimensionaTutte();

        st.osservatore = new IntersectionObserver(voci => {
            voci.forEach(v => { if (v.isIntersecting) renderizza(+v.target.dataset.n); });
        }, { root: el.area, rootMargin: '100% 0px' });
        st.pagine.forEach(p => p && st.osservatore.observe(p.div));

        vaiA(st.pagina, true);
    }

    function dimensiona(n) {
        const p   = st.pagine[n];
        const dim = p.dim || st.base;
        p.div.style.width  = Math.floor(dim.width * st.scala) + 'px';
        p.div.style.height = Math.floor(dim.height * st.scala) + 'px';
    }

    function dimensionaTutte() {
        for (let n = 1; n <= st.totale; n++) dimensiona(n);
    }

    function renderizza(n) {
        const s = st;
        if (!s || !s.doc || s.renderizzate.has(n)) return;
        const voce = { task: null, scala: s.scala };
        s.renderizzate.set(n, voce);

        s.doc.getPage(n).then(page => {
            if (s.chiuso || s.renderizzate.get(n) !== voce) return;
            const p = s.pagine[n];

            // Dimensioni reali della pagina: se diverse dalla prima, il segnaposto
            // si adatta senza spostare la pagina che l'utente sta guardando
            if (!p.dim) {
                const vp1  = page.getViewport({ scale: 1 });
                p.dim = { width: vp1.width, height: vp1.height };
                const prima = p.div.offsetHeight;
                dimensiona(n);
                const diff = p.div.offsetHeight - prima;
                if (diff && p.div.offsetTop < el.area.scrollTop) el.area.scrollTop += diff;
            }

            let dpr = Math.min(window.devicePixelRatio || 1, MAX_DPR);
            let vp  = page.getViewport({ scale: s.scala * dpr });
            if (vp.width * vp.height > MAX_PIXEL) {
                dpr *= Math.sqrt(MAX_PIXEL / (vp.width * vp.height));
                vp   = page.getViewport({ scale: s.scala * dpr });
            }

            const canvas = document.createElement('canvas');
            canvas.width  = Math.floor(vp.width);
            canvas.height = Math.floor(vp.height);
            canvas.setAttribute('aria-label', t('v_pagina', n));

            voce.task = page.render({ canvasContext: canvas.getContext('2d', { alpha: false }), viewport: vp });
            return voce.task.promise.then(() => {
                if (s.chiuso || s.renderizzate.get(n) !== voce) return;
                const vecchio = p.div.querySelector('canvas');
                if (vecchio) vecchio.remove();
                p.div.insertBefore(canvas, p.div.firstChild);
                p.div.classList.add('crv-pronta');
                liberaMemoria();
            });
        }).catch(err => {
            if (err && err.name === 'RenderingCancelledException') return;
            if (s.renderizzate.get(n) === voce) s.renderizzate.delete(n);
        });
    }

    // Rimuove i canvas delle pagine più lontane da quella corrente
    function liberaMemoria() {
        if (st.renderizzate.size <= MAX_RENDERIZZ) return;
        const ordinate = Array.from(st.renderizzate.keys())
            .sort((a, b) => Math.abs(b - st.corrente) - Math.abs(a - st.corrente));
        while (st.renderizzate.size > MAX_RENDERIZZ) {
            const n = ordinate.shift();
            const voce = st.renderizzate.get(n);
            if (voce.task) voce.task.cancel();
            st.renderizzate.delete(n);
            const c = st.pagine[n].div.querySelector('canvas');
            if (c) { c.width = c.height = 0; c.remove(); }
            st.pagine[n].div.classList.remove('crv-pronta');
        }
    }

    // -------------------------------------------------------------------------
    // Navigazione e zoom
    // -------------------------------------------------------------------------
    function vaiA(n, immediato) {
        if (!st || !st.totale || isNaN(n)) return;
        n = Math.max(1, Math.min(st.totale, n));
        st.corrente = n;
        el.input.value = n;
        el.area.scrollTop = st.pagine[n].div.offsetTop - 8;
        if (immediato) renderizza(n);
        aggiornaBottoni();
    }

    function aggiornaPaginaCorrente() {
        if (!st || !st.totale) return;
        // Pagina corrente = quella che attraversa una linea al 30% dell'area visibile
        const linea = el.area.scrollTop + el.area.clientHeight * 0.3;
        let n = st.corrente;
        while (n < st.totale && st.pagine[n + 1].div.offsetTop <= linea) n++;
        while (n > 1 && st.pagine[n].div.offsetTop > linea) n--;
        if (n !== st.corrente) {
            st.corrente = n;
            if (document.activeElement !== el.input) el.input.value = n;
            aggiornaBottoni();
        }
    }

    function aggiornaBottoni() {
        el.root.querySelector('[data-crv="prec"]').disabled = st.corrente <= 1;
        el.root.querySelector('[data-crv="succ"]').disabled = st.corrente >= st.totale;
    }

    function aggiornaZoomUi() {
        el.zoomVal.textContent = Math.round(st.scala * 100) + '%';
        el.root.querySelector('[data-crv="adatta"]').title = st.modo === 'larghezza' ? t('v_adattato') : t('v_adatta');
    }

    function impostaZoom(valore) {
        if (!st || !st.base) return;
        const nuova = valore === 'larghezza' ? scalaLarghezza()
                    : valore === 'auto'      ? scalaAuto()
                    : Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, valore));
        st.modo = typeof valore === 'string' ? valore : 'manuale';
        if (Math.abs(nuova - st.scala) < 0.001) { aggiornaZoomUi(); return; }

        // Mantiene la stessa porzione di pagina in vista dopo lo zoom
        const n      = st.corrente;
        const div    = st.pagine[n].div;
        const ratio  = (el.area.scrollTop - div.offsetTop) / Math.max(1, div.offsetHeight);

        st.scala = nuova;
        aggiornaZoomUi();
        st.renderizzate.forEach((voce, k) => {
            if (voce.task) voce.task.cancel();
            const c = st.pagine[k].div.querySelector('canvas');
            if (c) c.style.opacity = '.4'; // il vecchio rendering resta visibile finché arriva il nuovo
        });
        st.renderizzate.clear();
        dimensionaTutte();
        el.area.scrollTop = div.offsetTop + ratio * div.offsetHeight;

        // Ridisegna le pagine visibili alla nuova scala
        st.osservatore.disconnect();
        st.pagine.forEach(p => p && st.osservatore.observe(p.div));
    }

    // -------------------------------------------------------------------------
    // Link che aprono il visualizzatore: a[data-cr-pdf]
    // Ctrl/Cmd/Shift + click o click centrale mantengono il comportamento normale
    // (nuova scheda con #page=N).
    // -------------------------------------------------------------------------
    document.addEventListener('click', e => {
        const a = e.target.closest && e.target.closest('a[data-cr-pdf]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (typeof IntersectionObserver === 'undefined') return; // browser molto vecchi: apertura normale
        e.preventDefault();
        apri({ url: a.dataset.crPdf, pagina: a.dataset.crPagina, titolo: a.dataset.crTitolo });
    });

    window.CRViewer = { apri: apri, chiudi: chiudi };
})();
