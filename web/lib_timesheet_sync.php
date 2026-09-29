<?php

function timesheet_sync_line_select(): string
{
    return 'Time_Sheet_No,Line_No,Header_Resource_No,Header_Starting_Date,Header_Ending_Date,'
        . 'Type,Status,Description,Job_No,Job_Task_No,Cause_of_Absence_Code,Chargeable,Work_Type_Code,'
        . 'Service_Order_No,Assembly_Order_No,Archived,'
        . 'Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity';
}

function timesheet_sync_timesheet_select(): string
{
    return 'No,Starting_Date,Ending_Date,Description,Resource_No,Resource_Name';
}

function timesheet_sync_resource_select(): string
{
    return 'No,Name,Time_Sheet_Approver_User_ID';
}

function timesheet_sync_fetch_timesheets(string $base, array $auth, string $from, string $to, int $ttl, bool $forceRefresh): array
{
    $filter = rawurlencode("Ending_Date ge $from and Starting_Date le $to");
    $select = timesheet_sync_timesheet_select();
    $url = $base . "Urenstaten?\$select={$select}&\$filter={$filter}&\$format=json";
    return odata_get_all($url, $auth, $ttl, $forceRefresh);
}

function timesheet_sync_fetch_lines(string $base, array $auth, array $tsNos, int $ttl, bool $forceRefresh): array
{
    return odata_fetch_by_or_filter(
        $base,
        'Urenstaatregels',
        timesheet_sync_line_select(),
        'Time_Sheet_No',
        $tsNos,
        $auth,
        $ttl,
        40,
        $forceRefresh
    );
}

function timesheet_sync_fetch_resources(string $base, array $auth, array $resourceNos, int $ttl, bool $forceRefresh): array
{
    return odata_fetch_by_or_filter(
        $base,
        'AppResource',
        timesheet_sync_resource_select(),
        'No',
        $resourceNos,
        $auth,
        $ttl,
        60,
        $forceRefresh
    );
}

function timesheet_sync_resource_nos_from_lines(array $lines): array
{
    $nos = [];
    foreach ($lines as $line) {
        $no = (string) ($line['Header_Resource_No'] ?? '');
        if ($no !== '') {
            $nos[$no] = true;
        }
    }
    return array_keys($nos);
}

function timesheet_sync_ts_nos(array $timesheets): array
{
    $nos = [];
    foreach ($timesheets as $ts) {
        $no = (string) ($ts['No'] ?? '');
        if ($no !== '') {
            $nos[] = $no;
        }
    }
    return $nos;
}

function timesheet_sync_lines_have_resource_rows(array $lines): bool
{
    foreach ($lines as $line) {
        if (trim((string) ($line['Header_Resource_No'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function timesheet_sync_iso_weeks_for_range(string $from, string $to): array
{
    try {
        $start = (new DateTimeImmutable($from))->modify('monday this week');
        $end = (new DateTimeImmutable($to))->modify('monday this week');
    } catch (Exception $e) {
        return [];
    }

    $weeks = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $weeks[] = [
            'year' => (int) $cursor->format('o'),
            'week' => (int) $cursor->format('W'),
            'monday' => $cursor->format('Y-m-d'),
        ];
        $cursor = $cursor->modify('+7 days');
    }
    return $weeks;
}

function timesheet_sync_webfleet_hours_select(): string
{
    return 'Job_Task_No,KVT_Date_Webfleet_Activity,KVT_Start_time_Webfleet_Act,KVT_End_time_Webfleet_Act,KVT_Pause,Work_Type_Code,KVT_Calculated_Hours';
}

function timesheet_sync_webfleet_card_line_select(): string
{
    return 'No,Job_Task_No,KVT_Date_Webfleet_Activity,KVT_Start_time_Webfleet_Act,Quantity,KVT_End_time_Webfleet_Act,KVT_Pause,Work_Type_Code,KVT_Calculated_Hours';
}

function timesheet_sync_webfleet_card_select(): string
{
    return 'Resource_No,Resource_Name,Week_No,Year_No,Status';
}

function timesheet_sync_fetch_webfleet_hours(string $base, array $auth, string $from, string $to, int $ttl, bool $forceRefresh): array
{
    $filter = rawurlencode("KVT_Date_Webfleet_Activity ge $from and KVT_Date_Webfleet_Activity le $to");
    $select = timesheet_sync_webfleet_hours_select();
    $url = $base . "WebfleetHours?\$select={$select}&\$filter={$filter}&\$format=json";
    return odata_get_all($url, $auth, $ttl, $forceRefresh);
}

function timesheet_sync_fetch_webfleet_card_lines(string $base, array $auth, string $from, string $to, int $ttl, bool $forceRefresh): array
{
    $filter = rawurlencode("KVT_Date_Webfleet_Activity ge $from and KVT_Date_Webfleet_Activity le $to");
    $select = timesheet_sync_webfleet_card_line_select();
    $url = $base . "WebfleetHoursCardWebfleetHrsLines?\$select={$select}&\$filter={$filter}&\$format=json";
    return odata_get_all($url, $auth, $ttl, $forceRefresh);
}

function timesheet_sync_odata_filter_unsupported(Throwable $e): bool
{
    $message = $e->getMessage();
    return stripos($message, 'filter expression is not supported') !== false
        || stripos($message, 'BadRequest_MethodNotImplemented') !== false
        || stripos($message, 'HTTP 501') !== false;
}

function timesheet_sync_fetch_webfleet_cards_for_week(string $base, array $auth, int $yearNo, int $weekNo, string $resourceNo, int $ttl, bool $forceRefresh): array
{
    $filterDecoded = "Week_No eq {$weekNo} and Year_No eq {$yearNo}";
    if ($resourceNo !== '') {
        $filterDecoded = "Resource_No eq '" . str_replace("'", "''", $resourceNo) . "' and " . $filterDecoded;
    }
    $filter = rawurlencode($filterDecoded);
    $select = timesheet_sync_webfleet_card_select();
    $url = $base . "WebfleetHoursCard?\$select={$select}&\$filter={$filter}&\$format=json";
    return odata_get_all($url, $auth, $ttl, $forceRefresh) ?? [];
}

function timesheet_sync_fetch_webfleet_cards(string $base, array $auth, array $weeks, array $resourceNos, int $ttl, bool $forceRefresh, callable $log): array
{
    if (!$weeks) {
        return [];
    }

    $rows = [];
    $perResource = false;

    foreach ($weeks as $week) {
        $yearNo = (int) ($week['year'] ?? 0);
        $weekNo = (int) ($week['week'] ?? 0);
        if ($yearNo <= 0 || $weekNo <= 0) {
            continue;
        }

        if (!$perResource) {
            try {
                $chunkRows = timesheet_sync_fetch_webfleet_cards_for_week($base, $auth, $yearNo, $weekNo, '', $ttl, $forceRefresh);
                foreach ($chunkRows as $row) {
                    $rows[] = $row;
                }
                continue;
            } catch (Throwable $e) {
                if (!timesheet_sync_odata_filter_unsupported($e) || !$resourceNos) {
                    throw $e;
                }
                $perResource = true;
                $log('WebfleetHoursCard accepteert geen weekfilter zonder resource; ophalen per medewerker.');
            }
        }

        foreach ($resourceNos as $resourceNo) {
            $resourceNo = (string) $resourceNo;
            if ($resourceNo === '') {
                continue;
            }
            $chunkRows = timesheet_sync_fetch_webfleet_cards_for_week($base, $auth, $yearNo, $weekNo, $resourceNo, $ttl, $forceRefresh);
            foreach ($chunkRows as $row) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * @return array{hours:int,card_lines:int,cards:int}
 */
function timesheet_sync_webfleet_month(SQLite3 $db, string $base, array $auth, string $ym, int $ttl, bool $forceRefresh, callable $log): array
{
    $bounds = timesheet_store_month_bounds($ym);
    $from = $bounds['from'];
    $to = $bounds['to'];
    $liveFrom = timesheet_store_live_from_ymd();

    if (!$forceRefresh && $to >= $liveFrom) {
        $toDt = new DateTimeImmutable($liveFrom);
        $to = $toDt->modify('-1 day')->format('Y-m-d');
    }

    $empty = ['hours' => 0, 'card_lines' => 0, 'cards' => 0];
    if ($to < $from) {
        timesheet_store_mark_webfleet_fetched($db, $ym);
        return $empty;
    }

    $log("OData: WebfleetHours {$from} t/m {$to}");
    $hours = timesheet_sync_fetch_webfleet_hours($base, $auth, $from, $to, $ttl, $forceRefresh);
    timesheet_store_replace_webfleet_hours($db, $hours, $from, $to);
    $log('WebfleetHours opgeslagen: ' . count($hours));

    $log("OData: WebfleetHoursCardWebfleetHrsLines {$from} t/m {$to}");
    $cardLines = timesheet_sync_fetch_webfleet_card_lines($base, $auth, $from, $to, $ttl, $forceRefresh);
    timesheet_store_replace_webfleet_card_lines($db, $cardLines, $from, $to);
    $log('Webfleet kaartregels opgeslagen: ' . count($cardLines));

    $weeks = timesheet_sync_iso_weeks_for_range($from, $to);
    if (!$forceRefresh) {
        $weeks = array_values(array_filter($weeks, fn($week) => (string) ($week['monday'] ?? '') < $liveFrom));
    }
    $resourceNos = timesheet_store_all_resource_nos($db);
    $log('OData: WebfleetHoursCard (' . count($weeks) . ' weken, ' . count($resourceNos) . ' resources)');
    $cards = timesheet_sync_fetch_webfleet_cards($base, $auth, $weeks, $resourceNos, $ttl, $forceRefresh, $log);
    timesheet_store_upsert_webfleet_cards($db, $cards, $forceRefresh ? $weeks : []);
    $log('Webfleet kaarten opgeslagen: ' . count($cards));

    timesheet_store_mark_webfleet_fetched($db, $ym);

    return [
        'hours' => count($hours),
        'card_lines' => count($cardLines),
        'cards' => count($cards),
    ];
}

function timesheet_sync_pending_webfleet(SQLite3 $db, string $base, array $auth, int $ttl, callable $log): void
{
    $months = timesheet_store_months_missing_webfleet($db);
    if (!$months) {
        $log('Webfleet is voor alle bekende maanden al opgehaald.');
        return;
    }

    $log('Webfleet nabouwen voor ' . count($months) . ' maand(en).');
    $currentYm = timesheet_store_current_month();
    foreach ($months as $ym) {
        $forceRefresh = ($ym === $currentYm);
        $log("Webfleet-maand: {$ym}");
        timesheet_sync_webfleet_month($db, $base, $auth, $ym, $ttl, $forceRefresh, $log);
    }
}

/**
 * Haal één kalendermaand op uit OData en schrijf die naar SQLite.
 * @return array{timesheets:int,lines:int,has_lines:bool}
 */
function timesheet_sync_month(SQLite3 $db, string $base, array $auth, string $ym, int $ttl, bool $forceRefresh, callable $log): array
{
    $bounds = timesheet_store_month_bounds($ym);
    $liveFrom = timesheet_store_live_from_ymd();
    $log("OData: urenstaten {$ym} ({$bounds['from']} t/m {$bounds['to']})");

    $timesheets = timesheet_sync_fetch_timesheets($base, $auth, $bounds['from'], $bounds['to'], $ttl, $forceRefresh);

    if (!$forceRefresh) {
        $timesheets = array_values(array_filter($timesheets, function ($ts) use ($liveFrom) {
            $endingDate = (string) ($ts['Ending_Date'] ?? '');
            return $endingDate !== '' && $endingDate < $liveFrom;
        }));
    }

    $tsNos = timesheet_sync_ts_nos($timesheets);
    $log('Gevonden urenstaten: ' . count($tsNos));

    $lines = [];
    if ($tsNos) {
        $log('OData: urenstaatregels (' . count($tsNos) . ' urenstaten)');
        $lines = timesheet_sync_fetch_lines($base, $auth, $tsNos, $ttl, $forceRefresh);
    }
    $log('Gevonden regels: ' . count($lines));

    $hasLines = timesheet_sync_lines_have_resource_rows($lines);
    if ($timesheets) {
        timesheet_store_replace_month_data($db, $timesheets, $lines, $liveFrom);
    }

    $resourceNos = timesheet_sync_resource_nos_from_lines($lines);
    if ($resourceNos) {
        $log('OData: resources (' . count($resourceNos) . ')');
        $resources = timesheet_sync_fetch_resources($base, $auth, $resourceNos, $ttl, $forceRefresh);
        timesheet_store_upsert_resources($db, $resources);
        $log('Resources bijgewerkt: ' . count($resources));
    }

    timesheet_store_record_month($db, $ym, $hasLines);
    timesheet_store_mark_permanent_before($db, $liveFrom);

    $log("Webfleet voor {$ym}");
    timesheet_sync_webfleet_month($db, $base, $auth, $ym, $ttl, $forceRefresh, $log);

    return [
        'timesheets' => count($tsNos),
        'lines' => count($lines),
        'has_lines' => $hasLines,
    ];
}

function timesheet_sync_live_month(SQLite3 $db, string $base, array $auth, int $ttl, callable $log): array
{
    $ym = timesheet_store_current_month();
    $log("Live-maand verversen: {$ym}");
    return timesheet_sync_month($db, $base, $auth, $ym, $ttl, true, $log);
}

function timesheet_sync_backfill(SQLite3 $db, string $base, array $auth, int $ttl, callable $log, int $emptyStop = 5): void
{
    if (timesheet_store_backfill_complete($db)) {
        $log('Backfill is al voltooid.');
        return;
    }

    $currentYm = timesheet_store_current_month();
    $cursor = timesheet_store_meta_get($db, 'backfill_cursor', $currentYm);
    $emptyStreak = (int) timesheet_store_meta_get($db, 'backfill_empty_streak', '0');
    if ($cursor === '') {
        $cursor = $currentYm;
    }

    $log("Backfill vanaf {$cursor} (lege-maanden-reeks: {$emptyStreak}/{$emptyStop})");

    while ($emptyStreak < $emptyStop) {
        if (timesheet_store_month_recorded($db, $cursor)) {
            $stmt = $db->prepare('SELECT has_lines FROM months WHERE ym = :ym');
            $stmt->bindValue(':ym', $cursor, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
            $hasLines = $row && (int) ($row['has_lines'] ?? 0) === 1;
            if ($hasLines) {
                $emptyStreak = 0;
                $log("{$cursor}: al aanwezig, heeft regels — reeks gereset");
            } else {
                $emptyStreak++;
                $log("{$cursor}: al aanwezig, geen regels — reeks {$emptyStreak}/{$emptyStop}");
            }
        } else {
            $stats = timesheet_sync_month($db, $base, $auth, $cursor, $ttl, false, $log);
            if (!empty($stats['has_lines'])) {
                $emptyStreak = 0;
                $log("{$cursor}: {$stats['lines']} regels");
            } else {
                $emptyStreak++;
                $log("{$cursor}: geen regels — reeks {$emptyStreak}/{$emptyStop}");
            }
        }

        $cursor = timesheet_store_previous_month($cursor);
        timesheet_store_meta_set($db, 'backfill_cursor', $cursor);
        timesheet_store_meta_set($db, 'backfill_empty_streak', (string) $emptyStreak);
    }

    timesheet_store_meta_set($db, 'backfill_complete', '1');
    $log('Backfill voltooid.');
}
