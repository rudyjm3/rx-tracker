<?php

declare(strict_types=1);

final class PainChartRenderer
{
    // Canvas dimensions matching the in-app chart (app.js renderPainChart)
    private const WIDTH         = 500;
    private const HEIGHT        = 200;
    private const PAD_LEFT      = 44;
    private const PAD_RIGHT     = 12;
    private const PAD_TOP       = 12;
    private const PAD_BOTTOM    = 36;
    private const GRID_LEVELS   = [1, 3, 5, 7, 10];
    private const MAX_X_LABELS  = 6;

    // Mirror painLevelColor() from app.js
    private function colorForLevel(float $level): string
    {
        $level = round($level); // app.js colors by Math.round(average)
        if ($level <= 3) return '#2a9d49';
        if ($level <= 6) return '#d97706';
        if ($level <= 8) return '#e05b30';
        return '#c9213c';
    }

    /**
     * Render a pain trend SVG for the given raw dose_log pain rows.
     * Multiple entries on the same date are averaged.
     *
     * @param array  $painData   Rows with keys 'date' (Y-m-d) and 'pain_level' (int|string)
     * @param string $startDate  Y-m-d inclusive start of the chart window
     * @param string $endDate    Y-m-d inclusive end of the chart window
     * @return string Complete inline SVG string
     */
    public function renderSvg(array $painData, string $startDate, string $endDate): string
    {
        // Group by date: average pain levels, track whether any entry is dose-linked
        $byDate = [];
        foreach ($painData as $row) {
            $date = (string) $row['date'];
            $level = (float) $row['pain_level'];
            $isDose = ($row['source'] ?? 'dose') === 'dose';
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['sum' => 0.0, 'count' => 0, 'hasDose' => false];
            }
            $byDate[$date]['sum']   += $level;
            $byDate[$date]['count'] += 1;
            if ($isDose) {
                $byDate[$date]['hasDose'] = true;
            }
        }
        $dailyAvg = [];
        $dailyHasDose = [];
        foreach ($byDate as $date => $acc) {
            $dailyAvg[$date]    = $acc['sum'] / $acc['count'];
            $dailyHasDose[$date] = $acc['hasDose'];
        }
        ksort($dailyAvg);
        ksort($dailyHasDose);

        $chartW = self::WIDTH  - self::PAD_LEFT - self::PAD_RIGHT;
        $chartH = self::HEIGHT - self::PAD_TOP  - self::PAD_BOTTOM;
        $x0     = self::PAD_LEFT;
        $y0     = self::PAD_TOP;
        $x1     = self::PAD_LEFT + $chartW;
        $y1     = self::PAD_TOP  + $chartH;

        // Y coordinate for a pain level (1-10, inverted: 1 at bottom, 10 at top)
        $yFor = static function (float $v) use ($y0, $chartH): float {
            return round($y1 = $y0 + $chartH - (($v - 1) / 9.0) * $chartH, 2);
        };

        $startTs = strtotime($startDate);
        $endTs   = strtotime($endDate);
        $spanDays = max(1, (int) round(($endTs - $startTs) / 86400));

        // X coordinate for a date string
        $xFor = static function (string $date) use ($startTs, $spanDays, $x0, $chartW): float {
            $ts   = strtotime($date);
            $days = ($ts - $startTs) / 86400;
            return round($x0 + ($days / $spanDays) * $chartW, 2);
        };

        $svg = '';

        // Background
        $svg .= sprintf(
            '<rect x="0" y="0" width="%d" height="%d" fill="#f8fafc" stroke="none"/>',
            self::WIDTH, self::HEIGHT
        );

        // Gridlines and Y-axis labels
        foreach (self::GRID_LEVELS as $level) {
            $y = $yFor((float) $level);
            $svg .= sprintf(
                '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#e2e8f0" stroke-width="1"/>',
                $x0, $y, $x1, $y
            );
            $svg .= sprintf(
                '<text x="%s" y="%s" font-size="9" fill="#94a3b8" text-anchor="end" dy="0.35em" font-family="DejaVu Sans, sans-serif">%d</text>',
                $x0 - 4, $y, $level
            );
        }

        // Y-axis title, rotated to run up the left edge (matches the mood chart)
        $axisTitleCY = round($y0 + $chartH / 2, 2);
        $svg .= sprintf(
            '<text x="11" y="%s" font-size="9" fill="#94a3b8" text-anchor="middle" font-family="DejaVu Sans, sans-serif" transform="rotate(-90 11 %s)">Pain lvl score</text>',
            $axisTitleCY, $axisTitleCY
        );

        // Axis lines
        $svg .= sprintf(
            '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#cbd5e1" stroke-width="1"/>',
            $x0, $y0, $x0, $y1
        );
        $svg .= sprintf(
            '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#cbd5e1" stroke-width="1"/>',
            $x0, $y1, $x1, $y1
        );

        if (count($dailyAvg) < 1) {
            // No data — show placeholder text
            $cx = self::WIDTH / 2;
            $cy = self::HEIGHT / 2;
            $svg .= sprintf(
                '<text x="%s" y="%s" font-size="11" fill="#94a3b8" text-anchor="middle" font-family="DejaVu Sans, sans-serif">No pain data for this period</text>',
                $cx, $cy
            );
        } else {
            // X-axis date labels (up to MAX_X_LABELS evenly spaced)
            $dates = array_keys($dailyAvg);
            $n = count($dates);
            $step = max(1, (int) ceil($n / self::MAX_X_LABELS));
            for ($i = 0; $i < $n; $i += $step) {
                $date = $dates[$i];
                $x    = $xFor($date);
                $label = date('m-d', (int) strtotime($date));
                $svg .= sprintf(
                    '<text x="%s" y="%s" font-size="9" fill="#94a3b8" text-anchor="middle" font-family="DejaVu Sans, sans-serif">%s</text>',
                    $x, $y1 + 14, htmlspecialchars($label, ENT_XML1)
                );
            }

            // Connecting polyline
            $points = [];
            foreach ($dailyAvg as $date => $avg) {
                $points[] = $xFor($date) . ',' . $yFor($avg);
            }
            $svg .= sprintf(
                '<polyline points="%s" fill="none" stroke="#6b7a96" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>',
                implode(' ', $points)
            );

            // Data point circles — hollow when day contains only standalone logs
            foreach ($dailyAvg as $date => $avg) {
                $cx      = $xFor($date);
                $cy      = $yFor($avg);
                $color   = $this->colorForLevel($avg);
                $hasDose = $dailyHasDose[$date] ?? true;
                $fill    = $hasDose ? $color   : '#f8fafc';
                $stroke  = $hasDose ? '#ffffff' : $color;
                $sw      = $hasDose ? '1.5'     : '2.5';
                $svg .= sprintf(
                    '<circle cx="%s" cy="%s" r="5" fill="%s" stroke="%s" stroke-width="%s"/>',
                    $cx, $cy, $fill, $stroke, $sw
                );
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">%s</svg>',
            self::WIDTH, self::HEIGHT, $svg
        );
    }
}
