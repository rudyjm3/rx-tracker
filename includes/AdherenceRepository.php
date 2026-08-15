<?php

declare(strict_types=1);

final class AdherenceRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function recentLogs(?string $date = null, int $limit = 12): array
    {
        $sql = 'SELECT dose_logs.id, dose_logs.medication_id, dose_logs.taken_at, dose_logs.note, dose_logs.pain_level, dose_logs.mood_level, dose_logs.status,
                       dose_logs.scheduled_for_date, dose_logs.scheduled_time,
                       medications.name, medications.dose_amount, medications.dose_unit, medications.as_needed, medications.active
                FROM dose_logs
                INNER JOIN medications ON medications.id = dose_logs.medication_id
                WHERE medications.user_id = :user_id ' . $this->profileSql('medications');
        if ($date !== null && $date !== '') {
            $sql .= ' AND dose_logs.scheduled_for_date = :scheduled_for_date';
        }
        $sql .= ' ORDER BY dose_logs.taken_at DESC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
        if ($this->profileId !== null) {
            $statement->bindValue(':profile_id', $this->profileId, PDO::PARAM_INT);
        }
        if ($date !== null && $date !== '') {
            $statement->bindValue(':scheduled_for_date', $date, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function logsForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dose_logs.id, dose_logs.taken_at, dose_logs.note, dose_logs.pain_level, dose_logs.mood_level, dose_logs.status,
                    dose_logs.scheduled_for_date, dose_logs.scheduled_time,
                    medications.name, medications.dose
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             WHERE medications.user_id = :user_id
               ' . $this->profileSql('medications') . '
               AND dose_logs.scheduled_for_date BETWEEN :start_date AND :end_date
             ORDER BY dose_logs.scheduled_for_date DESC, dose_logs.scheduled_time DESC'
        );
        $statement->execute(array_merge(['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate], $this->profileParam()));

        return $statement->fetchAll();
    }

    public function adherenceForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dl.medication_id, m.name, m.medication_type, m.dose_amount, m.dose_unit, m.dose, m.active, dl.status, COUNT(*) AS n
             FROM dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             WHERE m.user_id = :user_id
               ' . $this->profileSql('m') . '
               AND m.as_needed = 0
               AND dl.scheduled_for_date BETWEEN :start_date AND :end_date
             GROUP BY dl.medication_id, m.name, m.medication_type, m.dose_amount, m.dose_unit, m.dose, m.active, dl.status'
        );
        $statement->execute(array_merge(
            ['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate],
            $this->profileParam()
        ));
        $rows = $statement->fetchAll();

        $perMed = [];
        foreach ($rows as $row) {
            $id = (int) $row['medication_id'];
            if (!isset($perMed[$id])) {
                $perMed[$id] = [
                    'name'            => $row['name'],
                    'medication_type' => $row['medication_type'] ?? 'prescription',
                    'dose_amount'     => $row['dose_amount'] ?? null,
                    'dose_unit'       => $row['dose_unit'] ?? '',
                    'dose'            => $row['dose'] ?? '',
                    'active'          => (int) ($row['active'] ?? 1),
                    'taken'           => 0,
                    'missed'          => 0,
                    'skipped'         => 0,
                ];
            }
            $perMed[$id][(string) $row['status']] += (int) $row['n'];
        }

        $totalTaken   = 0;
        $totalMissed  = 0;
        $totalSkipped = 0;
        $perMedOut    = [];

        foreach ($perMed as $id => $data) {
            $total     = $data['taken'] + $data['missed'] + $data['skipped'];
            $pct       = $total > 0 ? (int) round(($data['taken'] / $total) * 100) : 0;
            $totalTaken   += $data['taken'];
            $totalMissed  += $data['missed'];
            $totalSkipped += $data['skipped'];
            $perMedOut[]   = [
                'id'              => $id,
                'name'            => $data['name'],
                'medication_type' => $data['medication_type'] ?? 'prescription',
                'dose_amount'     => $data['dose_amount'],
                'dose_unit'       => $data['dose_unit'],
                'dose'            => $data['dose'],
                'active'          => $data['active'],
                'taken'           => $data['taken'],
                'missed'          => $data['missed'],
                'skipped'         => $data['skipped'],
                'total'           => $total,
                'pct'             => $pct,
            ];
        }

        usort($perMedOut, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $totalScheduled  = $totalTaken + $totalMissed + $totalSkipped;
        $overallPct      = $totalScheduled > 0
            ? (int) round(($totalTaken / $totalScheduled) * 100)
            : 0;

        return [
            'overall_pct'      => $overallPct,
            'total_scheduled'  => $totalScheduled,
            'total_taken'      => $totalTaken,
            'total_missed'     => $totalMissed,
            'total_skipped'    => $totalSkipped,
            'per_medication'   => $perMedOut,
        ];
    }

    public function painLevelTrendForRange(int $medicationId, string $startDate, string $endDate): array
    {
        return $this->mergeAndSortPainRows(
            $this->doseRowsForRange('pain', $medicationId, $startDate, $endDate),
            $this->standaloneRowsForRange('pain', $medicationId, $startDate, $endDate),
            'asc'
        );
    }

    public function missedAndSkippedForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dl.medication_id, dl.scheduled_for_date, dl.scheduled_time, dl.status,
                    m.name, m.medication_type, m.dose_amount, m.dose_unit, m.dose
             FROM dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             WHERE m.user_id = :user_id
               ' . $this->profileSql('m') . '
               AND m.as_needed = 0
               AND dl.status IN (\'missed\', \'skipped\')
               AND dl.scheduled_for_date BETWEEN :start_date AND :end_date
             ORDER BY dl.scheduled_for_date DESC, dl.scheduled_time DESC'
        );
        $statement->execute(array_merge(
            ['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate],
            $this->profileParam()
        ));

        return $statement->fetchAll();
    }

    public function dailyDoseSummaryForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dl.scheduled_for_date,
                    m.name,
                    m.medication_type,
                    m.dose_amount,
                    m.dose_unit,
                    SUM(CASE WHEN dl.status = \'taken\'   THEN 1 ELSE 0 END) AS taken,
                    SUM(CASE WHEN dl.status = \'missed\'  THEN 1 ELSE 0 END) AS missed,
                    SUM(CASE WHEN dl.status = \'skipped\' THEN 1 ELSE 0 END) AS skipped
             FROM dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             WHERE m.user_id = :user_id
               ' . $this->profileSql('m') . '
               AND m.as_needed = 0
               AND dl.scheduled_for_date BETWEEN :start_date AND :end_date
             GROUP BY dl.scheduled_for_date, dl.medication_id, m.name, m.medication_type, m.dose_amount, m.dose_unit
             ORDER BY dl.scheduled_for_date DESC, m.name ASC'
        );
        $statement->execute(array_merge(
            ['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate],
            $this->profileParam()
        ));

        return $statement->fetchAll();
    }

    public function painLevelTrend(int $medicationId, int $days): array
    {
        $startDate = (new DateTimeImmutable("now -$days days"))->format('Y-m-d');
        return $this->mergeAndSortPainRows(
            $this->doseRowsForRange('pain', $medicationId, $startDate, null),
            $this->standaloneRowsForRange('pain', $medicationId, $startDate, null),
            'asc'
        );
    }

    public function painLevelTrendForDate(int $medicationId, string $date): array
    {
        return $this->mergeAndSortPainRows(
            $this->doseRowsForRange('pain', $medicationId, $date, $date),
            $this->standaloneRowsForRange('pain', $medicationId, $date, $date),
            'asc'
        );
    }

    public function moodLevelTrendForRange(int $medicationId, string $startDate, string $endDate): array
    {
        return $this->mergeAndSortMoodRows(
            $this->doseRowsForRange('mood', $medicationId, $startDate, $endDate),
            $this->standaloneRowsForRange('mood', $medicationId, $startDate, $endDate),
            'asc'
        );
    }

    public function moodLevelTrend(int $medicationId, int $days): array
    {
        $startDate = (new DateTimeImmutable("now -$days days"))->format('Y-m-d');
        return $this->mergeAndSortMoodRows(
            $this->doseRowsForRange('mood', $medicationId, $startDate, null),
            $this->standaloneRowsForRange('mood', $medicationId, $startDate, null),
            'asc'
        );
    }

    public function moodLevelTrendForDate(int $medicationId, string $date): array
    {
        return $this->mergeAndSortMoodRows(
            $this->doseRowsForRange('mood', $medicationId, $date, $date),
            $this->standaloneRowsForRange('mood', $medicationId, $date, $date),
            'asc'
        );
    }

    public function painLogHistory(int $medicationId, int $days = 365, ?string $onDate = null): array
    {
        $startDate = $onDate ?? (new DateTimeImmutable("now -$days days"))->format('Y-m-d');
        $endDate   = $onDate;
        return $this->mergeAndSortPainRows(
            $this->doseRowsForRange('pain', $medicationId, $startDate, $endDate),
            $this->standaloneRowsForRange('pain', $medicationId, $startDate, $endDate),
            'desc'
        );
    }

    public function moodLogHistory(int $medicationId, int $days = 365, ?string $onDate = null): array
    {
        $startDate = $onDate ?? (new DateTimeImmutable("now -$days days"))->format('Y-m-d');
        $endDate   = $onDate;
        return $this->mergeAndSortMoodRows(
            $this->doseRowsForRange('mood', $medicationId, $startDate, $endDate),
            $this->standaloneRowsForRange('mood', $medicationId, $startDate, $endDate),
            'desc'
        );
    }

    /**
     * Dose-log rows (always tied to a real medication) contributing pain/mood data for a
     * medication in a date range. Returns an empty array for the independent (medicationId=0)
     * context, since dose logs are never independent of a medication.
     */
    private function doseRowsForRange(string $metric, int $medicationId, string $startDate, ?string $endDate): array
    {
        if ($medicationId === 0) {
            return [];
        }
        $col     = $metric === 'pain' ? 'pain_level' : 'mood_level';
        $tagsSql = $metric === 'mood' ? ', NULL AS tags' : '';
        $params  = ['medication_id' => $medicationId, 'user_id' => $this->userId, 'start_date' => $startDate];
        if ($endDate !== null) {
            $dateSql = "dl.scheduled_for_date BETWEEN :start_date AND :end_date";
            $params['end_date'] = $endDate;
        } else {
            $dateSql = "dl.scheduled_for_date >= :start_date";
        }
        $stmt = $this->db->prepare(
            "SELECT dl.id, dl.scheduled_for_date AS date, dl.scheduled_time AS time,
                    dl.{$col}, dl.note{$tagsSql}, dl.status, dl.feedback_edited_at AS edited_at
             FROM dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             WHERE dl.medication_id = :medication_id
               AND m.user_id = :user_id
               AND dl.{$col} IS NOT NULL
               AND {$dateSql}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Standalone-log rows contributing pain/mood data for a medication (or, when
     * medicationId=0, the independent/no-medication context) in a date range.
     */
    private function standaloneRowsForRange(string $metric, int $medicationId, string $startDate, ?string $endDate): array
    {
        $col     = $metric === 'pain' ? 'pain_level' : 'mood_level';
        $tagsSql = $metric === 'mood' ? ', s.tags' : '';
        $medSql  = $medicationId === 0 ? 's.medication_id IS NULL' : 's.medication_id = :medication_id';
        $params  = ['user_id' => $this->userId, 'start_date' => $startDate];
        if ($medicationId !== 0) {
            $params['medication_id'] = $medicationId;
        }
        // Independent entries have no medication to scope them to a family profile through, so
        // scope them directly by the log's own profile_id. Medication-tied entries stay scoped
        // via medication_id alone (each medication already belongs to exactly one profile).
        $profileSql = '';
        if ($medicationId === 0) {
            $profileSql = ' ' . $this->profileSql('s');
            $params = array_merge($params, $this->profileParam());
        }
        if ($endDate !== null) {
            $dateSql = 'DATE(s.logged_at) BETWEEN :start_date AND :end_date';
            $params['end_date'] = $endDate;
        } else {
            $dateSql = 'DATE(s.logged_at) >= :start_date';
        }
        $stmt = $this->db->prepare(
            "SELECT s.id, DATE(s.logged_at) AS date, TIME(s.logged_at) AS time,
                    s.{$col}, s.note{$tagsSql}, NULL AS status, s.updated_at AS edited_at
             FROM standalone_pain_mood_logs s
             WHERE {$medSql}
               AND s.user_id = :user_id
               AND s.{$col} IS NOT NULL
               AND {$dateSql}{$profileSql}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insertStandalonePainMoodLog(
        ?int $medicationId,
        string $logType,
        ?int $painLevel,
        ?int $moodLevel,
        string $note,
        string $loggedAt = '',
        string $tags = ''
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO standalone_pain_mood_logs
                 (user_id, medication_id, profile_id, log_type, pain_level, mood_level, note, tags, logged_at)
             VALUES (:user_id, :medication_id, :profile_id, :log_type, :pain_level, :mood_level, :note, :tags, :logged_at)'
        );
        $stmt->execute([
            'user_id'       => $this->userId,
            'medication_id' => $medicationId !== null && $medicationId > 0 ? $medicationId : null,
            'profile_id'    => $this->profileId,
            'log_type'      => $logType,
            'pain_level'    => $painLevel,
            'mood_level'    => $moodLevel,
            'note'          => $note,
            'tags'          => $tags,
            'logged_at'     => $loggedAt !== '' ? $loggedAt : date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateDoseLogFeedback(int $logId, ?int $painLevel, ?int $moodLevel, string $note): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             SET dl.pain_level = COALESCE(:pain_level, dl.pain_level),
                 dl.mood_level = COALESCE(:mood_level, dl.mood_level),
                 dl.note = :note,
                 dl.feedback_edited_at = :edited_at
             WHERE dl.id = :id
               AND m.user_id = :user_id'
        );
        $stmt->execute([
            'id'         => $logId,
            'user_id'    => $this->userId,
            'pain_level' => $painLevel,
            'mood_level' => $moodLevel,
            'note'       => $note,
            'edited_at'  => date('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() > 0;
    }

    public function updateStandaloneLog(int $logId, ?int $painLevel, ?int $moodLevel, string $note): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE standalone_pain_mood_logs
             SET pain_level = COALESCE(:pain_level, pain_level),
                 mood_level = COALESCE(:mood_level, mood_level),
                 note = :note,
                 updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            'id'         => $logId,
            'user_id'    => $this->userId,
            'pain_level' => $painLevel,
            'mood_level' => $moodLevel,
            'note'       => $note,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() > 0;
    }

    private function mergeAndSortPainRows(array $doseRows, array $standaloneRows, string $dir): array
    {
        foreach ($doseRows as &$row) {
            $row['source']   = 'dose';
            $row['entry_id'] = 'dose-' . $row['id'];
            $row['logged_at'] = $row['date'] . ' ' . $row['time'];
        }
        unset($row);
        foreach ($standaloneRows as &$row) {
            $row['source']   = 'standalone';
            $row['entry_id'] = 'standalone-' . $row['id'];
            $row['logged_at'] = $row['date'] . ' ' . $row['time'];
        }
        unset($row);

        $all = array_merge($doseRows, $standaloneRows);
        if ($dir === 'desc') {
            usort($all, static fn ($a, $b) => strcmp((string) $b['logged_at'], (string) $a['logged_at']));
        } else {
            usort($all, static fn ($a, $b) => strcmp((string) $a['logged_at'], (string) $b['logged_at']));
        }
        return $all;
    }

    private function mergeAndSortMoodRows(array $doseRows, array $standaloneRows, string $dir): array
    {
        foreach ($doseRows as &$row) {
            $row['source']   = 'dose';
            $row['entry_id'] = 'dose-' . $row['id'];
            $row['logged_at'] = $row['date'] . ' ' . $row['time'];
        }
        unset($row);
        foreach ($standaloneRows as &$row) {
            $row['source']   = 'standalone';
            $row['entry_id'] = 'standalone-' . $row['id'];
            $row['logged_at'] = $row['date'] . ' ' . $row['time'];
        }
        unset($row);

        $all = array_merge($doseRows, $standaloneRows);
        if ($dir === 'desc') {
            usort($all, static fn ($a, $b) => strcmp((string) $b['logged_at'], (string) $a['logged_at']));
        } else {
            usort($all, static fn ($a, $b) => strcmp((string) $a['logged_at'], (string) $b['logged_at']));
        }
        return $all;
    }

    public function medicationTracksPain(array $medication): bool
    {
        return in_array((string) ($medication['feedback_type'] ?? 'none'), ['pain', 'both'], true);
    }

    public function medicationTracksMood(array $medication): bool
    {
        return in_array((string) ($medication['feedback_type'] ?? 'none'), ['mood', 'both'], true);
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
