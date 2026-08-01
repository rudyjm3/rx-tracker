<?php

declare(strict_types=1);

final class UserSettingsRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function getMissedGraceMinutes(): int
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1');
        $statement->execute(['user_id' => $this->userId, 'key' => 'missed_grace_minutes']);
        $value = (string) ($statement->fetchColumn() ?: '60');
        $minutes = (int) $value;

        return in_array($minutes, [30, 60], true) ? $minutes : 60;
    }

    public function getSnoozeMinutes(): int
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1');
        $statement->execute(['user_id' => $this->userId, 'key' => 'snooze_minutes']);
        $value = (string) ($statement->fetchColumn() ?: '15');
        $minutes = (int) $value;

        return in_array($minutes, [5, 10, 15, 30], true) ? $minutes : 15;
    }

    public function setSnoozeMinutes(int $minutes): void
    {
        if (!in_array($minutes, [5, 10, 15, 30], true)) {
            throw new RuntimeException('Snooze duration must be 5, 10, 15, or 30 minutes.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :value)
               ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['user_id' => $this->userId, 'key' => 'snooze_minutes', 'value' => (string) $minutes]);
            return;
        }
        $statement->execute([
            'user_id'      => $this->userId,
            'key'          => 'snooze_minutes',
            'insert_value' => (string) $minutes,
            'update_value' => (string) $minutes,
        ]);
    }

    public function setMissedGraceMinutes(int $minutes): void
    {
        if (!in_array($minutes, [30, 60], true)) {
            throw new RuntimeException('Grace period must be 30 or 60 minutes.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :value)
               ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['user_id' => $this->userId, 'key' => 'missed_grace_minutes', 'value' => (string) $minutes]);
            return;
        }
        $statement->execute([
            'user_id'      => $this->userId,
            'key'          => 'missed_grace_minutes',
            'insert_value' => (string) $minutes,
            'update_value' => (string) $minutes,
        ]);
    }

    public function getUserTimezone(): string
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1');
        $statement->execute(['user_id' => $this->userId, 'key' => 'timezone']);
        $value = (string) ($statement->fetchColumn() ?: '');
        return ($value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true)) ? $value : '';
    }

    public function setUserTimezone(string $timezone): void
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Invalid timezone.');
        }
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :value)
               ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['user_id' => $this->userId, 'key' => 'timezone', 'value' => $timezone]);
            return;
        }
        $statement->execute([
            'user_id'      => $this->userId,
            'key'          => 'timezone',
            'insert_value' => $timezone,
            'update_value' => $timezone,
        ]);
    }

    public function getMoodChartScheme(): string
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1');
        $statement->execute(['user_id' => $this->userId, 'key' => 'mood_chart_scheme']);
        $value = (string) ($statement->fetchColumn() ?: 'classic');

        return in_array($value, ['classic', 'teal'], true) ? $value : 'classic';
    }

    public function setMoodChartScheme(string $scheme): void
    {
        if (!in_array($scheme, ['classic', 'teal'], true)) {
            throw new RuntimeException('Mood chart scheme must be classic or teal.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :value)
               ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (user_id, setting_key, setting_value)
               VALUES (:user_id, :key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['user_id' => $this->userId, 'key' => 'mood_chart_scheme', 'value' => $scheme]);
            return;
        }
        $statement->execute([
            'user_id'      => $this->userId,
            'key'          => 'mood_chart_scheme',
            'insert_value' => $scheme,
            'update_value' => $scheme,
        ]);
    }
}
