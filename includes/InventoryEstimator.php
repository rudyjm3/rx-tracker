<?php

declare(strict_types=1);

final class InventoryEstimator
{
    /**
     * Estimate the current pill count from fill information and schedule.
     *
     * Returns a structured breakdown so the UI can show the math transparently
     * and label the result as estimated (never exact).
     *
     * @param string      $startedUsingAt   ISO date/datetime when user started using this fill
     * @param string      $asOfDatetime     The datetime "now" that the estimate is accurate through
     * @param float       $quantityDispensed Quantity in the fill
     * @param float       $carryover        Pills remaining from the previous bottle
     * @param string      $scheduleMode     'fixed_times' or 'interval'
     * @param array       $times            Array of HH:MM schedule times (fixed_times mode)
     * @param array       $timeDoses        Map of HH:MM => float per-slot quantity overrides
     * @param float       $defaultQtyPerDose Default quantity per dose
     * @param int|null    $intervalHours    Hours between doses (interval mode)
     * @param bool        $asNeeded         PRN medications cannot be estimated precisely
     * @param bool        $allDosesTaken    User confirms all scheduled doses were taken
     */
    public static function estimate(
        string $startedUsingAt,
        string $asOfDatetime,
        float $quantityDispensed,
        float $carryover,
        string $scheduleMode,
        array $times,
        array $timeDoses,
        float $defaultQtyPerDose,
        ?int $intervalHours,
        bool $asNeeded,
        bool $allDosesTaken = true
    ): array {
        $warnings = [];

        if ($asNeeded) {
            return [
                'dispensed'             => $quantityDispensed,
                'carryover'             => $carryover,
                'scheduled_consumption' => 0.0,
                'estimated_remaining'   => $quantityDispensed + $carryover,
                'confidence'            => 'low',
                'warnings'              => ['As-needed medications cannot be estimated from schedule alone. Please count your current supply.'],
            ];
        }

        if ($scheduleMode === 'interval' && $intervalHours !== null && $intervalHours > 0) {
            $warnings[] = 'Interval-based schedules produce approximate estimates.';
        }

        $startDt   = new DateTimeImmutable($startedUsingAt);
        $asOfDt    = new DateTimeImmutable($asOfDatetime);
        $consumption = 0.0;

        if ($scheduleMode === 'fixed_times' && count($times) > 0) {
            // Walk day by day from start through yesterday
            $dayCursor = $startDt->setTime(0, 0, 0);
            $yesterday = $asOfDt->modify('-1 day')->setTime(23, 59, 59);

            while ($dayCursor <= $yesterday) {
                foreach ($times as $t) {
                    [$h, $m] = array_map('intval', explode(':', $t));
                    $slotDt = $dayCursor->setTime($h, $m, 0);
                    // Only count slots on or after the start datetime
                    if ($slotDt >= $startDt) {
                        $slotQty = isset($timeDoses[$t]) && $timeDoses[$t] !== null
                            ? (float) $timeDoses[$t]
                            : $defaultQtyPerDose;
                        $consumption += max(0.001, $slotQty);
                    }
                }
                $dayCursor = $dayCursor->modify('+1 day');
            }

            // Today: count slots that have passed up to asOf
            $today = $asOfDt->setTime(0, 0, 0);
            foreach ($times as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slotDt = $today->setTime($h, $m, 0);
                if ($slotDt >= $startDt && $slotDt <= $asOfDt) {
                    $slotQty = isset($timeDoses[$t]) && $timeDoses[$t] !== null
                        ? (float) $timeDoses[$t]
                        : $defaultQtyPerDose;
                    $consumption += max(0.001, $slotQty);
                }
            }
        } elseif ($scheduleMode === 'interval' && $intervalHours !== null && $intervalHours > 0) {
            $cursor = $startDt;
            while ($cursor <= $asOfDt) {
                $consumption += $defaultQtyPerDose;
                $cursor = $cursor->modify("+{$intervalHours} hours");
            }
        }

        if (!$allDosesTaken) {
            $warnings[] = 'Estimate assumes all scheduled doses were taken. Actual count may be higher if any doses were missed.';
        }

        $estimated = $quantityDispensed + $carryover - ($allDosesTaken ? $consumption : 0.0);
        $estimated = max(0.0, round($estimated, 3));

        $confidence = empty($warnings) ? 'high' : 'low';

        return [
            'dispensed'             => $quantityDispensed,
            'carryover'             => $carryover,
            'scheduled_consumption' => round($consumption, 3),
            'estimated_remaining'   => $estimated,
            'confidence'            => $confidence,
            'warnings'              => $warnings,
        ];
    }
}
