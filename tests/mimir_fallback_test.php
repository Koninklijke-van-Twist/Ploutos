<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 *
 * Dekking: odata_get_all (nightly-sync en live fetches), odata_mimir_company_base
 * (nightly.php en overzicht.php bij leeg $base), query/fetch en company-lijst.
 */

$logFile = sys_get_temp_dir() . '/ploutos-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$oneCompany = false;
$GLOBALS['PLOUTOS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl, bool $forceRefresh = false) use (&$calls, &$oneCompany): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
        'refresh' => $forceRefresh,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        if ($oneCompany) {
            return [['Name' => 'KVT Gas']];
        }
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Ploutos] Mímir failed, falling back to direct OData:');
}

$nightlySrc = (string) file_get_contents(dirname(__DIR__) . '/web/nightly.php');
$overzichtSrc = (string) file_get_contents(dirname(__DIR__) . '/web/overzicht.php');
if (strpos($nightlySrc, 'require __DIR__ . \'/auth.php\'') === false && strpos($nightlySrc, 'require __DIR__ . "/auth.php"') === false) {
    fail('nightly.php moet auth.php laden zodat BC-credentials beschikbaar zijn');
}
if (strpos($nightlySrc, 'odata_mimir_company_base') === false || strpos($nightlySrc, 'timesheet_sync_live_month') === false) {
    fail('nightly.php moet company-base en de OData-sync blijven aanroepen');
}
if (strpos($overzichtSrc, 'odata_mimir_company_base') === false) {
    fail('overzicht.php moet company-base blijven aanroepen');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

if (odata_mimir_company_base() !== $base) {
    fail('gezet $base (pre-Mímir company-URL) moet ongewijzigd terugkomen');
}
if (count($calls) !== 0) {
    fail('company_base mag BC niet aanroepen als $base al gezet is');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() < 2) {
    fail('elke fallback moet gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Ploutos] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeNightly = count($calls);
$nightlyUrl = $base . 'Urenstaten?$select=No';
$nightlyRows = odata_get_all($nightlyUrl, $auth, 86400, true);
$nightlyCall = $calls[$beforeNightly] ?? null;
if (($nightlyRows[0]['No'] ?? '') !== 'WO-1' || !is_array($nightlyCall) || $nightlyCall['url'] !== $nightlyUrl || $nightlyCall['refresh'] !== true || $nightlyCall['user'] !== 'bcuser') {
    fail('nightly-URL met forceRefresh viel niet terug op de pre-Mímir BC-fetch: ' . json_encode($nightlyCall));
}

odata_mimir_circuit_reset();
$base = '';
$oneCompany = true;
$resolved = odata_mimir_company_base();
$expectedResolved = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
if ($resolved !== $expectedResolved) {
    fail('leeg $base (nightly/overzicht) viel niet terug op een absolute BC-URL: ' . $resolved);
}
if (!odata_mimir_circuit_open()) {
    fail('leeg $base moet het circuit openen als Mímir onbereikbaar is');
}
$startedRelative = microtime(true);
$relativeRows = odata_get_all("/ODataV4/Company('KVT%20Gas')/Urenstaten?\$select=No", $auth, 30);
$relativeElapsed = microtime(true) - $startedRelative;
if ($relativeElapsed >= 2.0) {
    fail('relatieve company-URL probeerde Mímir opnieuw (' . round($relativeElapsed, 3) . 's)');
}
$relativeCall = $calls[count($calls) - 1] ?? null;
$expectedRelative = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Urenstaten?\$select=No";
if (($relativeRows[0]['No'] ?? '') !== 'WO-1' || !is_array($relativeCall) || $relativeCall['url'] !== $expectedRelative) {
    fail('relatieve URL werd niet naar BC herschreven: ' . json_encode($relativeCall));
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$oneCompany = false;
$mimirBase = 'http://127.0.0.1:9';
$base = '';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('geen exception gevangen');
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

echo "OK\n";
