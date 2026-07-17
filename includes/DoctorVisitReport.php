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
        private readonly MoodChartRenderer    $moodChartRenderer,
        private readonly string               $displayName
    ) {}

    /**
     * Build and return the combined Doctor Visit Report PDF binary string.
     *
     * @param array<int,int> $perMedChartDays     medication_id => pain chart window in days (0 = no chart)
     * @param array<int,int> $perMedMoodChartDays medication_id => mood chart window in days (0 = no chart)
     */
    public function generateReport(
        string $startDate,
        string $endDate,
        array $perMedChartDays,
        bool $includeMood,
        array $perMedMoodChartDays = []
    ): string {
        $html = $this->buildHtml(
            'Doctor Visit Report',
            $startDate,
            $endDate,
            $includeMood,
            function (array $meds, string $s, string $e) use ($perMedChartDays, $includeMood, $perMedMoodChartDays): string {
                $out = $this->sectionPainCharts($meds, $s, $e, $perMedChartDays);
                if ($includeMood) {
                    $out .= $this->sectionMoodCharts($meds, $s, $e, $perMedMoodChartDays);
                }
                return $out;
            }
        );

        return $this->renderPdfFromHtml($html);
    }

    private function renderPdfFromHtml(string $html): string
    {
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

    /**
     * @param callable(array,string,string):string $chartSection Renders the report-specific chart section.
     */
    private function buildHtml(string $title, string $startDate, string $endDate, bool $includeMood, callable $chartSection): string
    {
        $periodLabel = date('M j, Y', (int) strtotime($startDate))
            . ' – '
            . date('M j, Y', (int) strtotime($endDate));
        $generatedDate = date('F j, Y');

        $medications  = $this->repository->activeMedications();
        $inactiveMeds = $this->repository->inactiveMedications();
        $adherence    = $this->repository->adherenceForDateRange($startDate, $endDate);
        $missedDoses  = $this->repository->missedAndSkippedForDateRange($startDate, $endDate);
        $sideEffects  = $this->sideEffectRepo->sideEffectsForDateRange($startDate, $endDate);
        $doseChanges  = $this->repository->doseChangesForDateRange($startDate, $endDate);

        // Most recent in-range discontinuation per adherence medication. Sourced
        // from status events for every med in the adherence data (not just
        // currently inactive meds), so meds resumed since — or re-discontinued
        // after the period — still get their in-period annotation.
        $discontinuedDates = [];
        $adherenceIds = array_map(
            static fn(array $m): int => (int) $m['id'],
            (array) ($adherence['per_medication'] ?? [])
        );
        // Dose-change history per medication, used to label historical rows with
        // the dose that was in effect at the time rather than the current dose.
        $doseHistoryIds = array_values(array_unique(array_merge(
            $adherenceIds ?? [],
            array_map(static fn(array $r): int => (int) $r['medication_id'], $missedDoses)
        )));
        $doseChangesByMed = $this->repository->doseChangesByMedicationIds($doseHistoryIds);

        if ($adherenceIds !== []) {
            foreach ($this->repository->statusEventsByMedicationIds($adherenceIds) as $medId => $events) {
                foreach ($events as $event) {
                    if ((string) $event['event'] !== 'discontinued') {
                        continue;
                    }
                    $eventDate = date('Y-m-d', (int) strtotime((string) $event['event_at']));
                    if ($eventDate >= $startDate && $eventDate <= $endDate) {
                        $discontinuedDates[(int) $medId] = (string) $event['event_at'];
                        break;
                    }
                }
            }
        }

        $html  = $this->docHead($title, $generatedDate);
        $html .= '<body>';
        $html .= $this->sectionHeader($title, $periodLabel, $generatedDate);
        $html .= '<div style="padding:0.5in 0.65in 0.55in 0.65in;">';
        $html .= $this->sectionAdherence($adherence, $medications, $includeMood, $discontinuedDates, $startDate, $endDate, $doseChangesByMed);
        $html .= $this->sectionMedications($medications, $includeMood);
        $html .= $this->sectionDiscontinuedMedications($inactiveMeds, $includeMood);
        $html .= $this->sectionDoseChanges($doseChanges);
        $html .= $this->sectionMissedDoseDetail($missedDoses, $doseChangesByMed);
        $html .= $chartSection($medications, $startDate, $endDate);
        $html .= $this->sectionSideEffects($sideEffects);
        $html .= $this->footer($generatedDate);
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }

    // -------------------------------------------------------------------------
    // Document head
    // -------------------------------------------------------------------------

    private function docHead(string $title, string $generatedDate): string
    {
        $titleEsc = $this->h($title);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$titleEsc} — {$generatedDate}</title>
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
.section-caption {
    font-size: 8.5pt;
    color: #60708A;
    margin: 0 0 8pt;
    page-break-after: avoid;
}

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
.chart-note-item { margin-bottom: 2pt; margin-left: 6pt; }
.note-date-heading { font-size: 8.5pt; font-weight: bold; color: #102B57; margin: 6pt 0 2pt; }
.no-chart-note   { font-size: 8.5pt; color: #60708A; font-style: italic; margin-bottom: 10pt; }

/* Empty states */
.empty-state { color: #60708A; font-style: italic; font-size: 9pt; padding: 6pt 0; }

/* Standalone / dose-linked / edited annotations in patient notes */
.note-standalone { color: #2563eb; font-size: 8pt; }
.note-dose       { color: #0e7a68; font-size: 8pt; }
.note-edited     { color: #60708A; font-size: 7.5pt; font-style: italic; }

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

    private function sectionHeader(string $title, string $periodLabel, string $generatedDate): string
    {
        $logo  = $this->logoDataUri();
        $name  = $this->h($this->displayName ?: 'Patient');
        $period = $this->h($periodLabel);
        $gen   = $this->h($generatedDate);
        $titleEsc = $this->h($title);

        return <<<HTML
<table style="width:100%;border-collapse:collapse;margin-bottom:0;" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:77pt;background:#0d1b2e;padding:16pt;vertical-align:middle;border:none;">
      <img src="{$logo}" width="60" height="60" style="display:block;">
    </td>
    <td style="background:#0754A8;padding:16pt 18pt 16pt 20pt;vertical-align:middle;border:none;">
      <div style="font-size:17pt;font-weight:bold;color:#ffffff;line-height:1.2;">{$titleEsc}</div>
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

    /**
     * @param array<int,string> $discontinuedDates medication_id => datetime the med was discontinued
     */
    private function sectionAdherence(
        array $adherence,
        array $medications,
        bool $includeMood,
        array $discontinuedDates = [],
        string $startDate = '',
        string $endDate = '',
        array $doseChangesByMed = []
    ): string {
        $pct     = (int) ($adherence['overall_pct'] ?? 0);
        $taken   = (int) ($adherence['total_taken'] ?? 0);
        $missed  = (int) ($adherence['total_missed'] ?? 0);
        $skipped = (int) ($adherence['total_skipped'] ?? 0);
        $total   = (int) ($adherence['total_scheduled'] ?? 0);
        $perMed  = (array) ($adherence['per_medication'] ?? []);

        $medsById = [];
        foreach ($medications as $m) {
            $medsById[(int) $m['id']] = $m;
        }

        $rows = '';
        foreach ($perMed as $med) {
            $mp      = (int) $med['pct'];
            $mColor  = $this->adherenceColor($mp);
            $trackingMed = $medsById[(int) $med['id']] ?? $med;

            $dose     = $this->effectiveDoseOnDate($med, $endDate !== '' ? $endDate : date('Y-m-d'), $doseChangesByMed[(int) $med['id']] ?? []);
            $doseHtml = $dose !== '—' ? ' &ndash; ' . $this->h($dose) : '';

            $discontinuedHtml = '';
            $discontinuedAt   = $discontinuedDates[(int) $med['id']] ?? null;
            if ($discontinuedAt !== null) {
                $discDate = date('Y-m-d', (int) strtotime($discontinuedAt));
                if (($startDate === '' || $discDate >= $startDate) && ($endDate === '' || $discDate <= $endDate)) {
                    $discontinuedHtml = '<div style="font-size:7.5pt;color:#E5484D;margin-top:1pt;">(Discontinued use on '
                        . $this->h(date('m/d/Y', (int) strtotime($discontinuedAt))) . ')</div>';
                }
            }

            $rows .= sprintf(
                '<tr><td>%s%s%s%s%s</td><td><strong style="color:%s;">%d%%</strong></td><td>%d of %d</td></tr>',
                $this->h((string) $med['name']),
                $this->medTypeBadgeHtml($med),
                $this->medTrackingBadgeHtml($trackingMed, $includeMood),
                $doseHtml,
                $discontinuedHtml,
                $mColor,
                $mp,
                (int) $med['missed'],
                (int) $med['total']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="empty-state">No scheduled dose data for this period.</td></tr>';
        }

        $donut = $this->adherenceDonutImg($pct, 72);

        return <<<HTML
<div class="section-title">Adherence Summary</div>
<div class="section-block">
  <table>
    <thead><tr><th>Overall adherence</th><th>Doses scheduled</th><th>Doses taken</th><th>Doses missed</th><th>Doses skipped</th></tr></thead>
    <tbody>
      <tr>
        <td style="text-align:center;vertical-align:middle;">{$donut}</td>
        <td style="vertical-align:middle;">{$total}</td>
        <td style="vertical-align:middle;"><strong style="color:#18BFA6;">{$taken}</strong></td>
        <td style="vertical-align:middle;"><strong style="color:#E5484D;">{$missed}</strong></td>
        <td style="vertical-align:middle;"><strong style="color:#F5A524;">{$skipped}</strong></td>
      </tr>
    </tbody>
  </table>
  <div class="section-caption">Adherence by medication is broken out below where rates differ from the overall average.</div>
  <table>
    <thead><tr><th>Medication</th><th>Adherence</th><th>Missed</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 3: Current Medications
    // -------------------------------------------------------------------------

    private function sectionMedications(array $medications, bool $includeMood): string
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
                '<tr><td>%s%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->h((string) $med['name']),
                $this->medTypeBadgeHtml($med),
                $this->medTrackingBadgeHtml($med, $includeMood),
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
    // Section 3a: Discontinued Medications
    // -------------------------------------------------------------------------

    private function sectionDiscontinuedMedications(array $inactiveMeds, bool $includeMood): string
    {
        if ($inactiveMeds === []) {
            return <<<HTML
<div class="section-title">Discontinued Medications</div>
<div class="section-block section-caption" style="margin-bottom:12pt;">No discontinued medications.</div>
HTML;
        }

        $rows = '';
        foreach ($inactiveMeds as $med) {
            $event     = is_array($med['last_discontinued'] ?? null) ? $med['last_discontinued'] : [];
            $when      = !empty($event['event_at'])
                ? $this->h(date('M j, Y', (int) strtotime((string) $event['event_at'])))
                : '—';
            $reasonRaw = (string) ($event['reason'] ?? '');
            $noteRaw   = (string) ($event['comment'] ?? '');
            $reason    = $reasonRaw !== '' ? $this->h($reasonRaw) : '—';
            $note      = $noteRaw !== '' ? $this->h($noteRaw) : '—';
            $dose      = $this->h($this->formattedDose($med));

            $rows .= sprintf(
                '<tr><td>%s%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->h((string) $med['name']),
                $this->medTypeBadgeHtml($med),
                $this->medTrackingBadgeHtml($med, $includeMood),
                $dose,
                $reason,
                $note,
                $when
            );
        }

        return <<<HTML
<div class="section-title">Discontinued Medications</div>
<div class="section-caption">Medications the patient has stopped using, with the reason and notes recorded when use was discontinued.</div>
<div class="section-block">
  <table>
    <thead><tr><th>Medication</th><th>Dose</th><th>Reason</th><th>Notes</th><th>Date Discontinued</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 3b: Dose Change History
    // -------------------------------------------------------------------------

    private function sectionDoseChanges(array $changes): string
    {
        if (empty($changes)) {
            return <<<HTML
<div class="section-title">Dose Changes</div>
<div class="section-block section-caption" style="margin-bottom:12pt;">No dose changes recorded during this period.</div>
HTML;
        }

        $rows = '';
        foreach ($changes as $c) {
            $date    = $this->h(date('M j, Y', (int) strtotime((string) $c['changed_at'])));
            $med     = $this->h((string) $c['name']);
            $fmtDose = static function (?string $amount, string $unit): string {
                if ($amount === null || $amount === '') return '—';
                return rtrim(rtrim(number_format((float) $amount, 3), '0'), '.') . ($unit !== '' ? ' ' . $unit : '');
            };
            $old     = $this->h($fmtDose($c['old_dose_amount'] ?? null, (string) ($c['old_dose_unit'] ?? '')));
            $new     = $this->h($fmtDose($c['new_dose_amount'] ?? null, (string) ($c['new_dose_unit'] ?? '')));
            $reason  = $this->h((string) ($c['comment'] ?? ''));
            $rows   .= "<tr><td>{$date}</td><td>{$med}</td><td>{$old} &rarr; <strong>{$new}</strong></td><td>{$reason}</td></tr>";
        }

        return <<<HTML
<div class="section-title">Dose Changes</div>
<div class="section-block">
  <table>
    <thead><tr><th>Date</th><th>Medication</th><th>Dose Change</th><th>Reason</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Section 4: Missed Dose Detail
    // -------------------------------------------------------------------------

    private function sectionMissedDoseDetail(array $rows, array $doseChangesByMed = []): string
    {
        $maxRows   = 25;
        $totalRows = count($rows);
        $shown     = array_slice($rows, 0, $maxRows);

        $tableRows = '';
        foreach ($shown as $row) {
            $date    = $this->h(date('M j, Y', (int) strtotime((string) $row['scheduled_for_date'])));
            $dose    = $this->effectiveDoseOnDate($row, (string) $row['scheduled_for_date'], $doseChangesByMed[(int) $row['medication_id']] ?? []);
            $medCell = $this->h((string) $row['name'])
                . ($dose !== '—' ? ' &ndash; ' . $this->h($dose) : '')
                . $this->medTypeBadgeHtml($row);
            $time    = $this->h(date('g:i A', (int) strtotime((string) $row['scheduled_time'])));
            $status  = (string) $row['status'] === 'skipped'
                ? '<strong style="color:#F5A524;">Skipped</strong>'
                : '<strong style="color:#E5484D;">Missed</strong>';

            $tableRows .= "<tr><td>{$date}</td><td>{$medCell}</td><td>{$time}</td><td>{$status}</td></tr>";
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4" class="empty-state">No missed or skipped doses in this period.</td></tr>';
        }

        $overflowNote = '';
        if ($totalRows > $maxRows) {
            $extra = $totalRows - $maxRows;
            $overflowNote = "<div class=\"section-caption\" style=\"margin-top:4pt;\">+ {$extra} additional missed/skipped doses in this period (full list available in app).</div>";
        }

        return <<<HTML
<div class="section-title">Missed Dose Detail</div>
<div class="section-caption">Showing missed and skipped doses for the reporting period, most recent first.</div>
<div>
  <table>
    <thead><tr><th>Date</th><th>Medication</th><th>Scheduled Time</th><th>Status</th></tr></thead>
    <tbody>{$tableRows}</tbody>
  </table>
  {$overflowNote}
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
            fn(array $m): bool => $this->repository->medicationTracksPain($m)
        );

        if ($trackedMeds === []) {
            return '';
        }

        $html = '<div class="section-title page-break">Pain Level Tracking</div>';
        $html .= '<div class="section-caption">Recorded after doses and as standalone logs for medications with pain tracking enabled. Chart shows daily pain level (1–10) over the reporting period. Hollow circles indicate standalone log entries.</div>';

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

                $rawData    = $this->repository->painLevelTrendForRange($medId, $chartStart, $endDate);
                $rangeLabel = $this->h("{$chartDays}-day window: " . date('M j', strtotime($chartStart)) . ' – ' . date('M j, Y', strtotime($endDate)));

                if ($rawData === []) {
                    $html .= "<div class=\"no-chart-note\">No pain data logged in this {$rangeLabel}.</div>";
                } else {
                    $svg        = $this->chartRenderer->renderSvg($rawData, $chartStart, $endDate);
                    $avgPain    = $this->avgPain($rawData);
                    $daysLogged = count(array_unique(array_column($rawData, 'date')));

                    $html .= '<div class="chart-summary">';
                    $html .= $rangeLabel . ' &nbsp;|&nbsp; ';
                    $html .= "Avg pain: <strong>{$avgPain}/10</strong> &nbsp;|&nbsp; Days logged: <strong>{$daysLogged}</strong>";
                    $html .= '</div>';
                    $html .= '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                        . '" width="500" height="200" style="display:block;">';

                    $html .= $this->renderPatientNotes($rawData, (string) $med['name']);
                }
            }

            $html .= '</div>'; // .chart-section
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Section 5b: Mood & Wellbeing Tracking
    // -------------------------------------------------------------------------

    private function sectionMoodCharts(
        array $medications,
        string $startDate,
        string $endDate,
        array $perMedMoodChartDays
    ): string {
        $trackedMeds = array_filter(
            $medications,
            fn(array $m): bool => $this->repository->medicationTracksMood($m)
        );

        if ($trackedMeds === []) {
            return '';
        }

        $html = '<div class="section-title page-break">Mood & Wellbeing Tracking</div>';
        $html .= '<div class="section-caption">Recorded after doses and as standalone logs for medications with mood tracking enabled. Chart shows daily mood level (1–10) over the reporting period. Hollow circles indicate standalone log entries.</div>';

        foreach ($trackedMeds as $med) {
            $medId     = (int) $med['id'];
            $medName   = $this->h((string) $med['name']);
            $daysOn    = $this->daysOnMedication($med);
            $chartDays = isset($perMedMoodChartDays[$medId])
                ? (int) $perMedMoodChartDays[$medId]
                : $this->defaultChartDays($daysOn);

            $html .= '<div class="chart-section">';
            $html .= "<div class=\"chart-medname\">{$medName}" . $this->medTypeBadgeHtml($med) . '</div>';

            if ($daysOn < 7 || $chartDays === 0) {
                $startedLabel = !empty($med['start_date'])
                    ? date('F j', (int) strtotime((string) $med['start_date']))
                    : 'recently';
                $html .= "<div class=\"no-chart-note\">Mood tracking started {$startedLabel} — check back after a few more days of logged doses.</div>";
            } else {
                // Determine the actual chart start date based on selected window
                $chartStart = date('Y-m-d', strtotime("-{$chartDays} days", strtotime($endDate)));
                if ($chartStart < $startDate) {
                    $chartStart = $startDate;
                }

                $rawData    = $this->repository->moodLevelTrendForRange($medId, $chartStart, $endDate);
                $rangeLabel = $this->h("{$chartDays}-day window: " . date('M j', strtotime($chartStart)) . ' – ' . date('M j, Y', strtotime($endDate)));

                if ($rawData === []) {
                    $html .= "<div class=\"no-chart-note\">No mood data logged in this {$rangeLabel}.</div>";
                } else {
                    $svg        = $this->moodChartRenderer->renderSvg($rawData, $chartStart, $endDate);
                    $avgMood    = $this->avgMood($rawData);
                    $daysLogged = count(array_unique(array_column($rawData, 'date')));

                    $html .= '<div class="chart-summary">';
                    $html .= $rangeLabel . ' &nbsp;|&nbsp; ';
                    $html .= "Avg mood: <strong>{$avgMood}/10</strong> &nbsp;|&nbsp; Days logged: <strong>{$daysLogged}</strong>";
                    $html .= '</div>';
                    $html .= '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                        . '" width="500" height="200" style="display:block;">';

                    $html .= $this->renderPatientNotes($rawData, (string) $med['name']);
                }
            }

            $html .= '</div>'; // .chart-section
        }

        return $html;
    }

    /**
     * Render patient notes grouped under a date sub-heading per day, most recent
     * day last (matches the chronological chart data). Each note shows its time,
     * whether it was logged with a scheduled dose (and which medication) or as a
     * standalone entry, and any edited-at annotation.
     *
     * @param array<int,array<string,mixed>> $rawData Chart rows for one medication (as returned
     *                                                 by painLevelTrendForRange()/moodLevelTrendForRange())
     */
    private function renderPatientNotes(array $rawData, string $medName): string
    {
        $noteRows = array_filter(
            $rawData,
            static fn(array $r): bool => !empty($r['note'])
        );
        if ($noteRows === []) {
            return '';
        }

        $byDate = [];
        foreach ($noteRows as $r) {
            $byDate[(string) $r['date']][] = $r;
        }

        $html = '<div class="chart-notes"><strong>Patient notes:</strong>';
        foreach ($byDate as $date => $rows) {
            $html .= '<div class="note-date-heading">' . $this->h(date('M j, Y', (int) strtotime($date))) . '</div>';
            foreach ($rows as $r) {
                $time      = !empty($r['time']) ? $this->to12h((string) $r['time']) : '';
                $isDose    = ($r['source'] ?? 'dose') === 'dose';
                $sourceTag = $isDose
                    ? ' <span class="note-dose">(dose &mdash; ' . $this->h($medName) . ')</span>'
                    : ' <span class="note-standalone">(standalone)</span>';
                $editedTag = !empty($r['edited_at'])
                    ? ' <span class="note-edited">[edited ' . $this->h(date('M j, Y', (int) strtotime((string) $r['edited_at']))) . ']</span>'
                    : '';
                $html .= sprintf(
                    '<div class="chart-note-item">%s%s: %s%s</div>',
                    $this->h($time),
                    $sourceTag,
                    $this->h((string) $r['note']),
                    $editedTag
                );
            }
        }
        $html .= '</div>';

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
<div class="section-caption">Patient-logged side effects for the reporting period, most recent first.</div>
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
        RxTracker is a self-tracking aid and does not provide medical advice or clinical decision support.
        This report reflects patient-logged data only and has not been independently verified.
        Always consult your healthcare provider.
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

    private function adherenceColor(int $pct): string
    {
        if ($pct >= 85) {
            return '#18BFA6';
        }
        if ($pct >= 70) {
            return '#EAB308';
        }
        if ($pct >= 50) {
            return '#F5A524';
        }
        return '#E5484D';
    }

    /**
     * Colored adherence donut with the percent in the center, embedded as a
     * base64 <img> data URI. dompdf ignores inline <svg> markup (it renders
     * only the text node), and its php-svg-lib backend does not reliably
     * support stroke-dasharray — so the progress arc is drawn as an explicit
     * arc path and the whole SVG is delivered through an <img>, the same
     * embedding already proven to work for the report charts.
     */
    private function adherenceDonutImg(int $pct, int $size = 72): string
    {
        $pct   = min(100, max(0, $pct));
        $color = $this->adherenceColor($pct);
        $cx    = $size / 2;
        $cy    = $size / 2;
        $sw    = round($size * 0.11, 2);
        $r     = round($cx - $sw / 2 - 1, 2);
        $fs    = max(7, (int) round($size * 0.21));
        $textY = round($cy + $fs * 0.35, 2);

        $track = sprintf(
            '<circle cx="%s" cy="%s" r="%s" fill="none" stroke="#e2e8f0" stroke-width="%s"/>',
            $cx, $cy, $r, $sw
        );

        if ($pct >= 100) {
            $arc = sprintf(
                '<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="%s"/>',
                $cx, $cy, $r, $color, $sw
            );
        } elseif ($pct <= 0) {
            $arc = '';
        } else {
            $startAngle = -M_PI / 2; // 12 o'clock
            $endAngle   = $startAngle + 2 * M_PI * $pct / 100;
            $x0 = round($cx + $r * cos($startAngle), 2);
            $y0 = round($cy + $r * sin($startAngle), 2);
            $x1 = round($cx + $r * cos($endAngle), 2);
            $y1 = round($cy + $r * sin($endAngle), 2);
            $largeArc = $pct > 50 ? 1 : 0;
            $arc = sprintf(
                '<path d="M %s %s A %s %s 0 %d 1 %s %s" fill="none" stroke="%s" stroke-width="%s" stroke-linecap="round"/>',
                $x0, $y0, $r, $r, $largeArc, $x1, $y1, $color, $sw
            );
        }

        $svg = sprintf(
            '<svg width="%1$d" height="%1$d" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d">%2$s%3$s' .
            '<text x="%4$s" y="%5$s" text-anchor="middle" font-size="%6$d" font-weight="bold" fill="%7$s"' .
            ' font-family="DejaVu Sans, sans-serif">%8$d%%</text></svg>',
            $size, $track, $arc, $cx, $textY, $fs, $color, $pct
        );

        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
            . '" width="' . $size . '" height="' . $size . '" style="display:inline-block;">';
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

    private function avgMood(array $rawData): string
    {
        if ($rawData === []) {
            return '—';
        }
        $levels = array_filter(
            array_column($rawData, 'mood_level'),
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

    /**
     * Dose in effect on a given date, reconstructed by rewinding the
     * medication's dose-change history (newest first) from its current dose.
     * Falls back to the freeform dose text when no structured dose exists.
     *
     * @param array<int,array<string,mixed>> $changes Dose changes for this medication, newest first
     */
    private function effectiveDoseOnDate(array $med, string $onDate, array $changes): string
    {
        $amount = $med['dose_amount'] ?? null;
        $unit   = (string) ($med['dose_unit'] ?? '');
        foreach ($changes as $c) {
            $changeDate = date('Y-m-d', (int) strtotime((string) $c['changed_at']));
            if ($changeDate > $onDate) {
                $amount = $c['old_dose_amount'];
                $unit   = (string) ($c['old_dose_unit'] ?? '');
            } else {
                break;
            }
        }
        return $this->formattedDose([
            'dose_amount' => $amount,
            'dose_unit'   => $unit,
            'dose'        => $med['dose'] ?? '',
        ]);
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

    /**
     * Small outlined badge(s) showing whether a medication tracks pain and/or mood.
     * The Mood badge is suppressed when the report excludes the mood section, so the
     * tables don't advertise mood data that isn't included in this PDF.
     */
    private function medTrackingBadgeHtml(array $med, bool $includeMood): string
    {
        $tracksPain = $this->repository->medicationTracksPain($med);
        $tracksMood = $includeMood && $this->repository->medicationTracksMood($med);

        $style = 'display:inline-block;font-size:7pt;font-weight:bold;padding:1pt 4pt;border-radius:3pt;margin-left:4pt;border:1pt solid;background:#fff;';
        $html  = '';
        if ($tracksPain) {
            $html .= '<span style="' . $style . 'color:#B45309;border-color:#F5A524;">Pain</span>';
        }
        if ($tracksMood) {
            $html .= '<span style="' . $style . 'color:#5B21B6;border-color:#8B5CF6;">Mood</span>';
        }
        return $html;
    }

    /** HTML-escape a value for safe embedding in HTML output */
    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
