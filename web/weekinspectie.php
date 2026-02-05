<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/lib_times.php";
require __DIR__ . "/logincheck.php";

$tsNo = trim((string) ($_GET['tsNo'] ?? ''));
$resourceNo = trim((string) ($_GET['resourceNo'] ?? ''));
if ($tsNo === '' || $resourceNo === '')
    die("tsNo/resourceNo ontbreekt");

// Header
$tsFilter = rawurlencode("No eq '" . str_replace("'", "''", $tsNo) . "'");
$tsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Resource_Name&\$filter={$tsFilter}&\$format=json";
$ts = (odata_get_all($tsUrl, $auth, 300)[0] ?? null);
if (!$ts)
    die("Urenstaat niet gevonden");


// Lines voor ts + resource
$filter = rawurlencode("Time_Sheet_No eq '" . str_replace("'", "''", $tsNo) . "'");
$url = $base . "Urenstaatregels?\$select="
    . "Time_Sheet_No,Line_No,Header_Resource_No,Header_Starting_Date,Header_Ending_Date,"
    . "Type,Status,Description,Job_No,Job_Task_No,Cause_of_Absence_Code,Chargeable,Work_Type_Code,"
    . "Service_Order_No,Assembly_Order_No,Archived,"
    . "Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity" //monday, tuesday, wednesday, thursday, friday, saturday, sunday
    . "&\$filter={$filter}&\$format=json";

$linesAll = odata_get_all($url, $auth, 300);

//todo: fetch first starting date and last ending date

// 2) filter lokaal op persoon
$lines = array_values(array_filter($linesAll, function ($l) use ($resourceNo) {
    return (string) ($l['Header_Resource_No'] ?? '') === $resourceNo;
}));

$year = substr($ts['Starting_Date'], 0, 4);

$allProjects = array_unique(array_filter(array_column($lines, 'Job_Task_No')));

$webfleetLines = [];
$startDate = $ts['Starting_Date'];
$endDate = $ts['Ending_Date'];

foreach ($allProjects as $project) {
    $wfFilter = rawurlencode("Job_Task_No eq '" . str_replace("'", "''", $project) . "'");
    $wfUrl = $base . "WebfleetHours?\$select=Job_Task_No,KVT_Date_Webfleet_Activity,KVT_Start_time_Webfleet_Act,Quantity,KVT_End_time_Webfleet_Act,KVT_Pause,Work_Type_Code,KVT_Calculated_Hours&\$filter={$wfFilter}&\$format=json";
    $wf = (odata_get_all($wfUrl, $auth, 300) ?? null);
    if ($wf !== null) {
        // Filter to only include dates within the timesheet week
        $wfFiltered = array_filter($wf, function ($line) use ($startDate, $endDate) {
            $activityDate = (string) ($line['KVT_Date_Webfleet_Activity'] ?? '');
            return $activityDate >= $startDate && $activityDate <= $endDate;
        });
        // Merge the filtered results into webfleetLines
        $webfleetLines = array_merge($webfleetLines, $wfFiltered);
    }
}

$holidays = holiday_set($year);
$isHoliday = isset($holidays[$ts['Starting_Date']]);

function hhmm(float $hours): string
{
    return minutes_to_hhmm($hours * 60);
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

function formatDateDayOnly(string $dateStr): string
{
    if (!$dateStr)
        return '';
    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$dt)
        return htmlspecialchars($dateStr);
    return $dt->format('d');
}

function dayIsHoliday($i)
{
    global $ts;
    $d = $i - 1;
    $date = date('Y-m-d', strtotime($ts['Starting_Date'] . " + {$d} days"));
    $year = substr($date, 0, 4);
    $holidays = holiday_set($year);
    return isset($holidays[$date]);
}

?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weekinspectie</title>
    <style>
        @media print {
            noprint {
                display: none !important;
            }
        }

        h1 {
            background-color: #e4ecf8;
            background-image: url("images/kvtlogo.png");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: right;
            border-radius: 16px;
            padding: 5px;
        }

        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
        }

        .holiday {
            background-image: url("images/ballonnen.png");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            color: #FFFFFF;
            font-weight: bold;
            text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000;
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
            padding: 18px
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px
        }

        th,
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 8px 10px;
            font-size: 13px;
            text-align: center;
        }

        th {
            background: #f8fafc;
            text-align: left
        }

        .muted {
            color: #64748b
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #0f172a;
            background: #fff
        }

        .webfleet-row:hover {
            background-color: #ffd877;
            cursor: pointer;
        }

        .highlight-cell {
            background-color: #ffd877 !important;
            transition: background-color 0.2s;
        }

        .highlight-row {
            background-color: #ffd877 !important;
            transition: background-color 0.2s;
        }

        td[data-task][data-worktype][data-date]:hover {
            cursor: pointer;
        }

        .date-header {
            height: auto;
            padding: 4px 8px;
            font-size: 11px;
            align-content: center;
            text-align: center;
        }

        .date-header-row th {
            padding: 4px 2px;
            background-color: #e4ecf8;
        }

        .zeroHours {
            color: #ccc;
        }

        .openStatus {
            background-color: #ffa;
            color: #770;
        }

        .submittedStatus {
            background-color: #fa8;
            color: #750;
        }

        .approvedStatus {
            color: #070;
        }

        .rejectedStatus {
            background-color: #f88;
            color: #700;
        }

        #connection-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    </body>
</head>

<body>
    <svg id="connection-overlay">
        <path id="connection-line" d="M 0 0 Q 0 0 0 0" stroke="#ffd877" stroke-width="4"
            style="display:none; fill:none;" />
    </svg>
    <div class="wrap">
        <noprint><a class="btn" href="javascript:history.back()">← Terug</a></noprint>

        <div class="card" style="margin-top:12px;">
            <h1 style="margin:0 0 6px;">Weekinspectie</h1>
            <div class="muted">
                <?= htmlspecialchars((string) ($ts['Description'] ?? '')) ?> ·
                <?= htmlspecialchars((string) formatDate($ts['Starting_Date'] ?? '')) ?> –
                <?= htmlspecialchars((string) formatDate($ts['Ending_Date'] ?? '')) ?><br>

            </div>

            <table>
                <thead>
                    <tr class="date-header-row">
                        <th colspan="6" class="muted">Resource: <b>
                                <?= htmlspecialchars($resourceNo) ?>
                            </b></th>
                        <?php for ($i = 1; $i <= 7; $i++):
                            $d = $i - 1;
                            $cellDate = date('Y-m-d', strtotime($ts['Starting_Date'] . " + {$d} days"));
                            ?>
                            <th class="date-header"><?= htmlspecialchars(formatDateDayOnly($cellDate)) ?></th>
                        <?php endfor; ?>
                        <th></th>
                    </tr>
                    <tr>
                        <th>Line</th>
                        <th>Work type</th>
                        <th>Omschrijving</th>
                        <th>Project</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Ma</th>
                        <th>Di</th>
                        <th>Wo</th>
                        <th>Do</th>
                        <th>Vr</th>
                        <th>Za</th>
                        <th>Zo</th>
                        <th>Totaal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td>
                                <?= $l['Time_Sheet_No'] . "-" . ((int) ($l['Line_No'] ?? 0)) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($l['Work_Type_Code'] ?? '')) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($l['Description'] ?? '')) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($l['Job_No'] ?? '')) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($l['Job_Task_No'] ?? '')) ?>
                            </td>
                            <td class="<?php
                            $status = (string) ($l['Status'] ?? '');
                            if ($status === "Open")
                                echo "openStatus";
                            elseif ($status === "Submitted")
                                echo "submittedStatus";
                            elseif ($status === "Rejected")
                                echo "rejectedStatus";
                            elseif ($status === "Approved")
                                echo "approvedStatus";
                            ?>">
                                <?= htmlspecialchars($status) ?>
                            </td>
                            <?php for ($i = 1; $i <= 7; $i++):
                                $d = $i - 1;
                                $cellDate = date('Y-m-d', strtotime($ts['Starting_Date'] . " + {$d} days"));
                                ?>
                                <td class="<?= dayIsHoliday($i) ? "holiday" : "" ?> <?= hhmm($l["Field{$i}"] ?? '0') == "0:00" ? "zeroHours" : "" ?>"
                                    data-task="<?= htmlspecialchars((string) ($l['Job_Task_No'] ?? '')) ?>"
                                    data-worktype="<?= htmlspecialchars((string) ($l['Work_Type_Code'] ?? '')) ?>"
                                    data-date="<?= $cellDate ?>" data-hours="<?= round((float) ($l["Field{$i}"] ?? 0), 2) ?>">

                                    <?= $l['Work_Type_Code'] == "KM" ?
                                        htmlspecialchars((string) ($l["Field{$i}"] ?? '0')) . " km"
                                        : htmlspecialchars((string) hhmm($l["Field{$i}"] ?? '0')) ?>
                                </td>
                            <?php endfor; ?>
                            <td>
                                <b>
                                    <?= $l['Work_Type_Code'] == "KM" ?
                                        htmlspecialchars((string) ($l["Total_Quantity"] ?? '0')) . " km"
                                        : htmlspecialchars((string) hhmm($l["Total_Quantity"] ?? '0')) ?>
                                </b>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty(array_filter($webfleetLines))): ?>
            <div class="card" style="margin-top:12px;">
                <h2 style="margin:0 0 12px;">Webfleet Uren</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Werkorder</th>
                            <th>Datum</th>
                            <th>Work Type</th>
                            <th>Starttijd</th>
                            <th>Eindtijd</th>
                            <th>Pauze</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webfleetLines as $wf): ?>
                            <?php if ($wf): ?>
                                <tr class="webfleet-row" data-task="<?= htmlspecialchars((string) ($wf['Job_Task_No'] ?? '')) ?>"
                                    data-worktype="<?= htmlspecialchars((string) ($wf['Work_Type_Code'] ?? '')) ?>"
                                    data-date="<?= htmlspecialchars((string) ($wf['KVT_Date_Webfleet_Activity'] ?? '')) ?>"
                                    data-hours="<?= ($wf['Work_Type_Code'] ?? '') == 'KM' ? round((float) ($wf['Quantity'] ?? 0), 2) : round((float) ($wf['KVT_Calculated_Hours'] ?? 0), 2) ?>">
                                    <td>
                                        <?= htmlspecialchars((string) ($wf['Job_Task_No'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(formatDate((string) ($wf['KVT_Date_Webfleet_Activity'] ?? ''))) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($wf['Work_Type_Code'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $isKM = ($wf['Work_Type_Code'] ?? '') == 'KM';
                                        $startTime = (string) ($wf['KVT_Start_time_Webfleet_Act'] ?? '');
                                        echo ($isKM && $startTime === '00:00:00') ? '' : htmlspecialchars($startTime);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $endTime = (string) ($wf['KVT_End_time_Webfleet_Act'] ?? '');
                                        echo ($isKM && $endTime === '00:00:00') ? '' : htmlspecialchars($endTime);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $pause = (float) ($wf['KVT_Pause'] ?? 0);
                                        echo ($isKM && $pause == 0) ? '' : htmlspecialchars((string) hhmm($pause));
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        const connectionLine = document.getElementById('connection-line');

        function drawLine (elem1, elem2)
        {
            const rect1 = elem1.getBoundingClientRect();
            const rect2 = elem2.getBoundingClientRect();

            // Start from right edge of element1, vertically centered (3px before)
            const x1 = rect1.right - 3;
            const y1 = rect1.top + rect1.height / 2;

            // End at right edge of element2, vertically centered (3px before)
            const x2 = rect2.right - 3;
            const y2 = rect2.top + rect2.height / 2;

            // Control point to the right of both elements
            const cx = Math.max(rect1.right, rect2.right) + 300;
            const cy = (y1 + y2) / 2;

            const pathData = `M ${x1} ${y1} Q ${cx} ${cy} ${x2} ${y2}`;
            connectionLine.setAttribute('d', pathData);
            connectionLine.style.display = 'block';
        }

        function hideLine ()
        {
            connectionLine.style.display = 'none';
        }

        // Webfleet row hover -> highlight matching cells
        document.querySelectorAll('.webfleet-row').forEach(row =>
        {
            row.addEventListener('mouseenter', function ()
            {
                const task = this.dataset.task;
                const worktype = this.dataset.worktype;
                const date = this.dataset.date;
                let matchedCell = null;

                document.querySelectorAll('td[data-task][data-worktype][data-date]').forEach(cell =>
                {
                    const cellHours = parseFloat(cell.dataset.hours) || 0;
                    const rowHours = parseFloat(this.dataset.hours) || 0;
                    const tolerance = 0.5;
                    if (cell.dataset.task === task &&
                        cell.dataset.worktype === worktype &&
                        cell.dataset.date === date &&
                        Math.abs(cellHours - rowHours) <= tolerance)
                    {
                        cell.classList.add('highlight-cell');
                        if (!matchedCell) matchedCell = cell;
                    }
                });

                if (matchedCell)
                {
                    drawLine(matchedCell, this);
                }
            });

            row.addEventListener('mouseleave', function ()
            {
                document.querySelectorAll('.highlight-cell').forEach(cell =>
                {
                    cell.classList.remove('highlight-cell');
                });
                hideLine();
            });
        });

        // Cell hover -> highlight matching webfleet rows and the cell itself
        document.querySelectorAll('td[data-task][data-worktype][data-date]').forEach(cell =>
        {
            cell.addEventListener('mouseenter', function ()
            {
                const task = this.dataset.task;
                const worktype = this.dataset.worktype;
                const date = this.dataset.date;
                let matchFound = false;
                let matchedRow = null;

                document.querySelectorAll('.webfleet-row').forEach(row =>
                {
                    const rowHours = parseFloat(row.dataset.hours) || 0;
                    const cellHours = parseFloat(this.dataset.hours) || 0;
                    const tolerance = 0.5;
                    if (row.dataset.task === task &&
                        row.dataset.worktype === worktype &&
                        row.dataset.date === date &&
                        Math.abs(rowHours - cellHours) <= tolerance)
                    {
                        row.classList.add('highlight-row');
                        matchFound = true;
                        if (!matchedRow) matchedRow = row;
                    }
                });

                // Highlight the cell itself if a match was found
                if (matchFound)
                {
                    this.classList.add('highlight-cell');
                    if (matchedRow)
                    {
                        drawLine(this, matchedRow);
                    }
                }
            });

            cell.addEventListener('mouseleave', function ()
            {
                document.querySelectorAll('.highlight-row').forEach(row =>
                {
                    row.classList.remove('highlight-row');
                });
                this.classList.remove('highlight-cell');
                hideLine();
            });
        });
    </script>
</body>

</html>