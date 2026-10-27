<?php
/** Run: php tests/backfill_diff_test.php */
require __DIR__ . '/../web/lib_timesheet_store.php';
require __DIR__ . '/../web/lib_timesheet_backfill.php';

$fail = 0;
$check = function (string $label, $got, $want) use (&$fail) {
    if ($got !== $want) {
        $fail++;
        echo "FAIL {$label}: " . json_encode($got) . ' != ' . json_encode($want) . "\n";
    }
};
$ts = fn($no, $start) => ['No' => $no, 'Starting_Date' => $start, 'Description' => 'Week', 'Resource_No' => '1597', 'Resource_Name' => 'Nederlof V.'];
$line = fn($no, $ln, $qty, $status = 'Approved') => ['Time_Sheet_No' => $no, 'Line_No' => $ln, 'Header_Resource_No' => '1597', 'Status' => $status, 'Total_Quantity' => $qty];

$bcTs = [$ts('TS1', '2026-08-17'), $ts('TS2', '2026-08-24'), $ts('TS3', '2026-08-31'), $ts('TS4', '2026-08-10')];
$bcLines = [$line('TS1', 10000, 8.0), $line('TS2', 10000, 59.75), $line('TS3', 10000, 8.0)]; // TS4 leeg
$cacheTs = [$ts('TS1', '2026-08-17'), $ts('TS3', '2026-08-31')];
$cacheLines = [$line('TS1', 10000, 8.0), $line('TS3', 10000, 8.0, 'Submitted')];

$d = timesheet_backfill_diff($bcTs, $bcLines, $cacheTs, $cacheLines);
$check('aantal', count($d), 2);
$check('ontbreekt', [$d[0]['tsNo'], $d[0]['kind'], $d[0]['bcHours'], $d[0]['cacheHours']], ['TS2', 'ontbreekt', 59.75, 0]);
$check('verschilt', [$d[1]['tsNo'], $d[1]['kind'], $d[1]['bcStatus'], $d[1]['cacheStatus']], ['TS3', 'verschilt', 'Approved: 1', 'Submitted: 1']);
$check('gelijk', timesheet_backfill_diff($cacheTs, $cacheLines, $cacheTs, $cacheLines), []);
$check('header zonder regels in cache', timesheet_backfill_diff([$ts('TS1', 'x')], [$line('TS1', 1, 1.0)], [$ts('TS1', 'x')], [])[0]['kind'], 'ontbreekt');
$check('12 maanden', timesheet_backfill_months(null, '2026-10'), ['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
$check('jaar', count(timesheet_backfill_months(2025, '2026-10')), 12);
$m = timesheet_backfill_months(2026, "2026-10");
$check("lopend jaar", end($m), "2026-10");

$tries = 0;
$r = timesheet_backfill_with_retry(function () use (&$tries) {
    if (++$tries < 2) {
        throw new RuntimeException('BC-concurrency limiet bereikt');
    }
    return 'ok';
}, fn() => null, [0]);
$check('retry', [$r, $tries], ['ok', 2]);

echo $fail ? "{$fail} fout(en)\n" : "OK\n";
exit($fail ? 1 : 0);
