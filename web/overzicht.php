<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/lib_times.php";

$hour = 3600;
$day = $hour * 24;

$month = trim((string) ($_GET['month'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

// Bepaal range
if ($from && $to) {
    // ok
} elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $from = $month . "-01";
    $dt = new DateTimeImmutable($from);
    $to = $dt->modify("last day of this month")->format("Y-m-d");
} else {
    die("Geef month=YYYY-MM of from/to op.");
}

// Timesheets die overlappen: Ending_Date ge from AND Starting_Date le to
$filterDecoded = "Ending_Date ge $from and Starting_Date le $to";
$filter = rawurlencode($filterDecoded);
$tsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Resource_No,Resource_Name&\$filter={$filter}&\$format=json";
$tsRows = odata_get_all($tsUrl, $auth, $day);

if (!$tsRows)
    die("Geen urenstaten in dit tijdvak.");

// Index timesheets op No
$tsByNo = [];
$tsNos = [];
foreach ($tsRows as $t) {
    $no = (string) ($t['No'] ?? '');
    if ($no === '')
        continue;
    $tsByNo[$no] = $t;
    $tsNos[] = $no;
}

// Helper OR filter
function odata_or_filter(string $field, array $values): string
{
    $parts = array_map(fn($v) => "$field eq '" . str_replace("'", "''", $v) . "'", $values);
    return rawurlencode(implode(" or ", $parts));
}

// Lines voor alle timesheets
$lineFilter = odata_or_filter("Time_Sheet_No", $tsNos);
$linesUrl = $base . "Urenstaatregels?\$select=Time_Sheet_No,Header_Resource_No,Work_Type_Code,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$lineFilter}&\$format=json";
$lines = odata_get_all($linesUrl, $auth, $day);

// Codes voor “onkosten” en “verlet” (pas aan aan jouw BC codes)
$CODES_ONKOSTEN = ['ONK', 'ONKOST', 'EXP']; // voorbeeld
$CODES_VERLET = ['VER', 'VERLET'];       // voorbeeld

// Verzamel resources die we nodig hebben
$needRes = [];
foreach ($lines as $l) {
    $rno = (string) ($l['Header_Resource_No'] ?? '');
    if ($rno !== '')
        $needRes[$rno] = true;
}
$needResNos = array_keys($needRes);

// Resource lookup (naam, etc.)
$resourcesByNo = [];
if ($needResNos) {
    $rf = odata_or_filter("No", $needResNos);
    $resUrl = $base . "AppResources?\$select=No,Name&\$filter={$rf}&\$format=json";
    foreach (odata_get_all($resUrl, $auth, $day) as $r) {
        $resourcesByNo[(string) $r['No']] = $r;
    }
}

// Aggregatie: personNo + timesheetNo (week)
$byPerson = []; // personNo => ['name'=>..., 'weeks'=>[tsNo=>row]]
foreach ($lines as $l) {
    $tsNo = (string) ($l['Time_Sheet_No'] ?? '');
    if ($tsNo === '' || !isset($tsByNo[$tsNo]))
        continue;

    $personNo = (string) ($l['Header_Resource_No'] ?? '');
    if ($personNo === '')
        continue;

    $name = (string) ($resourcesByNo[$personNo]['Name'] ?? $personNo);

    $workType = (string) ($l['Work_Type_Code'] ?? '');
    if ($workType == "KM")
        continue;

    $weekStart = (string) ($tsByNo[$tsNo]['Starting_Date'] ?? '');
    if (!$weekStart)
        continue;



    // Init struct
    if (!isset($byPerson[$personNo])) {
        $byPerson[$personNo] = ['personNo' => $personNo, 'name' => $name, 'weeks' => []];
    }

    if (!isset($byPerson[$personNo]['weeks'][$tsNo])) {
        // weeknummer uit Description (fallback)
        $desc = (string) ($tsByNo[$tsNo]['Description'] ?? '');
        preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m);
        $weekNo = isset($m[1]) ? (int) $m[1] : 0;

        $byPerson[$personNo]['weeks'][$tsNo] = [
            'tsNo' => $tsNo,
            'weekNo' => $weekNo,
            'weekStart' => $weekStart,
            'p285' => 0,
            'p47' => 0,
            'p85' => 0,
            'onkosten' => 0.0,
            'verlet' => 0.0,
            'weekTotaal' => 0,
            'lines' => 0,
        ];
    }

    // Uren per dag (Field1..7)
    $dayHours = $byPerson[$personNo]['weeks'][$tsNo]['dayHours'] ?? [];
    $weekTotal = (int) $byPerson[$personNo]['weeks'][$tsNo]['weekTotaal'] ?? 0;

    for ($i = 1; $i <= 7; $i++) {
        $dayHours[$i - 1] = ($dayHours[$i - 1] ?? 0) + (float) ($l["Field{$i}"] ?? 0);
        $weekTotal += $dayHours[$i - 1];
    }

    $byPerson[$personNo]['weeks'][$tsNo]['weekTotaal'] = $weekTotal;
    $byPerson[$personNo]['weeks'][$tsNo]['dayHours'] = $dayHours;

    // Onkosten / verlet (voorbeeld: via Work_Type_Code en Total_Quantity als bedrag)
    if (in_array($workType, $CODES_ONKOSTEN, true)) {
        $byPerson[$personNo]['weeks'][$tsNo]['onkosten'] += (float) ($l['Total_Quantity'] ?? 0);
        continue;
    }
    if (in_array($workType, $CODES_VERLET, true)) {
        $byPerson[$personNo]['weeks'][$tsNo]['verlet'] += (float) ($l['Total_Quantity'] ?? 0);
        continue;
    }

    $byPerson[$personNo]['weeks'][$tsNo]['lines']++;
}

foreach ($byPerson as $pKey => $person) {
    foreach ($person['weeks'] as $tsKey => $ts) {
        // Dagdatums (Ma..Zo) uit weekStart
        $dates = [];
        for ($d = 0; $d < 7; $d++)
            $dates[$d] = ymd_add_days($ts['weekStart'], $d);

        $weekTotal = 0;
        // Premies verdelen per dag
        for ($d = 0; $d < 7; $d++) {
            $weekTotal += $byPerson[$pKey]['weeks'][$tsKey]['dayHours'][$d];
            $split = split_premiums_for_day($byPerson[$pKey]['weeks'][$tsKey]['dayHours'][$d], $dates[$d]);
            $byPerson[$pKey]['weeks'][$tsKey]['p285'] += $split['p285'];
            $byPerson[$pKey]['weeks'][$tsKey]['p47'] += $split['p47'];
            $byPerson[$pKey]['weeks'][$tsKey]['p85'] += $split['p85'];
        }
        $byPerson[$pKey]['weeks'][$tsKey]["weekTotaal"] = $weekTotal;
    }
}

// Sortering: personen op naam, weken per persoon op weeknummer desc
usort($byPerson, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($byPerson as &$p) {
    $weeks = array_values($p['weeks']);
    usort($weeks, fn($a, $b) => ($b['weekNo'] <=> $a['weekNo']));
    $p['weeks'] = $weeks;
}
unset($p);

function eur(float $v): string
{
    return "€ " . number_format($v, 2, ",", ".");
}
function hhmm(int $min): string
{
    return minutes_to_hhmm($min);
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overzicht</title>
    <style>
        @media print {
            noprint {
                display: none !important;
            }
        }

        body {
            font-family: system-ui, Segoe UI, Arial;
            margin: 0;
        }

        h2 {
            background-color: #e4ecf8;
            background-image: url("images/kvtlogo.png");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: right;
            border-radius: 16px;
            padding: 5px;
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px
        }

        table {
            background-color: #fff;
        }

        .card {
            background-color: #e4ecf8;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            margin: 16px 0
        }

        h1 {
            margin: 12px 0
        }

        h2 {
            margin: 0 0 10px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 8px 10px;
            font-size: 13px;
            vertical-align: top
        }

        th {
            background: #f8fafc;
            text-align: left
        }

        tfoot td {
            font-weight: 800
        }

        .muted {
            color: #64748b
        }

        .zeroTotal {

            color: #c7d1e0
        }

        .btn {
            display: inline-block;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #0f172a;
            background: #fff
        }

        .right {
            text-align: right
        }
    </style>
</head>

<body>
    <div class="wrap">
        <noprint><a href="feestdagen.php">Beheer Feestdagen</a></noprint>
        <h1>Overzicht <?= htmlspecialchars($from) ?> t/m <?= htmlspecialchars($to) ?></h1>
        <noprint>
            <div class="muted">Klik op een week om details te bekijken.</div>
        </noprint>

        <?php foreach ($byPerson as $person): ?>
            <div class="card">
                <h2><?= htmlspecialchars($person['name']) ?></h2>

                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Weeknummer</th>
                            <th>#</th>
                            <th>Gewerkt</th>
                            <th class="right">28.5%</th>
                            <th></th>
                            <th class="right">47%</th>
                            <th></th>
                            <th class="right">85%</th>
                            <th></th>
                            <th class="right">Onkosten</th>
                            <th class="right">Verlet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 0;
                        $tot285 = 0;
                        $tot47 = 0;
                        $tot85 = 0;
                        $totOnk = 0.0;
                        $totVer = 0.0;
                        ?>
                        <?php foreach ($person['weeks'] as $w): ?>
                            <?php
                            $i++;
                            $tot285 += (int) $w['p285'];
                            $tot47 += (int) $w['p47'];
                            $tot85 += (int) $w['p85'];
                            $totOnk += (float) $w['onkosten'];
                            $totVer += (float) $w['verlet'];

                            $inspectUrl = "weekinspectie.php?tsNo=" . rawurlencode($w['tsNo']) . "&resourceNo=" . rawurlencode($person['personNo']);
                            ?>
                            <tr>
                                <td>
                                    <a class="btn" href="<?= htmlspecialchars($inspectUrl) ?>">
                                        <noprint>Bekijk <?= $w['lines'] ?></noprint>&nbsp;
                                    </a>
                                </td>
                                <td><?= (int) $w['weekNo'] ?> <span
                                        class="muted">(<?= htmlspecialchars($w['weekStart']) ?>)</span></td>
                                <td><?= $i ?></td>

                                <td <?= hhmm($w['weekTotaal'] * 60) == "0:00" ? "class=\"zeroTotal\"" : "" ?>>
                                    <?= hhmm($w['weekTotaal'] * 60) ?>
                                </td>
                                <td class="right <?= $w['p285'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(hhmm((int) $w['p285'])) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['p47'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(hhmm((int) $w['p47'])) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['p85'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(hhmm((int) $w['p85'])) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['onkosten'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(eur((float) $w['onkosten'])) ?>
                                </td>
                                <td class="right <?= $w['verlet'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(eur((float) $w['verlet'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">Totalen</td>
                            <td class="right <?= $tot285 == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(hhmm($tot285)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $tot47 == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(hhmm($tot47)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $tot85 == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(hhmm($tot85)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $totOnk == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(eur($totOnk)) ?>
                            </td>
                            <td class="right <?= $totVer == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(eur($totVer)) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</body>

</html>