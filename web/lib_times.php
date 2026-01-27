<?php

function hours_to_minutes(float $h): int
{
  return (int) round($h * 60);
}

function minutes_to_hhmm(int $min): string
{
  $sign = $min < 0 ? "-" : "";
  $min = abs($min);
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
function split_premiums_for_day(float $hours, string $dateYmd, string $workType): array
{
  $minutes = hours_to_minutes($hours);
  if ($minutes <= 0)
    return ['p285' => 0, 'p47' => 0, 'p85' => 0];

  $dow = (int) (new DateTimeImmutable($dateYmd))->format('N'); // 1=Mon..7=Sun

  $year = substr($dateYmd, 0, 4);
  $holidays = holiday_set($year);

  $isHoliday = isset($holidays[$dateYmd]);

  // Feestdag/zondag: alles 85
  if ($isHoliday || $dow === 7 || $workType === "SOT200") {
    return ['p285' => 0, 'p47' => 0, 'p85' => $minutes];
  }

  // Zaterdag: alles 47
  if ($dow === 6 || $workType === "SOT150") {
    return ['p285' => 0, 'p47' => $minutes, 'p85' => 0];
  }

  if($workType === "SOT125")
  {
    return ['p285' => $minutes, 'p47' => 0, 'p85' => 0];
  }

  // Ma–Vr
  $base = hours_to_minutes(8);
  $ten = hours_to_minutes(10);

  $over = max(0, $minutes - $base);
  $p285 = min($over, hours_to_minutes(2));            // max 2 uur
  $p47 = max(0, $minutes - $ten);                    // alles boven 10 uur

  return ['p285' => $p285, 'p47' => $p47, 'p85' => 0];
}
