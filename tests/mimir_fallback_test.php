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
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . fallback_log());
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

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox' => $sandboxAuth,
];
$GLOBALS['odata_company_environment_map'] = ['Hunter van Twist' => 'Production'];

$loggedBeforeSandbox = fallback_count();
$beforeSandbox = count($calls);
$sandboxUrl = "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No";
$sandboxRows = odata_get_all($sandboxUrl, $auth, 30);
$sandboxCall = $calls[$beforeSandbox] ?? null;
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxCall)) {
    fail('tweede environment gaf geen stub-rij: ' . json_encode($sandboxCall));
}
if (strpos($sandboxCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company(\'Hunter%20van%20Twist\')/AppResource?') !== 0) {
    fail('URL-segment Sandbox moet de primaire environment winnen: ' . json_encode($sandboxCall));
}
if (strpos($sandboxCall['url'], '/Production/') !== false) {
    fail('Sandbox-URL werd naar Production herschreven: ' . $sandboxCall['url']);
}
if ($sandboxCall['user'] !== 'sandbox-user') {
    fail('Sandbox moet $auth_list[Sandbox] gebruiken, niet de primaire user: ' . json_encode($sandboxCall));
}
if (count($calls) !== $beforeSandbox + 1) {
    fail('Sandbox-URL mag geen extra company-discovery doen: ' . json_encode(array_slice($calls, $beforeSandbox)));
}
if (fallback_count() !== $loggedBeforeSandbox + 1) {
    fail('de eerste fout na een reset moet één keer gelogd worden');
}
$beforeOpen = count($calls);
odata_get_all($sandboxUrl, $auth, 30);
if (fallback_count() !== $loggedBeforeSandbox + 1) {
    fail('een open circuit mag niet bij elke call opnieuw loggen, log=' . fallback_log());
}
$openCall = $calls[$beforeOpen] ?? null;
if (!is_array($openCall) || $openCall['user'] !== 'sandbox-user' || strpos($openCall['url'], '/Sandbox/') === false) {
    fail('open circuit moet dezelfde Sandbox-route houden: ' . json_encode($openCall));
}

$encodedEnv = odata_bc_url_from_odata_url("https://mimir.invalid/My%20Env/ODataV4/Company('X')/AppResource");
if (strpos($encodedEnv, '/My%20Env/') === false || strpos($encodedEnv, 'My%2520Env') !== false) {
    fail('environment-segment moet één keer geëncodeerd worden: ' . $encodedEnv);
}
if (strpos($encodedEnv, 'https://bc.example:7148/My%20Env/ODataV4/') !== 0) {
    fail('herschreven environment-URL klopt niet: ' . $encodedEnv);
}

$GLOBALS['odata_company_environment_map']['Hunter van Twist'] = 'Sandbox';
odata_mimir_circuit_reset();
$beforeMapped = count($calls);
$mappedRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
$mappedCall = $calls[$beforeMapped] ?? null;
if (($mappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($mappedCall)) {
    fail('query via company-map gaf geen stub-rij: ' . json_encode($mappedCall));
}
if (strpos($mappedCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0) {
    fail('onbekend URL-segment moet de company→environment-map gebruiken: ' . json_encode($mappedCall));
}
if ($mappedCall['user'] !== 'sandbox-user') {
    fail('company-map moet de Sandbox-credentials kiezen: ' . json_encode($mappedCall));
}
if (count($calls) !== $beforeMapped + 1) {
    fail('bekende company-map mag niet opnieuw discovery doen: ' . json_encode(array_slice($calls, $beforeMapped)));
}

$environment = 'mimir';
$cacheKey = build_cache_key($sandboxUrl, $sandboxAuth);
if (substr($cacheKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox') {
    fail('cache-key moet de echte BC-environment gebruiken: ' . $cacheKey);
}
if (strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key mag de placeholder mimir niet gebruiken: ' . $cacheKey);
}
$mimirSegmentKey = build_cache_key(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppResource",
    $sandboxAuth
);
if (substr($mimirSegmentKey, -strlen('|Sandbox')) !== '|Sandbox' || strpos($mimirSegmentKey, '|mimir') !== false) {
    fail('cache-key moet mimir-segment negeren en de company-map gebruiken: ' . $mimirSegmentKey);
}
$environment = 'Production';

odata_mimir_circuit_reset();
$beforeCompanies = count($calls);
$companyRows = odata_direct_companies_as_rows('Sandbox');
$companyCall = $calls[$beforeCompanies] ?? null;
if (!is_array($companyCall) || strpos($companyCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company') !== 0) {
    fail('company-lijst met filter moet die environment gebruiken: ' . json_encode($companyCall));
}
if ($companyCall['user'] !== 'sandbox-user') {
    fail('gefilterde company-lijst moet $auth_list[Sandbox] gebruiken: ' . json_encode($companyCall));
}
if (count($calls) !== $beforeCompanies + 1) {
    fail('environment-filter mag alleen die environment ophalen: ' . json_encode(array_slice($calls, $beforeCompanies)));
}
$sawHunter = false;
foreach ($companyRows as $companyRow) {
    if (($companyRow['Name'] ?? '') === 'Hunter van Twist' && ($companyRow['environment'] ?? '') === 'Sandbox') {
        $sawHunter = true;
    }
}
if (!$sawHunter) {
    fail('company-rijen moeten de opgevraagde environment bewaren: ' . json_encode($companyRows));
}

odata_mimir_circuit_reset();
$loggedBeforeCaller = fallback_count();
$callsBeforeCaller = count($calls);
$callerThrown = null;
try {
    odata_mimir_or_direct(
        static function (): array {
            throw new Exception('geen Mímir-storing');
        },
        static function (): array {
            return [['No' => 'should-not-run']];
        }
    );
    fail('een exception van de caller moet blijven doorgaan');
} catch (Throwable $exception) {
    $callerThrown = $exception;
}
if (!$callerThrown instanceof Throwable || $callerThrown->getMessage() !== 'geen Mímir-storing') {
    $callerMessage = $callerThrown instanceof Throwable ? $callerThrown->getMessage() : 'geen exception';
    fail('caller-exception werd vervangen of geslikt: ' . $callerMessage);
}
if (odata_mimir_circuit_open()) {
    fail('een caller-exception mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeCaller) {
    fail('een caller-exception mag geen fallback loggen');
}
if (count($calls) !== $callsBeforeCaller) {
    fail('een caller-exception mag de directe route niet starten');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [];
$syncAuth = ['mode' => 'basic', 'user' => 'sync-user', 'pass' => 'sync-secret'];
$GLOBALS['odata_company_environment_map'] = [
    'KVT Gas' => 'Production',
    'Hunter van Twist' => 'Sandbox',
];
$beforePrimaryAuth = count($calls);
$primaryAuthRows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    $syncAuth,
    30
);
$primaryAuthCall = $calls[$beforePrimaryAuth] ?? null;
if (($primaryAuthRows[0]['No'] ?? '') !== 'WO-1' || !is_array($primaryAuthCall) || $primaryAuthCall['user'] !== 'sync-user') {
    fail('primaire environment zonder auth_list-entry moet de meegegeven credentials houden: ' . json_encode($primaryAuthCall));
}
if (strpos((string) ($primaryAuthCall['url'] ?? ''), '/Production/') === false) {
    fail('primaire environment-URL klopt niet: ' . json_encode($primaryAuthCall));
}
$beforeOtherAuth = count($calls);
$otherAuthRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No",
    $syncAuth,
    30
);
$otherAuthCall = $calls[$beforeOtherAuth] ?? null;
if (($otherAuthRows[0]['No'] ?? '') !== 'WO-1' || !is_array($otherAuthCall) || $otherAuthCall['user'] !== 'sync-user') {
    fail('lege auth_list moet meegegeven credentials gebruiken voor een andere environment: ' . json_encode($otherAuthCall));
}
if (strpos((string) ($otherAuthCall['url'] ?? ''), '/Sandbox/') === false) {
    fail('lege auth_list moet de Sandbox-URL houden: ' . json_encode($otherAuthCall));
}
odata_mimir_circuit_reset();
$beforePrimaryQuery = count($calls);
$primaryQueryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$primaryQueryCall = $calls[$beforePrimaryQuery] ?? null;
if (($primaryQueryRows[0]['No'] ?? '') !== 'WO-1' || !is_array($primaryQueryCall) || $primaryQueryCall['user'] !== 'bcuser') {
    fail('query op de primaire environment moet $auth gebruiken als auth_list leeg is: ' . json_encode($primaryQueryCall));
}
odata_mimir_circuit_reset();
$beforeSandboxQuery = count($calls);
$sandboxQueryRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
$sandboxQueryCall = $calls[$beforeSandboxQuery] ?? null;
if (($sandboxQueryRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxQueryCall) || $sandboxQueryCall['user'] !== 'bcuser') {
    fail('query op een andere environment moet $auth gebruiken als auth_list leeg is: ' . json_encode($sandboxQueryCall));
}
if (strpos((string) ($sandboxQueryCall['url'] ?? ''), '/Sandbox/') === false) {
    fail('lege auth_list moet de company-environment houden: ' . json_encode($sandboxQueryCall));
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
unset($auth_list, $GLOBALS['auth_list']);
unset($GLOBALS['odata_company_environment_map']);
$beforeOnlyAuthCompanies = count($calls);
$onlyAuthNames = odata_mimir_list_companies(null);
$onlyAuthCompanyCall = $calls[$beforeOnlyAuthCompanies] ?? null;
if (
    $onlyAuthNames === []
    || !is_array($onlyAuthCompanyCall)
    || $onlyAuthCompanyCall['user'] !== 'bcuser'
    || strpos($onlyAuthCompanyCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0
) {
    fail('companylijst zonder auth_list moet de primaire environment met $auth bevragen: ' . json_encode($onlyAuthCompanyCall));
}
odata_mimir_circuit_reset();
$beforeOnlyAuthQuery = count($calls);
$onlyAuthQueryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$onlyAuthQueryCall = null;
foreach (array_slice($calls, $beforeOnlyAuthQuery) as $onlyAuthCandidate) {
    if (strpos($onlyAuthCandidate['url'], '/AppResource?') !== false) {
        $onlyAuthQueryCall = $onlyAuthCandidate;
    }
}
if (($onlyAuthQueryRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyAuthQueryCall) || $onlyAuthQueryCall['user'] !== 'bcuser') {
    fail('query zonder auth_list moet $auth gebruiken: ' . json_encode($onlyAuthQueryCall));
}
odata_mimir_circuit_reset();
$beforeOnlyAuthFetch = count($calls);
$onlyAuthFetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No",
    30
);
$onlyAuthFetchCall = $calls[$beforeOnlyAuthFetch] ?? null;
if (($onlyAuthFetchRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyAuthFetchCall) || $onlyAuthFetchCall['user'] !== 'bcuser') {
    fail('URL-fetch zonder auth_list moet $auth gebruiken: ' . json_encode($onlyAuthFetchCall));
}
if (strpos((string) ($onlyAuthFetchCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/') !== 0) {
    fail('URL-fetch zonder auth_list moet de environment in de URL houden: ' . json_encode($onlyAuthFetchCall));
}
$beforeSandboxCompanies = count($calls);
$sandboxOnlyRows = odata_direct_companies_as_rows('Sandbox');
$sandboxOnlyCall = $calls[$beforeSandboxCompanies] ?? null;
if (
    $sandboxOnlyRows === []
    || !is_array($sandboxOnlyCall)
    || $sandboxOnlyCall['user'] !== 'bcuser'
    || strpos($sandboxOnlyCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company') !== 0
) {
    fail('companylijst van een andere environment zonder auth_list moet $auth gebruiken: ' . json_encode($sandboxOnlyCall));
}

odata_mimir_circuit_reset();
$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = ['Sandbox' => $sandboxAuth];
$environment = 'Production';
$GLOBALS['odata_company_environment_map'] = [];
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('Onbekend Bedrijf', 'AppResource', ['$select' => 'No'], 30);
$unmappedCall = null;
foreach (array_slice($calls, $beforeUnmapped) as $unmappedCandidate) {
    if (strpos($unmappedCandidate['url'], 'Onbekend') !== false) {
        $unmappedCall = $unmappedCandidate;
    }
}
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unmappedCall) || $unmappedCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet via $auth naar de primaire environment: ' . json_encode($unmappedCall));
}
if (strpos((string) ($unmappedCall['url'] ?? ''), '/Production/') === false) {
    fail('onbekend bedrijf moet de primaire environment gebruiken: ' . json_encode($unmappedCall));
}

odata_mimir_circuit_reset();
$auth_list = ['Production' => $auth];
$GLOBALS['odata_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
$beforeRefuse = count($calls);
$refuseThrown = null;
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No",
        $auth,
        30
    );
    fail('Sandbox-URL zonder auth_list-entry moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    $refuseThrown = $exception;
}
if (!$refuseThrown instanceof Throwable || strpos($refuseThrown->getMessage(), 'Mímir') === false) {
    $refuseMessage = $refuseThrown instanceof Throwable ? $refuseThrown->getMessage() : 'geen exception';
    fail('verwachte Mímir-fout bij Sandbox-URL zonder entry: ' . $refuseMessage);
}
if (count($calls) !== $beforeRefuse) {
    fail('Sandbox-URL zonder auth_list-entry mag geen BC-call doen: ' . json_encode(array_slice($calls, $beforeRefuse)));
}

$authFile = tempnam(sys_get_temp_dir(), 'ploutos-auth-');
if ($authFile === false) {
    fail('tijdelijk auth-bestand kon niet worden gemaakt');
}
file_put_contents(
    $authFile,
    "<?php\n"
    . "\$baseUrl = 'https://from-file.example:7148/';\n"
    . "\$base = \"https://from-file.example:7148/Sandbox/ODataV4/Company('File%20Co')/\";\n"
    . "\$environment = 'Sandbox';\n"
    . "\$auth = ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'];\n"
    . "\$auth_list = ['Sandbox' => \$auth];\n"
    . "\$mimirApi = 'file-key-should-not-stick';\n"
);
$GLOBALS['PLOUTOS_AUTH_PHP_PATH'] = $authFile;
$GLOBALS['baseUrl'] = 'https://preset.example/';
$GLOBALS['environment'] = 'keep-me';
$GLOBALS['mimirApi'] = 'already-set-key';
unset($GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['base']);
odata_load_auth_for_fallback();
@unlink($authFile);
unset($GLOBALS['PLOUTOS_AUTH_PHP_PATH']);
if (($GLOBALS['baseUrl'] ?? '') !== 'https://preset.example/') {
    fail('gezette baseUrl werd overschreven: ' . (string) ($GLOBALS['baseUrl'] ?? ''));
}
if (($GLOBALS['environment'] ?? '') !== 'keep-me') {
    fail('gezette environment werd overschreven: ' . (string) ($GLOBALS['environment'] ?? ''));
}
if (($GLOBALS['mimirApi'] ?? '') !== 'already-set-key') {
    fail('auth.php mag $mimirApi niet via de fallback-loader zetten');
}
$loadedAuth = $GLOBALS['auth'] ?? null;
if (!is_array($loadedAuth) || ($loadedAuth['user'] ?? '') !== 'file-user' || ($loadedAuth['pass'] ?? '') !== 'file-secret') {
    fail('ontbrekende $auth moet uit auth.php naar $GLOBALS gekopieerd worden: ' . json_encode($loadedAuth));
}
$loadedList = $GLOBALS['auth_list'] ?? null;
$loadedSandbox = is_array($loadedList) ? ($loadedList['Sandbox']['user'] ?? '') : '';
if ($loadedSandbox !== 'file-user') {
    fail('ontbrekende $auth_list moet uit auth.php naar $GLOBALS gekopieerd worden: ' . json_encode($loadedList));
}
$loadedBase = (string) ($GLOBALS['base'] ?? '');
if (strpos($loadedBase, 'https://from-file.example:7148/Sandbox/ODataV4/Company(') !== 0) {
    fail('ontbrekende $base moet uit auth.php naar $GLOBALS gekopieerd worden: ' . $loadedBase);
}
odata_load_auth_for_fallback();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://preset.example/' || ($GLOBALS['environment'] ?? '') !== 'keep-me') {
    fail('een tweede load mag gezette waarden alsnog niet overschrijven');
}

$log = fallback_log();
if (strpos($log, 'sandbox-secret') !== false || strpos($log, 'file-secret') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'file-key-should-not-stick') !== false) {
    fail('log bevat een API-sleutel');
}

echo "OK\n";
