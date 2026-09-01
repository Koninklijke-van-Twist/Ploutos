<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require __DIR__ . '/lib_timesheet_store.php';
require __DIR__ . '/lib_contract_hours.php';

function contracturen_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_GET['action'] ?? '') === 'save') {
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['hours']) || !is_array($data['hours'])) {
        contracturen_json(['ok' => false, 'error' => 'Ongeldige invoer.'], 400);
    }
    try {
        $saved = contract_hours_save_map(contract_hours_db(), $data['hours']);
        contracturen_json(['ok' => true, 'saved' => $saved]);
    } catch (Throwable $e) {
        contracturen_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$people = [];
$storeReady = false;
try {
    $store = timesheet_store_db();
    $storeReady = timesheet_store_has_any($store);
    $people = timesheet_store_list_people($store);
} catch (Throwable $e) {
    $storeReady = false;
}

$hoursMap = contract_hours_get_map(contract_hours_db());
$defaultHours = contract_hours_default();
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Beheer Contracturen</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --pri: #4338ca;
            --dirty: #fdba74;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .wrap {
            max-width: 900px;
            margin: 28px auto;
            padding: 0 16px 40px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .btn {
            display: inline-block;
            min-height: 38px;
            padding: 8px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            border: 0;
            background: #4338ca;
            color: #fff;
        }

        .btn-primary:disabled {
            opacity: 0.55;
            cursor: default;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }

        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            text-align: left;
        }

        th { background: #f8fafc; }

        input[type="number"] {
            width: 110px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
        }

        input[type="number"].dirty {
            background: #fff7ed;
            border-color: var(--dirty);
        }

        .empty {
            margin-top: 14px;
            padding: 14px;
            border: 1px dashed var(--border);
            border-radius: 12px;
            color: var(--muted);
            font-size: 13px;
        }

        .sticky-banner {
            position: sticky;
            top: 0;
            z-index: 20;
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 0 14px;
            padding: 10px 14px;
            background: #fff7ed;
            border: 1px solid var(--dirty);
            border-radius: 12px;
            font-weight: 700;
        }

        .sticky-banner.visible {
            display: flex;
        }

        .flash {
            display: none;
            margin: 0 0 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 14px;
        }

        .flash.ok {
            display: block;
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #14532d;
        }

        .flash.err {
            display: block;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="sticky-banner" id="dirtyBanner">
            <span id="dirtyLabel">0 niet-opgeslagen aanpassingen</span>
            <button type="button" class="btn btn-primary" id="saveBtn">Opslaan</button>
        </div>
        <div class="card">
            <a class="btn" href="index.php">← Terug</a>
            <a class="btn" href="feestdagen.php">Beheer Feestdagen</a>
            <h1 style="margin-top:14px;">Beheer Contracturen</h1>
            <p class="muted">Standaard is 40 uur per week. Aangepaste velden kleuren oranje tot ze zijn opgeslagen.</p>
            <div class="flash" id="flash"></div>
            <?php if (!$people): ?>
                <div class="empty">
                    <?= $storeReady
                        ? 'Geen personen gevonden in de lokale cache.'
                        : 'De lokale cache is nog niet gevuld. Draai eerst nightly.php of wacht tot 02:00.' ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Persoon</th>
                            <th>Nummer</th>
                            <th>Contracturen / week</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <?php
                            $no = (string) ($person['No'] ?? '');
                            $name = (string) ($person['Name'] ?? '');
                            $hours = $hoursMap[$no] ?? $defaultHours;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($name !== '' ? $name : $no) ?></td>
                                <td class="muted"><?= htmlspecialchars($no) ?></td>
                                <td>
                                    <input type="number" min="0" step="0.25"
                                        data-resource-no="<?= htmlspecialchars($no) ?>"
                                        data-saved="<?= htmlspecialchars((string) $hours) ?>"
                                        value="<?= htmlspecialchars(number_format($hours, 2, '.', '')) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
        (function ()
        {
            const inputs = Array.from(document.querySelectorAll('input[data-resource-no]'));
            const banner = document.getElementById('dirtyBanner');
            const label = document.getElementById('dirtyLabel');
            const saveBtn = document.getElementById('saveBtn');
            const flash = document.getElementById('flash');

            function currentValue (input)
            {
                const raw = String(input.value ?? '').trim();
                const num = Number(raw);
                if (!Number.isFinite(num) || num < 0)
                {
                    return null;
                }
                return Math.round(num * 4) / 4;
            }

            function isDirty (input)
            {
                const current = currentValue(input);
                const saved = Number(input.dataset.saved);
                if (current === null || !Number.isFinite(saved))
                {
                    return true;
                }
                return Math.abs(current - saved) > 0.001;
            }

            function dirtyInputs ()
            {
                return inputs.filter(isDirty);
            }

            function refreshDirty ()
            {
                inputs.forEach(function (input)
                {
                    input.classList.toggle('dirty', isDirty(input));
                });
                const count = dirtyInputs().length;
                banner.classList.toggle('visible', count > 0);
                label.textContent = count + ' niet-opgeslagen aanpassingen';
            }

            function setFlash (type, message)
            {
                flash.className = 'flash ' + type;
                flash.textContent = message;
            }

            inputs.forEach(function (input)
            {
                input.addEventListener('input', refreshDirty);
                input.addEventListener('change', refreshDirty);
            });

            window.addEventListener('beforeunload', function (event)
            {
                if (dirtyInputs().length)
                {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });

            saveBtn?.addEventListener('click', async function ()
            {
                const changed = dirtyInputs();
                if (!changed.length)
                {
                    return;
                }

                const hours = {};
                for (const input of changed)
                {
                    const value = currentValue(input);
                    if (value === null)
                    {
                        setFlash('err', 'Controleer de ingevulde uren. Gebruik een getal van 0 of hoger.');
                        return;
                    }
                    hours[input.dataset.resourceNo] = value;
                }

                saveBtn.disabled = true;
                try
                {
                    const response = await fetch('contracturen.php?action=save', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ hours: hours })
                    });
                    const payload = await response.json();
                    if (!payload || !payload.ok)
                    {
                        throw new Error((payload && payload.error) || 'Opslaan mislukt.');
                    }
                    changed.forEach(function (input)
                    {
                        const value = currentValue(input);
                        input.dataset.saved = String(value);
                        input.value = value.toFixed(2);
                    });
                    setFlash('ok', 'Contracturen opgeslagen.');
                    refreshDirty();
                }
                catch (error)
                {
                    setFlash('err', error.message || 'Opslaan mislukt.');
                }
                finally
                {
                    saveBtn.disabled = false;
                }
            });

            refreshDirty();
        })();
    </script>
</body>

</html>
