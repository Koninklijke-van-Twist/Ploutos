<?php

function contract_hours_default(): float
{
    return 40.0;
}

function contract_hours_db_path(): string
{
    return __DIR__ . '/cache/contracturen.sqlite';
}

function contract_hours_db(): ?SQLite3
{
    if (!class_exists('SQLite3')) {
        return null;
    }

    $dir = dirname(contract_hours_db_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $db = new SQLite3(contract_hours_db_path());
    $db->busyTimeout(5000);
    $db->exec('CREATE TABLE IF NOT EXISTS contract_hours (
        resource_no TEXT PRIMARY KEY,
        hours REAL NOT NULL DEFAULT 40,
        updated_at INTEGER NOT NULL
    )');
    return $db;
}

function contract_hours_normalize($value): float
{
    if (!is_numeric($value)) {
        return contract_hours_default();
    }
    $hours = (float) $value;
    if (is_nan($hours) || is_infinite($hours)) {
        return contract_hours_default();
    }
    return max(0, round($hours * 4) / 4);
}

function extra_hours_above_contract(float $normalHours, float $contractHours): float
{
    return max(0, round($normalHours - $contractHours, 2));
}

function contract_hours_get_map(?SQLite3 $db, array $resourceNos = []): array
{
    $map = [];
    if (!$db) {
        return $map;
    }

    $resourceNos = array_values(array_unique(array_filter(array_map('strval', $resourceNos), fn($v) => $v !== '')));
    if ($resourceNos) {
        foreach (array_chunk($resourceNos, 200) as $chunk) {
            $placeholders = [];
            $binds = [];
            foreach ($chunk as $i => $no) {
                $name = 'r' . $i;
                $placeholders[] = ':' . $name;
                $binds[$name] = $no;
            }
            $sql = 'SELECT resource_no, hours FROM contract_hours WHERE resource_no IN (' . implode(', ', $placeholders) . ')';
            $stmt = $db->prepare($sql);
            foreach ($binds as $name => $no) {
                $stmt->bindValue(':' . $name, $no, SQLITE3_TEXT);
            }
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $no = (string) ($row['resource_no'] ?? '');
                if ($no !== '') {
                    $map[$no] = contract_hours_normalize($row['hours'] ?? contract_hours_default());
                }
            }
        }
        return $map;
    }

    $result = $db->query('SELECT resource_no, hours FROM contract_hours');
    if (!$result) {
        return $map;
    }
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $no = (string) ($row['resource_no'] ?? '');
        if ($no !== '') {
            $map[$no] = contract_hours_normalize($row['hours'] ?? contract_hours_default());
        }
    }
    return $map;
}

function contract_hours_save_map(?SQLite3 $db, array $hoursByResource): int
{
    if (!$db) {
        throw new RuntimeException('Contracturen-database is niet beschikbaar.');
    }

    $now = time();
    $stmt = $db->prepare('INSERT INTO contract_hours(resource_no, hours, updated_at)
        VALUES(:resource_no, :hours, :updated_at)
        ON CONFLICT(resource_no) DO UPDATE SET hours = excluded.hours, updated_at = excluded.updated_at');

    $saved = 0;
    $db->exec('BEGIN');
    try {
        foreach ($hoursByResource as $resourceNo => $hours) {
            $resourceNo = trim((string) $resourceNo);
            if ($resourceNo === '') {
                continue;
            }
            $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
            $stmt->bindValue(':hours', contract_hours_normalize($hours), SQLITE3_FLOAT);
            $stmt->bindValue(':updated_at', $now, SQLITE3_INTEGER);
            $stmt->execute();
            $saved++;
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    return $saved;
}
