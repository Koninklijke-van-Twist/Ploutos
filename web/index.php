<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

$hour = 3600;
$day = $hour * 24;

$now = new DateTimeImmutable("now");
$from = $now->modify("-24 months")->format("Y-m-d");

// Timesheets in range (light)
$filter = rawurlencode("Starting_Date ge $from");
$url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date&\$filter={$filter}&\$format=json";
$rows = odata_get_all($url, $auth, $day);

// Bouw maandlijst op basis van Starting_Date (weekstart)
$months = []; // 'YYYY-MM' => true
foreach ($rows as $r) {
    $sd = (string) ($r['Starting_Date'] ?? '');
    if ($sd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) {
        $ym = substr($sd, 0, 7);
        $months[$ym] = true;
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
        body {
            font-family: system-ui, Segoe UI, Arial;
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
</head>

<body>
    <div class="wrap">
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