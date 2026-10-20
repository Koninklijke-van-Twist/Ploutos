<?php

/**
 * Niet-goedgekeurde urenstaten tellen alleen mee als includeUnapproved=1.
 * Uit houdt het huidige gedrag: alleen Status Approved.
 * Run: php web/tests/test_include_unapproved.php
 */

require_once dirname(__DIR__) . '/lib_times.php';

$failures = 0;

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

function test_blank_week(): array
{
    return [
        'p285' => 0,
        'p47' => 0,
        'p85' => 0,
        'onkosten' => 0.0,
        'verlet' => 0.0,
        'lines' => 0,
        'dayHours' => [0, 0, 0, 0, 0, 0, 0],
        'normalHours' => 0.0,
        'normalDayHours' => [0, 0, 0, 0, 0, 0, 0],
        'hasUnapproved' => false,
        'unapprovedCount' => 0,
        'validationLines' => [],
    ];
}

function test_line(string $status, string $workType, array $fields = [], float $total = 0.0): array
{
    $line = [
        'Status' => $status,
        'Work_Type_Code' => $workType,
        'Total_Quantity' => $total,
        'Job_Task_No' => 'TASK-1',
        'Header_Resource_No' => 'R1',
        'Time_Sheet_No' => 'TS1',
    ];
    for ($i = 1; $i <= 7; $i++) {
        $line['Field' . $i] = (float) ($fields[$i] ?? 0);
    }
    return $line;
}

function test_salary_hours(array $week): array
{
    return [
        'h285' => ((float) $week['p285']) / 60,
        'h47' => ((float) $week['p47']) / 60,
        'h85' => ((float) $week['p85']) / 60,
        'normal' => (float) $week['normalHours'],
        'onkosten' => (float) $week['onkosten'],
        'verlet' => (float) $week['verlet'],
        'lines' => (int) $week['lines'],
    ];
}

$codesOnkosten = ['ONK', 'ONKOST', 'EXP'];
$codesVerlet = ['VER', 'VERLET'];

test_assert('param-naam', timesheet_include_unapproved_param() === 'includeUnapproved');

foreach ([
    [],
    ['includeUnapproved' => ''],
    ['includeUnapproved' => '0'],
    ['includeUnapproved' => 'false'],
    ['includeUnapproved' => 'true'],
    ['includeUnapproved' => 'on'],
    ['includeUnapproved' => '2'],
    ['includeUnapproved' => ['1']],
] as $query) {
    test_assert(
        'stand uit voor ' . json_encode($query),
        timesheet_include_unapproved_from_request($query) === false
    );
}
test_assert(
    'stand aan voor 1',
    timesheet_include_unapproved_from_request(['includeUnapproved' => '1']) === true
);
test_assert(
    'query-suffix uit is leeg',
    timesheet_include_unapproved_query_suffix(false) === ''
);
test_assert(
    'query-suffix aan',
    timesheet_include_unapproved_query_suffix(true) === '&includeUnapproved=1'
);
test_assert(
    'hidden input uit is leeg',
    timesheet_include_unapproved_hidden_input(false) === ''
);
$hiddenOn = timesheet_include_unapproved_hidden_input(true);
test_assert(
    'hidden input aan',
    str_contains($hiddenOn, 'name="includeUnapproved"') && str_contains($hiddenOn, 'value="1"')
);

foreach (['Approved'] as $status) {
    $line = test_line($status, 'SNT', [1 => 8]);
    test_assert($status . ' telt uit', timesheet_line_counts_as_approved($line, false));
    test_assert($status . ' telt aan', timesheet_line_counts_as_approved($line, true));
}
foreach (['Open', 'Submitted', 'Rejected', ''] as $status) {
    $line = test_line($status, 'SNT', [1 => 4]);
    test_assert($status . ' telt niet uit', timesheet_line_counts_as_approved($line, false) === false);
    test_assert($status . ' telt wel aan', timesheet_line_counts_as_approved($line, true));
}

$approved = test_line('Approved', 'SNT', [1 => 8]);
$weekOff = timesheet_overview_apply_line(test_blank_week(), $approved, false, $codesOnkosten, $codesVerlet);
$weekOn = timesheet_overview_apply_line(test_blank_week(), $approved, true, $codesOnkosten, $codesVerlet);
test_assert(
    'goedgekeurde regel is gelijk met stand uit en aan',
    test_salary_hours($weekOff) === test_salary_hours($weekOn)
        && $weekOff['hasUnapproved'] === false
        && $weekOn['hasUnapproved'] === false
        && $weekOff['dayHours'][0] === 8.0
        && count($weekOff['validationLines']) === 1
);

$open = test_line('Open', 'SNT', [1 => 4, 2 => 1]);
$openOff = timesheet_overview_apply_line(test_blank_week(), $open, false, $codesOnkosten, $codesVerlet);
test_assert(
    'open regel telt niet mee uit',
    $openOff['hasUnapproved'] === true
        && (int) $openOff['unapprovedCount'] === 1
        && $openOff['dayHours'] === [0, 0, 0, 0, 0, 0, 0]
        && (int) $openOff['lines'] === 0
        && $openOff['validationLines'] === []
        && test_salary_hours($openOff) === test_salary_hours(test_blank_week())
);
$openOn = timesheet_overview_apply_line(test_blank_week(), $open, true, $codesOnkosten, $codesVerlet);
test_assert(
    'open regel telt mee aan en blijft een waarschuwing',
    $openOn['hasUnapproved'] === true
        && (int) $openOn['unapprovedCount'] === 1
        && $openOn['dayHours'][0] === 4.0
        && $openOn['dayHours'][1] === 1.0
        && (float) $openOn['normalHours'] === 5.0
        && (int) $openOn['lines'] === 1
        && count($openOn['validationLines']) === 1
        && test_salary_hours($openOn)['normal'] === 5.0
);

$sot = test_line('Submitted', 'SOT125', [1 => 2]);
$sotOff = timesheet_overview_apply_line(test_blank_week(), $sot, false, $codesOnkosten, $codesVerlet);
$sotOn = timesheet_overview_apply_line(test_blank_week(), $sot, true, $codesOnkosten, $codesVerlet);
test_assert('SOT125 uit niet in salarisspecificatie', test_salary_hours($sotOff)['h285'] === 0.0);
test_assert('SOT125 aan wel in salarisspecificatie', test_salary_hours($sotOn)['h285'] === 2.0);

$sot150 = test_line('Rejected', 'SOT150', [6 => 3]);
$sot200 = test_line('Open', 'SOT200', [7 => 1]);
test_assert(
    '47% en 85% aan',
    test_salary_hours(timesheet_overview_apply_line(test_blank_week(), $sot150, true, $codesOnkosten, $codesVerlet))['h47'] === 3.0
        && test_salary_hours(timesheet_overview_apply_line(test_blank_week(), $sot200, true, $codesOnkosten, $codesVerlet))['h85'] === 1.0
);
test_assert(
    '47% en 85% uit',
    test_salary_hours(timesheet_overview_apply_line(test_blank_week(), $sot150, false, $codesOnkosten, $codesVerlet))['h47'] === 0.0
        && test_salary_hours(timesheet_overview_apply_line(test_blank_week(), $sot200, false, $codesOnkosten, $codesVerlet))['h85'] === 0.0
);

$onk = test_line('Open', 'ONK', [], 12.5);
$onkOff = timesheet_overview_apply_line(test_blank_week(), $onk, false, $codesOnkosten, $codesVerlet);
$onkOn = timesheet_overview_apply_line(test_blank_week(), $onk, true, $codesOnkosten, $codesVerlet);
test_assert('onkosten uit blijft 0 en telt geen regel', (float) $onkOff['onkosten'] === 0.0 && (int) $onkOff['lines'] === 0);
test_assert('onkosten aan telt bedrag zonder regel', (float) $onkOn['onkosten'] === 12.5 && (int) $onkOn['lines'] === 0);

$ver = test_line('Submitted', 'VERLET', [], 4);
test_assert(
    'verlet alleen aan',
    (float) timesheet_overview_apply_line(test_blank_week(), $ver, false, $codesOnkosten, $codesVerlet)['verlet'] === 0.0
        && (float) timesheet_overview_apply_line(test_blank_week(), $ver, true, $codesOnkosten, $codesVerlet)['verlet'] === 4.0
);

$two = timesheet_overview_apply_line(
    timesheet_overview_apply_line(test_blank_week(), $open, false, $codesOnkosten, $codesVerlet),
    test_line('Rejected', 'SNT', [3 => 2]),
    false,
    $codesOnkosten,
    $codesVerlet
);
test_assert('twee niet-goedgekeurde regels uit', (int) $two['unapprovedCount'] === 2 && (int) $two['lines'] === 0);

$invalidOpen = test_line('Open', 'SNT', [1 => 30]);
$invalidOff = timesheet_overview_apply_line(test_blank_week(), $invalidOpen, false, $codesOnkosten, $codesVerlet);
$invalidOn = timesheet_overview_apply_line(test_blank_week(), $invalidOpen, true, $codesOnkosten, $codesVerlet);
test_assert(
    'onjuiste open regel uit niet in validatie',
    detect_hour_entry_issues($invalidOff['validationLines'])['hasIssues'] === false
);
test_assert(
    'onjuiste open regel aan wel in validatie en uren',
    detect_hour_entry_issues($invalidOn['validationLines'])['hasIssues'] === true
        && $invalidOn['dayHours'][0] === 30.0
);

$mixed = [
    test_line('Approved', 'SNT', [1 => 8]),
    test_line('Open', 'SNT', [1 => 2]),
    test_line('Rejected', 'SOT125', [1 => 1]),
];
$mixed[0]['Job_Task_No'] = 'T-APPROVED';
$mixed[1]['Job_Task_No'] = 'T-OPEN';
$mixed[2]['Job_Task_No'] = 'T-REJECTED';
$countedOff = timesheet_lines_counting_as_approved($mixed, false);
$countedOn = timesheet_lines_counting_as_approved($mixed, true);
$countedOffAgain = timesheet_lines_counting_as_approved($mixed, false);
test_assert('cache uit alleen Approved', count($countedOff) === 1 && ($countedOff[0]['Job_Task_No'] ?? '') === 'T-APPROVED');
test_assert('cache aan ook niet-goedgekeurd', count($countedOn) === 3);
test_assert('cache uit blijft gescheiden van aan', count($countedOffAgain) === 1 && ($countedOffAgain[0]['Status'] ?? '') === 'Approved');
$cacheOff = timesheet_approval_view_cache_key('overview-lines', false);
$cacheOn = timesheet_approval_view_cache_key('overview-lines', true);
test_assert(
    'cache-sleutel per stand',
    $cacheOff !== $cacheOn
        && str_contains($cacheOff, 'approved-only')
        && str_contains($cacheOn, 'include-unapproved')
);

$inspectionOff = timesheet_inspection_lines_as_counted($mixed, false);
test_assert('weekinspectie uit is ongewijzigd', $inspectionOff === $mixed);
$inspectionOn = timesheet_inspection_lines_as_counted($mixed, true);
test_assert(
    'weekinspectie aan behandelt status als Approved',
    ($inspectionOn[1]['Status'] ?? '') === 'Approved'
        && ($inspectionOn[2]['Status'] ?? '') === 'Approved'
        && ($inspectionOn[1]['Field1'] ?? 0) === 2.0
        && ($mixed[1]['Status'] ?? '') === 'Open'
);
$totalsOff = detect_hour_entry_issues($inspectionOff);
$totalsDirect = detect_hour_entry_issues($mixed);
$totalsOn = detect_hour_entry_issues($inspectionOn);
test_assert(
    'weekinspectie-totalen uit gelijk aan huidig',
    $totalsOff === $totalsDirect && ($totalsOff['dayTotals'][0] ?? 0) === 11.0
);
test_assert(
    'weekinspectie-totalen aan tellen niet-goedgekeurd mee',
    ($totalsOn['dayTotals'][0] ?? 0) === 11.0
);
test_assert(
    'approved-only weekinspectie sluit uit en telt aan',
    (detect_hour_entry_issues($inspectionOff, true)['dayTotals'][0] ?? -1) === 8.0
        && (detect_hour_entry_issues($inspectionOn, true)['dayTotals'][0] ?? -1) === 11.0
);

$root = dirname(__DIR__);
$overzicht = (string) file_get_contents($root . '/overzicht.php');
$weekinspectie = (string) file_get_contents($root . '/weekinspectie.php');
$onkosten = (string) file_get_contents($root . '/onkosten_editor.php');
test_assert('overzicht label', str_contains($overzicht, 'Niet-goedgekeurde staten ook tonen'));
test_assert('overzicht checkbox standaard via request', str_contains($overzicht, "timesheet_include_unapproved_from_request(\$_GET)"));
test_assert('overzicht past regel toe', str_contains($overzicht, 'timesheet_overview_apply_line('));
test_assert('overzicht gebruikt gescheiden telling', str_contains($overzicht, 'timesheet_lines_counting_as_approved('));
test_assert('overzicht geeft stand door in links', str_contains($overzicht, 'timesheet_include_unapproved_query_suffix($includeUnapproved)'));
test_assert(
    'salarisspecificatie krijgt de stand en de weektotalen',
    str_contains($overzicht, 'openPrintModal(event,')
        && str_contains($overzicht, 'function openPrintModal (event, person, includeUnapproved)')
        && str_contains($overzicht, 'Niet-goedgekeurde urenstaten zijn meegeteld alsof ze goedgekeurd zijn.')
        && str_contains($overzicht, '(week.p285 || 0) / 60')
);
test_assert('weekinspectie leest de stand', str_contains($weekinspectie, 'timesheet_include_unapproved_from_request($_GET)'));
test_assert('weekinspectie telt met dezelfde stand', str_contains($weekinspectie, 'timesheet_inspection_lines_as_counted('));
test_assert('weekinspectie geeft de stand door', str_contains($weekinspectie, 'timesheet_include_unapproved_query_suffix($includeUnapproved)'));
test_assert('onkosten leest de stand', str_contains($onkosten, 'timesheet_include_unapproved_from_request('));
test_assert('onkosten geeft de stand door', str_contains($onkosten, 'timesheet_include_unapproved_query_suffix($includeUnapproved)'));
test_assert('onkosten verbergt de stand in het formulier', str_contains($onkosten, 'timesheet_include_unapproved_hidden_input($includeUnapproved)'));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}

echo "Alle tests geslaagd\n";
exit(0);
