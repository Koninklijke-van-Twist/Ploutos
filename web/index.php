<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

$hour = 3600;
$day = $hour * 24;

$now = new DateTimeImmutable("now");
$from = $now->modify("-24 months")->format("Y-m-d");

// Timesheets in range (light)
$filter = rawurlencode("Starting_Date ge $from");
$url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date&\$filter={$filter}&\$format=json";
$rows = [];
try {
    $rows = odata_get_all($url, $auth, $day);
} catch (Exception $e) {
    $rows = [];
}

function odata_or_filter(string $field, array $values): string
{
    $parts = array_map(fn($v) => "$field eq '" . str_replace("'", "''", $v) . "'", $values);
    return rawurlencode(implode(" or ", $parts));
}

function odata_fetch_by_or_filter(string $base, string $entity, string $select, string $field, array $values, array $auth, int $ttl, int $chunkSize = 60): array
{
    $values = array_values(array_unique(array_filter(array_map(fn($v) => (string) $v, $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        $filter = odata_or_filter($field, $chunk);
        if ($filter === '') {
            continue;
        }
        $url = $base . $entity . "?\$select={$select}&\$filter={$filter}&\$format=json";
        $chunkRows = odata_get_all($url, $auth, $ttl);
        if ($chunkRows) {
            foreach ($chunkRows as $row) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

function odata_fetch_by_or_filter_safe(string $base, string $entity, string $select, string $field, array $values, array $auth, int $ttl, int $chunkSize = 40): array
{
    $values = array_values(array_unique(array_filter(array_map(fn($v) => (string) $v, $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        try {
            $chunkRows = odata_fetch_by_or_filter($base, $entity, $select, $field, $chunk, $auth, $ttl, $chunkSize);
            if ($chunkRows) {
                foreach ($chunkRows as $row) {
                    $rows[] = $row;
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    return $rows;
}

$timesheetsByNo = [];
$tsNos = [];
foreach ($rows as $r) {
    $no = (string) ($r['No'] ?? '');
    if ($no === '') {
        continue;
    }
    $timesheetsByNo[$no] = $r;
    $tsNos[] = $no;
}

$lines = odata_fetch_by_or_filter_safe($base, 'Urenstaatregels', 'Time_Sheet_No,Status', 'Time_Sheet_No', $tsNos, $auth, 0);
$validTsNos = [];
foreach ($lines as $line) {
    if ((string) ($line['Status'] ?? '') !== 'Approved') {
        continue;
    }

    $lineTsNo = (string) ($line['Time_Sheet_No'] ?? '');
    if ($lineTsNo !== '') {
        $validTsNos[$lineTsNo] = true;
    }
}

// Bouw maandlijst op basis van overlap met urenstaat-periodes
$months = []; // 'YYYY-MM' => true
foreach ($timesheetsByNo as $tsNo => $r) {
    if (!isset($validTsNos[$tsNo])) {
        continue;
    }

    $sd = (string) ($r['Starting_Date'] ?? '');
    $ed = (string) ($r['Ending_Date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
        continue;
    }

    try {
        $start = new DateTimeImmutable($sd);
        $end = new DateTimeImmutable($ed);
    } catch (Exception $e) {
        continue;
    }

    if ($end < $start) {
        continue;
    }

    $cursor = $start->modify('first day of this month');
    $lastMonth = $end->modify('first day of this month');

    while ($cursor <= $lastMonth) {
        $months[$cursor->format('Y-m')] = true;
        $cursor = $cursor->modify('+1 month');
    }
}

$monthList = array_keys($months);
rsort($monthList); // nieuwste eerst
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Selectie</title>
    <style>
        @media print {
            noprint {
                display: none !important;
            }
        }

        body {
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            margin: 0;
            background: #f6f7fb
        }

        .wrap {
            max-width: 820px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px
        }

        label {
            display: block;
            font-weight: 700;
            font-size: 13px;
            margin: 10px 0 6px
        }

        select,
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 12px
        }

        button {
            margin-top: 14px;
            padding: 12px 16px;
            border: 0;
            border-radius: 12px;
            background: #4338ca;
            color: #fff;
            font-weight: 800;
            cursor: pointer
        }

        .sep {
            margin: 18px 0;
            border: none;
            border-top: 1px solid #e2e8f0
        }

        .hint {
            color: #64748b;
            font-size: 13px
        }
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    </body>
</head>

<body>
    <div class="wrap">
        <noprint><a href="feestdagen.php">Beheer Feestdagen</a></noprint>
        <h1>Overzicht genereren</h1>
        <p class="hint">Kies een maand, of geef een periode op.</p>

        <form method="get" action="overzicht.php">
            <label>Maand</label>
            <select name="month">
                <option value="">— Kies maand —</option>
                <?php foreach ($monthList as $ym): ?>
                    <option value="<?= htmlspecialchars($ym) ?>"><?= htmlspecialchars($ym) ?></option>
                <?php endforeach; ?>
            </select>

            <hr class="sep">

            <div class="row">
                <div>
                    <label>Periode van (YYYY-MM-DD)</label>
                    <input name="from" placeholder="2026-01-01">
                </div>
                <div>
                    <label>t/m (YYYY-MM-DD)</label>
                    <input name="to" placeholder="2026-01-31">
                </div>
            </div>

            <button type="submit">Toon overzicht</button>
        </form>
    </div>
</body>

</html>