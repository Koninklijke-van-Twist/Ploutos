<?php

function hours_to_minutes(float $h): int
{
  return (int) round($h * 60);
}

function minutes_to_hhmm(float|int $min): string
{
  $sign = $min < 0 ? "-" : "";
  $min = (int) round(abs($min));
  $h = intdiv($min, 60);
  $m = $min % 60;
  return sprintf("%s%d:%02d", $sign, $h, $m);
}

function ymd_add_days(string $ymd, int $days): string
{
  $dt = new DateTimeImmutable($ymd);
  return $dt->modify(($days >= 0 ? "+" : "") . $days . " days")->format("Y-m-d");
}

/**
 * Vul hier NL feestdagen in (YYYY-MM-DD).
 */
function holiday_set($year): array
{
  $year = (string) $year;

  // Pad: feestdagen/feestdagen_2026.json
  $file = __DIR__ . "/feestdagen/feestdagen_{$year}.json";

  if (!file_exists($file)) {
    return []; // geen bestand = geen feestdagen
  }

  $raw = file_get_contents($file);
  if ($raw === false) {
    return [];
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return [];
  }

  // Verwacht formaat: [{name, date}, ...]
  $set = [];

  foreach ($data as $row) {
    if (!is_array($row))
      continue;
    $date = (string) ($row['date'] ?? '');
    if ($date !== '') {
      $set[$date] = true; // associative set
    }
  }

  return $set;
}


/**
 * Verdeel dag-uren in categorieën:
 * - 28.5%: overuren boven 8u met max 2u per werkdag (Ma-Vr) (dus min(max(h-8,0),2))
 * - 47%: uren boven 10u (Ma-Vr) + alle uren op zaterdag
 * - 85%: alle uren op zondag of feestdag
 *
 * Retourneert minuten: ['p285'=>..., 'p47'=>..., 'p85'=>...]
 */
function split_premiums_for_day(float $hours, string $dateYmd): array
{
  $minutes = hours_to_minutes($hours);
  if ($minutes <= 0)
    return ['p285' => 0, 'p47' => 0, 'p85' => 0];

  $dow = (int) (new DateTimeImmutable($dateYmd))->format('N'); // 1=Mon..7=Sun

  $year = substr($dateYmd, 0, 4);
  $holidays = holiday_set($year);

  $isHoliday = isset($holidays[$dateYmd]);

  // Feestdag/zondag: alles 85
  if ($isHoliday || $dow === 7) {
    return ['p285' => 0, 'p47' => 0, 'p85' => $minutes];
  }

  // Zaterdag: alles 47
  if ($dow === 6) {
    return ['p285' => 0, 'p47' => $minutes, 'p85' => 0];
  }

  // Ma–Vr
  $base = hours_to_minutes(8);
  $ten = hours_to_minutes(10);

  $over = max(0, $minutes - $base);
  $p285 = min($over, hours_to_minutes(2));            // max 2 uur
  $p47 = max(0, $minutes - $ten);                    // alles boven 10 uur

  return ['p285' => $p285, 'p47' => $p47, 'p85' => 0];
}

function hours_remaining_after_premiums(array $dayHours, array $dates): float
{
  $remaining = 0.0;
  for ($d = 0; $d < 7; $d++) {
    $hours = (float) ($dayHours[$d] ?? 0);
    if ($hours <= 0) {
      continue;
    }
    $date = (string) ($dates[$d] ?? '');
    $split = $date !== ''
      ? split_premiums_for_day($hours, $date)
      : ['p285' => 0, 'p47' => 0, 'p85' => 0];
    $premium = (
      (float) ($split['p285'] ?? 0)
      + (float) ($split['p47'] ?? 0)
      + (float) ($split['p85'] ?? 0)
    ) / 60.0;
    $remaining += max(0, $hours - $premium);
  }
  return $remaining;
}

/**
 * Detecteert potentieel onjuist ingevulde uren op basis van regels:
 * - >24 uur geschreven op een dag
 * - Ma-Vr meer dan 2 uur op SOT125
 * - Op zondag uren op SOT125 of SOT150
 *
 * Verwacht regels met keys: Work_Type_Code, Field1..Field7 en optioneel Status.
 */
function detect_hour_entry_issues(array $lines, bool $approvedOnly = false): array
{
  $dayTotals = array_fill(0, 7, 0.0);
  $sot125ByDay = array_fill(0, 7, 0.0);
  $sot150ByDay = array_fill(0, 7, 0.0);

  foreach ($lines as $line) {
    if ($approvedOnly && !timesheet_line_passes_approved_only_filter($line)) {
      continue;
    }

    $workType = (string) ($line['Work_Type_Code'] ?? '');
    for ($i = 1; $i <= 7; $i++) {
      $hours = (float) ($line["Field{$i}"] ?? 0);
      $dayIndex = $i - 1;

      if ($workType !== 'KM') {
        $dayTotals[$dayIndex] += $hours;
      }
      if ($workType === 'SOT125') {
        $sot125ByDay[$dayIndex] += $hours;
      } elseif ($workType === 'SOT150') {
        $sot150ByDay[$dayIndex] += $hours;
      }
    }
  }

  $over24Days = array_fill(0, 7, false);
  $weekdaySot125Over2Days = array_fill(0, 7, false);
  $sundaySotDays = array_fill(0, 7, false);
  $hasIssues = false;

  for ($d = 0; $d < 7; $d++) {
    $over24Days[$d] = $dayTotals[$d] > 24.0;
    if ($d <= 4) {
      $weekdaySot125Over2Days[$d] = $sot125ByDay[$d] > 2.0;
    }
    if ($d === 6) {
      $sundaySotDays[$d] = $sot125ByDay[$d] > 0.0 || $sot150ByDay[$d] > 0.0;
    }
    if ($over24Days[$d] || $weekdaySot125Over2Days[$d] || $sundaySotDays[$d]) {
      $hasIssues = true;
    }
  }

  return [
    'dayTotals' => $dayTotals,
    'sot125ByDay' => $sot125ByDay,
    'sot150ByDay' => $sot150ByDay,
    'over24Days' => $over24Days,
    'weekdaySot125Over2Days' => $weekdaySot125Over2Days,
    'sundaySotDays' => $sundaySotDays,
    'hasIssues' => $hasIssues,
  ];
}

/**
 * Queryparam voor "Niet-goedgekeurde staten ook tonen".
 * Alleen de waarde 1 zet de stand aan; afwezig of iets anders blijft uit.
 */
function timesheet_include_unapproved_param(): string
{
  return 'includeUnapproved';
}

function timesheet_include_unapproved_from_request(array $query): bool
{
  $param = timesheet_include_unapproved_param();
  if (!array_key_exists($param, $query)) {
    return false;
  }
  $value = $query[$param];
  if (is_array($value)) {
    return false;
  }
  return (string) $value === '1';
}

function timesheet_include_unapproved_query_suffix(bool $includeUnapproved): string
{
  if (!$includeUnapproved) {
    return '';
  }
  return '&' . rawurlencode(timesheet_include_unapproved_param()) . '=1';
}

function timesheet_include_unapproved_hidden_input(bool $includeUnapproved): string
{
  if (!$includeUnapproved) {
    return '';
  }
  $name = htmlspecialchars(timesheet_include_unapproved_param(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="' . $name . '" value="1">';
}

/**
 * BC levert de urenstaatstatus soms Engels, soms Nederlands.
 * Eén canonieke waarde voor elke vergelijking: approved, open, submitted, rejected.
 * Onbekend of leeg blijft de getrimde, kleine tekst. De getoonde kolom gebruikt dit niet.
 */
function timesheet_normalize_status(string $status): string
{
  $normalized = strtolower(trim($status));
  static $map = [
    'approved' => 'approved',
    'goedgekeurd' => 'approved',
    'open' => 'open',
    'submitted' => 'submitted',
    'ingediend' => 'submitted',
    'rejected' => 'rejected',
    'afgewezen' => 'rejected',
    'afgekeurd' => 'rejected',
  ];
  return $map[$normalized] ?? $normalized;
}

function timesheet_status_is_approved(string $status): bool
{
  return timesheet_normalize_status($status) === 'approved';
}

function timesheet_status_css_class(string $status): string
{
  return match (timesheet_normalize_status($status)) {
    'open' => 'openStatus',
    'submitted' => 'submittedStatus',
    'rejected' => 'rejectedStatus',
    'approved' => 'approvedStatus',
    default => '',
  };
}

/**
 * Goedgekeurde regels tellen altijd mee.
 * Andere statussen (Open, Submitted, Rejected, leeg, of de Nederlandse BC-tekst)
 * tellen alleen mee als niet-goedgekeurde staten expliciet getoond worden.
 */
function timesheet_line_original_status(array $line): string
{
  if (array_key_exists('original_status', $line)) {
    return (string) $line['original_status'];
  }
  return (string) ($line['Status'] ?? '');
}

function timesheet_line_display_status(array $line): string
{
  return timesheet_line_original_status($line);
}

/**
 * Berekeningen mogen een regel als goedgekeurd behandelen via treated_as_approved.
 * De BC-status zelf blijft in Status en original_status staan.
 */
function timesheet_line_is_originally_approved(array $line): bool
{
  return timesheet_status_is_approved(timesheet_line_original_status($line));
}

function timesheet_line_passes_approved_only_filter(array $line): bool
{
  if (!empty($line['treated_as_approved'])) {
    return true;
  }
  return timesheet_line_is_originally_approved($line);
}

function timesheet_line_counts_as_approved(array $line, bool $includeUnapproved): bool
{
  if (timesheet_line_is_originally_approved($line)) {
    return true;
  }
  return $includeUnapproved;
}

function timesheet_counted_unapproved_class(): string
{
  return 'counted-unapproved';
}

/**
 * Klasse voor een regel die niet goedgekeurd is, maar wel als goedgekeurd meetelt.
 * Leeg als de optie uit staat of de regel echt Approved is.
 */
function timesheet_row_counts_as_unapproved_highlight(bool $includeUnapproved, bool $hasUnapproved): string
{
  if (!$includeUnapproved || !$hasUnapproved) {
    return '';
  }
  return timesheet_counted_unapproved_class();
}

function timesheet_counted_unapproved_row_attr(bool $includeUnapproved, bool $hasUnapproved): string
{
  $class = timesheet_row_counts_as_unapproved_highlight($includeUnapproved, $hasUnapproved);
  if ($class === '') {
    return '';
  }
  return ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
}

function timesheet_line_highlight_class(array $line, bool $includeUnapproved): string
{
  return timesheet_row_counts_as_unapproved_highlight(
    $includeUnapproved,
    !timesheet_line_is_originally_approved($line)
  );
}

function timesheet_line_highlight_row_attr(array $line, bool $includeUnapproved): string
{
  return timesheet_counted_unapproved_row_attr(
    $includeUnapproved,
    !timesheet_line_is_originally_approved($line)
  );
}

/**
 * Scheidt een afgeleide cache per stand. De ruwe BC-/sqlite-cache blijft gedeeld;
 * die bevat alle statussen en filtert niet.
 */
function timesheet_approval_view_cache_key(string $cacheKey, bool $includeUnapproved): string
{
  return $cacheKey . "\n" . ($includeUnapproved ? 'include-unapproved' : 'approved-only');
}

function timesheet_lines_counting_as_approved(array $lines, bool $includeUnapproved): array
{
  static $memo = [];

  $parts = [];
  foreach ($lines as $line) {
    if (!is_array($line)) {
      $parts[] = 'invalid';
      continue;
    }
    $encoded = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $parts[] = $encoded === false ? serialize($line) : $encoded;
  }
  $cacheKey = timesheet_approval_view_cache_key(hash('sha256', implode("\n", $parts)), $includeUnapproved);
  if (array_key_exists($cacheKey, $memo)) {
    return $memo[$cacheKey];
  }

  $counted = [];
  foreach ($lines as $line) {
    if (!is_array($line)) {
      continue;
    }
    if (timesheet_line_counts_as_approved($line, $includeUnapproved)) {
      $counted[] = $line;
    }
  }
  $memo[$cacheKey] = $counted;
  return $counted;
}

/**
 * Weekinspectie: uit laat de regels ongemoeid (huidige pagina).
 * Aan zet treated_as_approved, zodat berekeningen die op goedgekeurd filteren
 * de regel meetellen. Status en original_status blijven de BC-status.
 */
function timesheet_inspection_lines_as_counted(array $lines, bool $includeUnapproved): array
{
  if (!$includeUnapproved) {
    return $lines;
  }

  $counted = [];
  foreach ($lines as $line) {
    if (!is_array($line)) {
      continue;
    }
    $original = timesheet_line_original_status($line);
    $line['original_status'] = $original;
    $line['Status'] = $original;
    $line['treated_as_approved'] = true;
    $counted[] = $line;
  }
  return $counted;
}

/**
 * Telt een urenstaatregel mee in een weekbucket van het overzicht.
 * Niet-goedgekeurde regels krijgen altijd de waarschuwing, maar hun uren,
 * onkosten, verlet en validatieregels alleen als $includeUnapproved aan staat.
 */
function timesheet_overview_apply_line(array $week, array $line, bool $includeUnapproved, array $codesOnkosten, array $codesVerlet): array
{
  require_once __DIR__ . '/lib_contract_hours.php';

  $originalStatus = timesheet_line_original_status($line);
  $line['original_status'] = $originalStatus;
  $isApproved = timesheet_status_is_approved($originalStatus);
  if ($isApproved) {
    $week['hasApproved'] = true;
  } else {
    $week['hasUnapproved'] = true;
    $week['unapprovedCount'] = (int) ($week['unapprovedCount'] ?? 0) + 1;
    if (!$includeUnapproved) {
      return $week;
    }
  }
  $line['treated_as_approved'] = true;

  $dayHours = $week['dayHours'];
  $week['validationLines'][] = $line;

  $workType = (string) ($line['Work_Type_Code'] ?? '');

  for ($i = 1; $i <= 7; $i++) {
    if ($workType === 'SOT125')
      $week['p285'] += $line["Field{$i}"] * 60;
    else if ($workType === 'SOT150')
      $week['p47'] += $line["Field{$i}"] * 60;
    else if ($workType === 'SOT200')
      $week['p85'] += $line["Field{$i}"] * 60;
    else
      $dayHours[$i - 1] = ($dayHours[$i - 1] ?? 0) + (float) ($line["Field{$i}"] ?? 0);
  }

  $week['dayHours'] = $dayHours;

  if (in_array($workType, $codesOnkosten, true)) {
    $week['onkosten'] += (float) ($line['Total_Quantity'] ?? 0);
    return $week;
  }
  if (in_array($workType, $codesVerlet, true)) {
    $week['verlet'] += (float) ($line['Total_Quantity'] ?? 0);
    return $week;
  }

  if (!work_type_is_overtime($workType) && $workType !== 'KM') {
    $normalHours = (float) ($week['normalHours'] ?? 0);
    $normalDayHours = $week['normalDayHours'] ?? [0, 0, 0, 0, 0, 0, 0];
    for ($i = 1; $i <= 7; $i++) {
      $hours = (float) ($line["Field{$i}"] ?? 0);
      $normalHours += $hours;
      $normalDayHours[$i - 1] = ($normalDayHours[$i - 1] ?? 0) + $hours;
    }
    $week['normalHours'] = $normalHours;
    $week['normalDayHours'] = $normalDayHours;
  }

  $week['lines']++;

  return $week;
}
