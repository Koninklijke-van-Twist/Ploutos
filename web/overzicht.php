<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/lib_times.php";
require __DIR__ . "/logincheck.php";

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

function formatDate(string $dateStr): string
{
    if (!$dateStr)
        return '';
    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$dt)
        return htmlspecialchars($dateStr);
    $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $dt->format('d') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
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
$linesUrl = $base . "Urenstaatregels?\$select=Time_Sheet_No,Status,Header_Resource_No,Work_Type_Code,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$lineFilter}&\$format=json";
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

    if ($l['Status'] !== "Approved")
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
            'lines' => 0
        ];
    }

    // Uren per dag (Field1..7)
    $dayHours = $byPerson[$personNo]['weeks'][$tsNo]['dayHours'] ?? [];

    $workType = (string) ($l['Work_Type_Code'] ?? '');

    for ($i = 1; $i <= 7; $i++) {
        if ($workType === 'SOT125')
            $byPerson[$personNo]['weeks'][$tsNo]['p285'] += $l["Field{$i}"] * 60;
        else if ($workType === 'SOT150')
            $byPerson[$personNo]['weeks'][$tsNo]['p47'] += $l["Field{$i}"] * 60;
        else if ($workType === 'SOT200')
            $byPerson[$personNo]['weeks'][$tsNo]['p85'] += $l["Field{$i}"] * 60;
        else
            $dayHours[$i - 1] = ($dayHours[$i - 1] ?? 0) + (float) ($l["Field{$i}"] ?? 0);
    }

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

        $weekTotal = ($byPerson[$pKey]['weeks'][$tsKey]['p285'] / 60) + ($byPerson[$pKey]['weeks'][$tsKey]['p47'] / 60) + ($byPerson[$pKey]['weeks'][$tsKey]['p85'] / 60);
        // Premies verdelen per dag
        for ($d = 0; $d < 7; $d++) {
            $weekTotal += +$byPerson[$pKey]['weeks'][$tsKey]['dayHours'][$d];
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

        .kvt_logo_big {
            max-width: 200px;
        }

        /* Print modal styles */
        .print-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: auto;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            font-size: small;
        }

        .print-modal.active {
            display: flex;
        }

        .print-modal-content {
            background: white;
            width: 210mm;
            height: 297mm;
            padding: 20mm;
            overflow-y: clip;
            position: relative;
        }

        .print-close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #333;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            z-index: 1001;
        }

        .print-close-btn:hover {
            background: #555;
        }

        /* Salary slip styles */
        .kvt-banner {
            background: #003da5;
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .salary-slip-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 2px solid #003da5;
            padding-bottom: 15px;
        }

        .salary-slip-header h1 {
            flex: 1;
            margin: 0;
            font-size: 28px;
            color: #003da5;
        }

        .salary-slip-header .logo {
            width: 200px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-size: 12px;
            text-align: center;
            color: #999;
        }

        .employee-info {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .employee-info-block {
            display: flex;
            flex-direction: column;
        }

        .employee-info-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .salary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }

        .salary-table th,
        .salary-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: right;
        }

        .salary-table th {
            background: #f0f0f0;
            text-align: center;
            font-weight: bold;
        }

        .salary-table td:first-child,
        .salary-table th:first-child {
            text-align: left;
        }

        .salary-table tr.section-header {
            background: #d4e4f7;
            font-weight: bold;
        }

        .salary-table tr.section-header td {
            border: none;
            border-bottom: 1px solid #999;
        }

        .salary-table tr.section-close {
            background: #1a3a7a;
            border-bottom: 3px solid #1a3a7a;
        }

        .salary-table tr.section-close td {
            border: none;
        }
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
</head>

<body>
    <div class="wrap">
        <noprint><a href="feestdagen.php">Beheer Feestdagen</a></noprint>
        <h1>Overzicht <?= formatDate(htmlspecialchars($from)) ?> t/m <?= formatDate(htmlspecialchars($to)) ?></h1>
        <noprint>
            <div class="muted">Klik op een week om details te bekijken.</div>
        </noprint>

        <?php foreach ($byPerson as $person): ?>
            <div class="card">
                <h2><?= htmlspecialchars($person['name']) ?></h2>
                <noprint><button class="print-btn"
                        onclick="openPrintModal(event, '<?= htmlspecialchars(str_replace("'", "′", json_encode($person)), ENT_QUOTES) ?>')">
                        Toon Salarisspecificatie
                    </button>
                </noprint>
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
                                        class="muted">(<?= formatDate(htmlspecialchars($w['weekStart'])) ?>)</span></td>
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

    <!-- Print Modal -->
    <div id="printModal" class="print-modal">
        <div class="print-modal-content">
            <noprint>
                <button class="print-close-btn" onclick="window.print()" style="right: 70px;">Afdrukken</button>
                <button class="print-close-btn" onclick="closePrintModal()">Sluiten</button>
            </noprint>
            <div id="salarySlipContent"></div>
        </div>
    </div>

    <script>
        function hours_to_minutes (h)
        {
            return Math.round(h * 60);
        }
        function hhmm (h)
        {
            return minutes_to_hhmm(h * 60);
        }
        function minutes_to_hhmm (min)
        {
            const sign = min < 0 ? "-" : "";
            min = Math.abs(min);
            const h = Math.floor(min / 60);
            const m = Math.round(min % 60);
            return `${sign}${h}:${String(m).padStart(2, '0')}`;
        }
        function openPrintModal (event, personData)
        {
            event.preventDefault();
            const person = JSON.parse(personData);
            const modal = document.getElementById('printModal');
            const content = document.getElementById('salarySlipContent');

            // Build weeks columns
            let weeksHtml = '';
            let totalWeeks = person.weeks.length;
            person.weeks.forEach(week =>
            {
                const weekYear = week.weekStart.substring(0, 4);
                const weekDate = new Date(week.weekStart);
                const weekNum = Math.ceil((weekDate.getDate() + new Date(weekDate.getFullYear(), weekDate.getMonth(), 1).getDay()) / 7);
                weeksHtml += `<th>${weekYear}${String(weekNum).padStart(2, '0')}</th>`;
            });
            weeksHtml += '<th>Totaal</th>';

            // Calculate totals for each category
            let total285 = 0, total47 = 0, total85 = 0;
            person.weeks.forEach(week =>
            {
                total285 += (week.p285 || 0) / 60;
                total47 += (week.p47 || 0) / 60;
                total85 += (week.p85 || 0) / 60;
            });

            // Build hours per week rows
            let hours285Cells = '';
            let hours47Cells = '';
            let hours85Cells = '';
            person.weeks.forEach(week =>
            {
                const h285 = ((week.p285 || 0) / 60).toFixed(2);
                const h47 = ((week.p47 || 0) / 60).toFixed(2);
                const h85 = ((week.p85 || 0) / 60).toFixed(2);
                hours285Cells += `<td>${h285 > 0 ? hhmm(h285) : ''}</td>`;
                hours47Cells += `<td>${h47 > 0 ? hhmm(h47) : ''}</td>`;
                hours85Cells += `<td>${h85 > 0 ? hhmm(h85) : ''}</td>`;
            });
            hours285Cells += `<td><strong>${hhmm(total285.toFixed(2))}</strong></td>`;
            hours47Cells += `<td><strong>${hhmm(total47.toFixed(2))}</strong></td>`;
            hours85Cells += `<td><strong>${hhmm(total85.toFixed(2))}</strong></td>`;

            // Build salary slip HTML
            const salarySlip = `
                <div class="salary-slip-header">
                    <h1>Salarisspecificatie</h1>
                    <div class="logo"><img class="kvt_logo_big" src="images/kvtlogo_l.png"/></div>
                </div>

                <div class="employee-info">
                    <div class="employee-info-block">
                        <div class="employee-info-label">Werknemer:</div>
                        <div>Salarisstrook</div>
                    </div>
                    <div class="employee-info-block">
                        <div class="employee-info-label">${htmlspecialchars(person.name)}</div>
                        <div>202601</div>
                    </div>
                    <div class="employee-info-block">
                        <div class="employee-info-label"><!--email--></div>
                        <div></div>
                    </div>
                </div>

                <table class="salary-table">
                    <thead>
                        <tr>
                            <th>Uren</th>
                            ${weeksHtml}
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Overwerk</td>
                        </tr>
                        <tr>
                            <td>28.5%: ma/vr eerste 2 overuren</td>
                            ${hours285Cells}
                        </tr>
                        <tr>
                            <td>47%: ma/vr overige uren en za</td>
                            ${hours47Cells}
                        </tr>
                        <tr>
                            <td>85%: zon- en feestdagen</td>
                            ${hours85Cells}
                        </tr>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Toeslag - buiten dagvenster</td>
                        </tr>
                        <tr>
                            <td>30%: 00:00 - 07:00 uur</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>50%: 20:00 - 00:00</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Uren</td>
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>

                <table class="salary-table">
                    <thead>
                        <tr>
                            <th>Vergoedingen</th>
                            ${weeksHtml}
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Koffievergoeding</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Lunchvergoeding</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Dinervergoeding</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Scheidingsvergoeding &lt;EU</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Scheidingsvergoeding &gt;EU</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Weekendtoeslag</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Consignatiedienst</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td>Nachttoeslag</td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>

                <!--<table class="salary-table">
                    <tbody>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Diversen</td>
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Inhoudingen</td>
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>-->
            `;

            content.innerHTML = salarySlip;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePrintModal ()
        {
            const modal = document.getElementById('printModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.getElementById('printModal')?.addEventListener('click', function (event)
        {
            if (event.target === this)
            {
                closePrintModal();
            }
        });

        function htmlspecialchars (str)
        {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>

</html>