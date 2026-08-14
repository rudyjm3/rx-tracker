<?php

declare(strict_types=1);

final class InventoryRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId,
        private readonly ?int $profileId,
        private readonly StockNotificationRepository $stockNotificationRepo
    ) {
    }

    public function logRefill(int $medicationId, string $refillDate, float $amount, string $note): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Refill amount must be greater than 0.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT current_quantity FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1');
            $stmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Medication not found.');
            }
            $newCount = (float) $current + $amount;

            $update = $this->db->prepare(
                'UPDATE medications SET current_quantity = :current_quantity, starting_quantity = :starting_quantity WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            );
            $update->execute(array_merge([
                'current_quantity' => $newCount,
                'starting_quantity' => $amount,
                'id' => $medicationId,
                'user_id' => $this->userId,
            ], $this->profileParam()));

            $insert = $this->db->prepare(
                "INSERT INTO medication_refills (medication_id, refill_date, amount, pills_on_hand, note, entry_type)
                 VALUES (:medication_id, :refill_date, :amount, :pills_on_hand, :note, 'refill')"
            );
            $insert->execute([
                'medication_id' => $medicationId,
                'refill_date' => $refillDate,
                'amount' => $amount,
                'pills_on_hand' => $newCount,
                'note' => $note,
            ]);

            $this->stockNotificationRepo->clearStockNotificationsForMedication($medicationId);

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function adjustQuantity(int $medicationId, float $newCount, string $note = ''): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT current_quantity, low_supply_threshold FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1');
            $stmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Medication not found.');
            }
            $current = (float) ($row['current_quantity'] ?? 0);
            $delta = $newCount - $current;

            if (abs($delta) < 0.0005) {
                $this->db->commit();
                return;
            }

            $update = $this->db->prepare(
                'UPDATE medications SET current_quantity = :current_quantity WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            );
            $update->execute(array_merge([
                'current_quantity' => $newCount,
                'id' => $medicationId,
                'user_id' => $this->userId,
            ], $this->profileParam()));

            $insert = $this->db->prepare(
                "INSERT INTO medication_refills (medication_id, refill_date, amount, pills_on_hand, note, entry_type)
                 VALUES (:medication_id, :refill_date, :amount, :pills_on_hand, :note, 'adjustment')"
            );
            $insert->execute([
                'medication_id' => $medicationId,
                'refill_date' => date('Y-m-d'),
                'amount' => $delta,
                'pills_on_hand' => $newCount,
                'note' => $note,
            ]);

            if ($newCount > (float) ($row['low_supply_threshold'] ?? 0)) {
                $this->stockNotificationRepo->clearStockNotificationsForMedication($medicationId);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function lastRefillForMedication(int $medicationId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, refill_date, amount, pills_on_hand, note
             FROM medication_refills
             WHERE medication_id = :medication_id AND entry_type = 'refill'
             ORDER BY refill_date DESC, id DESC
             LIMIT 1"
        );
        $statement->execute(['medication_id' => $medicationId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function refillsForMonth(int $medicationId, string $monthStart, string $monthEnd): array
    {
        $statement = $this->db->prepare(
            "SELECT r1.id, r1.refill_date, r1.amount, r1.pills_on_hand, r1.note, r1.entry_type,
                    (SELECT r2.refill_date FROM medication_refills r2
                     WHERE r2.medication_id = r1.medication_id AND r2.refill_date < r1.refill_date
                       AND r2.entry_type = 'refill'
                     ORDER BY r2.refill_date DESC LIMIT 1) AS prev_refill_date
             FROM medication_refills r1
             WHERE r1.medication_id = :medication_id
               AND r1.refill_date BETWEEN :month_start AND :month_end
             ORDER BY r1.refill_date DESC, r1.id DESC"
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ]);
        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            // "Days since prev" is only meaningful between pharmacy refills.
            if ($row['prev_refill_date'] !== null && (string) $row['entry_type'] === 'refill') {
                $prev = new DateTimeImmutable((string) $row['prev_refill_date']);
                $curr = new DateTimeImmutable((string) $row['refill_date']);
                $row['days_since_prev'] = (int) $prev->diff($curr)->days;
            } else {
                $row['days_since_prev'] = null;
            }
            unset($row['prev_refill_date']);
        }

        return $rows;
    }

    public function refillSummaryStats(int $medicationId, int $year): array
    {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);

        $stmt = $this->db->prepare(
            "SELECT refill_date
             FROM medication_refills
             WHERE medication_id = :medication_id AND entry_type = 'refill'
               AND refill_date BETWEEN :year_start AND :year_end
             ORDER BY refill_date ASC"
        );
        $stmt->execute([
            'medication_id' => $medicationId,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
        ]);
        $rows = $stmt->fetchAll();
        $count = count($rows);

        $avgDays = null;
        if ($count >= 2) {
            $dates = array_map(static fn(array $r): DateTimeImmutable => new DateTimeImmutable((string) $r['refill_date']), $rows);
            $totalDays = 0;
            for ($i = 1; $i < count($dates); $i++) {
                $totalDays += (int) $dates[$i - 1]->diff($dates[$i])->days;
            }
            $avgDays = (int) round($totalDays / ($count - 1));
        }

        return [
            'count' => $count,
            'avg_days' => $avgDays,
            'year' => $year,
        ];
    }

    public function deductInventory(int $medicationId, ?float $quantityOverride = null): float
    {
        $stmt = $this->db->prepare(
            'SELECT quantity_per_dose FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $stmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return 0.0;
        }

        $dose = max(0.0, $quantityOverride ?? (float) ($row['quantity_per_dose'] ?? 1));

        // Atomic (SQL-side) arithmetic, matching restoreInventory() below — avoids a
        // read-modify-write race between two concurrent deductions on the same medication.
        $this->db->prepare(
            'UPDATE medications SET current_quantity = COALESCE(current_quantity, 0) - :dose WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        )->execute(array_merge([
            'dose' => $dose,
            'id' => $medicationId,
            'user_id' => $this->userId,
        ], $this->profileParam()));

        return $dose;
    }

    /**
     * Reconstructs a running balance as a single chronological ledger — an
     * opening balance, every refill/adjustment delta, and every taken dose's
     * deduction, all ordered by the date each actually applies (not
     * insertion order) — and returns the date of the most recent transition
     * into a non-positive balance that is still in effect now. Recomputed
     * fresh on every call so it stays correct after later refills/
     * adjustments, including backdated ones: a refill dated earlier than
     * doses already logged against a negative count simply slots into the
     * ledger at its own date and everything after it re-nets out. Same-day
     * ties resolve refills/adjustments before doses, treating a same-day
     * refill as having happened before that day's doses were taken.
     *
     * The opening balance can't just be medications.starting_quantity: every
     * logRefill() call overwrites that column with the refill amount, so
     * once a medication has ever been refilled it no longer reflects the
     * original starting count. Instead it's derived backward from the live,
     * trusted current_quantity: opening balance = current_quantity minus the
     * sum of every recorded refill/adjustment/dose delta since. That sum is
     * built entirely from write-once data (medication_refills.amount,
     * dose_logs.deducted_quantity), so it stays correct regardless of
     * whether the earliest such event happened before or after other
     * doses/refills in the ledger.
     */
    public function dateInventoryCrossedZero(int $medicationId): ?string
    {
        $medStmt = $this->db->prepare(
            'SELECT current_quantity, start_date, created_at
             FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1'
        );
        $medStmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        $med = $medStmt->fetch();
        if (!is_array($med)) {
            return null;
        }

        $events = [];

        $refillStmt = $this->db->prepare(
            'SELECT refill_date, amount FROM medication_refills WHERE medication_id = :medication_id'
        );
        $refillStmt->execute(['medication_id' => $medicationId]);
        foreach ($refillStmt->fetchAll() as $refill) {
            $events[] = ['date' => (string) $refill['refill_date'], 'delta' => (float) $refill['amount'], 'seq' => 1];
        }

        $doseStmt = $this->db->prepare(
            "SELECT scheduled_for_date, deducted_quantity FROM dose_logs
             WHERE medication_id = :medication_id AND status = 'taken'"
        );
        $doseStmt->execute(['medication_id' => $medicationId]);
        $fallbackQpd = null;
        foreach ($doseStmt->fetchAll() as $dose) {
            if ($dose['deducted_quantity'] !== null) {
                $amount = (float) $dose['deducted_quantity'];
            } else {
                if ($fallbackQpd === null) {
                    $qpdStmt = $this->db->prepare(
                        'SELECT quantity_per_dose FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
                    );
                    $qpdStmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
                    $fallbackQpd = max(0.001, (float) ($qpdStmt->fetchColumn() ?: 1));
                }
                $amount = $fallbackQpd;
            }
            $events[] = ['date' => (string) $dose['scheduled_for_date'], 'delta' => -$amount, 'seq' => 2];
        }

        $recordedDeltaSum = array_sum(array_column($events, 'delta'));
        $events[] = [
            'date' => (string) ($med['start_date'] ?: substr((string) $med['created_at'], 0, 10)),
            'delta' => (float) ($med['current_quantity'] ?? 0) - $recordedDeltaSum,
            'seq' => 0,
        ];

        usort($events, static function (array $a, array $b): int {
            return $a['date'] <=> $b['date'] ?: $a['seq'] <=> $b['seq'];
        });

        // Track the most recent crossing into a non-positive balance that
        // hasn't since been resolved by a refill/adjustment — not just the
        // first time it ever happened, which could be a long-since-resolved
        // dip (e.g. an initial zero balance before the first refill ever
        // arrived).
        $balance = 0.0;
        $inDeficit = false;
        $lastCrossingDate = null;
        foreach ($events as $event) {
            $balance += $event['delta'];
            if ($balance <= 0) {
                if (!$inDeficit) {
                    $lastCrossingDate = $event['date'];
                    $inDeficit = true;
                }
            } else {
                $inDeficit = false;
            }
        }

        return $lastCrossingDate;
    }

    public function restoreInventory(int $medicationId, ?float $quantityOverride = null): void
    {
        if ($quantityOverride !== null) {
            $this->db->prepare(
                'UPDATE medications
                 SET current_quantity = COALESCE(current_quantity, 0) + :qty
                 WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            )->execute(array_merge(['qty' => $quantityOverride, 'id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        } else {
            $this->db->prepare(
                'UPDATE medications
                 SET current_quantity = COALESCE(current_quantity, 0) + quantity_per_dose
                 WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            )->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        }
    }

    public function lastRefillsByMedicationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT id, medication_id, refill_date, amount, pills_on_hand, note
             FROM (
                 SELECT id, medication_id, refill_date, amount, pills_on_hand, note,
                        ROW_NUMBER() OVER (PARTITION BY medication_id ORDER BY refill_date DESC, id DESC) AS rn
                 FROM medication_refills
                 WHERE medication_id IN ({$placeholders}) AND entry_type = 'refill'
             ) ranked
             WHERE rn = 1"
        );
        $statement->execute(array_values($ids));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['medication_id']] = $row;
        }
        return $result;
    }

    private function profileSql(string $alias = 'm'): string
    {
        $col = $alias !== '' ? "{$alias}.profile_id" : 'profile_id';
        return $this->profileId === null
            ? "AND {$col} IS NULL"
            : "AND {$col} = :profile_id";
    }

    private function profileParam(): array
    {
        return $this->profileId !== null ? ['profile_id' => $this->profileId] : [];
    }
}
