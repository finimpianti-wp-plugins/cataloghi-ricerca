# Cataloghi Ricerca

Ricerca di motori e componenti nei cataloghi PDF a partire da indici CSV (motori e componenti), con visualizzatore PDF integrato alla pagina giusta, carosello immagini e pannello "Stile e testi". Shortcode `[cataloghi_ricerca]`. Multilingua con Elementor Page Translator (lingua per PDF, testi per lingua).

## Caricare i cataloghi

*Cataloghi → Pannello di controllo → Carica cataloghi*: scegli o trascina **uno o più PDF**. Compare l'elenco dei file con etichetta (dal nome del file, modificabile) e lingua (anche "Lingua per tutti"); i cataloghi già presenti si sostituiscono solo se spunti "sostituisci", i file oltre il limite di upload del server vanno copiati via FTP in `wp-content/uploads/cataloghi-pdf/` e poi "Scansiona cartella". I PDF si possono caricare anche prima degli indici CSV.

## Installazione

1. Scarica lo zip dell'ultima versione da **Releases** (a destra in questa pagina): `cataloghi-ricerca-<versione>.zip`.
2. In WordPress: *Plugin → Aggiungi nuovo → Carica plugin* → scegli lo zip → *Installa* (o *Sostituisci quella attuale*).

## Aggiornamenti

Il plugin controlla da solo le release di questo repository: quando ne esce una nuova compare in
*Bacheca → Aggiornamenti* e in *Plugin*, e si installa con un clic. Impostazioni e dati restano nel database del sito.

## Nuove versioni (per chi sviluppa)

1. Aggiorna `Version:` nell'intestazione del file principale (e la costante della versione) e aggiungi le novità in `CHANGELOG.md`.
2. Carica le modifiche sul ramo `main`: se la versione è nuova, GitHub crea da solo etichetta, zip e release (`.github/workflows/release.yml`). Si può anche pubblicare una release a mano con etichetta `v<versione>`: lo zip viene allegato automaticamente.

Per creare lo zip in locale: `bash bin/build-zip.sh` (risultato in `dist/`).

## Licenza

GPL-2.0-or-later. Include [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
