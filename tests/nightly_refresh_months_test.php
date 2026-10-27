<?php
/**
 * Regressie: nightly moet ook afgesloten vorige maanden verversen, zodat
 * urenstaten die na de maandwissel zijn ingevuld/goedgekeurd (bv. W35 2026)
 * niet ontbreken. Run: php tests/nightly_refresh_months_test.php
 */
require __DIR__ . '/../web/lib_timesheet_store.php';
require __DIR__ . '/../web/lib_timesheet_sync.php';

$fail = 0;
$check = function (string $label, $got, $want) use (&$fail) {
    if ($got !== $want) {
        $fail++;
        echo "FAIL {$label}: " . json_encode($got) . ' != ' . json_encode($want) . "\n";
    }
};
$check('oktober', timesheet_sync_refresh_months('2026-10'), ['2026-10', '2026-09', '2026-08', '2026-07', '2026-06', '2026-05']);
$check('jaarwissel', timesheet_sync_refresh_months('2027-01'), ['2027-01', '2026-12', '2026-11', '2026-10', '2026-09', '2026-08']);
$check('alleen huidig', timesheet_sync_refresh_months('2026-10', 0), ['2026-10']);
echo $fail ? "{$fail} fout(en)\n" : "OK\n";
exit($fail ? 1 : 0);
