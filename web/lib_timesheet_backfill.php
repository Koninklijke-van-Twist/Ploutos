<?php
/**
 * Vergelijking BC ↔ lokale cache voor backfill_year.php (zuivere functies, testbaar).
 */

require_once __DIR__ . '/lib_times.php'; // timesheet_normalize_status

/** Maanden (Y-m) voor een kalenderjaar, of de laatste 12 maanden t/m $currentYm. */
function timesheet_backfill_months(?int $year, string $currentYm): array
{
    $months = [];
    if ($year !== null) {
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            if ($ym <= $currentYm) {
                $months[] = $ym;
            }
        }
        return $months;
    }
    $ym = $currentYm;
    for ($i = 0; $i < 12; $i++) {
        array_unshift($months, $ym);
        $ym = timesheet_store_previous_month($ym);
    }
    return $months;
}

/** Velden die de cache opslaat en die in het rapport per regel vergeleken worden. */
const TIMESHEET_BACKFILL_LINE_FIELDS = ['Type', 'Status', 'Job_No', 'Job_Task_No', 'Work_Type_Code', 'Cause_of_Absence_Code',
    'Description', 'Field1', 'Field2', 'Field3', 'Field4', 'Field5', 'Field6', 'Field7', 'Total_Quantity'];

/**
 * Waarde zoals de cache hem opslaat, voor vergelijking. Status gaat via
 * timesheet_normalize_status: BC levert die soms Engels, soms Nederlands.
 */
function timesheet_backfill_norm(string $field, $value): string
{
    if ($field === 'Status') {
        return timesheet_normalize_status((string) $value);
    }
    if ($field === 'Total_Quantity' || preg_match('/^Field\d$/', $field)) {
        return number_format((float) $value, 4, '.', '');
    }
    return (string) $value;
}

/** Namen van velden die tussen cache- en BC-regel verschillen. */
function timesheet_backfill_changed_fields(array $cache, array $bc): array
{
    $changed = [];
    foreach (TIMESHEET_BACKFILL_LINE_FIELDS as $f) {
        if (timesheet_backfill_norm($f, $cache[$f] ?? '') !== timesheet_backfill_norm($f, $bc[$f] ?? '')) {
            $changed[] = $f;
        }
    }
    return $changed;
}

function timesheet_backfill_status_summary(array $lines): string
{
    $counts = [];
    foreach ($lines as $l) {
        $s = (string) ($l['Status'] ?? '');
        $counts[$s === '' ? '?' : $s] = ($counts[$s === '' ? '?' : $s] ?? 0) + 1;
    }
    ksort($counts);
    $out = [];
    foreach ($counts as $s => $n) {
        $out[] = "{$s}: {$n}";
    }
    return implode(', ', $out);
}

/**
 * Per urenstaat: 'ontbreekt' (niet of zonder regels in cache) of 'verschilt' (regels anders).
 * 'lines': per regelnummer cache (null = ontbrak in Ploutos), bc (null = alleen in cache) en changed-velden.
 * @return array<int,array<string,mixed>>
 */
function timesheet_backfill_diff(array $bcTimesheets, array $bcLines, array $cacheTimesheets, array $cacheLines): array
{
    $group = function (array $lines): array {
        $by = [];
        foreach ($lines as $l) {
            $tsNo = (string) ($l['Time_Sheet_No'] ?? '');
            if ($tsNo !== '') {
                $by[$tsNo][timesheet_store_line_no($l)] = $l;
            }
        }
        return $by;
    };
    $bcByTs = $group($bcLines);
    $cacheByTs = $group($cacheLines);
    $cacheTsNos = [];
    foreach ($cacheTimesheets as $t) {
        $cacheTsNos[(string) ($t['No'] ?? '')] = true;
    }

    $diffs = [];
    foreach ($bcTimesheets as $ts) {
        $no = (string) ($ts['No'] ?? '');
        $bc = $bcByTs[$no] ?? [];
        if ($no === '' || !$bc) {
            continue; // lege urenstaat in BC: niets te tonen
        }
        $cache = isset($cacheTsNos[$no]) ? ($cacheByTs[$no] ?? []) : [];
        $lineNos = array_unique(array_merge(array_keys($cache), array_keys($bc)));
        sort($lineNos);
        $lineDiffs = [];
        foreach ($lineNos as $ln) {
            $c = $cache[$ln] ?? null;
            $b = $bc[$ln] ?? null;
            $changed = ($c && $b) ? timesheet_backfill_changed_fields($c, $b) : TIMESHEET_BACKFILL_LINE_FIELDS;
            if ($changed) {
                $lineDiffs[] = ['lineNo' => $ln, 'cache' => $c, 'bc' => $b, 'changed' => $changed];
            }
        }
        if (!$lineDiffs) {
            continue;
        }
        $sum = fn(array $ls) => array_sum(array_map(fn($l) => (float) ($l['Total_Quantity'] ?? 0), $ls));
        $diffs[] = [
            'tsNo' => $no,
            'kind' => $cache ? 'verschilt' : 'ontbreekt',
            'resourceNo' => (string) ($ts['Resource_No'] ?? ''),
            'resourceName' => (string) ($ts['Resource_Name'] ?? ''),
            'weekStart' => (string) ($ts['Starting_Date'] ?? ''),
            'description' => (string) ($ts['Description'] ?? ''),
            'bcHours' => $sum($bc),
            'cacheHours' => $sum($cache),
            'bcStatus' => timesheet_backfill_status_summary($bc),
            'cacheStatus' => timesheet_backfill_status_summary($cache),
            'lines' => $lineDiffs,
        ];
    }
    usort($diffs, fn($a, $b) => [$a['resourceName'], $a['weekStart']] <=> [$b['resourceName'], $b['weekStart']]);
    return $diffs;
}

/** Herhaal een fetch bij de Mímir-concurrencylimiet, met oplopende wachttijd. */
function timesheet_backfill_with_retry(callable $fetch, callable $log, array $delays = [15, 45, 90])
{
    foreach (array_merge($delays, [null]) as $delay) {
        try {
            return $fetch();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $isLimit = stripos($msg, 'limiet') !== false || stripos($msg, 'concurrency') !== false || stripos($msg, '429') !== false;
            if ($delay === null || !$isLimit) {
                throw $e;
            }
            $log("Mímir-limiet bereikt, opnieuw over {$delay}s…");
            sleep($delay);
        }
    }
}
