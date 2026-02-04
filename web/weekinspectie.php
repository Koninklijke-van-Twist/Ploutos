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
    . "Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity"
    . "&\$filter={$filter}&\$format=json";

$linesAll = odata_get_all($url, $auth, 300);

// 2) filter lokaal op persoon
$lines = array_values(array_filter($linesAll, function ($l) use ($resourceNo) {
    return (string) ($l['Header_Resource_No'] ?? '') === $resourceNo;
}));

$year = substr($ts['Starting_Date'], 0, 4);

$allProjects = array_unique(array_filter(array_column($lines, 'Job_Task_No')));

$webfleetLines = [];
foreach ($allProjects as $project) {
    $wfFilter = rawurlencode("LVS_Work_Order_No eq '" . str_replace("'", "''", $project) . "'");
    $wfUrl = $base . "WebfleetHours?\$select=LVS_Work_Order_No,KVT_Date_Webfleet_Activity,KVT_Actual_Start_Time_Webfleet_Act,KVT_Actual_End_Time_Webfleet_Act,KVT_Pause,Work_Type_Code&\$filter={$wfFilter}&\$format=json";
    $wf = (odata_get_all($wfUrl, $auth, 300)[0] ?? null);
    $webfleetLines[] = $wf;
}

$holidays = holiday_set($year);
$isHoliday = isset($holidays[$ts['Starting_Date']]);

function hhmm(float $hours): string
{
    return minutes_to_hhmm($hours * 60);
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
            font-family: system-ui, Segoe UI, Arial;
            margin: 0;
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
            background-color: #fef3c7;
            cursor: pointer;
        }

        .highlight-cell {
            background-color: #fef3c7 !important;
            transition: background-color 0.2s;
        }

        .highlight-row {
            background-color: #fef3c7 !important;
            transition: background-color 0.2s;
        }

        td[data-task][data-worktype][data-date]:hover {
            cursor: pointer;
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
        <noprint><a class="btn" href="javascript:history.back()">← Terug</a></noprint>

        <div class="card" style="margin-top:12px;">
            <h1 style="margin:0 0 6px;">Weekinspectie</h1>
            <div class="muted">
                <?= htmlspecialchars((string) ($ts['Description'] ?? '')) ?> ·
                <?= htmlspecialchars((string) ($ts['Starting_Date'] ?? '')) ?> –
                <?= htmlspecialchars((string) ($ts['Ending_Date'] ?? '')) ?><br>
                Resource: <b>
                    <?= htmlspecialchars($resourceNo) ?>
                </b>
            </div>

            <table>
                <thead>
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
                            <td>
                                <?= htmlspecialchars((string) ($l['Status'] ?? '')) ?>
                            </td>
                            <?php for ($i = 1; $i <= 7; $i++):
                                $d = $i - 1;
                                $cellDate = date('Y-m-d', strtotime($ts['Starting_Date'] . " + {$d} days"));
                                ?>
                                <td <?= dayIsHoliday($i) ? "class=\"holiday\"" : "" ?>
                                    data-task="<?= htmlspecialchars((string) ($l['Job_Task_No'] ?? '')) ?>"
                                    data-worktype="<?= htmlspecialchars((string) ($l['Work_Type_Code'] ?? '')) ?>"
                                    data-date="<?= $cellDate ?>">

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
                                <tr class="webfleet-row"
                                    data-task="<?= htmlspecialchars((string) ($wf['LVS_Work_Order_No'] ?? '')) ?>"
                                    data-worktype="<?= htmlspecialchars((string) ($wf['Work_Type_Code'] ?? '')) ?>"
                                    data-date="<?= htmlspecialchars((string) ($wf['KVT_Date_Webfleet_Activity'] ?? '')) ?>">
                                    <td>
                                        <?= htmlspecialchars((string) ($wf['LVS_Work_Order_No'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($wf['KVT_Date_Webfleet_Activity'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($wf['Work_Type_Code'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $isKM = ($wf['Work_Type_Code'] ?? '') == 'KM';
                                        $startTime = (string) ($wf['KVT_Actual_Start_Time_Webfleet_Act'] ?? '');
                                        echo ($isKM && $startTime === '00:00:00') ? '' : htmlspecialchars($startTime);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $endTime = (string) ($wf['KVT_Actual_End_Time_Webfleet_Act'] ?? '');
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
        // Webfleet row hover -> highlight matching cells
        document.querySelectorAll('.webfleet-row').forEach(row =>
        {
            row.addEventListener('mouseenter', function ()
            {
                const task = this.dataset.task;
                const worktype = this.dataset.worktype;
                const date = this.dataset.date;

                document.querySelectorAll('td[data-task][data-worktype][data-date]').forEach(cell =>
                {
                    if (cell.dataset.task === task &&
                        cell.dataset.worktype === worktype &&
                        cell.dataset.date === date)
                    {
                        cell.classList.add('highlight-cell');
                    }
                });
            });

            row.addEventListener('mouseleave', function ()
            {
                document.querySelectorAll('.highlight-cell').forEach(cell =>
                {
                    cell.classList.remove('highlight-cell');
                });
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

                document.querySelectorAll('.webfleet-row').forEach(row =>
                {
                    if (row.dataset.task === task &&
                        row.dataset.worktype === worktype &&
                        row.dataset.date === date)
                    {
                        row.classList.add('highlight-row');
                        matchFound = true;
                    }
                });

                // Highlight the cell itself if a match was found
                if (matchFound)
                {
                    this.classList.add('highlight-cell');
                }
            });

            cell.addEventListener('mouseleave', function ()
            {
                document.querySelectorAll('.highlight-row').forEach(row =>
                {
                    row.classList.remove('highlight-row');
                });
                this.classList.remove('highlight-cell');
            });
        });
    </script>
</body>

</html>