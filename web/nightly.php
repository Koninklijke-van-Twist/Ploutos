<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('max_input_time', '-1');
ini_set('memory_limit', '-1');
ignore_user_abort(true);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/plain; charset=UTF-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

require __DIR__ . '/odata.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require __DIR__ . '/lib_timesheet_store.php';
require __DIR__ . '/lib_timesheet_sync.php';

function nightly_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    flush();
}

$lockPath = __DIR__ . '/cache/nightly.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0777, true);
}

$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    http_response_code(500);
    nightly_log('Kon lockbestand niet openen.');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    nightly_log('Nightly draait al.');
    fclose($lock);
    exit(0);
}

$startedAt = microtime(true);
nightly_log('Nightly gestart.');

try {
    $db = timesheet_store_db();
    $ttl = 3600 * 24;

    nightly_log('Stap 1/3: huidige maand verversen uit OData.');
    $live = timesheet_sync_live_month($db, $base, $auth, $ttl, 'nightly_log');
    nightly_log('Live-maand klaar: ' . (int) $live['timesheets'] . ' urenstaten, ' . (int) $live['lines'] . ' regels.');

    if (!timesheet_store_backfill_complete($db)) {
        nightly_log('Stap 2/3: eenmalige backfill (terug tot 5 lege maanden).');
        timesheet_sync_backfill($db, $base, $auth, $ttl, 'nightly_log');
    } else {
        nightly_log('Stap 2/3: backfill was al voltooid, historische maanden blijven in SQLite.');
    }

    nightly_log('Stap 3/3: ontbrekende Webfleet-maanden bijwerken.');
    timesheet_sync_pending_webfleet($db, $base, $auth, $ttl, 'nightly_log');

    timesheet_store_meta_set($db, 'last_nightly_at', (string) time());
    $elapsed = round(microtime(true) - $startedAt, 1);
    nightly_log("Nightly voltooid in {$elapsed}s.");
} catch (Throwable $e) {
    http_response_code(500);
    nightly_log('FOUT: ' . $e->getMessage());
    nightly_log($e->getFile() . ':' . $e->getLine());
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
