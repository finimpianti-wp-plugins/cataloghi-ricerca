/* ============================================================
   Cataloghi Ricerca — pannello "Stile e testi"
   Campi colore/carattere con riferimenti agli stili globali,
   anteprima dal vivo della pagina (desktop / tablet / mobile),
   esporta / importa.
   ============================================================ */

(function () {
    'use strict';

    const D      = window.CR_STILE;
    const form   = document.getElementById('cr-stile-form');
    const iframe = document.getElementById('cr-stile-iframe');
    const cornice = document.getElementById('cr-stile-cornice');
    const stato  = document.getElementById('cr-stile-stato');
    if (!D || !form || !iframe) return;

    let larghezza = 1366;
    let modificato = false;
    let timer = null;
    let daRicaricare = false;

    // ------------------------------------------------------------------
    // Variabili CSS nel pannello: servono a mostrare i campioni dei colori
    // che si riferiscono ad altri colori (globali, del plugin)
    // ------------------------------------------------------------------
    const varGlobali = {};
    D.globali.colori.forEach(([, , v, valore]) => { if (valore) varGlobali[v] = valore; });

    function aggiornaVariabili() {
        Object.entries(varGlobali).forEach(([v, val]) => form.style.setProperty(v, val));
        form.querySelectorAll('.cr-tipo-colore input[type="text"]').forEach(inp => {
            const chiave = inp.closest('.cr-stile-campo').dataset.chiave;
            form.style.setProperty('--cr-' + chiave.replace(/_/g, '-'), inp.value || 'transparent');
        });
        form.style.setProperty('--cr-tinta', 'color-mix(in srgb, var(--cr-colore-accento) 22%, transparent)');
    }

    // Descrizione del riferimento, es. "Globale Elementor: Primary"
    function descriviRiferimento(valore, elenco) {
        const m = /^var\(\s*(--[\w-]+)/.exec(valore || '');
        if (!m) return '';
        const voce = elenco.find(x => x[2] === m[1]);
        if (!voce) return 'Variabile ' + m[1] + ' (non trovata in questo sito: si usa il valore di riserva)';
        return (voce[0] === 'Plugin' ? 'Collegato a: ' : 'Globale ' + voce[0] + ': ') + voce[1];
    }

    function aggiornaCampo(campo) {
        // solo i campi colore e carattere hanno campione e riferimento
        if (!campo.classList.contains('cr-tipo-colore') && !campo.classList.contains('cr-tipo-font')) return;
        const inp = campo.querySelector('input[type="text"]');
        const rif = campo.querySelector('.cr-stile-riferimento');
        if (campo.classList.contains('cr-tipo-colore')) {
            campo.querySelector('.cr-campione span').style.background = inp.value;
            rif.textContent = descriviRiferimento(inp.value, D.palette.concat(D.globali.colori));
        } else {
            rif.textContent = inp.value === 'inherit' || inp.value === '' ? 'Font del sito (eredita dalla pagina)' : descriviRiferimento(inp.value, D.globali.font);
        }
    }

    // ------------------------------------------------------------------
    // Menu "Globali"
    // ------------------------------------------------------------------
    let menu = null;
    function chiudiMenu() { if (menu) { menu.remove(); menu = null; } }

    function apriMenu(btn) {
        chiudiMenu();
        const campo  = btn.closest('.cr-stile-campo');
        const inp    = campo.querySelector('input[type="text"]');
        const colore = campo.classList.contains('cr-tipo-colore');
        const chiave = campo.dataset.chiave;

        let voci;
        if (colore) {
            const propria = '--cr-' + chiave.replace(/_/g, '-');
            voci = D.palette.filter(p => p[2] !== propria).map(p => ({ gruppo: 'Colori del plugin', nome: p[1], valore: 'var(' + p[2] + ')', anteprima: 'var(' + p[2] + ')' }))
                .concat(D.globali.colori.map(c => ({ gruppo: 'Globali ' + c[0], nome: c[1], valore: 'var(' + c[2] + (c[3] ? ', ' + c[3] : '') + ')', anteprima: c[3] })));
        } else {
            voci = [{ gruppo: 'Sito', nome: 'Font del sito (eredita dalla pagina)', valore: 'inherit' }]
                .concat(D.globali.font.map(f => ({ gruppo: 'Globali ' + f[0], nome: f[1] + (f[3] ? ' — ' + f[3] : ''), valore: 'var(' + f[2] + ', inherit)' })));
        }

        menu = document.createElement('div');
        menu.className = 'cr-globali-menu';
        menu.setAttribute('role', 'listbox');
        let gruppo = '';
        voci.forEach(v => {
            if (v.gruppo !== gruppo) {
                gruppo = v.gruppo;
                const h = document.createElement('div');
                h.className = 'cr-globali-gruppo';
                h.textContent = gruppo;
                menu.appendChild(h);
            }
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'cr-globali-voce' + (inp.value === v.valore ? ' scelta' : '');
            if (colore) {
                const sw = document.createElement('span');
                sw.className = 'cr-globali-sw';
                sw.style.background = v.anteprima || 'transparent';
                b.appendChild(sw);
            }
            b.appendChild(document.createTextNode(v.nome));
            b.addEventListener('click', () => {
                inp.value = v.valore;
                inp.dispatchEvent(new Event('input', { bubbles: true }));
                chiudiMenu();
                inp.focus();
            });
            menu.appendChild(b);
        });
        if (!voci.length) {
            menu.innerHTML = '<p class="cr-globali-vuoto">Nessuno stile globale trovato in questo sito.</p>';
        }
        // il menu sta dentro il form: i campioni dei colori del plugin si risolvono con le sue variabili
        campo.appendChild(menu);
    }

    document.addEventListener('click', e => {
        const btn = e.target.closest('.cr-globali-btn');
        if (btn) { e.preventDefault(); apriMenu(btn); return; }
        if (menu && !e.target.closest('.cr-globali-menu')) chiudiMenu();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') chiudiMenu(); });

    // "Usa gli stili globali del sito": collega i colori e i font principali ai globali di Elementor
    const btnGlobali = document.getElementById('cr-stile-globali');
    if (btnGlobali) btnGlobali.addEventListener('click', () => {
        const col = id => D.globali.colori.find(c => c[0] === 'Elementor' && c[2] === '--e-global-color-' + id);
        const fnt = id => D.globali.font.find(f => f[0] === 'Elementor' && f[2] === '--e-global-typography-' + id + '-font-family');
        const mappa = [
            ['colore_principale', col('primary')], ['colore_accento', col('accent')], ['colore_testo', col('text')],
            ['font_base', fnt('text')], ['font_titoli', fnt('primary')], ['font_nav', fnt('accent')],
        ].filter(([, g]) => g);
        if (!mappa.length) {
            window.alert('In questo sito non ci sono colori e font globali di Elementor da collegare. Puoi comunque scegliere i colori del tema campo per campo con il pulsante "Globali".');
            return;
        }
        if (!window.confirm('Collegare il plugin agli stili globali di Elementor?\n\n'
            + 'Colore principale → Primary, Colore di accento → Accent, Testo secondario → Text\n'
            + 'Carattere dei testi → Text, dei titoli → Primary, dei pulsanti → Accent\n\n'
            + 'Gli altri colori del plugin seguono questi. Puoi sempre correggere i singoli campi.')) return;
        mappa.forEach(([chiave, g]) => {
            const inp = form.querySelector('[name="cr_stile[' + chiave + ']"]');
            inp.value = chiave.indexOf('font_') === 0 ? 'var(' + g[2] + ', inherit)' : 'var(' + g[2] + (g[3] ? ', ' + g[3] : '') + ')';
            aggiornaCampo(inp.closest('.cr-stile-campo'));
        });
        segnaModifica(false);
    });

    // ------------------------------------------------------------------
    // Campi: cursori, colori nativi
    // ------------------------------------------------------------------
    form.addEventListener('input', e => {
        const t = e.target;
        const campo = t.closest('.cr-stile-campo');
        if (t.type === 'range') {
            campo.querySelector('.cr-stile-numero input[type="number"]').value = t.value;
        } else if (t.type === 'number' && t.closest('.cr-stile-numero')) {
            const r = campo.querySelector('input[type="range"]');
            if (r) r.value = t.value;
        } else if (t.type === 'color') {
            campo.querySelector('input[type="text"]').value = t.value;
        }
        if (campo) aggiornaCampo(campo);
        if (campo && campo.classList.contains('cr-tipo-colore')) aggiornaVariabili();
        segnaModifica(!!(t.dataset.ricarica || (campo && campo.querySelector('[data-ricarica]') === t)));
    });
    form.addEventListener('change', e => {
        if (e.target.type === 'checkbox' || e.target.tagName === 'SELECT') segnaModifica(!!e.target.dataset.ricarica);
    });

    // ------------------------------------------------------------------
    // Anteprima
    // ------------------------------------------------------------------
    function origine(url) { try { return new URL(url, location.href).origin; } catch (e) { return location.origin; } }

    function scala() {
        const W = cornice.clientWidth;
        const k = Math.min(1, W / larghezza);
        iframe.style.width  = larghezza + 'px';
        iframe.style.height = (cornice.clientHeight / k) + 'px';
        iframe.style.transform = 'scale(' + k + ')';
        iframe.style.marginLeft = k < 1 ? '0' : Math.round((W - larghezza) / 2) + 'px';
    }
    window.addEventListener('resize', scala);

    document.querySelectorAll('.cr-stile-dispositivi button').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll('.cr-stile-dispositivi button').forEach(x => x.classList.toggle('attivo', x === b));
            larghezza = +b.dataset.larghezza;
            scala();
        });
    });

    const selPagina = document.getElementById('cr-stile-pagina');
    const linkApri  = document.getElementById('cr-stile-apri');
    function caricaAnteprima() {
        iframe.src = selPagina.value;
        linkApri.href = selPagina.value;
    }
    selPagina.addEventListener('change', caricaAnteprima);

    function invia(msg) {
        try { iframe.contentWindow.postMessage(msg, origine(iframe.src)); } catch (e) { /* anteprima non pronta */ }
    }

    const esempi = document.getElementById('cr-stile-esempi');
    const vai    = document.getElementById('cr-stile-vai');
    esempi.addEventListener('change', () => { if (esempi.checked) invia({ cr: 'esempi' }); else iframe.contentWindow.location.reload(); });
    vai.addEventListener('change', () => { if (vai.value) invia({ cr: 'vai', id: vai.value }); });

    // L'anteprima avvisa quando è pronta: si ripetono i comandi attivi
    window.addEventListener('message', e => {
        if (!e.data || e.data.cr !== 'pronta' || e.source !== iframe.contentWindow) return;
        if (esempi.checked) invia({ cr: 'esempi' });
        if (vai.value) setTimeout(() => invia({ cr: 'vai', id: vai.value }), 300);
    });

    // Bozza: le impostazioni non salvate vengono inviate al server, che le usa solo
    // per l'anteprima di questo utente, e ritorna il CSS da applicare subito
    function segnaModifica(ricarica) {
        modificato = true;
        daRicaricare = daRicaricare || ricarica;
        stato.textContent = 'Modifiche non salvate';
        stato.className = 'cr-stile-stato da-salvare';
        clearTimeout(timer);
        timer = setTimeout(inviaBozza, 300);
    }

    function inviaBozza(primoCaricamento) {
        const dati = new FormData(form);
        dati.append('action', 'cr_stile_bozza');
        dati.append('nonce', D.nonce);
        return fetch(D.ajax, { method: 'POST', body: dati, credentials: 'same-origin' })
            .then(r => r.json())
            .then(r => {
                if (!r.success) throw new Error(r.data || 'errore');
                if (primoCaricamento) return;
                if (daRicaricare) { daRicaricare = false; iframe.contentWindow.location.reload(); }
                else invia({ cr: 'css', css: r.data.css });
            })
            .catch(() => {
                stato.textContent = 'Anteprima non aggiornata (sessione scaduta? ricarica la pagina)';
                stato.className = 'cr-stile-stato errore';
            });
    }

    // ------------------------------------------------------------------
    // Esporta / importa
    // ------------------------------------------------------------------
    function valoriForm() {
        const out = {};
        new FormData(form).forEach((v, k) => {
            const m = /^cr_stile\[(.+)\]$/.exec(k);
            if (m) out[m[1]] = v; // le caselle di spunta inviano 0 e poi 1: vince l'ultimo
        });
        return out;
    }

    document.getElementById('cr-stile-esporta').addEventListener('click', () => {
        const json = JSON.stringify({ plugin: 'cataloghi-ricerca', versione: D.versione, esportato: new Date().toISOString(), stile: valoriForm() }, null, 2);
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([json], { type: 'application/json' }));
        a.download = 'cataloghi-stile-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    });

    document.getElementById('cr-stile-file').addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        const lettore = new FileReader();
        lettore.onload = () => {
            let dati;
            try { dati = JSON.parse(lettore.result); } catch (err) { window.alert('Il file non è uno stile valido.'); return; }
            const stile = dati && dati.stile ? dati.stile : dati;
            let n = 0;
            Object.entries(stile).forEach(([k, v]) => {
                form.querySelectorAll('[name="cr_stile[' + k + ']"]').forEach(el => {
                    if (el.type === 'hidden') return;
                    if (el.type === 'checkbox') el.checked = String(v) === '1';
                    else el.value = v;
                    n++;
                });
            });
            form.querySelectorAll('.cr-stile-numero input[type="number"]').forEach(inp => {
                const r = inp.closest('.cr-stile-campo').querySelector('input[type="range"]');
                if (r) r.value = inp.value;
            });
            form.querySelectorAll('.cr-stile-campo').forEach(aggiornaCampo);
            aggiornaVariabili();
            segnaModifica(true);
            window.alert('Importate ' + n + ' impostazioni. Controlla l\'anteprima e premi "Salva lo stile" per applicarle al sito.');
            e.target.value = '';
        };
        lettore.readAsText(file);
    });

    // ------------------------------------------------------------------
    // Salvataggio e uscita
    // ------------------------------------------------------------------
    form.addEventListener('submit', () => { modificato = false; });
    window.addEventListener('beforeunload', e => {
        if (!modificato) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ------------------------------------------------------------------
    // Avvio: la bozza parte uguale allo stile salvato, poi si carica l'anteprima
    // ------------------------------------------------------------------
    aggiornaVariabili();
    form.querySelectorAll('.cr-stile-campo').forEach(aggiornaCampo);
    scala();
    inviaBozza(true).finally(caricaAnteprima);
})();
