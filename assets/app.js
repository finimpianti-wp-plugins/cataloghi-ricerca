let crComponentiOffset = 0;
let crComponentiQuery  = '';
let crMotoriOffset     = 0;
let crMotoriQuery      = '';

// Costruttori selezionati nel pannello filtri
const crCostruttoriSel = new Set();

// Contatori di "generazione": ogni nuova ricerca li incrementa e le risposte
// arrivate in ritardo da ricerche precedenti vengono scartate.
let crGenMotori     = 0;
let crGenComponenti = 0;
let crTimerMotore   = null;

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('cr-app')) return;

    // memorizza subito i suggerimenti scritti nella pagina, prima che vengano sostituiti
    crHintIniziale('cr-input-hint');
    crHintIniziale('cr-motore-hint');

    crInitFullbleed();
    crInitNav();
    crInitCarosello();
    crInitFiltri();
    crCaricaCostruttori();

    // Ricerca componenti
    const inputCodice = document.getElementById('cr-input-codice');
    if (inputCodice) {
        let timer;
        inputCodice.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(() => crValidaECerca(this.value.trim()), 400);
        });
    }

    // Ricerca motore
    const inputMotore = document.getElementById('cr-input-motore');
    if (inputMotore) {
        inputMotore.addEventListener('input', function () {
            clearTimeout(crTimerMotore);
            crTimerMotore = setTimeout(crAggiornaMotori, 400);
        });
    }
});

// =============================================================================
// UTILITY
// =============================================================================

function crNomePdf(fileOrigine) {
    if (!fileOrigine) return '';
    return fileOrigine.replace(/\.pdf$/i, '').replace(/-/g, ' ');
}

// Etichetta del catalogo impostata dall'admin, altrimenti nome del file ripulito
function crNomeCatalogo(r) {
    return r.catalogo_label || crNomePdf(r.file_origine);
}

// Testi dell'interfaccia (traducibili, passati da PHP in CR.t); %s = valori in ordine
function crT(chiave) {
    const args = Array.prototype.slice.call(arguments, 1);
    let i = 0;
    const testo = (window.CR && CR.t && CR.t[chiave]) || chiave;
    return testo.replace(/%s/g, () => (i < args.length ? args[i++] : '')).replace(/%%/g, '%');
}

function crMessaggioErrore(err) {
    if (err && err.name === 'AbortError') {
        return '⏱ ' + crEsc(crT('timeout'));
    }
    if (err && err.message) return '⚠ ' + crEsc(err.message);
    return '⚠ ' + crEsc(crT('errore'));
}

function crEsc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Stessa normalizzazione del server (cr_normalizza): maiuscolo, senza accenti e separatori.
// "6500.095" → "6500095", "LDA 80" → "LDA80"
function crNormalizza(s) {
    return String(s === null || s === undefined ? '' : s)
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toUpperCase().replace(/[^A-Z0-9]/g, '');
}

// Escape + evidenzia (<mark>) la parte di testo che corrisponde a q, ignorando
// maiuscole e separatori: cercando "6500095" viene evidenziato "6500.095"
function crEvidenzia(testo, q) {
    testo = testo === null || testo === undefined ? '' : String(testo);
    const qn = crNormalizza(q);
    if (!qn) return crEsc(testo);

    let norm = '';
    const pos = []; // per ogni carattere di norm, l'indice nel testo originale
    for (let i = 0; i < testo.length; i++) {
        const c = crNormalizza(testo[i]);
        for (let k = 0; k < c.length; k++) { norm += c[k]; pos.push(i); }
    }
    const j = norm.indexOf(qn);
    if (j === -1) return crEsc(testo);
    const inizio = pos[j];
    const fine   = pos[j + qn.length - 1] + 1;
    return crEsc(testo.slice(0, inizio))
        + '<mark>' + crEsc(testo.slice(inizio, fine)) + '</mark>'
        + crEsc(testo.slice(fine));
}

// Costruisce l'URL REST. Con i permalink "semplici" rest_url contiene già
// "?rest_route=...", quindi i parametri vanno accodati con "&".
// params: oggetto oppure array di coppie [chiave, valore] (per parametri ripetuti)
function crUrl(path, params) {
    const coppie = Array.isArray(params) ? params.slice() : Object.entries(params || {});
    if (CR.lang) coppie.push(['lang', CR.lang]); // messaggi e cataloghi nella lingua della pagina
    const qs = new URLSearchParams(coppie).toString();
    const url = CR.rest_url + path;
    if (!qs) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + qs;
}

// fetch con timeout di 8s; rifiuta con l'oggetto errore del server se la risposta non è ok
function crFetchJson(url) {
    const controller = new AbortController();
    const timeout    = setTimeout(() => controller.abort(), 8000);

    return fetch(url, { signal: controller.signal })
        .then(r => r.json().then(
            data => r.ok ? data : Promise.reject(data),
            ()   => Promise.reject(new Error(crT('risposta')))
        ))
        .finally(() => clearTimeout(timeout));
}

function crIcona(nome) {
    const icone = {
        pdf: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>'
    };
    return '<svg class="cr-icona" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + icone[nome] + '</svg>';
}

function crSkeleton() {
    return '<div class="cr-skeleton" aria-label="' + crEsc(crT('caricamento')) + '"><span></span><span></span><span></span></div>';
}

// chiave: prefisso dei testi "_1" (singolare) e "_n" (plurale)
function crPlurale(n, chiave) {
    return crT(chiave + (n === 1 ? '_1' : '_n'), n);
}

// "4.0" → "4"
function crNumero(v) {
    return String(v).replace(/^(\d+)[.,]0+$/, '$1');
}

function crTagTecnici(r) {
    const tag = [];
    if (r.nr_cilindri) tag.push(crEsc(crT('cilindri', crNumero(r.nr_cilindri))));
    if (r.alesaggio)   tag.push('Ø ' + crEsc(r.alesaggio) + ' mm');
    if (r.corsa)       tag.push(crEsc(crT('corsa', r.corsa)));
    return tag.length ? '<div class="cr-ris-tag">' + tag.map(t => '<span>' + t + '</span>').join('') + '</div>' : '';
}

function crColonnaPdf(r) {
    if (!r.pdf_disponibile) {
        return `
        <div class="cr-ris-pdf">
            <span class="cr-ris-catalogo">${crIcona('pdf')}<span>${crEsc(crT('non_disponibile'))}</span></span>
            ${r.file_origine ? '<span class="cr-ris-pagina">' + crEsc(crNomePdf(r.file_origine)) + '</span>' : ''}
        </div>
        <span class="cr-ris-freccia" aria-hidden="true"></span>`;
    }
    const nome = crNomeCatalogo(r);
    return `
        <div class="cr-ris-pdf">
            ${nome ? '<span class="cr-ris-catalogo">' + crIcona('pdf') + '<span>' + crEsc(nome) + '</span></span>' : ''}
            <span class="cr-ris-pagina">${crEsc(r.pagina_pdf > 0 ? crT('pagina_n', r.pagina_pdf) : crT('pagina_assente'))}</span>
        </div>
        <span class="cr-ris-freccia" aria-hidden="true">→</span>`;
}

// Riga di risultato: link che apre il catalogo nel visualizzatore alla pagina giusta
// (href con #page=N resta valido per "apri in nuova scheda" e senza JavaScript).
// Se il PDF non è disponibile la riga non è cliccabile.
function crRigaRisultato(r, contenuto) {
    if (!r.pdf_disponibile) {
        return `<div class="cr-risultato cr-non-disponibile">${contenuto}</div>`;
    }
    return `
        <a class="cr-risultato" href="${crEsc(r.url_pdf)}" target="_blank" rel="noopener"
           data-cr-pdf="${crEsc(r.pdf_url)}" data-cr-pagina="${crEsc(Math.max(1, r.pagina_pdf || 1))}"
           data-cr-titolo="${crEsc(crNomeCatalogo(r))}">${contenuto}</a>`;
}

// Errore: in caricamento iniziale sostituisce i risultati; su "Carica altri"
// lo accoda e rimuove il bottone rimasto disabilitato
function crMostraErrore(el, err, append, btnId) {
    if (append) {
        const oldBtn = document.getElementById(btnId);
        if (oldBtn) oldBtn.remove();
        el.insertAdjacentHTML('beforeend', `<p class="cr-errore">${crMessaggioErrore(err)}</p>`);
    } else {
        el.innerHTML = `<p class="cr-errore">${crMessaggioErrore(err)}</p>`;
    }
}

function crBottoneAltri(el, id, rimanenti, onClick) {
    const btn = document.createElement('button');
    btn.type        = 'button';
    btn.id          = id;
    btn.className   = 'cr-btn-carica-altri';
    btn.textContent = crT('carica_altri', rimanenti);
    btn.onclick = () => {
        btn.disabled    = true;
        btn.textContent = crT('caricamento_puntini');
        onClick();
    };
    el.appendChild(btn);
}

function crImpostaHint(id, testo, stato) {
    const hint = document.getElementById(id);
    if (!hint) return;
    hint.textContent = testo;
    hint.className   = 'cr-input-hint' + (stato ? ' cr-hint-' + stato : '');
}

// =============================================================================
// LAYOUT: sezioni a tutta larghezza
// =============================================================================

// 100vw include la scrollbar verticale: ne misuro la larghezza per evitare
// lo scroll orizzontale delle sezioni a tutta larghezza
function crInitFullbleed() {
    const aggiorna = () => {
        const sbw = window.innerWidth - document.documentElement.clientWidth;
        document.getElementById('cr-app').style.setProperty('--cr-sbw', sbw + 'px');
    };
    aggiorna();
    window.addEventListener('resize', aggiorna);
}

// =============================================================================
// NAVIGAZIONE RAPIDA
// =============================================================================

function crInitNav() {
    document.querySelectorAll('#cr-app .cr-nav-btn').forEach(link => {
        link.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (!target) return;
            e.preventDefault();

            const ridotto = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target.scrollIntoView({ behavior: ridotto ? 'auto' : 'smooth', block: 'start' });

            // Su desktop il cursore va direttamente nella ricerca;
            // su touch no, per non aprire la tastiera all'improvviso
            const input = target.querySelector('input[type="search"]');
            if (input && window.matchMedia('(hover: hover)').matches) {
                input.focus({ preventScroll: true });
            }

            target.classList.remove('cr-evidenzia');
            void target.offsetWidth; // riavvia l'animazione
            target.classList.add('cr-evidenzia');
        });
    });
}

// =============================================================================
// CAROSELLO
// =============================================================================

function crInitCarosello() {
    const el = document.querySelector('#cr-app .cr-carosello');
    if (!el) return;
    const track  = el.querySelector('.cr-carosello-track');
    const gruppo = el.querySelector('.cr-carosello-gruppo');
    const imgs   = Array.from(gruppo.querySelectorAll('img'));
    if (!imgs.length) return;

    const pronte = imgs.map(img => img.complete ? Promise.resolve() : new Promise(r => {
        img.addEventListener('load',  r, { once: true });
        img.addEventListener('error', r, { once: true });
    }));

    Promise.all(pronte).then(() => {
        // Ripete le immagini finché un gruppo copre lo schermo più largo possibile,
        // poi duplica il gruppo: l'animazione scorre di metà traccia e riparte senza salti
        const minimo = Math.max(window.screen.width || 0, el.clientWidth);
        let giri = 0;
        while (gruppo.scrollWidth < minimo && giri++ < 20) {
            imgs.forEach(img => gruppo.appendChild(img.cloneNode(true)));
        }
        track.appendChild(gruppo.cloneNode(true));

        // Velocità costante in px/s, indipendente dal numero di immagini
        const pxs = { lenta: 30, media: 50, veloce: 80 }[el.dataset.velocita] || 30;
        track.style.setProperty('--cr-durata', Math.round(gruppo.scrollWidth / pxs) + 's');
        el.classList.add('cr-carosello-attivo');
    });
}

// =============================================================================
// FILTRI COSTRUTTORE
// =============================================================================

function crInitFiltri() {
    const toggle  = document.getElementById('cr-filtri-toggle');
    const chips   = document.getElementById('cr-filtri-chips');
    const attivi  = document.getElementById('cr-filtri-attivi');
    if (!toggle) return;

    toggle.addEventListener('click', () => crApriFiltri(toggle.getAttribute('aria-expanded') !== 'true'));
    document.getElementById('cr-filtri-chiudi').addEventListener('click', () => {
        crApriFiltri(false);
        toggle.focus();
    });
    document.getElementById('cr-filtri-azzera').addEventListener('click', () => {
        crCostruttoriSel.clear();
        crFiltriCambiati();
    });

    // Selezione costruttori (delegato: il nome viaggia in data-costruttore)
    chips.addEventListener('click', function (e) {
        const chip = e.target.closest('.cr-chip');
        if (!chip) return;
        const c = chip.dataset.costruttore;
        crCostruttoriSel.has(c) ? crCostruttoriSel.delete(c) : crCostruttoriSel.add(c);
        crFiltriCambiati();
    });

    // Rimozione dal riepilogo a pannello chiuso
    attivi.addEventListener('click', function (e) {
        const tag = e.target.closest('.cr-tag-attivo');
        if (!tag) return;
        crCostruttoriSel.delete(tag.dataset.costruttore);
        crFiltriCambiati();
    });

    // Esc chiude il pannello
    document.getElementById('cr-filtri-pannello').addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            crApriFiltri(false);
            toggle.focus();
        }
    });
}

function crApriFiltri(apri) {
    const toggle  = document.getElementById('cr-filtri-toggle');
    const pannello = document.getElementById('cr-filtri-pannello');
    toggle.setAttribute('aria-expanded', apri ? 'true' : 'false');
    pannello.classList.toggle('cr-aperto', apri);
    document.getElementById('cr-motori').classList.toggle('cr-filtri-aperti', apri);
}

function crFiltriCambiati() {
    crFiltriCambiatiSoloStato();

    // Contatore sul pulsante e "Azzera"
    const n = crCostruttoriSel.size;
    const conteggio = document.getElementById('cr-filtri-conteggio');
    conteggio.hidden      = n === 0;
    conteggio.textContent = n;
    document.getElementById('cr-filtri-azzera').disabled = n === 0;

    // Riepilogo filtri attivi
    document.getElementById('cr-filtri-attivi').innerHTML = Array.from(crCostruttoriSel).map(c =>
        `<button type="button" class="cr-tag-attivo" data-costruttore="${crEsc(c)}" aria-label="${crEsc(crT('rimuovi_filtro', c))}">${crEsc(c)}<span aria-hidden="true">×</span></button>`
    ).join('');

    clearTimeout(crTimerMotore);
    crTimerMotore = setTimeout(crAggiornaMotori, 250);
}

function crCaricaCostruttori() {
    const el = document.getElementById('cr-filtri-chips');
    if (!el) return;

    crFetchJson(crUrl('/costruttori'))
        .then(data => {
            if (!Array.isArray(data)) throw new Error();
            if (!data.length) { el.innerHTML = '<p class="cr-loading">' + crEsc(crT('nessun_costruttore')) + '</p>'; return; }
            el.innerHTML = data.map(c =>
                `<button type="button" class="cr-chip" data-costruttore="${crEsc(c)}" aria-pressed="false">${crEsc(c)}</button>`
            ).join('');
            crFiltriCambiatiSoloStato();
        })
        .catch(() => {
            el.innerHTML = '<p class="cr-errore">' + crEsc(crT('errore_costruttori')) + '</p>';
        });
}

// Allinea le chip appena create alla selezione corrente, senza rilanciare la ricerca
function crFiltriCambiatiSoloStato() {
    document.querySelectorAll('#cr-filtri-chips .cr-chip').forEach(chip => {
        chip.setAttribute('aria-pressed', crCostruttoriSel.has(chip.dataset.costruttore) ? 'true' : 'false');
    });
}

// =============================================================================
// RICERCA MOTORI (testo + filtri costruttore, paginata)
// =============================================================================

function crAggiornaMotori() {
    clearTimeout(crTimerMotore);
    const q         = document.getElementById('cr-input-motore').value.trim();
    const risultati = document.getElementById('cr-motori-risultati');
    const box       = document.getElementById('cr-input-motore').closest('.cr-searchbox');

    if (!q && !crCostruttoriSel.size) {
        crGenMotori++; // scarta eventuali risposte ancora in arrivo
        risultati.innerHTML = '';
        box.classList.remove('cr-input-error');
        crImpostaHint('cr-motore-hint', crHintIniziale('cr-motore-hint'));
        return;
    }
    if (q && q.length < 3) {
        crGenMotori++;
        box.classList.add('cr-input-error');
        crImpostaHint('cr-motore-hint', '⚠ ' + crT('min_3'), 'error');
        return;
    }

    box.classList.remove('cr-input-error');
    crImpostaHint('cr-motore-hint', '');
    crCercaMotori(q);
}

function crCercaMotori(q) {
    crMotoriQuery  = q;
    crMotoriOffset = 0;
    document.getElementById('cr-motori-risultati').innerHTML = crSkeleton();
    crFetchMotori(q, Array.from(crCostruttoriSel), 0, false, ++crGenMotori);
}

function crFetchMotori(q, costruttori, offset, append, gen) {
    const risultati = document.getElementById('cr-motori-risultati');

    const params = [['offset', offset]];
    if (q) params.push(['q', q]);
    costruttori.forEach(c => params.push(['costruttori[]', c]));

    crFetchJson(crUrl('/motori/cerca', params))
        .then(data => {
            if (gen !== crGenMotori) return;

            const oldBtn = document.getElementById('cr-carica-altri-motori');
            if (oldBtn) oldBtn.remove();

            if (!append) {
                if (!data.total) {
                    const qHtml = '<strong>' + crEsc(q) + '</strong>';
                    const vuoto = q ? (costruttori.length ? crT('nessun_motore_qc', qHtml) : crT('nessun_motore_q', qHtml))
                                    : (costruttori.length ? crT('nessun_motore_c') : crT('nessun_motore'));
                    risultati.innerHTML = `<p class="cr-vuoto">${vuoto}</p>`;
                    return;
                }
                risultati.innerHTML =
                    `<p class="cr-count"><strong>${crEsc(crPlurale(data.total, 'motori'))}</strong>${q ? ' ' + crEsc(crT('per_q', q)) : ''}${costruttori.length ? ' · ' + crEsc(crPlurale(costruttori.length, 'costruttori')) : ''}</p>`
                    + '<div class="cr-lista"></div>';
            }

            const lista = risultati.querySelector('.cr-lista');
            data.results.forEach(m => lista.insertAdjacentHTML('beforeend', crCardMotore(m, q)));

            crMotoriOffset = offset + data.results.length;

            if (crMotoriOffset < data.total) {
                crBottoneAltri(risultati, 'cr-carica-altri-motori', data.total - crMotoriOffset,
                    () => crFetchMotori(crMotoriQuery, costruttori, crMotoriOffset, true, gen));
            }
        })
        .catch(err => {
            if (gen !== crGenMotori) return;
            crMostraErrore(risultati, err, append, 'cr-carica-altri-motori');
        });
}

function crCardMotore(m, q) {
    return crRigaRisultato(m, `
            <div class="cr-ris-main">
                <div class="cr-ris-titolo">
                    ${m.costruttore ? '<span class="cr-ris-costruttore">' + crEvidenzia(m.costruttore, q) + '</span>' : ''}
                    <strong>${crEvidenzia(m.motore, q)}</strong>
                    ${m.versione ? '<span class="cr-ris-versione">' + crEsc(m.versione) + '</span>' : ''}
                </div>
                ${crTagTecnici(m)}
            </div>
            ${crColonnaPdf(m)}`);
}

// =============================================================================
// RICERCA COMPONENTI (FIN/OEM riconosciuto in automatico)
// =============================================================================

// Suggerimenti iniziali sotto i campi: sono quelli scritti nella pagina (personalizzabili
// dal pannello "Stile e testi"), memorizzati al primo uso per poterli ripristinare
const crHintSalvati = {};
function crHintIniziale(id) {
    if (!(id in crHintSalvati)) {
        const el = document.getElementById(id);
        crHintSalvati[id] = el ? el.textContent : '';
    }
    return crHintSalvati[id];
}

function crValidaECerca(q) {
    const el  = document.getElementById('cr-componenti-risultati');
    const box = document.getElementById('cr-input-codice').closest('.cr-searchbox');

    if (!q) {
        crGenComponenti++;
        el.innerHTML = '';
        box.classList.remove('cr-input-error');
        crImpostaHint('cr-input-hint', crHintIniziale('cr-input-hint'));
        return;
    }

    // Validazione sulla forma normalizzata, come fa il server
    const qn    = crNormalizza(q);
    const isFin = /^FIN/.test(qn);

    if (isFin && !/^FIN.*\d{3}/.test(qn)) {
        // Codice FIN ancora incompleto: indicazione neutra, non un errore
        crGenComponenti++;
        el.innerHTML = '';
        box.classList.remove('cr-input-error');
        crImpostaHint('cr-input-hint', crT('fin_incompleto'));
        return;
    }
    if (!isFin && qn.length < 4) {
        crGenComponenti++;
        el.innerHTML = '';
        box.classList.add('cr-input-error');
        crImpostaHint('cr-input-hint', '⚠ ' + crT('min_4'), 'error');
        return;
    }

    box.classList.remove('cr-input-error');
    crImpostaHint('cr-input-hint', isFin ? crT('fin_ok') : crT('oem_fin'), 'ok');
    crCercaComponente(q);
}

function crCercaComponente(q) {
    crComponentiOffset = 0;
    crComponentiQuery  = q;
    document.getElementById('cr-componenti-risultati').innerHTML = crSkeleton();
    crFetchComponenti(q, 0, false, ++crGenComponenti);
}

function crFetchComponenti(q, offset, append, gen) {
    const el = document.getElementById('cr-componenti-risultati');

    crFetchJson(crUrl('/componenti', { q: q, tipo: 'auto', offset: offset }))
        .then(data => {
            if (gen !== crGenComponenti) return;

            const oldBtn = document.getElementById('cr-carica-altri');
            if (oldBtn) oldBtn.remove();

            if (!append) {
                if (!data.total) {
                    el.innerHTML = `<p class="cr-vuoto">${crT('nessun_componente', '<strong>' + crEsc(q) + '</strong>')}</p>`;
                    return;
                }
                el.innerHTML = `<p class="cr-count"><strong>${crEsc(crPlurale(data.total, 'risultati'))}</strong> ${crEsc(crT('per_q', q))}</p>`
                    + '<div class="cr-lista"></div>';
            }

            const lista = el.querySelector('.cr-lista');
            data.results.forEach(c => lista.insertAdjacentHTML('beforeend', crCardComponente(c, q)));

            crComponentiOffset = offset + data.results.length;

            if (crComponentiOffset < data.total) {
                crBottoneAltri(el, 'cr-carica-altri', data.total - crComponentiOffset,
                    () => crFetchComponenti(crComponentiQuery, crComponentiOffset, true, gen));
            }
        })
        .catch(err => {
            if (gen !== crGenComponenti) return;
            crMostraErrore(el, err, append, 'cr-carica-altri');
        });
}

function crCardComponente(c, q) {
    // Alcuni componenti hanno solo il codice OEM: in quel caso è lui il codice principale
    const codice = c.fin_code
        ? `<strong class="cr-codice">${crEvidenzia(c.fin_code, q)}</strong>
           ${c.oem_code ? '<span class="cr-oem"><em>OEM</em> ' + crEvidenzia(c.oem_code, q) + '</span>' : ''}`
        : `<strong class="cr-codice"><em class="cr-codice-tipo">OEM</em> ${crEvidenzia(c.oem_code, q)}</strong>`;
    return crRigaRisultato(c, `
            <div class="cr-ris-main">
                <div class="cr-ris-titolo">
                    ${c.tipo_componente ? '<span class="cr-badge">' + crEsc(c.tipo_componente) + '</span>' : ''}
                    ${codice}
                </div>
                <div class="cr-ris-motore">${crEsc(c.costruttore)} — ${crEsc(c.motore)}${c.versione ? ' (' + crEsc(c.versione) + ')' : ''}</div>
                ${crTagTecnici(c)}
            </div>
            ${crColonnaPdf(c)}`);
}
