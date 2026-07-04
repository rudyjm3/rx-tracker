<?php

declare(strict_types=1);

final class MoodChartRenderer
{
    // Canvas dimensions matching PainChartRenderer for visual consistency.
    private const WIDTH         = 500;
    private const HEIGHT        = 200;
    private const PAD_LEFT      = 32;
    private const PAD_RIGHT     = 12;
    private const PAD_TOP       = 12;
    private const PAD_BOTTOM    = 36;
    private const GRID_LEVELS   = [1, 3, 5, 7, 10];
    private const MAX_X_LABELS  = 6;

    // dompdf's bundled php-svg-lib does not support <linearGradient> fills/strokes
    // (fill="url(#...)" resolves to nothing and falls back to solid black), so the
    // area chart uses a solid color instead of the red-to-green gradient.
    private const FILL_COLOR = '#028AA9';

    // Mood scale: low mood = red/bad, high mood = green/good (inverted vs pain).
    private function colorForLevel(float $level): string
    {
        if ($level <= 3) return '#c9213c';
        if ($level <= 6) return '#e05b30';
        if ($level <= 8) return '#d97706';
        return '#2a9d49';
    }

    /**
     * Render a mood trend SVG for the given raw dose_log mood rows.
     * Multiple entries on the same date are averaged.
     *
     * @param array  $moodData   Rows with keys 'date' (Y-m-d) and 'mood_level' (int|string)
     * @param string $startDate  Y-m-d inclusive start of the chart window
     * @param string $endDate    Y-m-d inclusive end of the chart window
     * @return string Complete inline SVG string
     */
    public function renderSvg(array $moodData, string $startDate, string $endDate): string
    {
        // Group by date and average mood levels
        $byDate = [];
        foreach ($moodData as $row) {
            $date = (string) $row['date'];
            $level = (float) $row['mood_level'];
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['sum' => 0.0, 'count' => 0];
            }
            $byDate[$date]['sum']   += $level;
            $byDate[$date]['count'] += 1;
        }
        $dailyAvg = [];
        foreach ($byDate as $date => $acc) {
            $dailyAvg[$date] = $acc['sum'] / $acc['count'];
        }
        ksort($dailyAvg);

        $chartW = self::WIDTH  - self::PAD_LEFT - self::PAD_RIGHT;
        $chartH = self::HEIGHT - self::PAD_TOP  - self::PAD_BOTTOM;
        $x0     = self::PAD_LEFT;
        $y0     = self::PAD_TOP;
        $x1     = self::PAD_LEFT + $chartW;
        $y1     = self::PAD_TOP  + $chartH;

        // Y coordinate for a mood level (1-10, inverted: 1 at bottom, 10 at top)
        $yFor = static function (float $v) use ($y0, $chartH): float {
            return round($y0 + $chartH - (($v - 1) / 9.0) * $chartH, 2);
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
                '<text x="%s" y="%s" font-size="11" fill="#94a3b8" text-anchor="middle" font-family="DejaVu Sans, sans-serif">No mood data for this period</text>',
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

            // Build ordered point list
            $points = [];
            foreach ($dailyAvg as $date => $avg) {
                $points[] = ['x' => $xFor($date), 'y' => $yFor($avg)];
            }

            if (count($points) === 1) {
                // Single point — draw a short flat segment so the stroke/fill render.
                $p = $points[0];
                $curvePath = sprintf('M %s,%s L %s,%s', $p['x'], $p['y'], $p['x'], $p['y']);
                $areaPath  = sprintf(
                    'M %s,%s L %s,%s L %s,%s L %s,%s Z',
                    $p['x'], $y1, $p['x'], $p['y'], $p['x'], $p['y'], $p['x'], $y1
                );
            } else {
                [$curvePath, $lastPoint] = $this->buildSmoothPath($points);

                // Closed area path: smooth curve across the top, then straight down to
                // baseline and back to the start, for the area fill.
                $firstPoint = $points[0];
                $areaPath = $curvePath
                    . sprintf(' L %s,%s L %s,%s Z', $lastPoint['x'], $y1, $firstPoint['x'], $y1);
            }

            // Filled area under the curve
            $svg .= sprintf(
                '<path d="%s" fill="%s" fill-opacity="0.35" stroke="none"/>',
                $areaPath, self::FILL_COLOR
            );

            // Smooth curve stroke
            $svg .= sprintf(
                '<path d="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>',
                $curvePath, self::FILL_COLOR
            );

            // Data point circles
            foreach ($dailyAvg as $date => $avg) {
                $cx    = $xFor($date);
                $cy    = $yFor($avg);
                $color = $this->colorForLevel($avg);
                $svg .= sprintf(
                    '<circle cx="%s" cy="%s" r="5" fill="%s" stroke="white" stroke-width="1.5"/>',
                    $cx, $cy, $color
                );
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">%s</svg>',
            self::WIDTH, self::HEIGHT, $svg
        );
    }

    /**
     * Build a smooth cubic-Bezier path through an ordered list of points using a
     * simple Catmull-Rom-to-Bezier conversion (control points placed 1/6 of the
     * distance between neighboring points). Returns [pathString, lastPoint].
     *
     * @param array<int,array{x:float,y:float}> $points
     * @return array{0:string,1:array{x:float,y:float}}
     */
    private function buildSmoothPath(array $points): array
    {
        $n = count($points);
        $path = sprintf('M %s,%s', $points[0]['x'], $points[0]['y']);

        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $points[$i - 1] ?? $points[$i];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[$i + 2] ?? $p2;

            $cp1x = round($p1['x'] + ($p2['x'] - $p0['x']) / 6, 2);
            $cp1y = round($p1['y'] + ($p2['y'] - $p0['y']) / 6, 2);
            $cp2x = round($p2['x'] - ($p3['x'] - $p1['x']) / 6, 2);
            $cp2y = round($p2['y'] - ($p3['y'] - $p1['y']) / 6, 2);

            $path .= sprintf(
                ' C %s,%s %s,%s %s,%s',
                $cp1x, $cp1y, $cp2x, $cp2y, $p2['x'], $p2['y']
            );
        }

        return [$path, $points[$n - 1]];
    }
}
