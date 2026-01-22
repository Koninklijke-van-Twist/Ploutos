<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

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

?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weekinspectie</title>
    <style>
        body {
            font-family: system-ui, Segoe UI, Arial;
            margin: 0;
            background: #f6f7fb
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px
        }

        .card {
            background: #fff;
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
            font-size: 13px
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
    </style>
</head>

<body>
    <div class="wrap">
        <a class="btn" href="javascript:history.back()">← Terug</a>

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
                                <?= (int) ($l['Line_No'] ?? 0) ?>
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
                            <?php for ($i = 1; $i <= 7; $i++): ?>
                                <td>
                                    <?= htmlspecialchars((string) ($l["Field{$i}"] ?? '0')) . ($l['Work_Type_Code'] == "KM" ? " km" : "") ?>
                                </td>
                            <?php endfor; ?>
                            <td>
                                <?= htmlspecialchars((string) ($l['Total_Quantity'] ?? '0')) . ($l['Work_Type_Code'] == "KM" ? " km" : "") ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>