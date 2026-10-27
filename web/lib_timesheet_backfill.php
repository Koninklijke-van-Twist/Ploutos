<?php
/**
 * Vergelijking BC ↔ lokale cache voor backfill_year.php (zuivere functies, testbaar).
 */

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

/** Vergelijkbare sleutel van een regel: velden zoals de cache ze opslaat. */
function timesheet_backfill_line_signature(array $line): string
{
    $parts = [(string) ($line['Header_Resource_No'] ?? ''), (string) ($line['Type'] ?? ''), (string) ($line['Status'] ?? ''),
        (string) ($line['Job_No'] ?? ''), (string) ($line['Job_Task_No'] ?? ''), (string) ($line['Work_Type_Code'] ?? ''),
        (string) ($line['Cause_of_Absence_Code'] ?? ''), (string) ($line['Description'] ?? '')];
    foreach (['Field1', 'Field2', 'Field3', 'Field4', 'Field5', 'Field6', 'Field7', 'Total_Quantity'] as $f) {
        $parts[] = number_format((float) ($line[$f] ?? 0), 4, '.', '');
    }
    return implode('|', $parts);
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
        $cache = $cacheByTs[$no] ?? [];
        if (!isset($cacheTsNos[$no]) || !$cache) {
            $kind = 'ontbreekt';
        } else {
            $same = count($bc) === count($cache);
            foreach ($bc as $lineNo => $l) {
                if (!$same) {
                    break;
                }
                $same = isset($cache[$lineNo]) && timesheet_backfill_line_signature($l) === timesheet_backfill_line_signature($cache[$lineNo]);
            }
            if ($same) {
                continue;
            }
            $kind = 'verschilt';
        }
        $sum = fn(array $ls) => array_sum(array_map(fn($l) => (float) ($l['Total_Quantity'] ?? 0), $ls));
        $diffs[] = [
            'tsNo' => $no,
            'kind' => $kind,
            'resourceNo' => (string) ($ts['Resource_No'] ?? ''),
            'resourceName' => (string) ($ts['Resource_Name'] ?? ''),
            'weekStart' => (string) ($ts['Starting_Date'] ?? ''),
            'description' => (string) ($ts['Description'] ?? ''),
            'bcHours' => $sum($bc),
            'cacheHours' => $sum($cache),
            'bcStatus' => timesheet_backfill_status_summary($bc),
            'cacheStatus' => timesheet_backfill_status_summary($cache),
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
