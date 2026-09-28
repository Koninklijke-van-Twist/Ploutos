# Ploutos

OData-reads gaan via Mímir als `$mimirApi` in `web/auth.php` staat. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-fout), dan haalt Ploutos dezelfde data op via het oude Business Central-pad (`$base` of `$baseUrl`, `$auth` / `$auth_list`, `$environment` en de lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Laat die BC-gegevens naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug.

Dit geldt voor live pagina's (waaronder `overzicht.php`) en voor `nightly.php` (CLI via `php nightly.php`, en cron). Zonder `$mimirApi` blijft alleen het directe BC-pad actief.
