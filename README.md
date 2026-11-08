# Cataloghi Ricerca

Ricerca di motori e componenti nei cataloghi PDF a partire da indici CSV (motori e componenti), con visualizzatore PDF integrato alla pagina giusta, carosello immagini e pannello "Stile e testi". Shortcode `[cataloghi_ricerca]`. Multilingua con Elementor Page Translator (lingua per PDF, testi per lingua).

## Installazione

1. Scarica lo zip dell'ultima versione da **Releases** (a destra in questa pagina): `cataloghi-ricerca-<versione>.zip`.
2. In WordPress: *Plugin → Aggiungi nuovo → Carica plugin* → scegli lo zip → *Installa* (o *Sostituisci quella attuale*).

## Aggiornamenti

Il plugin controlla da solo le release di questo repository: quando ne esce una nuova compare in
*Bacheca → Aggiornamenti* e in *Plugin*, e si installa con un clic. Impostazioni e dati restano nel database del sito.

## Nuove versioni (per chi sviluppa)

1. Aggiorna `Version:` nell'intestazione del file principale (e la costante della versione) e aggiungi le novità in `CHANGELOG.md`.
2. Pubblica una release (o un'etichetta) `v<versione>` (es. `v3.4`): GitHub crea lo zip e la release (`.github/workflows/release.yml`).

Per creare lo zip in locale: `bash bin/build-zip.sh` (risultato in `dist/`).

## Licenza

GPL-2.0-or-later. Include [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
