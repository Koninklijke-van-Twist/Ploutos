<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
require __DIR__ . "/lib_timesheet_store.php";

if ((string) ($_GET['action'] ?? '') === 'months') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $store = timesheet_store_db();
        echo json_encode([
            'ok' => true,
            'months' => timesheet_store_valid_months($store),
            'ready' => timesheet_store_has_any($store),
            'backfill_complete' => timesheet_store_backfill_complete($store),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'months' => [], 'ready' => false, 'backfill_complete' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
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

        .progress-wrap {
            margin-top: 8px;
            display: none;
        }

        .progress-wrap.active {
            display: block;
        }

        .progress-track {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 0%;
            background: #4338ca;
            transition: width 200ms linear;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .page-loader.active {
            display: flex;
        }

        .page-loader-box {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            font-weight: 700;
            color: #0f172a;
        }
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    </body>
</head>

<body>
    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
    ]) ?>
    <div id="pageLoader" class="page-loader" aria-live="polite" aria-busy="true">
        <div class="page-loader-box">Bezig met laden…</div>
    </div>
    <div class="wrap">
        <noprint><a href="feestdagen.php">Beheer Feestdagen</a></noprint>
        <h1>Overzicht genereren</h1>
        <p class="hint">Kies een maand, of geef een periode op.</p>

        <form id="overviewForm" method="get" action="overzicht.php">
            <label>Maand</label>
            <select id="monthSelect" name="month" disabled>
                <option value="">Maanden laden…</option>
            </select>
            <div id="monthStatus" class="hint">Maanden worden uit de lokale cache geladen…</div>
            <div id="monthProgressWrap" class="progress-wrap active" aria-hidden="false">
                <div class="progress-track">
                    <div id="monthProgressFill" class="progress-fill"></div>
                </div>
            </div>

            <hr class="sep">

            <div class="row">
                <div>
                    <label>Periode van</label>
                    <input type="date" name="from" placeholder="2026-01-01">
                </div>
                <div>
                    <label>t/m</label>
                    <input type="date" name="to" placeholder="2026-01-31">
                </div>
            </div>

            <button type="submit">Toon overzicht</button>
        </form>
    </div>

    <script>
        (function ()
        {
            const monthSelect = document.getElementById('monthSelect');
            const monthStatus = document.getElementById('monthStatus');
            const monthProgressWrap = document.getElementById('monthProgressWrap');
            const monthProgressFill = document.getElementById('monthProgressFill');
            const form = document.getElementById('overviewForm');
            const loader = document.getElementById('pageLoader');

            // Instelblok voor voortgangsbalk-pauzes
            const monthProgressConfig = {
                minPauseCount: 8,
                maxPauseCount: 24,
                minPauseMs: 180,
                maxPauseMs: 3720,
                tickMs: 180,
                milestones: [9, 13, 18, 24, 27, 33, 39, 44, 48, 55, 61, 66, 71, 77, 82, 86, 89, 92]
            };

            let monthProgressTimer = null;
            let monthProgressValue = 0;
            let monthProgressStepIndex = 0;
            let monthProgressPauseUntil = 0;
            let monthProgressPausePlan = new Map();
            let monthReachedWaitState = false;

            function randomInt (min, max)
            {
                return Math.floor(Math.random() * (max - min + 1)) + min;
            }

            function buildPausePlan (milestoneCount)
            {
                const plan = new Map();
                if (milestoneCount <= 0)
                {
                    return plan;
                }

                const minCount = Math.max(0, Math.min(monthProgressConfig.minPauseCount, milestoneCount));
                const maxCount = Math.max(minCount, Math.min(monthProgressConfig.maxPauseCount, milestoneCount));
                const pauseCount = randomInt(minCount, maxCount);
                const used = new Set();

                while (used.size < pauseCount)
                {
                    const stepIndex = randomInt(0, milestoneCount - 1);
                    used.add(stepIndex);
                }

                used.forEach(function (stepIndex)
                {
                    plan.set(stepIndex, randomInt(monthProgressConfig.minPauseMs, monthProgressConfig.maxPauseMs));
                });

                return plan;
            }

            function showLoader ()
            {
                loader?.classList.add('active');
            }

            function hideLoader ()
            {
                loader?.classList.remove('active');
            }

            function setMonthOptions (months, ready)
            {
                monthSelect.innerHTML = '';

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '— Kies maand —';
                monthSelect.appendChild(placeholder);

                months.forEach((ym) =>
                {
                    const opt = document.createElement('option');
                    opt.value = ym;
                    opt.textContent = ym;
                    monthSelect.appendChild(opt);
                });

                monthSelect.disabled = false;
                if (months.length > 0) {
                    monthStatus.textContent = 'Alleen maanden met urenstaatregels worden getoond.';
                    return;
                }
                monthStatus.textContent = ready
                    ? 'Geen maanden met urenstaatregels gevonden.'
                    : 'De nachtelijke cache is nog niet gevuld. Maanden verschijnen na de sync om 02:00.';
            }

            function startMonthProgress ()
            {
                monthProgressValue = 6;
                monthProgressStepIndex = 0;
                monthProgressPauseUntil = 0;
                monthReachedWaitState = false;
                monthProgressWrap?.classList.add('active');
                if (monthProgressFill)
                {
                    monthProgressFill.style.width = `${monthProgressValue}%`;
                }
                monthStatus.textContent = 'Maanden worden uit de lokale cache geladen…';

                if (monthProgressTimer)
                {
                    clearInterval(monthProgressTimer);
                }

                monthProgressPausePlan = buildPausePlan(monthProgressConfig.milestones.length);

                monthProgressTimer = setInterval(function ()
                {
                    if (Date.now() < monthProgressPauseUntil)
                    {
                        return;
                    }

                    if (monthProgressStepIndex < monthProgressConfig.milestones.length)
                    {
                        monthProgressValue = monthProgressConfig.milestones[monthProgressStepIndex];
                        const pauseMs = monthProgressPausePlan.get(monthProgressStepIndex) ?? 0;
                        if (pauseMs > 0)
                        {
                            monthProgressPauseUntil = Date.now() + pauseMs;
                        }
                        monthProgressStepIndex++;
                    }

                    if (monthProgressFill)
                    {
                        monthProgressFill.style.width = `${monthProgressValue}%`;
                    }

                    if (!monthReachedWaitState && monthProgressValue >= 92)
                    {
                        monthReachedWaitState = true;
                        monthStatus.textContent = 'Maanden worden uit de lokale cache geladen…';
                    }
                }, monthProgressConfig.tickMs);
            }

            function finishMonthProgress ()
            {
                if (monthProgressTimer)
                {
                    clearInterval(monthProgressTimer);
                    monthProgressTimer = null;
                }

                if (monthProgressFill)
                {
                    monthProgressFill.style.width = '100%';
                }

                monthProgressWrap?.classList.remove('active');
            }

            async function loadMonths ()
            {
                startMonthProgress();
                try
                {
                    const response = await fetch('index.php?action=months', { cache: 'no-store' });
                    const payload = await response.json();

                    if (!payload || !Array.isArray(payload.months))
                    {
                        throw new Error('Invalid months payload');
                    }

                    setMonthOptions(payload.months, payload.ready !== false);
                } catch (e)
                {
                    monthSelect.innerHTML = '<option value="">— Kies maand —</option>';
                    monthSelect.disabled = false;
                    monthStatus.textContent = 'Maanden laden mislukt. Gebruik eventueel datum-range.';
                } finally
                {
                    finishMonthProgress();
                }
            }

            form?.addEventListener('submit', function ()
            {
                showLoader();
            });

            window.addEventListener('pageshow', function ()
            {
                hideLoader();
            });

            loadMonths();
        })();
    </script>
</body>

</html>