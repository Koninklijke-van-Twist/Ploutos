<?php
/**
 * Mappen met server-data (SQLite-caches, OData-cache, locks, feestdagen-JSON,
 * testscripts) mogen nooit via de webserver op te vragen zijn. De FTP-deploy
 * slaat .htaccess over, dus de app schrijft de afscherming zelf (zoals
 * analytics.php). Idempotent: alleen schrijven als het bestand ontbreekt of
 * de regel nog niet bevat.
 */

const PLOUTOS_PROTECTED_DIRS = ['cache', 'feestdagen', 'tests'];

function ploutos_deny_all_rules(): string
{
    return "# Door Ploutos aangemaakt: alleen server-side gebruik.\n"
        . "Options -Indexes\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n";
}

function ploutos_protect_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    $path = rtrim($dir, '/\\') . '/.htaccess';
    $current = is_file($path) ? (string) @file_get_contents($path) : null;
    if ($current === null) {
        @file_put_contents($path, ploutos_deny_all_rules(), LOCK_EX);
    } elseif (stripos($current, 'Require all denied') === false) {
        @file_put_contents($path, rtrim($current) . "\n\n" . ploutos_deny_all_rules(), LOCK_EX);
    }
}

function ploutos_protect_server_dirs(?string $webRoot = null): void
{
    $webRoot = $webRoot ?? __DIR__;
    foreach (PLOUTOS_PROTECTED_DIRS as $name) {
        ploutos_protect_dir($webRoot . '/' . $name);
    }
}
