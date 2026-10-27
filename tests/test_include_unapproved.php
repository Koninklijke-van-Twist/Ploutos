<?php

/**
 * Niet-goedgekeurde urenstaten tellen alleen mee als includeUnapproved=1.
 * Uit houdt het huidige gedrag: alleen Status Approved.
 * Run: php tests/test_include_unapproved.php
 */

require_once dirname(__DIR__) . '/web' . '/lib_times.php';

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

foreach (['Approved', 'approved', ' APPROVED ', 'Goedgekeurd', 'goedgekeurd', ' Goedgekeurd '] as $status) {
    $line = test_line($status, 'SNT', [1 => 8]);
    test_assert(json_encode($status) . ' telt uit', timesheet_line_counts_as_approved($line, false));
    test_assert(json_encode($status) . ' telt aan', timesheet_line_counts_as_approved($line, true));
    test_assert(json_encode($status) . ' niet oranje', timesheet_line_highlight_class($line, true) === '');
    test_assert(json_encode($status) . ' toont origineel', timesheet_line_display_status($line) === $status);
}
foreach (['Open', 'Submitted', 'Rejected', '', 'Ingediend', ' ingediend ', 'Afgewezen', 'Afgekeurd'] as $status) {
    $line = test_line($status, 'SNT', [1 => 4]);
    test_assert(json_encode($status) . ' telt niet uit', timesheet_line_counts_as_approved($line, false) === false);
    test_assert(json_encode($status) . ' telt wel aan', timesheet_line_counts_as_approved($line, true));
    test_assert(json_encode($status) . ' oranje alleen aan', timesheet_line_highlight_class($line, false) === ''
        && timesheet_line_highlight_class($line, true) === 'counted-unapproved');
    test_assert(json_encode($status) . ' toont origineel', timesheet_line_display_status($line) === $status);
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
    'weekinspectie aan bewaart de BC-status en zet alleen de telvlag',
    ($inspectionOn[0]['Status'] ?? '') === 'Approved'
        && ($inspectionOn[0]['original_status'] ?? '') === 'Approved'
        && !empty($inspectionOn[0]['treated_as_approved'])
        && ($inspectionOn[1]['Status'] ?? '') === 'Open'
        && ($inspectionOn[1]['original_status'] ?? '') === 'Open'
        && !empty($inspectionOn[1]['treated_as_approved'])
        && ($inspectionOn[2]['Status'] ?? '') === 'Rejected'
        && ($inspectionOn[2]['original_status'] ?? '') === 'Rejected'
        && ($inspectionOn[1]['Field1'] ?? 0) === 2.0
        && ($mixed[1]['Status'] ?? '') === 'Open'
        && !array_key_exists('original_status', $mixed[1])
);
test_assert(
    'mix: Approved blijft zichtbaar en zonder oranje',
    timesheet_line_display_status($inspectionOn[0]) === 'Approved'
        && timesheet_line_highlight_class($inspectionOn[0], true) === ''
        && timesheet_line_highlight_row_attr($inspectionOn[0], true) === ''
);
test_assert(
    'mix: Open en Rejected blijven hun status en zijn oranje',
    timesheet_line_display_status($inspectionOn[1]) === 'Open'
        && timesheet_line_display_status($inspectionOn[2]) === 'Rejected'
        && timesheet_line_highlight_class($inspectionOn[1], true) === 'counted-unapproved'
        && timesheet_line_highlight_class($inspectionOn[2], true) === 'counted-unapproved'
        && timesheet_line_highlight_class($inspectionOn[1], false) === ''
        && timesheet_line_highlight_row_attr($inspectionOn[1], true) === ' class="counted-unapproved"'
);
$overwritten = test_line('Approved', 'SNT', [1 => 3]);
$overwritten['original_status'] = 'Open';
$overwritten['treated_as_approved'] = true;
test_assert(
    'overschreven Status toont origineel en telt niet als echt Approved',
    timesheet_line_display_status($overwritten) === 'Open'
        && timesheet_line_counts_as_approved($overwritten, false) === false
        && timesheet_line_highlight_class($overwritten, true) === 'counted-unapproved'
        && timesheet_line_passes_approved_only_filter($overwritten)
);
$reallyApproved = test_line('Approved', 'SNT', [1 => 8]);
$reallyApproved['original_status'] = 'Approved';
$reallyApproved['treated_as_approved'] = true;
test_assert(
    'echte Approved met telvlag is niet oranje',
    timesheet_line_display_status($reallyApproved) === 'Approved'
        && timesheet_line_highlight_class($reallyApproved, true) === ''
        && timesheet_line_counts_as_approved($reallyApproved, false)
);
$mixWeek = test_blank_week();
foreach ($mixed as $mixLine) {
    $mixWeek = timesheet_overview_apply_line($mixWeek, $mixLine, true, $codesOnkosten, $codesVerlet);
}
test_assert(
    'mix-week scheidt goedgekeurd en niet-goedgekeurd',
    !empty($mixWeek['hasApproved'])
        && !empty($mixWeek['hasUnapproved'])
        && (int) $mixWeek['unapprovedCount'] === 2
        && (float) $mixWeek['normalHours'] === 10.0
        && test_salary_hours($mixWeek)['h285'] === 1.0
        && timesheet_counted_unapproved_row_attr(true, !empty($mixWeek['hasUnapproved'])) === ' class="counted-unapproved"'
);
$approvedOnlyWeek = timesheet_overview_apply_line(test_blank_week(), $mixed[0], true, $codesOnkosten, $codesVerlet);
test_assert(
    'alleen-Approved week is niet oranje',
    !empty($approvedOnlyWeek['hasApproved'])
        && empty($approvedOnlyWeek['hasUnapproved'])
        && timesheet_counted_unapproved_row_attr(true, !empty($approvedOnlyWeek['hasUnapproved'])) === ''
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

test_assert('markering-klasse', timesheet_counted_unapproved_class() === 'counted-unapproved');
test_assert('markering uit bij optie uit', timesheet_row_counts_as_unapproved_highlight(false, true) === '');
test_assert('markering uit zonder niet-goedgekeurd', timesheet_row_counts_as_unapproved_highlight(true, false) === '');
test_assert(
    'markering aan',
    timesheet_row_counts_as_unapproved_highlight(true, true) === 'counted-unapproved'
);
$highlightAttr = timesheet_counted_unapproved_row_attr(true, true);
test_assert('rij-attribuut leeg als optie uit', timesheet_counted_unapproved_row_attr(false, true) === '');
test_assert('rij-attribuut leeg zonder niet-goedgekeurd', timesheet_counted_unapproved_row_attr(true, false) === '');
test_assert('rij-attribuut aan', $highlightAttr === ' class="counted-unapproved"');
foreach (['Open', 'Submitted', 'Rejected', ''] as $status) {
    $line = test_line($status, 'SNT', [1 => 1]);
    test_assert(
        'regel ' . ($status === '' ? 'leeg' : $status) . ' oranje alleen aan',
        timesheet_line_highlight_class($line, false) === ''
            && timesheet_line_highlight_class($line, true) === 'counted-unapproved'
            && timesheet_line_highlight_row_attr($line, true) === $highlightAttr
            && timesheet_line_highlight_row_attr($line, false) === ''
    );
}
$approvedHighlight = test_line('Approved', 'SNT', [1 => 8]);
test_assert(
    'goedgekeurde regel blijft zonder oranje',
    timesheet_line_highlight_class($approvedHighlight, true) === ''
        && timesheet_line_highlight_row_attr($approvedHighlight, true) === ''
);

test_assert('normalisatie trim en case', timesheet_normalize_status('  GoedgeKEURD ') === 'approved');
test_assert('normalisatie open', timesheet_normalize_status(' Open ') === 'open');
test_assert('normalisatie ingediend', timesheet_normalize_status('INGEDIEND') === 'submitted');
test_assert('normalisatie afgewezen', timesheet_normalize_status('Afgewezen') === 'rejected');
test_assert('normalisatie afgekeurd', timesheet_normalize_status('afgekeurd') === 'rejected');
test_assert('normalisatie onbekend', timesheet_normalize_status(' Concept ') === 'concept');
test_assert('normalisatie leeg', timesheet_normalize_status('   ') === '');
test_assert(
    'css-klasse volgt normalisatie',
    timesheet_status_css_class('Goedgekeurd') === 'approvedStatus'
        && timesheet_status_css_class(' ingediend ') === 'submittedStatus'
        && timesheet_status_css_class('Afgewezen') === 'rejectedStatus'
        && timesheet_status_css_class('Open') === 'openStatus'
        && timesheet_status_css_class('Concept') === ''
);

$nlOnkostenApproved = timesheet_overview_apply_line(
    test_blank_week(),
    test_line('Goedgekeurd', 'ONK', [], 9.5),
    false,
    $codesOnkosten,
    $codesVerlet
);
$nlOnkostenOpenOff = timesheet_overview_apply_line(
    test_blank_week(),
    test_line('Ingediend', 'ONK', [], 4),
    false,
    $codesOnkosten,
    $codesVerlet
);
$nlOnkostenOpenOn = timesheet_overview_apply_line(
    test_blank_week(),
    test_line('Ingediend', 'ONK', [], 4),
    true,
    $codesOnkosten,
    $codesVerlet
);
test_assert(
    'onkosten Goedgekeurd telt zonder optie en zonder waarschuwing',
    (float) $nlOnkostenApproved['onkosten'] === 9.5
        && empty($nlOnkostenApproved['hasUnapproved'])
        && !empty($nlOnkostenApproved['hasApproved'])
);
test_assert(
    'onkosten Ingediend uit geeft waarschuwing en telt niet',
    (float) $nlOnkostenOpenOff['onkosten'] === 0.0
        && !empty($nlOnkostenOpenOff['hasUnapproved'])
        && (int) $nlOnkostenOpenOff['unapprovedCount'] === 1
);
test_assert(
    'onkosten Ingediend aan telt mee en blijft een waarschuwing',
    (float) $nlOnkostenOpenOn['onkosten'] === 4.0
        && !empty($nlOnkostenOpenOn['hasUnapproved'])
);
$nlSotOff = timesheet_overview_apply_line(test_blank_week(), test_line('Afgewezen', 'SOT125', [1 => 2]), false, $codesOnkosten, $codesVerlet);
$nlSotOn = timesheet_overview_apply_line(test_blank_week(), test_line('Afgewezen', 'SOT125', [1 => 2]), true, $codesOnkosten, $codesVerlet);
test_assert('salaris Afgewezen uit niet', test_salary_hours($nlSotOff)['h285'] === 0.0 && !empty($nlSotOff['hasUnapproved']));
test_assert('salaris Afgewezen aan wel en oranje week', test_salary_hours($nlSotOn)['h285'] === 2.0 && !empty($nlSotOn['hasUnapproved']));
$nlApprovedHours = timesheet_overview_apply_line(test_blank_week(), test_line('Goedgekeurd', 'SOT125', [1 => 3]), false, $codesOnkosten, $codesVerlet);
test_assert(
    'salaris Goedgekeurd telt uit en week is niet oranje',
    test_salary_hours($nlApprovedHours)['h285'] === 3.0
        && empty($nlApprovedHours['hasUnapproved'])
        && timesheet_counted_unapproved_row_attr(true, !empty($nlApprovedHours['hasUnapproved'])) === ''
);
$nlMix = test_blank_week();
$nlMix = timesheet_overview_apply_line($nlMix, test_line('Approved', 'SNT', [1 => 8]), true, $codesOnkosten, $codesVerlet);
$nlMix = timesheet_overview_apply_line($nlMix, test_line('Ingediend', 'SNT', [2 => 1]), true, $codesOnkosten, $codesVerlet);
test_assert(
    'mix Engels en Nederlands: alleen Ingediend is niet goedgekeurd',
    !empty($nlMix['hasApproved'])
        && !empty($nlMix['hasUnapproved'])
        && (int) $nlMix['unapprovedCount'] === 1
        && (float) $nlMix['normalHours'] === 9.0
        && timesheet_line_highlight_class(test_line('Goedgekeurd', 'SNT'), true) === ''
        && timesheet_line_highlight_class(test_line('Ingediend', 'SNT'), true) === 'counted-unapproved'
        && timesheet_line_display_status(test_line('Ingediend', 'SNT')) === 'Ingediend'
);

$root = dirname(__DIR__) . '/web';
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
test_assert(
    'overzicht markeert weekrijen die als goedgekeurd meetellen',
    str_contains($overzicht, 'timesheet_counted_unapproved_row_attr($includeUnapproved, !empty($w[\'hasUnapproved\']))')
        && substr_count($overzicht, 'timesheet_counted_unapproved_row_attr(') >= 2
        && str_contains($overzicht, 'tr.counted-unapproved > td')
        && str_contains($overzicht, 'background-color: #ffb347')
);
test_assert(
    'salarisspecificatie markeert niet-goedgekeurde weken op scherm',
    str_contains($overzicht, 'if (!includeUnapproved || !week || !week.hasUnapproved)')
        && str_contains($overzicht, "return ' class=\"counted-unapproved\"';")
        && str_contains($overzicht, 'countedUnapprovedAttr(week)')
        && str_contains($overzicht, '@media screen')
        && str_contains($overzicht, '.salary-table td.counted-unapproved')
        && str_contains($overzicht, '.salary-table th.counted-unapproved')
);
$printStart = strpos($overzicht, 'body.salary-slip-open .print-modal');
$printSlice = $printStart === false ? '' : substr($overzicht, $printStart, 900);
test_assert(
    'print verbergt de meetelezin',
    str_contains($overzicht, '<noprint><div class="include-unapproved-note">Niet-goedgekeurde urenstaten zijn meegeteld alsof ze goedgekeurd zijn.</div></noprint>')
        && str_contains($printSlice, '#salarySlipContent .include-unapproved-note')
        && str_contains($printSlice, 'display: none !important')
);
test_assert(
    'print verbergt oranje achtergrond op de salarisspecificatie',
    str_contains($printSlice, '#salarySlipContent th.counted-unapproved')
        && str_contains($printSlice, 'background-color: #f0f0f0 !important')
        && str_contains($printSlice, '#salarySlipContent td.counted-unapproved')
        && str_contains($printSlice, 'background-color: transparent !important')
);
test_assert('weekinspectie leest de stand', str_contains($weekinspectie, 'timesheet_include_unapproved_from_request($_GET)'));
test_assert('weekinspectie telt met dezelfde stand', str_contains($weekinspectie, 'timesheet_inspection_lines_as_counted('));
test_assert(
    'weekinspectie markeert niet-goedgekeurde regels',
    str_contains($weekinspectie, 'timesheet_line_highlight_row_attr($l, $includeUnapproved)')
        && str_contains($weekinspectie, 'timesheet_line_display_status($l)')
        && str_contains($weekinspectie, 'timesheet_status_css_class($status)')
        && str_contains($weekinspectie, 'foreach ($displayLines as $l)')
        && str_contains($weekinspectie, 'tr.counted-unapproved > td')
        && str_contains($weekinspectie, 'background-color: #ffb347')
);
test_assert(
    'overzicht kleurt status via normalisatie',
    str_contains($overzicht, 'return timesheet_status_css_class($status);')
);
$lib = (string) file_get_contents($root . '/lib_times.php');
test_assert(
    'BC-status wordt niet overschreven naar Approved',
    !str_contains($lib, "\$line['Status'] = 'Approved'")
        && str_contains($lib, "\$line['original_status']")
        && str_contains($lib, "\$line['treated_as_approved']")
);
test_assert(
    'statusvergelijking loopt via timesheet_normalize_status',
    str_contains($lib, 'function timesheet_normalize_status')
        && !preg_match("/===\\s*'Approved'/", $lib)
        && !preg_match('/===\\s*"Approved"/', $lib)
        && !preg_match("/!==\\s*'Approved'/", $lib)
);
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
