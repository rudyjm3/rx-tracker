<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

final class DoctorVisitReport
{
    public function __construct(
        private readonly MedicationRepository $repository,
        private readonly SideEffectRepository $sideEffectRepo,
        private readonly PainChartRenderer    $chartRenderer,
        private readonly string               $displayName
    ) {}

    /**
     * Build and return the PDF binary string.
     *
     * @param array<int,int> $perMedChartDays  medication_id => chart window in days (0 = no chart)
     */
    public function generate(string $startDate, string $endDate, array $perMedChartDays): string
    {
        $html = $this->buildHtml($startDate, $endDate, $perMedChartDays);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function buildHtml(string $startDate, string $endDate, array $perMedChartDays): string
    {
        $periodLabel = date('M j, Y', (int) strtotime($startDate))
            . ' – '
            . date('M j, Y', (int) strtotime($endDate));
        $generatedDate = date('F j, Y');

        $medications  = $this->repository->activeMedications();
        $adherence    = $this->repository->adherenceForDateRange($startDate, $endDate);
        $dailySummary = $this->repository->dailyDoseSummaryForDateRange($startDate, $endDate);
        $sideEffects  = $this->sideEffectRepo->sideEffectsForDateRange($startDate, $endDate);

        $html  = $this->docHead($generatedDate);
        $html .= '<body>';
        $html .= $this->sectionHeader($periodLabel, $generatedDate);
        $html .= '<div style="padding:0.5in 0.65in 0.55in 0.65in;">';
        $html .= $this->sectionAdherence($adherence);
        $html .= $this->sectionMedications($medications);
        $html .= $this->sectionDoseSummary($dailySummary);
        $html .= $this->sectionPainCharts($medications, $startDate, $endDate, $perMedChartDays);
        $html .= $this->sectionSideEffects($sideEffects);
        $html .= $this->footer($generatedDate);
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }

    // -------------------------------------------------------------------------
    // Document head
    // -------------------------------------------------------------------------

    private function docHead(string $generatedDate): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Doctor Visit Report — {$generatedDate}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; color: #172033; background: #fff; }
@page { size: letter portrait; margin: 0; }

/* Tables */
table  { width: 100%; border-collapse: collapse; margin-bottom: 12pt; font-size: 9pt; }
th, td { border: 1px solid #D7E6F8; padding: 5pt 7pt; vertical-align: top; text-align: left; }
thead tr { background: #EAF4FF; }
thead th { color: #102B57; font-weight: bold; font-size: 8.5pt; }
tbody tr:nth-child(even) { background: #f8fbff; }

/* Section headings */
.section-title {
    color: #102B57;
    font-size: 12pt;
    font-weight: bold;
    border-bottom: 1.5pt solid #D7E6F8;
    padding-bottom: 4pt;
    margin-bottom: 8pt;
    margin-top: 14pt;
    page-break-after: avoid;
}
.section-block { page-break-inside: avoid; }
.page-break    { page-break-before: always; }

/* Badges */
.badge {
    display: inline-block;
    padding: 2pt 7pt;
    border-radius: 4pt;
    font-size: 8.5pt;
    font-weight: bold;
    color: #fff;
}
.badge-taken    { background: #18BFA6; }
.badge-missed   { background: #E5484D; }
.badge-skipped  { background: #F5A524; }
.badge-mild     { background: #18BFA6; }
.badge-moderate { background: #F5A524; }
.badge-severe   { background: #E5484D; }
.badge-pct-good { background: #18BFA6; }
.badge-pct-warn { background: #F5A524; }
.badge-pct-bad  { background: #E5484D; }

/* Adherence summary */
.adh-overall { font-size: 20pt; font-weight: bold; color: #102B57; }
.adh-label   { font-size: 9pt; color: #60708A; margin-top: 2pt; }
.adh-totals  { font-size: 9pt; margin-top: 6pt; }
.adh-totals span { margin-right: 14pt; }

/* Pain chart */
.chart-section   { margin-bottom: 14pt; page-break-inside: avoid; }
.chart-medname   { font-size: 10pt; font-weight: bold; color: #102B57; margin-bottom: 4pt; }
.chart-summary   { font-size: 8.5pt; color: #60708A; margin-top: 4pt; margin-bottom: 4pt; }
.chart-notes     { font-size: 8.5pt; color: #172033; margin-top: 4pt; }
.chart-note-item { margin-bottom: 2pt; }
.no-chart-note   { font-size: 8.5pt; color: #60708A; font-style: italic; margin-bottom: 10pt; }

/* Empty states */
.empty-state { color: #60708A; font-style: italic; font-size: 9pt; padding: 6pt 0; }

/* Footer */
.report-footer {
    border-top: 1pt solid #D7E6F8;
    margin-top: 20pt;
    padding-top: 8pt;
    font-size: 8pt;
    color: #60708A;
}
</style>
</head>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 1: Header band
    // -------------------------------------------------------------------------

    private function sectionHeader(string $periodLabel, string $generatedDate): string
    {
        $logo  = $this->logoDataUri();
        $name  = $this->h($this->displayName ?: 'Patient');
        $period = $this->h($periodLabel);
        $gen   = $this->h($generatedDate);

        return <<<HTML
<table style="width:100%;border-collapse:collapse;margin-bottom:0;" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:38%;background:#0d1b2e;padding:16pt 18pt;vertical-align:middle;border:none;">
      <img src="{$logo}" width="60" height="60" style="display:block;">
    </td>
    <td style="background:#0754A8;padding:16pt 18pt 16pt 20pt;vertical-align:middle;border:none;">
      <div style="font-size:17pt;font-weight:bold;color:#ffffff;line-height:1.2;">Doctor Visit Report</div>
      <div style="font-size:9pt;color:#cde8ff;margin-top:4pt;">Prepared by RxTracker &bull; Patient: {$name}</div>
      <div style="font-size:9pt;color:#cde8ff;margin-top:2pt;">Reporting period: {$period} &bull; Generated: {$gen}</div>
    </td>
  </tr>
</table>
<div style="height:3pt;background:#14CFE0;margin-bottom:0;"></div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 2: Adherence Summary
    // -------------------------------------------------------------------------

    private function sectionAdherence(array $adherence): string
    {
        $pct     = (int) ($adherence['overall_pct'] ?? 0);
        $taken   = (int) ($adherence['total_taken'] ?? 0);
        $missed  = (int) ($adherence['total_missed'] ?? 0);
        $skipped = (int) ($adherence['total_skipped'] ?? 0);
        $total   = (int) ($adherence['total_scheduled'] ?? 0);
        $perMed  = (array) ($adherence['per_medication'] ?? []);

        $rows = '';
        foreach ($perMed as $med) {
            $mp  = (int) $med['pct'];
            $mColor = $mp >= 71 ? '#18BFA6' : ($mp >= 51 ? '#EAB308' : ($mp >= 31 ? '#F5A524' : '#E5484D'));
            $miniRing = $this->adherenceRingSvg($mp, 44);
            $rows .= sprintf(
                '<tr><td>%s%s</td><td style="text-align:center;vertical-align:middle;">%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                $this->h((string) $med['name']),
                $this->medTypeBadgeHtml($med),
                $miniRing,
                (int) $med['taken'],
                (int) $med['missed'],
                (int) $med['skipped'],
                (int) $med['total']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty-state">No scheduled dose data for this period.</td></tr>';
        }

        $overallRing = $this->adherenceRingSvg($pct, 90);

        return <<<HTML
<div class="section-title">Adherence Summary</div>
<div class="section-block">
  <div class="adh-overall">{$overallRing}</div>
  <div class="adh-totals">
    <span>Scheduled: <strong>{$total}</strong></span>
    <span>Taken: <strong style="color:#18BFA6;">{$taken}</strong></span>
    <span>Missed: <strong style="color:#E5484D;">{$missed}</strong></span>
    <span>Skipped: <strong style="color:#F5A524;">{$skipped}</strong></span>
  </div>
  <table style="margin-top:10pt;">
    <thead><tr><th>Medication</th><th>Adherence</th><th>Taken</th><th>Missed</th><th>Skipped</th><th>Total Scheduled</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 3: Current Medications
    // -------------------------------------------------------------------------

    private function sectionMedications(array $medications): string
    {
        $rows = '';
        foreach ($medications as $med) {
            $schedule = $this->formatSchedule($med);
            if (!empty($med['start_date'])) {
                $startDate = $this->h(date('M j, Y', (int) strtotime((string) $med['start_date'])));
            } else {
                $fallbackTs = !empty($med['created_at']) ? strtotime((string) $med['created_at']) : time();
                $startDate  = $this->h(date('M j, Y', (int) $fallbackTs))
                    . '<div style="font-size:7pt;color:#64748b;margin-top:1pt;">(Date added to the app)</div>';
            }
            $dose = $this->h($this->formattedDose($med));
            $rows .= sprintf(
                '<tr><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->h((string) $med['name']),
                $this->medTypeBadgeHtml($med),
                $dose,
                $startDate,
                $this->h($schedule),
                $this->h((string) ($med['instructions'] ?: '—'))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty-state">No active medications.</td></tr>';
        }

        return <<<HTML
<div class="section-title">Current Medications</div>
<div class="section-block">
  <table>
    <thead><tr><th>Medication</th><th>Dose</th><th>Start Date</th><th>Schedule</th><th>Instructions</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 4: Daily Dose Summary
    // -------------------------------------------------------------------------

    private function sectionDoseSummary(array $rows): string
    {
        $tableRows = '';
        foreach ($rows as $row) {
            $date    = $this->h(date('M j, Y', (int) strtotime((string) $row['scheduled_for_date'])));
            $med     = $this->h((string) $row['name']);
            $dose    = trim((string) $row['dose_amount'] . ' ' . (string) $row['dose_unit']);
            $medCell = $med . $this->medTypeBadgeHtml($row) . ($dose !== '' ? " <span style=\"color:#60708A;\">{$this->h($dose)}</span>" : '');

            $taken   = (int) $row['taken'];
            $missed  = (int) $row['missed'];
            $skipped = (int) $row['skipped'];

            $takenCell   = $taken   > 0 ? "<strong style=\"color:#18BFA6;\">{$taken}</strong>"   : '0';
            $missedCell  = $missed  > 0 ? "<strong style=\"color:#E5484D;\">{$missed}</strong>"  : '0';
            $skippedCell = $skipped > 0 ? "<strong style=\"color:#F5A524;\">{$skipped}</strong>" : '0';

            $tableRows .= "<tr><td>{$date}</td><td>{$medCell}</td><td>{$takenCell}</td><td>{$missedCell}</td><td>{$skippedCell}</td></tr>";
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="5" class="empty-state">No scheduled dose data for this period.</td></tr>';
        }

        return <<<HTML
<div class="section-title page-break">Dose History</div>
<div class="section-block">
  <table>
    <thead><tr><th>Date</th><th>Medication</th><th>Taken</th><th>Missed</th><th>Skipped</th></tr></thead>
    <tbody>{$tableRows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 5: Pain Level Tracking
    // -------------------------------------------------------------------------

    private function sectionPainCharts(
        array $medications,
        string $startDate,
        string $endDate,
        array $perMedChartDays
    ): string {
        $trackedMeds = array_filter(
            $medications,
            static fn(array $m): bool => (bool) $m['track_dose_feedback']
        );

        if ($trackedMeds === []) {
            return '';
        }

        $html = '<div class="section-title page-break">Pain Level Tracking</div>';

        foreach ($trackedMeds as $med) {
            $medId     = (int) $med['id'];
            $medName   = $this->h((string) $med['name']);
            $daysOn    = $this->daysOnMedication($med);
            $chartDays = isset($perMedChartDays[$medId])
                ? (int) $perMedChartDays[$medId]
                : $this->defaultChartDays($daysOn);

            $html .= '<div class="chart-section">';
            $html .= "<div class=\"chart-medname\">{$medName}" . $this->medTypeBadgeHtml($med) . '</div>';

            if ($daysOn < 7 || $chartDays === 0) {
                $startedLabel = !empty($med['start_date'])
                    ? date('F j', (int) strtotime((string) $med['start_date']))
                    : 'recently';
                $html .= "<div class=\"no-chart-note\">Pain tracking started {$startedLabel} — check back after a few more days of logged doses.</div>";
            } else {
                // Determine the actual chart start date based on selected window
                $chartStart = date('Y-m-d', strtotime("-{$chartDays} days", strtotime($endDate)));
                if ($chartStart < $startDate) {
                    $chartStart = $startDate;
                }

                $rawData = $this->repository->painLevelTrendForRange($medId, $chartStart, $endDate);
                $svg     = $this->chartRenderer->renderSvg($rawData, $chartStart, $endDate);

                // Summary stats
                $avgPain   = $this->avgPain($rawData);
                $daysLogged = count(array_unique(array_column($rawData, 'date')));
                $rangeLabel = $this->h("{$chartDays}-day window: " . date('M j', strtotime($chartStart)) . ' – ' . date('M j, Y', strtotime($endDate)));

                $html .= '<div class="chart-summary">';
                $html .= $rangeLabel . ' &nbsp;|&nbsp; ';
                $html .= $daysLogged > 0
                    ? "Avg pain: <strong>{$avgPain}/10</strong> &nbsp;|&nbsp; Days logged: <strong>{$daysLogged}</strong>"
                    : 'No pain data in this range.';
                $html .= '</div>';
                $html .= $svg;

                // Patient notes from dose_logs.note in this range
                $noteRows = array_filter(
                    $rawData,
                    static fn(array $r): bool => !empty($r['note'])
                );
                if ($noteRows !== []) {
                    $html .= '<div class="chart-notes"><strong>Patient notes:</strong>';
                    foreach ($noteRows as $r) {
                        $date = date('M j', (int) strtotime((string) $r['date']));
                        $html .= sprintf(
                            '<div class="chart-note-item">%s: %s</div>',
                            $this->h($date),
                            $this->h((string) $r['note'])
                        );
                    }
                    $html .= '</div>';
                }
            }

            $html .= '</div>'; // .chart-section
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Section 6: Side Effects
    // -------------------------------------------------------------------------

    private function sectionSideEffects(array $sideEffects): string
    {
        $rows = '';
        foreach ($sideEffects as $se) {
            $sevClass = 'badge-' . (string) $se['severity'];
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td><span class="badge %s">%s</span></td><td>%s</td><td>%s</td></tr>',
                $this->h((string) $se['occurred_date']),
                $this->h((string) $se['medication_name']),
                $sevClass,
                ucfirst((string) $se['severity']),
                $this->h((string) $se['description']),
                $this->h((string) ($se['note'] ?: '—'))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty-state">No side effects logged for this period.</td></tr>';
        }

        return <<<HTML
<div class="section-title page-break">Reported Side Effects</div>
<div class="section-block">
  <table>
    <thead><tr><th>Date</th><th>Medication</th><th>Severity</th><th>Side Effect</th><th>Notes</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Footer
    // -------------------------------------------------------------------------

    private function footer(string $generatedDate): string
    {
        $gen = $this->h($generatedDate);
        return <<<HTML
<div class="report-footer">
  <table style="border:none;margin-bottom:0;" cellpadding="0" cellspacing="0">
    <tr>
      <td style="border:none;padding:0;font-size:8pt;color:#60708A;">
        RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.
        This report is for informational use only. Always consult your healthcare provider.
      </td>
      <td style="border:none;padding:0;text-align:right;white-space:nowrap;font-size:8pt;color:#60708A;width:120pt;">
        Generated {$gen}
      </td>
    </tr>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function logoDataUri(): string
    {
        $path = dirname(__DIR__) . '/assets/icons/icon-192.png';
        if (!file_exists($path)) {
            $path = dirname(__DIR__) . '/assets/icons/logo-round.png';
        }
        if (!file_exists($path)) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }

    private function adherenceRingSvg(int $pct, int $size = 90): string
    {
        $color = $pct >= 71 ? '#18BFA6' : ($pct >= 51 ? '#EAB308' : ($pct >= 31 ? '#F5A524' : '#E5484D'));
        $cx    = $size / 2;
        $cy    = $size / 2;
        $r     = round($cx - $size * 0.1, 2);
        $sw    = round($size * 0.1, 2);
        $circ  = round(2 * M_PI * $r, 2);
        $off   = round($circ * (1 - min(100, max(0, $pct)) / 100), 2);
        $fs    = max(7, (int) round($size * 0.22));

        return sprintf(
            '<svg width="%1$d" height="%1$d" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d">' .
            '<circle cx="%2$s" cy="%2$s" r="%3$s" fill="none" stroke="#e2e8f0" stroke-width="%4$s"/>' .
            '<circle cx="%2$s" cy="%2$s" r="%3$s" fill="none" stroke="%5$s" stroke-width="%4$s"' .
            ' stroke-dasharray="%6$s" stroke-dashoffset="%7$s"' .
            ' transform="rotate(-90 %2$s %2$s)" stroke-linecap="round"/>' .
            '<text x="%2$s" y="%2$s" text-anchor="middle" dy="0.35em"' .
            ' font-size="%8$d" font-weight="bold" fill="%5$s"' .
            ' font-family="DejaVu Sans, sans-serif">%9$d%%</text>' .
            '</svg>',
            $size, $cx, $r, $sw, $color, $circ, $off, $fs, $pct
        );
    }

    private function daysOnMedication(array $med): int
    {
        $startDate = !empty($med['start_date']) ? (string) $med['start_date'] : null;
        if ($startDate === null) {
            return 0;
        }
        return max(0, (int) floor((time() - strtotime($startDate)) / 86400));
    }

    private function defaultChartDays(int $daysOn): int
    {
        if ($daysOn < 7)  return 0;
        if ($daysOn < 30) return 7;
        if ($daysOn < 90) return 30;
        return 90;
    }

    private function avgPain(array $rawData): string
    {
        if ($rawData === []) {
            return '—';
        }
        $levels = array_filter(
            array_column($rawData, 'pain_level'),
            static fn ($v): bool => $v !== null && $v !== ''
        );
        if ($levels === []) {
            return '—';
        }
        return number_format(array_sum($levels) / count($levels), 1);
    }

    private function formatSchedule(array $med): string
    {
        if ((string) $med['schedule_mode'] === 'interval') {
            $hours = (string) ($med['interval_hours'] ?? '?');
            $first = !empty($med['first_dose_time']) ? $this->to12h((string) $med['first_dose_time']) : '';
            return "Every {$hours}h" . ($first !== '' ? " from {$first}" : '');
        }
        $times = $med['times'] ?? [];
        if ($times === []) {
            return 'As needed';
        }
        return implode(', ', array_map([$this, 'to12h'], $times));
    }

    private function formattedDose(array $med): string
    {
        if (!empty($med['dose_amount']) && !empty($med['dose_unit'])) {
            $amount = rtrim(rtrim(number_format((float) $med['dose_amount'], 3), '0'), '.');
            return $amount . ' ' . $med['dose_unit'];
        }
        return (string) ($med['dose'] ?: '—');
    }

    private function to12h(string $time): string
    {
        if ($time === '') {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('H:i:s', $time)
            ?: DateTimeImmutable::createFromFormat('H:i', $time);
        return $dt ? $dt->format('g:i A') : $time;
    }

    private function medTypeBadgeHtml(array $med): string
    {
        $type = (string) ($med['medication_type'] ?? 'prescription');
        $labels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement'];
        $styles = [
            'prescription' => 'background:#EAF4FF;color:#102B57;',
            'otc'          => 'background:#E6FAF7;color:#0e7a68;',
            'supplement'   => 'background:#FEF3C7;color:#8a5c00;',
        ];
        $label = $labels[$type] ?? 'Rx';
        $style = $styles[$type] ?? $styles['prescription'];
        return '<span style="' . $style . 'font-size:7pt;font-weight:bold;padding:1pt 4pt;border-radius:3pt;margin-left:4pt;">' . $label . '</span>';
    }

    /** HTML-escape a value for safe embedding in HTML output */
    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
