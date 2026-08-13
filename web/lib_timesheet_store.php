<?php

function timesheet_store_path(): string
{
    return __DIR__ . '/cache/timesheets.sqlite';
}

function timesheet_store_db(): SQLite3
{
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('SQLite3 is niet beschikbaar.');
    }

    $dir = dirname(timesheet_store_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $db = new SQLite3(timesheet_store_path());
    $db->busyTimeout(30000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA foreign_keys=ON');
    timesheet_store_init_schema($db);
    return $db;
}

function timesheet_store_init_schema(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS months (
        ym TEXT PRIMARY KEY,
        has_lines INTEGER NOT NULL DEFAULT 0,
        fetched_at INTEGER NOT NULL,
        webfleet_fetched INTEGER NOT NULL DEFAULT 0
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS timesheets (
        no TEXT PRIMARY KEY,
        starting_date TEXT NOT NULL,
        ending_date TEXT NOT NULL,
        description TEXT,
        resource_no TEXT,
        resource_name TEXT,
        permanent INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS lines (
        time_sheet_no TEXT NOT NULL,
        line_no INTEGER NOT NULL,
        header_resource_no TEXT,
        header_starting_date TEXT,
        header_ending_date TEXT,
        type TEXT,
        status TEXT,
        description TEXT,
        job_no TEXT,
        job_task_no TEXT,
        cause_of_absence_code TEXT,
        chargeable INTEGER,
        work_type_code TEXT,
        service_order_no TEXT,
        assembly_order_no TEXT,
        archived INTEGER,
        field1 REAL, field2 REAL, field3 REAL, field4 REAL,
        field5 REAL, field6 REAL, field7 REAL,
        total_quantity REAL,
        PRIMARY KEY (time_sheet_no, line_no)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS resources (
        no TEXT PRIMARY KEY,
        name TEXT,
        time_sheet_approver_user_id TEXT,
        updated_at INTEGER NOT NULL
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_timesheets_dates ON timesheets(starting_date, ending_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lines_resource ON lines(header_resource_no)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lines_type ON lines(type)');

    timesheet_store_ensure_column($db, 'months', 'webfleet_fetched', 'INTEGER NOT NULL DEFAULT 0');

    $db->exec('CREATE TABLE IF NOT EXISTS webfleet_hours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_task_no TEXT NOT NULL DEFAULT \'\',
        activity_date TEXT NOT NULL,
        start_time TEXT NOT NULL DEFAULT \'\',
        end_time TEXT NOT NULL DEFAULT \'\',
        pause TEXT NOT NULL DEFAULT \'\',
        work_type_code TEXT NOT NULL DEFAULT \'\',
        calculated_hours REAL NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_webfleet_hours_date ON webfleet_hours(activity_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_webfleet_hours_task_date ON webfleet_hours(job_task_no, activity_date)');

    $db->exec('CREATE TABLE IF NOT EXISTS webfleet_card_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_no TEXT NOT NULL DEFAULT \'\',
        job_task_no TEXT NOT NULL DEFAULT \'\',
        activity_date TEXT NOT NULL,
        start_time TEXT NOT NULL DEFAULT \'\',
        end_time TEXT NOT NULL DEFAULT \'\',
        pause TEXT NOT NULL DEFAULT \'\',
        work_type_code TEXT NOT NULL DEFAULT \'\',
        quantity REAL NOT NULL DEFAULT 0,
        calculated_hours REAL NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_webfleet_card_lines_res_date ON webfleet_card_lines(resource_no, activity_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_webfleet_card_lines_date ON webfleet_card_lines(activity_date)');

    $db->exec('CREATE TABLE IF NOT EXISTS webfleet_cards (
        resource_no TEXT NOT NULL,
        week_no INTEGER NOT NULL,
        year_no INTEGER NOT NULL,
        resource_name TEXT,
        status TEXT,
        PRIMARY KEY (resource_no, week_no, year_no)
    )');
}

function timesheet_store_ensure_column(SQLite3 $db, string $table, string $column, string $ddl): void
{
    $result = $db->query('PRAGMA table_info(' . $table . ')');
    if (!$result) {
        return;
    }
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
            return;
        }
    }
    $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $ddl);
}

function timesheet_store_meta_get(SQLite3 $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare('SELECT value FROM meta WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return $default;
    }
    return (string) ($row['value'] ?? $default);
}

function timesheet_store_meta_set(SQLite3 $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO meta(key, value) VALUES(:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function timesheet_store_backfill_complete(SQLite3 $db): bool
{
    return timesheet_store_meta_get($db, 'backfill_complete', '0') === '1';
}

function timesheet_store_has_any(SQLite3 $db): bool
{
    $row = $db->querySingle('SELECT 1 FROM timesheets LIMIT 1');
    return (bool) $row;
}

function timesheet_store_live_from_ymd(?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now');
    return $now->modify('first day of this month')->format('Y-m-d');
}

function timesheet_store_current_month(?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now');
    return $now->format('Y-m');
}

function timesheet_store_month_bounds(string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Ongeldige maand: ' . $ym);
    }
    $start = new DateTimeImmutable($ym . '-01');
    return [
        'from' => $start->format('Y-m-d'),
        'to' => $start->modify('last day of this month')->format('Y-m-d'),
    ];
}

function timesheet_store_previous_month(string $ym): string
{
    $start = new DateTimeImmutable($ym . '-01');
    return $start->modify('-1 month')->format('Y-m');
}

function timesheet_store_month_recorded(SQLite3 $db, string $ym): bool
{
    $stmt = $db->prepare('SELECT 1 FROM months WHERE ym = :ym');
    $stmt->bindValue(':ym', $ym, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_NUM) : false;
    return (bool) $row;
}

function timesheet_store_record_month(SQLite3 $db, string $ym, bool $hasLines): void
{
    $stmt = $db->prepare('INSERT INTO months(ym, has_lines, fetched_at, webfleet_fetched) VALUES(:ym, :has_lines, :fetched_at, 0)
        ON CONFLICT(ym) DO UPDATE SET has_lines = excluded.has_lines, fetched_at = excluded.fetched_at');
    $stmt->bindValue(':ym', $ym, SQLITE3_TEXT);
    $stmt->bindValue(':has_lines', $hasLines ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':fetched_at', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function timesheet_store_mark_webfleet_fetched(SQLite3 $db, string $ym): void
{
    $stmt = $db->prepare('UPDATE months SET webfleet_fetched = 1 WHERE ym = :ym');
    $stmt->bindValue(':ym', $ym, SQLITE3_TEXT);
    $stmt->execute();
}

function timesheet_store_months_missing_webfleet(SQLite3 $db): array
{
    $result = $db->query('SELECT ym FROM months WHERE COALESCE(webfleet_fetched, 0) = 0 ORDER BY ym DESC');
    $months = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $ym = (string) ($row['ym'] ?? '');
        if ($ym !== '') {
            $months[] = $ym;
        }
    }
    return $months;
}

function timesheet_store_boolish($value): int
{
    if ($value === true || $value === 1 || $value === '1') {
        return 1;
    }
    if (is_string($value) && strcasecmp($value, 'true') === 0) {
        return 1;
    }
    return 0;
}

function timesheet_store_line_no(array $line): int
{
    $lineNo = (int) ($line['Line_No'] ?? 0);
    if ($lineNo > 0) {
        return $lineNo;
    }

    $hash = implode('|', [
        (string) ($line['Time_Sheet_No'] ?? ''),
        (string) ($line['Header_Resource_No'] ?? ''),
        (string) ($line['Type'] ?? ''),
        (string) ($line['Work_Type_Code'] ?? ''),
        (string) ($line['Job_No'] ?? ''),
        (string) ($line['Job_Task_No'] ?? ''),
        (string) ($line['Description'] ?? ''),
        (string) ($line['Field1'] ?? ''),
        (string) ($line['Field2'] ?? ''),
        (string) ($line['Field3'] ?? ''),
        (string) ($line['Field4'] ?? ''),
        (string) ($line['Field5'] ?? ''),
        (string) ($line['Field6'] ?? ''),
        (string) ($line['Field7'] ?? ''),
    ]);
    $crc = (int) sprintf('%u', crc32($hash));
    return $crc > 0 ? $crc : 1;
}

function timesheet_store_replace_month_data(SQLite3 $db, array $timesheets, array $lines, string $liveFromYmd): void
{
    $now = time();
    $db->exec('BEGIN');
    try {
        $deleteLines = $db->prepare('DELETE FROM lines WHERE time_sheet_no = :no');
        $deleteTs = $db->prepare('DELETE FROM timesheets WHERE no = :no');
        $insertTs = $db->prepare('INSERT INTO timesheets(
            no, starting_date, ending_date, description, resource_no, resource_name, permanent, updated_at
        ) VALUES(
            :no, :starting_date, :ending_date, :description, :resource_no, :resource_name, :permanent, :updated_at
        )');
        $insertLine = $db->prepare('INSERT INTO lines(
            time_sheet_no, line_no, header_resource_no, header_starting_date, header_ending_date,
            type, status, description, job_no, job_task_no, cause_of_absence_code, chargeable,
            work_type_code, service_order_no, assembly_order_no, archived,
            field1, field2, field3, field4, field5, field6, field7, total_quantity
        ) VALUES(
            :time_sheet_no, :line_no, :header_resource_no, :header_starting_date, :header_ending_date,
            :type, :status, :description, :job_no, :job_task_no, :cause_of_absence_code, :chargeable,
            :work_type_code, :service_order_no, :assembly_order_no, :archived,
            :field1, :field2, :field3, :field4, :field5, :field6, :field7, :total_quantity
        )');

        $seenNos = [];
        foreach ($timesheets as $ts) {
            $no = (string) ($ts['No'] ?? '');
            if ($no === '' || isset($seenNos[$no])) {
                continue;
            }
            $seenNos[$no] = true;

            $endingDate = (string) ($ts['Ending_Date'] ?? '');
            $permanent = ($endingDate !== '' && $endingDate < $liveFromYmd) ? 1 : 0;

            $deleteLines->bindValue(':no', $no, SQLITE3_TEXT);
            $deleteLines->execute();
            $deleteTs->bindValue(':no', $no, SQLITE3_TEXT);
            $deleteTs->execute();

            $insertTs->bindValue(':no', $no, SQLITE3_TEXT);
            $insertTs->bindValue(':starting_date', (string) ($ts['Starting_Date'] ?? ''), SQLITE3_TEXT);
            $insertTs->bindValue(':ending_date', $endingDate, SQLITE3_TEXT);
            $insertTs->bindValue(':description', (string) ($ts['Description'] ?? ''), SQLITE3_TEXT);
            $insertTs->bindValue(':resource_no', (string) ($ts['Resource_No'] ?? ''), SQLITE3_TEXT);
            $insertTs->bindValue(':resource_name', (string) ($ts['Resource_Name'] ?? ''), SQLITE3_TEXT);
            $insertTs->bindValue(':permanent', $permanent, SQLITE3_INTEGER);
            $insertTs->bindValue(':updated_at', $now, SQLITE3_INTEGER);
            $insertTs->execute();
        }

        $seenLines = [];
        foreach ($lines as $line) {
            $tsNo = (string) ($line['Time_Sheet_No'] ?? '');
            if ($tsNo === '') {
                continue;
            }
            $lineNo = timesheet_store_line_no($line);
            $key = $tsNo . '|' . $lineNo;
            if (isset($seenLines[$key])) {
                continue;
            }
            $seenLines[$key] = true;

            $insertLine->bindValue(':time_sheet_no', $tsNo, SQLITE3_TEXT);
            $insertLine->bindValue(':line_no', $lineNo, SQLITE3_INTEGER);
            $insertLine->bindValue(':header_resource_no', (string) ($line['Header_Resource_No'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':header_starting_date', (string) ($line['Header_Starting_Date'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':header_ending_date', (string) ($line['Header_Ending_Date'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':type', (string) ($line['Type'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':status', (string) ($line['Status'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':description', (string) ($line['Description'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':job_no', (string) ($line['Job_No'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':job_task_no', (string) ($line['Job_Task_No'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':cause_of_absence_code', (string) ($line['Cause_of_Absence_Code'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':chargeable', timesheet_store_boolish($line['Chargeable'] ?? 0), SQLITE3_INTEGER);
            $insertLine->bindValue(':work_type_code', (string) ($line['Work_Type_Code'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':service_order_no', (string) ($line['Service_Order_No'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':assembly_order_no', (string) ($line['Assembly_Order_No'] ?? ''), SQLITE3_TEXT);
            $insertLine->bindValue(':archived', timesheet_store_boolish($line['Archived'] ?? 0), SQLITE3_INTEGER);
            $insertLine->bindValue(':field1', (float) ($line['Field1'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field2', (float) ($line['Field2'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field3', (float) ($line['Field3'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field4', (float) ($line['Field4'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field5', (float) ($line['Field5'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field6', (float) ($line['Field6'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':field7', (float) ($line['Field7'] ?? 0), SQLITE3_FLOAT);
            $insertLine->bindValue(':total_quantity', (float) ($line['Total_Quantity'] ?? 0), SQLITE3_FLOAT);
            $insertLine->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function timesheet_store_mark_permanent_before(SQLite3 $db, string $liveFromYmd): void
{
    $stmt = $db->prepare('UPDATE timesheets SET permanent = 1 WHERE ending_date < :live_from AND permanent = 0');
    $stmt->bindValue(':live_from', $liveFromYmd, SQLITE3_TEXT);
    $stmt->execute();
}

function timesheet_store_upsert_resources(SQLite3 $db, array $resources): void
{
    $now = time();
    $stmt = $db->prepare('INSERT INTO resources(no, name, time_sheet_approver_user_id, updated_at)
        VALUES(:no, :name, :approver, :updated_at)
        ON CONFLICT(no) DO UPDATE SET
            name = excluded.name,
            time_sheet_approver_user_id = excluded.time_sheet_approver_user_id,
            updated_at = excluded.updated_at');

    foreach ($resources as $resource) {
        $no = (string) ($resource['No'] ?? '');
        if ($no === '') {
            continue;
        }
        $stmt->bindValue(':no', $no, SQLITE3_TEXT);
        $stmt->bindValue(':name', (string) ($resource['Name'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':approver', (string) ($resource['Time_Sheet_Approver_User_ID'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function timesheet_store_timesheet_from_db(array $row): array
{
    return [
        'No' => (string) ($row['no'] ?? ''),
        'Starting_Date' => (string) ($row['starting_date'] ?? ''),
        'Ending_Date' => (string) ($row['ending_date'] ?? ''),
        'Description' => (string) ($row['description'] ?? ''),
        'Resource_No' => (string) ($row['resource_no'] ?? ''),
        'Resource_Name' => (string) ($row['resource_name'] ?? ''),
    ];
}

function timesheet_store_line_from_db(array $row): array
{
    return [
        'Time_Sheet_No' => (string) ($row['time_sheet_no'] ?? ''),
        'Line_No' => (int) ($row['line_no'] ?? 0),
        'Header_Resource_No' => (string) ($row['header_resource_no'] ?? ''),
        'Header_Starting_Date' => (string) ($row['header_starting_date'] ?? ''),
        'Header_Ending_Date' => (string) ($row['header_ending_date'] ?? ''),
        'Type' => (string) ($row['type'] ?? ''),
        'Status' => (string) ($row['status'] ?? ''),
        'Description' => (string) ($row['description'] ?? ''),
        'Job_No' => (string) ($row['job_no'] ?? ''),
        'Job_Task_No' => (string) ($row['job_task_no'] ?? ''),
        'Cause_of_Absence_Code' => (string) ($row['cause_of_absence_code'] ?? ''),
        'Chargeable' => (int) ($row['chargeable'] ?? 0) === 1,
        'Work_Type_Code' => (string) ($row['work_type_code'] ?? ''),
        'Service_Order_No' => (string) ($row['service_order_no'] ?? ''),
        'Assembly_Order_No' => (string) ($row['assembly_order_no'] ?? ''),
        'Archived' => (int) ($row['archived'] ?? 0) === 1,
        'Field1' => (float) ($row['field1'] ?? 0),
        'Field2' => (float) ($row['field2'] ?? 0),
        'Field3' => (float) ($row['field3'] ?? 0),
        'Field4' => (float) ($row['field4'] ?? 0),
        'Field5' => (float) ($row['field5'] ?? 0),
        'Field6' => (float) ($row['field6'] ?? 0),
        'Field7' => (float) ($row['field7'] ?? 0),
        'Total_Quantity' => (float) ($row['total_quantity'] ?? 0),
    ];
}

function timesheet_store_resource_from_db(array $row): array
{
    return [
        'No' => (string) ($row['no'] ?? ''),
        'Name' => (string) ($row['name'] ?? ''),
        'Time_Sheet_Approver_User_ID' => (string) ($row['time_sheet_approver_user_id'] ?? ''),
    ];
}

function timesheet_store_fetch_all(SQLite3Result $result, callable $mapper): array
{
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $mapper($row);
    }
    return $rows;
}

function timesheet_store_get_timesheets(SQLite3 $db, string $from, string $to, string $resourceNo = ''): array
{
    $sql = 'SELECT * FROM timesheets
        WHERE ending_date >= :from AND starting_date <= :to';
    if ($resourceNo !== '') {
        $sql .= ' AND resource_no = :resource_no';
    }
    $sql .= ' ORDER BY starting_date DESC, no ASC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':from', $from, SQLITE3_TEXT);
    $stmt->bindValue(':to', $to, SQLITE3_TEXT);
    if ($resourceNo !== '') {
        $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    return timesheet_store_fetch_all($result, 'timesheet_store_timesheet_from_db');
}

function timesheet_store_get_timesheets_for_resource(SQLite3 $db, string $from, string $to, string $resourceNo): array
{
    $sql = 'SELECT DISTINCT t.*
        FROM timesheets t
        LEFT JOIN lines l ON l.time_sheet_no = t.no
        WHERE t.ending_date >= :from AND t.starting_date <= :to
          AND (
            t.resource_no = :resource_no
            OR l.header_resource_no = :resource_no_line
          )
        ORDER BY t.starting_date DESC, t.no ASC';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':from', $from, SQLITE3_TEXT);
    $stmt->bindValue(':to', $to, SQLITE3_TEXT);
    $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
    $stmt->bindValue(':resource_no_line', $resourceNo, SQLITE3_TEXT);
    $result = $stmt->execute();
    return timesheet_store_fetch_all($result, 'timesheet_store_timesheet_from_db');
}

function timesheet_store_get_timesheet(SQLite3 $db, string $no): ?array
{
    $stmt = $db->prepare('SELECT * FROM timesheets WHERE no = :no');
    $stmt->bindValue(':no', $no, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }
    return timesheet_store_timesheet_from_db($row);
}

function timesheet_store_in_chunks(SQLite3 $db, string $sql, string $placeholder, array $values, callable $mapper, int $chunkSize = 200): array
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        $placeholders = [];
        $stmtSql = $sql;
        $binds = [];
        foreach ($chunk as $i => $value) {
            $name = $placeholder . $i;
            $placeholders[] = ':' . $name;
            $binds[$name] = $value;
        }
        $stmtSql = str_replace('/*IN*/', implode(', ', $placeholders), $stmtSql);
        $stmt = $db->prepare($stmtSql);
        foreach ($binds as $name => $value) {
            $stmt->bindValue(':' . $name, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        foreach (timesheet_store_fetch_all($result, $mapper) as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function timesheet_store_get_lines_for_timesheets(SQLite3 $db, array $tsNos): array
{
    return timesheet_store_in_chunks(
        $db,
        'SELECT * FROM lines WHERE time_sheet_no IN (/*IN*/) ORDER BY time_sheet_no, line_no',
        'ts',
        $tsNos,
        'timesheet_store_line_from_db'
    );
}

function timesheet_store_get_lines_for_timesheet(SQLite3 $db, string $tsNo): array
{
    $stmt = $db->prepare('SELECT * FROM lines WHERE time_sheet_no = :no ORDER BY line_no');
    $stmt->bindValue(':no', $tsNo, SQLITE3_TEXT);
    $result = $stmt->execute();
    return timesheet_store_fetch_all($result, 'timesheet_store_line_from_db');
}

function timesheet_store_get_resources(SQLite3 $db, array $resourceNos): array
{
    $rows = timesheet_store_in_chunks(
        $db,
        'SELECT * FROM resources WHERE no IN (/*IN*/)',
        'res',
        $resourceNos,
        'timesheet_store_resource_from_db'
    );
    $byNo = [];
    foreach ($rows as $row) {
        $byNo[(string) $row['No']] = $row;
    }
    return $byNo;
}

function timesheet_store_all_resource_nos(SQLite3 $db): array
{
    $nos = [];
    $queries = [
        'SELECT no FROM resources WHERE no IS NOT NULL AND no != \'\'',
        'SELECT DISTINCT header_resource_no AS no FROM lines WHERE header_resource_no IS NOT NULL AND header_resource_no != \'\'',
        'SELECT DISTINCT resource_no AS no FROM webfleet_card_lines WHERE resource_no IS NOT NULL AND resource_no != \'\'',
        'SELECT DISTINCT resource_no AS no FROM timesheets WHERE resource_no IS NOT NULL AND resource_no != \'\'',
    ];
    foreach ($queries as $sql) {
        $result = @$db->query($sql);
        if (!$result) {
            continue;
        }
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $no = trim((string) ($row['no'] ?? ''));
            if ($no !== '') {
                $nos[$no] = true;
            }
        }
    }
    $list = array_keys($nos);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

function timesheet_store_valid_months(SQLite3 $db): array
{
    $result = $db->query('SELECT t.starting_date, t.ending_date
        FROM timesheets t
        WHERE EXISTS (
            SELECT 1 FROM lines l
            WHERE l.time_sheet_no = t.no
              AND l.header_resource_no IS NOT NULL
              AND l.header_resource_no != \'\'
        )');

    $months = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sd = (string) ($row['starting_date'] ?? '');
        $ed = (string) ($row['ending_date'] ?? '');
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
    rsort($monthList);
    return $monthList;
}

function timesheet_leave_codes(): array
{
    return [
        'VAK' => 'Vakantie / ATV',
        'ZK' => 'Ziek',
        'BV' => 'Bijzonder verlof',
        'DK' => 'Dokter/Tandarts/Fysio',
        'FD' => 'Feestdag',
        'TVT' => 'Tijd voor tijd',
        'OV' => 'Overig',
        'WW' => 'WW',
    ];
}

function timesheet_line_leave_code(array $line): string
{
    $codes = timesheet_leave_codes();
    $workType = strtoupper(trim((string) ($line['Work_Type_Code'] ?? '')));
    if (isset($codes[$workType])) {
        return $workType;
    }
    $task = strtoupper(trim((string) ($line['Job_Task_No'] ?? '')));
    if (isset($codes[$task])) {
        return $task;
    }
    return $workType !== '' ? $workType : $task;
}

function timesheet_line_is_leave(array $line): bool
{
    $absenceCode = trim((string) ($line['Cause_of_Absence_Code'] ?? ''));
    if ($absenceCode !== '') {
        return true;
    }

    $type = $line['Type'] ?? '';
    if (is_numeric($type) && (int) $type === 2) {
        return true;
    }
    $typeNorm = strtolower(trim((string) $type));
    if (in_array($typeNorm, ['absence', 'afwezigheid', 'leave'], true)) {
        return true;
    }

    $codes = timesheet_leave_codes();
    $workType = strtoupper(trim((string) ($line['Work_Type_Code'] ?? '')));
    $task = strtoupper(trim((string) ($line['Job_Task_No'] ?? '')));
    return isset($codes[$workType]) || isset($codes[$task]);
}

function timesheet_week_no_from_timesheet(array $timesheet): int
{
    $desc = (string) ($timesheet['Description'] ?? '');
    if (preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m)) {
        return (int) $m[1];
    }

    $start = (string) ($timesheet['Starting_Date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        return 0;
    }

    try {
        return (int) (new DateTimeImmutable($start))->format('W');
    } catch (Exception $e) {
        return 0;
    }
}

function timesheet_store_webfleet_hour_from_db(array $row): array
{
    return [
        'Job_Task_No' => (string) ($row['job_task_no'] ?? ''),
        'KVT_Date_Webfleet_Activity' => (string) ($row['activity_date'] ?? ''),
        'KVT_Start_time_Webfleet_Act' => (string) ($row['start_time'] ?? ''),
        'KVT_End_time_Webfleet_Act' => (string) ($row['end_time'] ?? ''),
        'KVT_Pause' => (string) ($row['pause'] ?? ''),
        'Work_Type_Code' => (string) ($row['work_type_code'] ?? ''),
        'KVT_Calculated_Hours' => (float) ($row['calculated_hours'] ?? 0),
    ];
}

function timesheet_store_webfleet_card_line_from_db(array $row): array
{
    return [
        'No' => (string) ($row['resource_no'] ?? ''),
        'Job_Task_No' => (string) ($row['job_task_no'] ?? ''),
        'KVT_Date_Webfleet_Activity' => (string) ($row['activity_date'] ?? ''),
        'KVT_Start_time_Webfleet_Act' => (string) ($row['start_time'] ?? ''),
        'KVT_End_time_Webfleet_Act' => (string) ($row['end_time'] ?? ''),
        'KVT_Pause' => (string) ($row['pause'] ?? ''),
        'Work_Type_Code' => (string) ($row['work_type_code'] ?? ''),
        'Quantity' => (float) ($row['quantity'] ?? 0),
        'KVT_Calculated_Hours' => (float) ($row['calculated_hours'] ?? 0),
    ];
}

function timesheet_store_webfleet_card_from_db(array $row): array
{
    return [
        'Resource_No' => (string) ($row['resource_no'] ?? ''),
        'Resource_Name' => (string) ($row['resource_name'] ?? ''),
        'Week_No' => (int) ($row['week_no'] ?? 0),
        'Year_No' => (int) ($row['year_no'] ?? 0),
        'Status' => (string) ($row['status'] ?? ''),
    ];
}

function timesheet_store_replace_webfleet_hours(SQLite3 $db, array $rows, string $from, string $to): void
{
    $db->exec('BEGIN');
    try {
        $del = $db->prepare('DELETE FROM webfleet_hours WHERE activity_date >= :from AND activity_date <= :to');
        $del->bindValue(':from', $from, SQLITE3_TEXT);
        $del->bindValue(':to', $to, SQLITE3_TEXT);
        $del->execute();

        $ins = $db->prepare('INSERT INTO webfleet_hours(
            job_task_no, activity_date, start_time, end_time, pause, work_type_code, calculated_hours
        ) VALUES(
            :job_task_no, :activity_date, :start_time, :end_time, :pause, :work_type_code, :calculated_hours
        )');

        $seen = [];
        foreach ($rows as $row) {
            $mapped = [
                'job_task_no' => (string) ($row['Job_Task_No'] ?? ''),
                'activity_date' => (string) ($row['KVT_Date_Webfleet_Activity'] ?? ''),
                'start_time' => (string) ($row['KVT_Start_time_Webfleet_Act'] ?? ''),
                'end_time' => (string) ($row['KVT_End_time_Webfleet_Act'] ?? ''),
                'pause' => (string) ($row['KVT_Pause'] ?? ''),
                'work_type_code' => (string) ($row['Work_Type_Code'] ?? ''),
                'calculated_hours' => (float) ($row['KVT_Calculated_Hours'] ?? 0),
            ];
            if ($mapped['activity_date'] === '' || $mapped['activity_date'] < $from || $mapped['activity_date'] > $to) {
                continue;
            }
            $key = implode('|', $mapped);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $ins->bindValue(':job_task_no', $mapped['job_task_no'], SQLITE3_TEXT);
            $ins->bindValue(':activity_date', $mapped['activity_date'], SQLITE3_TEXT);
            $ins->bindValue(':start_time', $mapped['start_time'], SQLITE3_TEXT);
            $ins->bindValue(':end_time', $mapped['end_time'], SQLITE3_TEXT);
            $ins->bindValue(':pause', $mapped['pause'], SQLITE3_TEXT);
            $ins->bindValue(':work_type_code', $mapped['work_type_code'], SQLITE3_TEXT);
            $ins->bindValue(':calculated_hours', $mapped['calculated_hours'], SQLITE3_FLOAT);
            $ins->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function timesheet_store_replace_webfleet_card_lines(SQLite3 $db, array $rows, string $from, string $to): void
{
    $db->exec('BEGIN');
    try {
        $del = $db->prepare('DELETE FROM webfleet_card_lines WHERE activity_date >= :from AND activity_date <= :to');
        $del->bindValue(':from', $from, SQLITE3_TEXT);
        $del->bindValue(':to', $to, SQLITE3_TEXT);
        $del->execute();

        $ins = $db->prepare('INSERT INTO webfleet_card_lines(
            resource_no, job_task_no, activity_date, start_time, end_time, pause, work_type_code, quantity, calculated_hours
        ) VALUES(
            :resource_no, :job_task_no, :activity_date, :start_time, :end_time, :pause, :work_type_code, :quantity, :calculated_hours
        )');

        $seen = [];
        foreach ($rows as $row) {
            $mapped = [
                'resource_no' => (string) ($row['No'] ?? $row['Resource_No'] ?? ''),
                'job_task_no' => (string) ($row['Job_Task_No'] ?? ''),
                'activity_date' => (string) ($row['KVT_Date_Webfleet_Activity'] ?? ''),
                'start_time' => (string) ($row['KVT_Start_time_Webfleet_Act'] ?? ''),
                'end_time' => (string) ($row['KVT_End_time_Webfleet_Act'] ?? ''),
                'pause' => (string) ($row['KVT_Pause'] ?? ''),
                'work_type_code' => (string) ($row['Work_Type_Code'] ?? ''),
                'quantity' => (float) ($row['Quantity'] ?? 0),
                'calculated_hours' => (float) ($row['KVT_Calculated_Hours'] ?? 0),
            ];
            if ($mapped['activity_date'] === '' || $mapped['activity_date'] < $from || $mapped['activity_date'] > $to) {
                continue;
            }
            $key = implode('|', $mapped);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $ins->bindValue(':resource_no', $mapped['resource_no'], SQLITE3_TEXT);
            $ins->bindValue(':job_task_no', $mapped['job_task_no'], SQLITE3_TEXT);
            $ins->bindValue(':activity_date', $mapped['activity_date'], SQLITE3_TEXT);
            $ins->bindValue(':start_time', $mapped['start_time'], SQLITE3_TEXT);
            $ins->bindValue(':end_time', $mapped['end_time'], SQLITE3_TEXT);
            $ins->bindValue(':pause', $mapped['pause'], SQLITE3_TEXT);
            $ins->bindValue(':work_type_code', $mapped['work_type_code'], SQLITE3_TEXT);
            $ins->bindValue(':quantity', $mapped['quantity'], SQLITE3_FLOAT);
            $ins->bindValue(':calculated_hours', $mapped['calculated_hours'], SQLITE3_FLOAT);
            $ins->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function timesheet_store_upsert_webfleet_cards(SQLite3 $db, array $cards, array $weeksToReplace = []): void
{
    $db->exec('BEGIN');
    try {
        if ($weeksToReplace) {
            $del = $db->prepare('DELETE FROM webfleet_cards WHERE year_no = :year_no AND week_no = :week_no');
            $seenWeeks = [];
            foreach ($weeksToReplace as $week) {
                $yearNo = (int) ($week['year'] ?? 0);
                $weekNo = (int) ($week['week'] ?? 0);
                $key = $yearNo . '-' . $weekNo;
                if ($yearNo <= 0 || $weekNo <= 0 || isset($seenWeeks[$key])) {
                    continue;
                }
                $seenWeeks[$key] = true;
                $del->bindValue(':year_no', $yearNo, SQLITE3_INTEGER);
                $del->bindValue(':week_no', $weekNo, SQLITE3_INTEGER);
                $del->execute();
            }
        }

        $ins = $db->prepare('INSERT INTO webfleet_cards(resource_no, week_no, year_no, resource_name, status)
            VALUES(:resource_no, :week_no, :year_no, :resource_name, :status)
            ON CONFLICT(resource_no, week_no, year_no) DO UPDATE SET
                resource_name = excluded.resource_name,
                status = excluded.status');

        foreach ($cards as $card) {
            $resourceNo = (string) ($card['Resource_No'] ?? '');
            if ($resourceNo === '') {
                continue;
            }
            $ins->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
            $ins->bindValue(':week_no', (int) ($card['Week_No'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':year_no', (int) ($card['Year_No'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':resource_name', (string) ($card['Resource_Name'] ?? ''), SQLITE3_TEXT);
            $ins->bindValue(':status', (string) ($card['Status'] ?? ''), SQLITE3_TEXT);
            $ins->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function timesheet_store_get_webfleet_hours(SQLite3 $db, string $from, string $to, array $jobTaskNos = []): array
{
    $jobTaskNos = array_values(array_unique(array_filter(array_map('strval', $jobTaskNos), fn($v) => $v !== '')));
    if (!$jobTaskNos) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($jobTaskNos, 200) as $chunk) {
        $placeholders = [];
        $binds = [];
        foreach ($chunk as $i => $value) {
            $name = 'task' . $i;
            $placeholders[] = ':' . $name;
            $binds[$name] = $value;
        }
        $sql = 'SELECT * FROM webfleet_hours
            WHERE activity_date >= :from AND activity_date <= :to
              AND job_task_no IN (' . implode(', ', $placeholders) . ')';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':from', $from, SQLITE3_TEXT);
        $stmt->bindValue(':to', $to, SQLITE3_TEXT);
        foreach ($binds as $name => $value) {
            $stmt->bindValue(':' . $name, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        foreach (timesheet_store_fetch_all($result, 'timesheet_store_webfleet_hour_from_db') as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function timesheet_store_get_webfleet_card(SQLite3 $db, string $resourceNo, int $weekNo, int $yearNo): ?array
{
    $stmt = $db->prepare('SELECT * FROM webfleet_cards
        WHERE resource_no = :resource_no AND week_no = :week_no AND year_no = :year_no');
    $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
    $stmt->bindValue(':week_no', $weekNo, SQLITE3_INTEGER);
    $stmt->bindValue(':year_no', $yearNo, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }
    return timesheet_store_webfleet_card_from_db($row);
}

function timesheet_store_get_webfleet_card_lines(SQLite3 $db, string $resourceNo, string $from, string $to, array $jobTaskNos = []): array
{
    $sql = 'SELECT * FROM webfleet_card_lines
        WHERE resource_no = :resource_no
          AND activity_date >= :from
          AND activity_date <= :to';
    $jobTaskNos = array_values(array_unique(array_filter(array_map('strval', $jobTaskNos), fn($v) => $v !== '')));

    $binds = [];
    if ($jobTaskNos) {
        $placeholders = [];
        foreach ($jobTaskNos as $i => $value) {
            $name = 'task' . $i;
            $placeholders[] = ':' . $name;
            $binds[$name] = $value;
        }
        $sql .= ' AND job_task_no IN (' . implode(', ', $placeholders) . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
    $stmt->bindValue(':from', $from, SQLITE3_TEXT);
    $stmt->bindValue(':to', $to, SQLITE3_TEXT);
    foreach ($binds as $name => $value) {
        $stmt->bindValue(':' . $name, $value, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    return timesheet_store_fetch_all($result, 'timesheet_store_webfleet_card_line_from_db');
}
