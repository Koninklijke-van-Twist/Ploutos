<?php
/**
 * Eenmalige controle: haal een jaar urenstaten uit BC, vergelijk met de lokale
 * cache en voeg ontbrekende/afwijkende urenstaten pas na bevestiging toe.
 *
 * Browser: backfill_year.php[?year=2026]  (één maand per request, daarna rapport + knop)
 * CLI:     php backfill_year.php [--year=2026]  (vraagt j/n vóór het schrijven)
 */
set_time_limit(0);
ini_set('memory_limit', '-1');

require __DIR__ . '/odata.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require __DIR__ . '/lib_timesheet_store.php';
require __DIR__ . '/lib_timesheet_sync.php';
require __DIR__ . '/lib_timesheet_backfill.php';

if (odata_mimir_enabled()) {
    if (!isset($auth) || !is_array($auth)) {
        $auth = [];
    }
    if (!isset($base) || !is_string($base) || trim($base) === '') {
        $base = odata_mimir_company_base();
    }
}

$isCli = PHP_SAPI === 'cli';
$companyLabel = rawurldecode((string) (preg_match("/Company\\('([^']*)'\\)/", (string) $base, $m) ? $m[1] : $base));

/** Zelfde lock als nightly.php, zodat ze nooit tegelijk schrijven/ophalen. */
function backfill_lock()
{
    $path = __DIR__ . '/cache/nightly.lock';
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0777, true);
    }
    $lock = @fopen($path, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $lock;
}

function backfill_state_path(string $run): string
{
    // Buiten de webroot: web/cache is via de webserver op te vragen.
    return rtrim(sys_get_temp_dir(), '/') . '/ploutos_backfill_' . $run . '.json';
}

/** Haal één maand uit BC (zoals nightly met forceRefresh) en vergelijk met de cache. */
function backfill_compare_month(SQLite3 $db, string $base, array $auth, string $ym, callable $log): array
{
    $b = timesheet_store_month_bounds($ym);
    $ts = timesheet_backfill_with_retry(fn() => timesheet_sync_fetch_timesheets($base, $auth, $b['from'], $b['to'], 0, true), $log);
    $nos = timesheet_sync_ts_nos($ts);
    $lines = $nos ? timesheet_backfill_with_retry(fn() => timesheet_sync_fetch_lines($base, $auth, $nos, 0, true), $log) : [];

    $cacheTs = timesheet_store_get_timesheets($db, $b['from'], $b['to']);
    $cacheLines = timesheet_store_get_lines_for_timesheets($db, timesheet_sync_ts_nos($cacheTs));
    $diffs = timesheet_backfill_diff($ts, $lines, $cacheTs, $cacheLines);

    $diffNos = array_flip(array_column($diffs, 'tsNo'));
    $keepTs = array_values(array_filter($ts, fn($t) => isset($diffNos[(string) ($t['No'] ?? '')])));
    $keepLines = array_values(array_filter($lines, fn($l) => isset($diffNos[(string) ($l['Time_Sheet_No'] ?? '')])));
    $resNos = timesheet_sync_resource_nos_from_lines($keepLines);
    $resources = $resNos ? timesheet_backfill_with_retry(fn() => timesheet_sync_fetch_resources($base, $auth, $resNos, 0, true), $log) : [];

    $log("{$ym}: " . count($nos) . ' urenstaten in BC, ' . count($diffs) . ' afwijkend');
    return ['ym' => $ym, 'diffs' => $diffs, 'timesheets' => $keepTs, 'lines' => $keepLines, 'resources' => $resources];
}

/** Schrijf precies zoals nightly: replace_month_data + resources upsert. */
function backfill_apply(SQLite3 $db, array $results): int
{
    $liveFrom = timesheet_store_live_from_ymd();
    $n = 0;
    foreach ($results as $r) {
        if (!$r['timesheets']) {
            continue;
        }
        timesheet_store_replace_month_data($db, $r['timesheets'], $r['lines'], $liveFrom);
        timesheet_store_upsert_resources($db, $r['resources']);
        $n += count($r['timesheets']);
    }
    timesheet_store_mark_permanent_before($db, $liveFrom);
    return $n;
}

function backfill_all_diffs(array $results): array
{
    return array_merge([], ...array_map(fn($r) => $r['diffs'], $results));
}

$yearArg = null;
if ($isCli) {
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--year=(\d{4})$/', $a, $mm)) {
            $yearArg = (int) $mm[1];
        }
    }
} elseif (preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? ''))) {
    $yearArg = (int) $_GET['year'];
}
$months = timesheet_backfill_months($yearArg, timesheet_store_current_month());
$db = timesheet_store_db();

// ---------- CLI ----------
if ($isCli) {
    $log = function (string $msg): void {
        echo '[' . date('H:i:s') . "] {$msg}\n";
    };
    $lock = backfill_lock();
    if (!$lock) {
        $log('Nightly (of een andere backfill) draait nu; probeer het later opnieuw.');
        exit(1);
    }
    $log("Bedrijf: {$companyLabel}; maanden " . reset($months) . ' t/m ' . end($months));
    $results = [];
    foreach ($months as $ym) {
        $results[] = backfill_compare_month($db, $base, $auth, $ym, $log);
    }
    $diffs = backfill_all_diffs($results);
    foreach ($diffs as $d) {
        printf("%-9s %-28s %s %-10s BC %6.2f u (%s) | cache %6.2f u (%s)\n", $d['kind'], $d['resourceName'] . ' (' . $d['resourceNo'] . ')',
            $d['weekStart'], $d['description'], $d['bcHours'], $d['bcStatus'], $d['cacheHours'], $d['cacheStatus'] ?: '-');
    }
    if (!$diffs) {
        $log('Geen verschillen gevonden.');
        exit(0);
    }
    echo count($diffs) . ' urenstaten toevoegen/bijwerken in de cache? [j/n] ';
    $answer = strtolower(trim((string) fgets(STDIN)));
    if ($answer !== 'j' && $answer !== 'y') {
        $log('Niets geschreven.');
        exit(0);
    }
    $log('Geschreven: ' . backfill_apply($db, $results) . ' urenstaten.');
    exit(0);
}

// ---------- Browser ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['ploutos_backfill_csrf'])) {
    $_SESSION['ploutos_backfill_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string) $_SESSION['ploutos_backfill_csrf'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$run = (string) ($_GET['run'] ?? $_POST['run'] ?? '');
if ($run !== '' && !preg_match('/^[a-f0-9]{16}$/', $run)) {
    http_response_code(400);
    die('Ongeldige run.');
}

$page = function (string $body, string $refresh = '') use ($h, $companyLabel): void {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>Ploutos – jaar controleren</title>'
        . ($refresh !== '' ? '<meta http-equiv="refresh" content="1;url=' . $h($refresh) . '">' : '')
        . '<style>body{font-family:sans-serif;margin:24px}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px 8px;font-size:13px}'
        . '.ontbreekt{background:#fde2e2}.verschilt{background:#fff4d6}</style></head><body>'
        . '<h1>Urenstaten-cache controleren</h1><p>Bedrijf: <b>' . $h($companyLabel) . '</b></p>' . $body . '</body></html>';
    exit;
};

// Toepassen na bevestiging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? '')) || $run === '' || !is_file(backfill_state_path($run))) {
        http_response_code(400);
        $page('<p>Ongeldig of verlopen verzoek. <a href="backfill_year.php">Opnieuw beginnen</a>.</p>');
    }
    $state = json_decode((string) file_get_contents(backfill_state_path($run)), true);
    $lock = backfill_lock();
    if (!$lock) {
        $page('<p>Nightly draait nu; probeer het over een paar minuten opnieuw (de vergelijking blijft bewaard).</p>');
    }
    $n = backfill_apply($db, (array) $state['results']);
    @unlink(backfill_state_path($run));
    $page('<p>Klaar: ' . $n . ' urenstaten toegevoegd/bijgewerkt in de cache.</p><p><a href="index.php">Terug naar Ploutos</a></p>');
}

// Nieuwe run starten
if ($run === '') {
    $run = bin2hex(random_bytes(8));
    file_put_contents(backfill_state_path($run), json_encode(['months' => $months, 'idx' => 0, 'results' => []]));
    $page('<p>Vergelijking gestart voor ' . $h(reset($months)) . ' t/m ' . $h(end($months)) . '…</p>', 'backfill_year.php?run=' . $run);
}

if (!is_file(backfill_state_path($run))) {
    $page('<p>Deze run bestaat niet (meer). <a href="backfill_year.php">Opnieuw beginnen</a>.</p>');
}
$state = json_decode((string) file_get_contents(backfill_state_path($run)), true);
$months = (array) $state['months'];
$idx = (int) $state['idx'];

// Eén maand per request (voorkomt timeouts)
if ($idx < count($months)) {
    $lock = backfill_lock();
    if (!$lock) {
        $page('<p>Nightly draait nu; even wachten…</p>', 'backfill_year.php?run=' . $run);
    }
    $logLines = [];
    try {
        $state['results'][] = backfill_compare_month($db, $base, $auth, $months[$idx], function ($m) use (&$logLines) {
            $logLines[] = $m;
        });
    } catch (Throwable $e) {
        $page('<p>Fout bij ' . $h($months[$idx]) . ': ' . $h($e->getMessage()) . '</p><p><a href="backfill_year.php?run=' . $run . '">Opnieuw proberen</a></p>');
    }
    $state['idx'] = $idx + 1;
    file_put_contents(backfill_state_path($run), json_encode($state));
    $page('<p>Bezig: maand ' . ($idx + 1) . ' van ' . count($months) . ' (' . $h($months[$idx]) . ')</p><pre>' . $h(implode("\n", $logLines)) . '</pre>',
        'backfill_year.php?run=' . $run);
}

// Rapport + bevestigen
$diffs = backfill_all_diffs((array) $state['results']);
if (!$diffs) {
    @unlink(backfill_state_path($run));
    $page('<p>Geen verschillen gevonden tussen BC en de cache voor ' . $h(reset($months)) . ' t/m ' . $h(end($months)) . '.</p>');
}
$rows = '';
foreach ($diffs as $d) {
    $rows .= '<tr class="' . $h($d['kind']) . '"><td>' . $h($d['kind']) . '</td><td>' . $h($d['resourceName']) . ' (' . $h($d['resourceNo']) . ')</td><td>'
        . $h($d['weekStart']) . ' ' . $h($d['description']) . '</td><td>' . $h($d['tsNo']) . '</td><td>' . number_format($d['bcHours'], 2, ',', '') . '</td><td>'
        . $h($d['bcStatus']) . '</td><td>' . number_format($d['cacheHours'], 2, ',', '') . '</td><td>' . $h($d['cacheStatus'] ?: '-') . '</td></tr>';
}
$page('<p>' . count($diffs) . ' urenstaten wijken af (' . $h(reset($months)) . ' t/m ' . $h(end($months)) . '). Er is nog niets geschreven.</p>'
    . '<table><tr><th>Soort</th><th>Medewerker</th><th>Week</th><th>Urenstaat</th><th>Uren BC</th><th>Status BC</th><th>Uren cache</th><th>Status cache</th></tr>'
    . $rows . '</table><form method="post" style="margin-top:16px"><input type="hidden" name="run" value="' . $h($run) . '">'
    . '<input type="hidden" name="csrf" value="' . $h($csrf) . '"><button type="submit">Toevoegen</button> '
    . '<a href="index.php">Annuleren</a></form>');
