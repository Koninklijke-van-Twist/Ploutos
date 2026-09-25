<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

require_once dirname(__DIR__) . '/odata.php';

$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/ploutos-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/ploutos-mimir-mock.php';
$authPath = dirname(__DIR__) . '/auth.php';
$authBackup = null;

function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$apiKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if (function_exists('getallheaders')) {
    foreach (getallheaders() ?: [] as $name => $value) {
        if ($authorization === '' && strcasecmp((string) $name, 'Authorization') === 0) {
            $authorization = (string) $value;
        }
        if ($apiKey === '' && strcasecmp((string) $name, 'X-API-Key') === 0) {
            $apiKey = (string) $value;
        }
    }
}
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => $apiKey,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

if (str_contains($uri, '/redirect.php')) {
    header('Location: /should-not-follow', true, 302);
    echo '{"error":"redirect"}';
    exit;
}

header('Content-Type: application/json');

if (str_contains($uri, '/mimir-one/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php') || str_contains($uri, '/mimir-one/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'TS1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function test_cache_files(): array
{
    $paths = glob(dirname(__DIR__) . '/cache/odata/*.json');
    return is_array($paths) ? $paths : [];
}

test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default Mímir-base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$restoreProbe = sys_get_temp_dir() . '/ploutos-auth-restore-probe.php';
file_put_contents($restoreProbe, "<?php\n\$marker = 'original';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", false);
test_assert(
    'restore laat bestaand bestand met rust als de test niet schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
file_put_contents($restoreProbe, "<?php\n\$marker = 'replaced';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", true);
test_assert(
    'restore zet backup terug nadat de test schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
@unlink($restoreProbe);

$spaceUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke van Twist')/Urenstaten"
    . '?$select=No,Resource_No&$filter=' . rawurlencode("Ending_Date ge 2026-01-01") . '&$format=json';
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'Urenstaten'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Resource_No'
        && ($parsedSpace['query']['$filter'] ?? '') === 'Ending_Date ge 2026-01-01',
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert('entity-URL is geen company-discovery', odata_mimir_parse_companies_url($spaceUrl) === null);

$apostropheUrl = "/ODataV4/Company('Van Twist''s')/Urenstaten?\$select=No";
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'OData-apostrof in Company-pad',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'Urenstaten',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$encodedApostrophe = "/Production/ODataV4/Company('Van%20Twist%27%27s')/Urenstaten";
$parsedEncoded = odata_mimir_parse_entity_url($encodedApostrophe);
test_assert(
    'geëncodeerde apostrof in Company-pad',
    is_array($parsedEncoded) && ($parsedEncoded['company'] ?? '') === "Van Twist's",
    json_encode($parsedEncoded, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
unset($GLOBALS['base'], $GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['environment']);

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;
$cacheBeforeBc = test_cache_files();

try {
    $multiThrew = false;
    try {
        odata_mimir_company_base();
    } catch (Exception $error) {
        $multiThrew = str_contains($error->getMessage(), 'Meerdere bedrijven');
    }
    test_assert('leeg $base met meerdere bedrijven blijft een fout', $multiThrew);

    $beforeCache = test_cache_files();
    $rows = odata_fetch_by_or_filter(
        "https://bc.example:7148/Production/ODataV4/Company('Koninklijke van Twist')/",
        'Urenstaten',
        'No,Resource_No',
        'No',
        ['TS1'],
        [],
        120,
        60,
        false
    );
    test_assert(
        'urenstaat-fetch via Mímir zonder BC-auth',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($rows[0]['table'] ?? '') === 'Urenstaten'
            && ($rows[0]['filter'] ?? '') === "No eq 'TS1'"
            && ($rows[0]['max_age'] ?? null) === 120
            && ($rows[0]['select'] ?? []) === ['No', 'Resource_No'],
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    test_assert('Mímir slaat Ploutos-filecache over', test_cache_files() === $beforeCache);

    $fresh = odata_get_all($spaceUrl, [], 86400, true);
    test_assert(
        'forceRefresh stuurt max_age 0',
        ($fresh[0]['max_age'] ?? null) === 0,
        json_encode($fresh, JSON_UNESCAPED_UNICODE)
    );

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $redirectThrew = false;
    try {
        odata_mimir_request('GET', 'redirect.php');
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
    }
    test_assert('Mímir volgt redirects niet', $redirectThrew);

    $requests = test_mock_requests();
    $hitBcHost = false;
    $followedRedirect = false;
    $sawMimirUa = false;
    $sawKey = false;
    foreach ($requests as $request) {
        $uri = (string) ($request['uri'] ?? '');
        if (str_contains($uri, 'bc.example')) {
            $hitBcHost = true;
        }
        if (str_contains($uri, 'should-not-follow')) {
            $followedRedirect = true;
        }
        if (($request['ua'] ?? '') === 'Ploutos-MimirClient/1.0' && str_contains($uri, '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (($request['api_key'] ?? '') === 'mimir_test_key' && str_starts_with((string) ($request['authorization'] ?? ''), 'Bearer ')) {
            $sawKey = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('redirect-doel is niet opgehaald', $followedRedirect === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Mímir-key in Authorization en X-API-Key', $sawKey);

    @unlink($mockLog);
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-one/api';
    unset($GLOBALS['base']);
    $synthetic = odata_mimir_company_base();
    test_assert(
        'één bedrijf levert een Company-pad zonder BC-host',
        str_contains($synthetic, "/ODataV4/Company('") && !str_contains($synthetic, 'bc.example'),
        $synthetic
    );
    $bare = odata_get_all('Urenstaten?$select=No&$format=json', [], 90);
    test_assert(
        'kale entity-URL gebruikt het enige Mímir-bedrijf',
        ($bare[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($bare[0]['table'] ?? '') === 'Urenstaten'
            && ($bare[0]['max_age'] ?? null) === 90,
        json_encode($bare, JSON_UNESCAPED_UNICODE)
    );

    $mimirApi = '';
    unset($GLOBALS['mimirApi']);
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth = [
        'mode' => 'basic',
        'user' => 'bcuser',
        'pass' => 'bcpass',
    ];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents(
        $authPath,
        "<?php\n\$environment = 'Production';\n\$mimirApi = '';\n"
    );
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcRows = odata_get_all($baseUrl . '/Production/ODataV4/Companies?$select=Name', $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    foreach (test_cache_files() as $cacheFile) {
        if (!in_array($cacheFile, $cacheBeforeBc, true)) {
            @unlink($cacheFile);
        }
    }
    @unlink($mockScript);
    @unlink($mockLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
