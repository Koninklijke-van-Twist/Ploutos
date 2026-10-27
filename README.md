# Ploutos

OData-reads gaan via Mímir als `$mimirApi` in `web/auth.php` staat. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-fout), dan haalt Ploutos dezelfde data op via het oude Business Central-pad (`$base` of `$baseUrl`, `$auth` / `$auth_list`, `$environment` en de lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Laat die BC-gegevens naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug.

Dit geldt voor live pagina's (waaronder `overzicht.php`) en voor `nightly.php` (CLI via `php nightly.php`, en cron). Zonder `$mimirApi` blijft alleen het directe BC-pad actief.

`nightly.php` ververst elke nacht de laatste 6 maanden (huidige + 5 vorige, `TIMESHEET_SYNC_REFRESH_PREVIOUS_MONTHS`), zodat laat ingevulde of goedgekeurde urenstaten alsnog in de cache komen.

`web/analytics/analytics.sqlite` (en `*.sqlite-*`) blijft op de server staan bij een deploy: de FTP-mirror slaat die bestanden al over. Dezelfde mirror slaat `.htaccess` over, dus een gecommit bestand komt niet mee en een bestaand bestand wordt niet gewist. `analytics.php` schrijft `web/analytics/.htaccess` als die ontbreekt (of vult hem aan als het woord `sqlite` er nog niet in staat), zodat Apache de database weigert en `analytics.php` zelf bereikbaar blijft.

## Eenmalige jaarcontrole: `backfill_year.php`

Vergelijkt een jaar urenstaten in BC met de lokale cache en voegt ontbrekende of afwijkende urenstaten pas toe **na bevestiging**. De data komt in de cache op dezelfde manier als bij nightly (`timesheet_store_replace_month_data` en `timesheet_store_upsert_resources`).

- Browser (alleen voor gebruikers die Ploutos al mogen gebruiken): `https://sleutels.kvt.nl/ploutos/backfill_year.php` (laatste 12 maanden) of `…/backfill_year.php?year=2025`. De pagina haalt één maand per request op en gaat dan automatisch door. Daarna volgt een rapport met de knop **Toevoegen** (POST met CSRF-token).
- CLI: `php web/backfill_year.php [--year=2025]` toont het rapport en vraagt `[j/n]`.
- Gebruikt dezelfde lock als `nightly.php` (`cache/nightly.lock`). Draait nightly, dan wacht het script of stopt het.
- Bij de Mímir-melding "limiet bereikt" probeert het script het opnieuw na 15, 45 en 90 seconden. Er loopt altijd maar één verzoek tegelijk.
- Urenstaten die alleen in de cache staan, worden niet verwijderd.
