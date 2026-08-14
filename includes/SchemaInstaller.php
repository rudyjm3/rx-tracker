<?php

declare(strict_types=1);

final class SchemaInstaller
{

    private const CURRENT_SCHEMA_VERSION = 8;

    private static array $schemaSweepDone = [];

    private bool $schemaSweepFailed = false;

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0
    ) {
    }

    public function install(): void
    {
        $this->ensureSchemaSweep();
        $this->seedMoodTagsForUser();
        $this->backfillMedicationNotesForUser();
        $this->backfillGroupScheduleTimesForUser();
    }

    // Runs just the version-gated schema sweep, without the per-user seed/backfill
    // steps `install()` also does. Callable with no real user (e.g. userId 0) very
    // early in boot — before login is checked — so tables/columns added by a new
    // deploy exist before any code (including the login gate) queries them.
    public function ensureSchemaUpToDate(): void
    {
        $this->ensureSchemaSweep();
    }

    // Runs the full 33-method schema-migration sweep at most once per database: cached in-process
    // via a static keyed on the PDO connection, and across processes via the `schema_state` table.
    private function ensureSchemaSweep(): void
    {
        $connKey = spl_object_id($this->db);
        if (isset(self::$schemaSweepDone[$connKey])) {
            return;
        }

        $version = 0;
        try {
            $stmt = $this->db->query('SELECT schema_version FROM schema_state WHERE id = 1');
            if ($stmt !== false) {
                $version = (int) $stmt->fetchColumn();
            }
        } catch (Throwable) {
            // schema_state doesn't exist yet: fresh DB or a pre-upgrade production DB.
            $version = 0;
        }

        if ($version < self::CURRENT_SCHEMA_VERSION) {
            $this->schemaSweepFailed = false;
            $this->runFullSchemaSweep();
            // Only record success if every step in the sweep completed without an individual
            // ensure*() catching a Throwable — otherwise the sweep is silently incomplete (e.g. a
            // required column never got added) and must be retried on the next construction
            // rather than permanently marked as migrated.
            if (!$this->schemaSweepFailed) {
                $this->recordSchemaVersion(self::CURRENT_SCHEMA_VERSION);
            }
        }

        self::$schemaSweepDone[$connKey] = true;
    }

    private function ensureSchemaStateTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS schema_state (
                        id             INT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                        schema_version INT UNSIGNED NOT NULL DEFAULT 0,
                        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB'
                );
            } elseif ($driver === 'sqlite') {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS schema_state (
                        id             INTEGER PRIMARY KEY,
                        schema_version INTEGER NOT NULL DEFAULT 0,
                        updated_at     TEXT DEFAULT CURRENT_TIMESTAMP
                    )'
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if this fails; ensureSchemaSweep() will simply re-run the
            // full sweep on every subsequent construction until the table can be created.
        }
    }

    private function recordSchemaVersion(int $version): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->prepare(
                    'INSERT INTO schema_state (id, schema_version) VALUES (1, :v)
                     ON DUPLICATE KEY UPDATE schema_version = :v2'
                )->execute(['v' => $version, 'v2' => $version]);
            } else {
                $this->db->prepare(
                    'INSERT INTO schema_state (id, schema_version) VALUES (1, :v)
                     ON CONFLICT(id) DO UPDATE SET schema_version = :v2'
                )->execute(['v' => $version, 'v2' => $version]);
            }
        } catch (Throwable) {
            // Non-fatal — see ensureSchemaSweep()'s fail-safe: version stays unrecorded, so the
            // full sweep (idempotent) simply runs again next construction.
        }
    }

    private function runFullSchemaSweep(): void
    {
        $this->ensureSchemaStateTable();
        $this->ensureStartingPillCountColumn();
        $this->ensureTimeFormatColumn();
        $this->ensureSupportTables();
        $this->ensureAppSettingsPerUser();
        $this->ensureTrackDoseFeedbackColumn();
        $this->ensurePainLevelColumn();
        $this->ensureDeductedQuantityColumn();
        $this->ensureFeedbackTypeColumn();
        $this->ensureMoodLevelColumn();
        $this->ensureInstructionsWidened();
        $this->ensureSetIdColumn();
        $this->ensureGroupTables();
        $this->ensureGroupMembersUpgrade();
        $this->ensureRefillsTable();
        $this->ensureStatusEventsTable();
        $this->ensureDoseChangesTable();
        $this->ensurePushActionNonceColumn();
        $this->ensurePushDeliveryPostponedColumn();
        $this->ensureMedicationTypeColumn();
        $this->ensureDoseStructuredColumns();
        $this->ensureInventoryColumns();
        $this->ensureScheduleTimeDoseColumn();
        $this->ensureScheduleTimeGroupColumn();
        $this->ensureSortOrderColumns();
        $this->ensureMedicationUserIndex();
        $this->ensureUserNotificationsTable();
        $this->ensureFamilyProfilesTable();
        $this->ensureStartDateColumn();
        $this->ensureStandalonePainMoodLogsTable();
        $this->ensureFeedbackEditedAtColumn();
        $this->ensurePreTakeSnapshotColumns();
        $this->ensureStandaloneTagsColumn();
        $this->ensureMoodTagsTableSchema();
        $this->ensureMedicationNotesTableSchema();
        $this->ensureOnboardingColumns();
        $this->ensureMedicationDraftsTable();
        $this->ensureEndDateColumn();
        $this->ensureNameAndBirthdateColumns();
        $this->ensureAllergyTables();
        $this->ensureProfileExtrasColumns();
        $this->ensureAllergyDetailColumns();
        $this->ensureStandaloneMedicationNullable();
    }

    private function ensureGroupTables(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_groups (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL,
                        scheduled_time TIME NOT NULL,
                        active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_groups_active_time (active, scheduled_time)
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_group_members (
                        group_id INT UNSIGNED NOT NULL,
                        medication_id INT UNSIGNED NOT NULL,
                        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        quantity_per_dose DECIMAL(10,2) NULL DEFAULT NULL,
                        PRIMARY KEY (group_id, medication_id),
                        CONSTRAINT fk_group_members_group
                            FOREIGN KEY (group_id) REFERENCES medication_groups (id) ON DELETE CASCADE,
                        CONSTRAINT fk_group_members_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }

            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_groups (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL DEFAULT 0,
                        name TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        active INTEGER NOT NULL DEFAULT 1,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_group_members (
                        group_id INTEGER NOT NULL,
                        medication_id INTEGER NOT NULL,
                        sort_order INTEGER NOT NULL DEFAULT 0,
                        quantity_per_dose REAL NULL DEFAULT NULL,
                        PRIMARY KEY (group_id, medication_id)
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureGroupMembersUpgrade(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                // Drop the one-group-per-medication unique constraint if it still exists.
                $idx = $this->db->query(
                    "SELECT COUNT(*) FROM information_schema.statistics
                     WHERE table_schema = DATABASE()
                       AND table_name = 'medication_group_members'
                       AND index_name = 'uq_medication_one_group'"
                );
                if ($idx !== false && (int) $idx->fetchColumn() > 0) {
                    // MySQL requires an index with medication_id as its leftmost column
                    // to support fk_group_members_medication. The unique key IS that index,
                    // so we must create a non-unique replacement before dropping it.
                    $hasReplacement = $this->db->query(
                        "SELECT COUNT(*) FROM information_schema.statistics
                         WHERE table_schema = DATABASE()
                           AND table_name = 'medication_group_members'
                           AND index_name = 'idx_mgm_medication_id'"
                    );
                    if ($hasReplacement !== false && (int) $hasReplacement->fetchColumn() === 0) {
                        $this->db->exec('ALTER TABLE medication_group_members ADD INDEX idx_mgm_medication_id (medication_id)');
                    }
                    $this->db->exec('ALTER TABLE medication_group_members DROP INDEX uq_medication_one_group');
                }
                // Add quantity_per_dose column if missing.
                $col = $this->db->query("SHOW COLUMNS FROM medication_group_members LIKE 'quantity_per_dose'");
                if ($col !== false && $col->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medication_group_members ADD COLUMN quantity_per_dose DECIMAL(10,2) NULL DEFAULT NULL');
                }
                return;
            }

            if ($driver === 'sqlite') {
                $info = $this->db->query("PRAGMA table_info(medication_group_members)");
                if ($info === false) {
                    return;
                }
                $columns = array_column($info->fetchAll(), 'name');
                if (in_array('quantity_per_dose', $columns, true)) {
                    // Column exists; check if old UNIQUE constraint needs removal by attempting
                    // a benign cross-group duplicate that the PRIMARY KEY allows.
                    // We detect the old schema by inspecting the CREATE TABLE SQL.
                    $sqlRow = $this->db->query(
                        "SELECT sql FROM sqlite_master WHERE type='table' AND name='medication_group_members'"
                    );
                    if ($sqlRow === false) return;
                    $createSql = (string) ($sqlRow->fetchColumn() ?: '');
                    if (stripos($createSql, 'UNIQUE (medication_id)') === false &&
                        stripos($createSql, 'UNIQUE(medication_id)') === false) {
                        return; // already upgraded
                    }
                }
                // Recreate the table without the UNIQUE(medication_id) constraint and with
                // the quantity_per_dose column. Use a transaction for safety.
                $this->db->beginTransaction();
                $this->db->exec(
                    "CREATE TABLE medication_group_members_new (
                        group_id INTEGER NOT NULL,
                        medication_id INTEGER NOT NULL,
                        sort_order INTEGER NOT NULL DEFAULT 0,
                        quantity_per_dose REAL NULL DEFAULT NULL,
                        PRIMARY KEY (group_id, medication_id)
                    )"
                );
                $this->db->exec(
                    "INSERT INTO medication_group_members_new (group_id, medication_id, sort_order, quantity_per_dose)
                     SELECT group_id, medication_id, sort_order, NULL FROM medication_group_members"
                );
                $this->db->exec('DROP TABLE medication_group_members');
                $this->db->exec('ALTER TABLE medication_group_members_new RENAME TO medication_group_members');
                $this->db->commit();
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            try { $this->db->rollBack(); } catch (Throwable) {}
            // Keep app booting even if migration fails.
        }
    }

    private function ensureStartingPillCountColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'starting_pill_count'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_pill_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER as_needed');
                    $this->db->exec('UPDATE medications SET starting_pill_count = pill_count WHERE starting_pill_count = 0');
                }
                return;
            }

            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'starting_pill_count') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_pill_count INTEGER NOT NULL DEFAULT 0');
                    $this->db->exec('UPDATE medications SET starting_pill_count = pill_count WHERE starting_pill_count = 0');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureTimeFormatColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'time_format'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN time_format ENUM('24h', '12h') NOT NULL DEFAULT '12h' AFTER schedule_mode");
                }
                return;
            }

            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'time_format') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN time_format TEXT NOT NULL DEFAULT '12h'");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureAppSettingsPerUser(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM app_settings LIKE 'user_id'");
                if ($check === false || $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE app_settings ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST');
                    $this->db->exec('ALTER TABLE app_settings DROP PRIMARY KEY');
                    $this->db->exec('ALTER TABLE app_settings ADD PRIMARY KEY (user_id, setting_key)');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(app_settings)");
                if ($check === false) {
                    return;
                }
                $columns = array_column($check->fetchAll(), 'name');
                if (!in_array('user_id', $columns, true)) {
                    $this->db->exec('ALTER TABLE app_settings RENAME TO app_settings_old');
                    $this->db->exec(
                        "CREATE TABLE app_settings (
                            user_id INTEGER NOT NULL DEFAULT 1,
                            setting_key TEXT NOT NULL,
                            setting_value TEXT NOT NULL,
                            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (user_id, setting_key)
                        )"
                    );
                    $this->db->exec(
                        "INSERT INTO app_settings (user_id, setting_key, setting_value, updated_at)
                         SELECT 1, setting_key, setting_value, updated_at FROM app_settings_old"
                    );
                    $this->db->exec('DROP TABLE app_settings_old');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureSupportTables(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS app_settings (
                        user_id INT UNSIGNED NOT NULL DEFAULT 1,
                        setting_key VARCHAR(120) NOT NULL,
                        setting_value VARCHAR(255) NOT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, setting_key)
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS dose_postpones (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        scheduled_for_date DATE NOT NULL,
                        scheduled_time TIME NOT NULL,
                        postponed_until DATETIME NOT NULL,
                        resolved_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_postpone_dose (medication_id, scheduled_for_date, scheduled_time),
                        INDEX idx_postpone_due (postponed_until, resolved_at),
                        CONSTRAINT fk_dose_postpones_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_subscriptions (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        endpoint TEXT NOT NULL,
                        p256dh_key VARCHAR(255) NOT NULL,
                        auth_key VARCHAR(255) NOT NULL,
                        user_agent VARCHAR(255) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_push_endpoint (endpoint(191))
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_delivery_log (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        scheduled_for_date DATE NOT NULL,
                        scheduled_time TIME NOT NULL,
                        sent_at DATETIME NOT NULL,
                        action_nonce VARCHAR(64) NOT NULL DEFAULT '',
                        postponed_until VARCHAR(19) NOT NULL DEFAULT '',
                        UNIQUE KEY uq_push_delivery (medication_id, scheduled_for_date, scheduled_time, postponed_until),
                        INDEX idx_push_nonce (action_nonce(32)),
                        CONSTRAINT fk_push_delivery_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }

            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS app_settings (
                        user_id INTEGER NOT NULL DEFAULT 1,
                        setting_key TEXT NOT NULL,
                        setting_value TEXT NOT NULL,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, setting_key)
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS dose_postpones (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        scheduled_for_date TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        postponed_until TEXT NOT NULL,
                        resolved_at TEXT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (medication_id, scheduled_for_date, scheduled_time)
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_subscriptions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        endpoint TEXT NOT NULL UNIQUE,
                        p256dh_key TEXT NOT NULL,
                        auth_key TEXT NOT NULL,
                        user_agent TEXT NOT NULL DEFAULT '',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_delivery_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        scheduled_for_date TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        sent_at TEXT NOT NULL,
                        action_nonce TEXT NOT NULL DEFAULT '',
                        postponed_until TEXT NOT NULL DEFAULT '',
                        UNIQUE (medication_id, scheduled_for_date, scheduled_time, postponed_until)
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails; runtime errors will surface if unresolved.
        }
    }

    private function ensureTrackDoseFeedbackColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'track_dose_feedback'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN track_dose_feedback TINYINT(1) NOT NULL DEFAULT 0');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'track_dose_feedback') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN track_dose_feedback INTEGER NOT NULL DEFAULT 0');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensurePainLevelColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE 'pain_level'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN pain_level TINYINT UNSIGNED NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(dose_logs)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'pain_level') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN pain_level INTEGER NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureDeductedQuantityColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE 'deducted_quantity'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN deducted_quantity DECIMAL(10,3) NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(dose_logs)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'deducted_quantity') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN deducted_quantity REAL NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureFeedbackTypeColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'feedback_type'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN feedback_type ENUM('none','pain','mood','both') NOT NULL DEFAULT 'none'");
                    $this->db->exec("UPDATE medications SET feedback_type = 'pain' WHERE track_dose_feedback = 1 AND feedback_type = 'none'");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'feedback_type') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN feedback_type TEXT NOT NULL DEFAULT 'none'");
                    $this->db->exec("UPDATE medications SET feedback_type = 'pain' WHERE track_dose_feedback = 1 AND feedback_type = 'none'");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureMoodLevelColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE 'mood_level'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN mood_level TINYINT UNSIGNED NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(dose_logs)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'mood_level') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN mood_level INTEGER NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureInstructionsWidened(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'instructions'");
                $column = $check !== false ? $check->fetch() : false;
                if (is_array($column) && stripos((string) ($column['Type'] ?? ''), 'varchar') !== false) {
                    $this->db->exec('ALTER TABLE medications MODIFY COLUMN instructions TEXT NOT NULL');
                }
            }
            // SQLite has no fixed-length VARCHAR enforcement, so no migration is needed there.
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureSetIdColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'set_id'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN set_id VARCHAR(64) NOT NULL DEFAULT ''");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'set_id') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN set_id TEXT NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureRefillsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_refills (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        refill_date DATE NOT NULL,
                        amount DECIMAL(10,3) NOT NULL,
                        pills_on_hand DECIMAL(10,3) NOT NULL,
                        note VARCHAR(255) NOT NULL DEFAULT '',
                        entry_type VARCHAR(20) NOT NULL DEFAULT 'refill',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_refills_med_date (medication_id, refill_date),
                        CONSTRAINT fk_refills_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                $check = $this->db->query("SHOW COLUMNS FROM medication_refills LIKE 'entry_type'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medication_refills ADD COLUMN entry_type VARCHAR(20) NOT NULL DEFAULT 'refill'");
                }
                // Manual adjustments store a signed delta, so amount must not stay
                // INT UNSIGNED (the original definition on already-deployed tables).
                $check = $this->db->query("SHOW COLUMNS FROM medication_refills LIKE 'amount'");
                $column = $check !== false ? $check->fetch() : false;
                if (is_array($column) && stripos((string) ($column['Type'] ?? ''), 'int') !== false) {
                    $this->db->exec('ALTER TABLE medication_refills MODIFY COLUMN amount DECIMAL(10,3) NOT NULL');
                    $this->db->exec('ALTER TABLE medication_refills MODIFY COLUMN pills_on_hand DECIMAL(10,3) NOT NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_refills (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        refill_date TEXT NOT NULL,
                        amount NUMERIC NOT NULL,
                        pills_on_hand NUMERIC NOT NULL,
                        note TEXT NOT NULL DEFAULT '',
                        entry_type TEXT NOT NULL DEFAULT 'refill',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $check = $this->db->query('PRAGMA table_info(medication_refills)');
                if ($check !== false && !in_array('entry_type', array_column($check->fetchAll(), 'name'), true)) {
                    $this->db->exec("ALTER TABLE medication_refills ADD COLUMN entry_type TEXT NOT NULL DEFAULT 'refill'");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureStatusEventsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_status_events (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        event VARCHAR(20) NOT NULL,
                        event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        reason VARCHAR(64) NOT NULL DEFAULT '',
                        comment VARCHAR(500) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_status_events_med_date (medication_id, event_at),
                        CONSTRAINT fk_status_events_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_status_events (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        event TEXT NOT NULL,
                        event_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        reason TEXT NOT NULL DEFAULT '',
                        comment TEXT NOT NULL DEFAULT '',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureDoseChangesTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_dose_changes (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        old_dose_amount DECIMAL(10,3) NULL,
                        old_dose_unit VARCHAR(20) NOT NULL DEFAULT '',
                        new_dose_amount DECIMAL(10,3) NULL,
                        new_dose_unit VARCHAR(20) NOT NULL DEFAULT '',
                        comment VARCHAR(500) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_dose_changes_med_date (medication_id, changed_at),
                        CONSTRAINT fk_dose_changes_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_dose_changes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        changed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        old_dose_amount REAL NULL,
                        old_dose_unit TEXT NOT NULL DEFAULT '',
                        new_dose_amount REAL NULL,
                        new_dose_unit TEXT NOT NULL DEFAULT '',
                        comment TEXT NOT NULL DEFAULT '',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensurePushActionNonceColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM push_delivery_log LIKE 'action_nonce'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE push_delivery_log ADD COLUMN action_nonce VARCHAR(64) NOT NULL DEFAULT ''");
                    $this->db->exec("ALTER TABLE push_delivery_log ADD INDEX idx_push_nonce (action_nonce(32))");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(push_delivery_log)');
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'action_nonce') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE push_delivery_log ADD COLUMN action_nonce TEXT NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; runtime errors will surface if unresolved.
        }
    }

    private function ensurePushDeliveryPostponedColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM push_delivery_log LIKE 'postponed_until'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE push_delivery_log ADD COLUMN postponed_until VARCHAR(19) NOT NULL DEFAULT ''");
                    $this->db->exec('ALTER TABLE push_delivery_log DROP INDEX uq_push_delivery');
                    $this->db->exec('ALTER TABLE push_delivery_log ADD UNIQUE KEY uq_push_delivery (medication_id, scheduled_for_date, scheduled_time, postponed_until)');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(push_delivery_log)');
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'postponed_until') {
                        $hasColumn = true;
                        break;
                    }
                }
                if ($hasColumn) {
                    return;
                }
                // SQLite can't alter a UNIQUE constraint in place, so rebuild the table.
                $this->db->exec('ALTER TABLE push_delivery_log RENAME TO push_delivery_log_old');
                $this->db->exec(
                    "CREATE TABLE push_delivery_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        scheduled_for_date TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        sent_at TEXT NOT NULL,
                        action_nonce TEXT NOT NULL DEFAULT '',
                        postponed_until TEXT NOT NULL DEFAULT '',
                        UNIQUE (medication_id, scheduled_for_date, scheduled_time, postponed_until)
                    )"
                );
                $this->db->exec(
                    "INSERT INTO push_delivery_log (id, medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce, postponed_until)
                     SELECT id, medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce, ''
                     FROM push_delivery_log_old"
                );
                $this->db->exec('DROP TABLE push_delivery_log_old');
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails; runtime errors will surface if unresolved.
        }
    }

    private function ensureMedicationTypeColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'medication_type'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN medication_type ENUM('prescription','otc','supplement') NOT NULL DEFAULT 'prescription'");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'medication_type') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN medication_type TEXT NOT NULL DEFAULT 'prescription'");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureDoseStructuredColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                foreach (['dose_amount' => 'DECIMAL(10,3) NULL', 'dose_unit' => 'VARCHAR(20) NULL', 'dose_form' => 'VARCHAR(30) NULL'] as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medications LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $existing = array_column($check->fetchAll(), 'name');
                if (!in_array('dose_amount', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_amount REAL NULL');
                }
                if (!in_array('dose_unit', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_unit TEXT NULL');
                }
                if (!in_array('dose_form', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_form TEXT NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureInventoryColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $cols = [
                    'inventory_type'   => "VARCHAR(30) NOT NULL DEFAULT 'pills'",
                    'inventory_unit'   => "VARCHAR(20) NOT NULL DEFAULT 'tablets'",
                    'starting_quantity' => 'DECIMAL(10,3) NULL',
                    'current_quantity'  => 'DECIMAL(10,3) NULL',
                    'quantity_per_dose' => 'DECIMAL(10,3) NOT NULL DEFAULT 1.000',
                ];
                foreach ($cols as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medications LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                $this->db->exec(
                    "UPDATE medications
                     SET current_quantity  = pill_count,
                         starting_quantity = starting_pill_count,
                         inventory_unit    = 'tablets'
                     WHERE current_quantity IS NULL"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $existing = array_column($check->fetchAll(), 'name');
                if (!in_array('inventory_type', $existing, true)) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN inventory_type TEXT NOT NULL DEFAULT 'pills'");
                }
                if (!in_array('inventory_unit', $existing, true)) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN inventory_unit TEXT NOT NULL DEFAULT 'tablets'");
                }
                if (!in_array('starting_quantity', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_quantity REAL NULL');
                }
                if (!in_array('current_quantity', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN current_quantity REAL NULL');
                }
                if (!in_array('quantity_per_dose', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN quantity_per_dose REAL NOT NULL DEFAULT 1.0');
                }
                $this->db->exec(
                    "UPDATE medications
                     SET current_quantity  = pill_count,
                         starting_quantity = starting_pill_count,
                         inventory_unit    = 'tablets'
                     WHERE current_quantity IS NULL"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureScheduleTimeDoseColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medication_schedule_times LIKE 'quantity_per_dose'");
                if ($check !== false) {
                    $col = $check->fetch(PDO::FETCH_ASSOC);
                    if ($col === false) {
                        $this->db->exec('ALTER TABLE medication_schedule_times ADD COLUMN quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL');
                    } elseif (stripos((string) ($col['Type'] ?? ''), 'decimal(10,3)') === false) {
                        // Widen from DECIMAL(10,2) to DECIMAL(10,3) to preserve three-decimal precision.
                        $this->db->exec('ALTER TABLE medication_schedule_times MODIFY COLUMN quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL');
                    }
                }
                $checkGroup = $this->db->query("SHOW COLUMNS FROM medication_group_members LIKE 'quantity_per_dose'");
                if ($checkGroup !== false) {
                    $col = $checkGroup->fetch(PDO::FETCH_ASSOC);
                    if ($col !== false && stripos((string) ($col['Type'] ?? ''), 'decimal(10,3)') === false) {
                        $this->db->exec('ALTER TABLE medication_group_members MODIFY COLUMN quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL');
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medication_schedule_times)");
                if ($check === false) {
                    return;
                }
                $columns = array_column($check->fetchAll(), 'name');
                if (!in_array('quantity_per_dose', $columns, true)) {
                    $this->db->exec('ALTER TABLE medication_schedule_times ADD COLUMN quantity_per_dose REAL NULL DEFAULT NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    // A medication's schedule row can now be "owned" by a group (group_id set), letting a
    // medication belong to several groups at once, each firing its own alert at that group's
    // time — see MedicationGroupRepository::syncGroupScheduleTime(). group_id IS NULL means a
    // plain individual dose time, exactly as before this column existed.
    private function ensureScheduleTimeGroupColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medication_schedule_times LIKE 'group_id'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medication_schedule_times ADD COLUMN group_id INT UNSIGNED NULL DEFAULT NULL AFTER medication_id');
                    $this->db->exec('ALTER TABLE medication_schedule_times ADD INDEX idx_schedule_group (group_id)');
                }
                // Widen the uniqueness guard to (medication_id, reminder_time, group_id) so a
                // group-owned row can't collide with a different group's row at the same time
                // for the same medication (two groups may legitimately share a time).
                $idx = $this->db->query(
                    "SELECT COUNT(*) FROM information_schema.statistics
                     WHERE table_schema = DATABASE()
                       AND table_name = 'medication_schedule_times'
                       AND index_name = 'uq_schedule_medication_time'
                       AND seq_in_index = 3"
                );
                if ($idx !== false && (int) $idx->fetchColumn() === 0) {
                    $this->db->exec('ALTER TABLE medication_schedule_times DROP INDEX uq_schedule_medication_time');
                    $this->db->exec('ALTER TABLE medication_schedule_times ADD UNIQUE KEY uq_schedule_medication_time (medication_id, reminder_time, group_id)');
                }
                return;
            }

            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medication_schedule_times)");
                if ($check === false) {
                    return;
                }
                $columns = array_column($check->fetchAll(), 'name');
                if (!in_array('group_id', $columns, true)) {
                    $this->db->exec('ALTER TABLE medication_schedule_times ADD COLUMN group_id INTEGER NULL DEFAULT NULL');
                }
                // SQLite test/dev fixtures for this table are created without a UNIQUE
                // constraint at all (see tests/*.php), so there's nothing to widen here — the
                // production guard above only applies to the real MySQL schema.
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureSortOrderColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                foreach (['medications', 'medication_groups'] as $table) {
                    $check = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'sort_order'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE {$table} ADD COLUMN sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                foreach (['medications', 'medication_groups'] as $table) {
                    $check = $this->db->query("PRAGMA table_info({$table})");
                    if ($check === false) {
                        continue;
                    }
                    $columns = array_column($check->fetchAll(), 'name');
                    if (!in_array('sort_order', $columns, true)) {
                        $this->db->exec("ALTER TABLE {$table} ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
                    }
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureMedicationUserIndex(): void
    {
        // Nearly every query filters medications by user_id (+ profile_id). The base
        // schema only indexes (active, name), and migration 002 created a single-column
        // idx_medications_user (user_id), so use a NEW name for the composite index —
        // reusing the old name would leave the intended index uninstalled on upgrades.
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW INDEX FROM medications WHERE Key_name = 'idx_medications_tenant'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('CREATE INDEX idx_medications_tenant ON medications (user_id, profile_id, active)');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_medications_tenant ON medications (user_id, profile_id, active)');
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Non-fatal: the index is a performance optimization only.
        }
    }

    private function ensureUserNotificationsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS user_notifications (
                        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id       INT UNSIGNED NOT NULL,
                        medication_id INT UNSIGNED NOT NULL,
                        type          ENUM('low_stock','critical_stock','out_of_stock') NOT NULL,
                        is_read       TINYINT(1) NOT NULL DEFAULT 0,
                        is_dismissed  TINYINT(1) NOT NULL DEFAULT 0,
                        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_notif_user_unread (user_id, is_read, is_dismissed),
                        CONSTRAINT fk_notif_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS user_notifications (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id       INTEGER NOT NULL,
                        medication_id INTEGER NOT NULL,
                        type          TEXT NOT NULL CHECK(type IN ('low_stock','critical_stock','out_of_stock')),
                        is_read       INTEGER NOT NULL DEFAULT 0,
                        is_dismissed  INTEGER NOT NULL DEFAULT 0,
                        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureFamilyProfilesTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS family_profiles (
                        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        owner_user_id  INT UNSIGNED NOT NULL,
                        display_name   VARCHAR(100) NOT NULL,
                        avatar_color   VARCHAR(7) NULL,
                        relationship   VARCHAR(50) NULL,
                        birth_year     YEAR NULL,
                        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_family_profiles_owner (owner_user_id),
                        CONSTRAINT fk_family_profiles_user
                            FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                foreach (['medications', 'medication_groups'] as $table) {
                    $check = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'profile_id'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE {$table} ADD COLUMN profile_id INT UNSIGNED NULL AFTER user_id");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS family_profiles (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        owner_user_id INTEGER NOT NULL,
                        display_name  TEXT NOT NULL,
                        avatar_color  TEXT NULL,
                        relationship  TEXT NULL,
                        birth_year    INTEGER NULL,
                        created_at    TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                // SQLite does not support IF NOT EXISTS on ADD COLUMN; swallow the error if the column exists.
                try { $this->db->exec("ALTER TABLE medications ADD COLUMN profile_id INTEGER NULL"); } catch (Throwable) {}
                try { $this->db->exec("ALTER TABLE medication_groups ADD COLUMN profile_id INTEGER NULL"); } catch (Throwable) {}
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureStartDateColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'start_date'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN start_date DATE NULL AFTER dose");
                    $this->db->exec("UPDATE medications SET start_date = DATE(created_at) WHERE start_date IS NULL");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'start_date') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN start_date TEXT NULL");
                    $this->db->exec("UPDATE medications SET start_date = date(created_at) WHERE start_date IS NULL");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureStandalonePainMoodLogsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS standalone_pain_mood_logs (
                        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id       INT UNSIGNED NOT NULL,
                        medication_id INT UNSIGNED NOT NULL,
                        log_type      ENUM('pain','mood','both') NOT NULL,
                        pain_level    TINYINT UNSIGNED NULL,
                        mood_level    TINYINT UNSIGNED NULL,
                        note          VARCHAR(255) NOT NULL DEFAULT '',
                        tags          VARCHAR(500) NOT NULL DEFAULT '',
                        logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TIMESTAMP NULL DEFAULT NULL,
                        INDEX idx_standalone_user_med_date (user_id, medication_id, logged_at),
                        CONSTRAINT fk_standalone_user
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        CONSTRAINT fk_standalone_medication
                            FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS standalone_pain_mood_logs (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id       INTEGER NOT NULL,
                        medication_id INTEGER NOT NULL,
                        log_type      TEXT NOT NULL DEFAULT 'pain',
                        pain_level    INTEGER NULL,
                        mood_level    INTEGER NULL,
                        note          TEXT NOT NULL DEFAULT '',
                        tags          TEXT NOT NULL DEFAULT '',
                        logged_at     TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TEXT NULL
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureFeedbackEditedAtColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE 'feedback_edited_at'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE dose_logs ADD COLUMN feedback_edited_at TIMESTAMP NULL DEFAULT NULL AFTER mood_level");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(dose_logs)');
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'feedback_edited_at') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN feedback_edited_at TEXT NULL');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    // Snapshot of a dose_logs row's status/note/taken_at from just before it was
    // flipped to 'taken' — set only when that flip overwrote a pre-existing
    // non-taken row (e.g. an auto-marked 'missed' slot taken retroactively).
    // Lets revertTakenDose() restore the original record instead of deleting
    // history that predates the take it's undoing.
    private function ensurePreTakeSnapshotColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $columns = [
            'pre_take_status' => ['mysql' => 'VARCHAR(20) NULL', 'sqlite' => 'TEXT NULL'],
            'pre_take_note' => ['mysql' => 'VARCHAR(255) NULL', 'sqlite' => 'TEXT NULL'],
            'pre_take_taken_at' => ['mysql' => 'TIMESTAMP NULL DEFAULT NULL', 'sqlite' => 'TEXT NULL'],
        ];
        try {
            if ($driver === 'mysql') {
                foreach ($columns as $name => $types) {
                    $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE '{$name}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE dose_logs ADD COLUMN {$name} {$types['mysql']}");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(dose_logs)');
                if ($check === false) {
                    return;
                }
                $existing = array_column($check->fetchAll(), 'name');
                foreach ($columns as $name => $types) {
                    if (!in_array($name, $existing, true)) {
                        $this->db->exec("ALTER TABLE dose_logs ADD COLUMN {$name} {$types['sqlite']}");
                    }
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureStandaloneTagsColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM standalone_pain_mood_logs LIKE 'tags'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE standalone_pain_mood_logs ADD COLUMN tags VARCHAR(500) NOT NULL DEFAULT '' AFTER note");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(standalone_pain_mood_logs)');
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'tags') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE standalone_pain_mood_logs ADD COLUMN tags TEXT NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    // Allows standalone pain/mood logs to exist without a medication ("independent" logging).
    private function ensureStandaloneMedicationNullable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM standalone_pain_mood_logs LIKE 'medication_id'");
                $column = $check !== false ? $check->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($column) && strtoupper((string) ($column['Null'] ?? '')) === 'NO') {
                    $this->db->exec('ALTER TABLE standalone_pain_mood_logs MODIFY COLUMN medication_id INT UNSIGNED NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(standalone_pain_mood_logs)');
                if ($check === false) {
                    return;
                }
                $medColumn = null;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'medication_id') {
                        $medColumn = $column;
                        break;
                    }
                }
                if ($medColumn === null || (int) ($medColumn['notnull'] ?? 0) === 0) {
                    return; // already nullable (or table doesn't exist yet)
                }
                // SQLite can't drop a NOT NULL constraint in place, so rebuild the table.
                $this->db->beginTransaction();
                $this->db->exec(
                    "CREATE TABLE standalone_pain_mood_logs_new (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id       INTEGER NOT NULL,
                        medication_id INTEGER NULL,
                        log_type      TEXT NOT NULL DEFAULT 'pain',
                        pain_level    INTEGER NULL,
                        mood_level    INTEGER NULL,
                        note          TEXT NOT NULL DEFAULT '',
                        tags          TEXT NOT NULL DEFAULT '',
                        logged_at     TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TEXT NULL
                    )"
                );
                $this->db->exec(
                    "INSERT INTO standalone_pain_mood_logs_new
                         (id, user_id, medication_id, log_type, pain_level, mood_level, note, tags, logged_at, updated_at)
                     SELECT id, user_id, medication_id, log_type, pain_level, mood_level, note, tags, logged_at, updated_at
                     FROM standalone_pain_mood_logs"
                );
                $this->db->exec('DROP TABLE standalone_pain_mood_logs');
                $this->db->exec('ALTER TABLE standalone_pain_mood_logs_new RENAME TO standalone_pain_mood_logs');
                $this->db->commit();
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            try { $this->db->rollBack(); } catch (Throwable) {}
            // Keep app booting even if migration fails.
        }
    }

    private function ensureMoodTagsTableSchema(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS mood_tags (
                        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id     INT UNSIGNED NOT NULL,
                        name        VARCHAR(30) NOT NULL,
                        always_show TINYINT(1) NOT NULL DEFAULT 1,
                        sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_mood_tags_user_name (user_id, name),
                        INDEX idx_mood_tags_user (user_id),
                        CONSTRAINT fk_mood_tags_user
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
            } elseif ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS mood_tags (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id     INTEGER NOT NULL,
                        name        TEXT NOT NULL,
                        always_show INTEGER NOT NULL DEFAULT 1,
                        sort_order  INTEGER NOT NULL DEFAULT 0,
                        created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (user_id, name)
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    // Per-user seed step: must run on every construction (not just once globally), since new
    // users still need their predefined mood tags seeded. Kept cheap by the app_settings flag.
    private function seedMoodTagsForUser(): void
    {
        if ($this->userId <= 0) {
            return;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            return;
        }

        try {
            $flagStmt = $this->db->prepare(
                'SELECT 1 FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1'
            );
            $flagStmt->execute(['user_id' => $this->userId, 'key' => 'mood_tags_seeded']);
            if ($flagStmt->fetchColumn()) {
                return;
            }

            $predefined = [
                'Annoyed', 'Anxious', 'Bored', 'Calm', 'Excited', 'Grateful',
                'Happy', 'In Love', 'Indifferent', 'Lonely', 'Productive', 'Sad',
                'Stressed', 'Tired',
            ];
            $insertTag = $this->db->prepare(
                'INSERT INTO mood_tags (user_id, name, always_show, sort_order)
                 VALUES (:user_id, :name, 1, :sort_order)'
            );
            foreach ($predefined as $i => $name) {
                try {
                    $insertTag->execute(['user_id' => $this->userId, 'name' => $name, 'sort_order' => $i]);
                } catch (Throwable) {
                    // Ignore unique-constraint collisions (tag already exists for this user).
                }
            }
            if ($driver === 'mysql') {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :insert_value)
                     ON DUPLICATE KEY UPDATE setting_value = :update_value'
                )->execute(['user_id' => $this->userId, 'key' => 'mood_tags_seeded', 'insert_value' => '1', 'update_value' => '1']);
            } else {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :value)
                     ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
                )->execute(['user_id' => $this->userId, 'key' => 'mood_tags_seeded', 'value' => '1']);
            }
        } catch (Throwable) {
            // Keep app booting even if seeding fails.
        }
    }

    private function ensureMedicationNotesTableSchema(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_notes (
                        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        note          TEXT NOT NULL,
                        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_notes_medication (medication_id),
                        CONSTRAINT fk_notes_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } elseif ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_notes (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        note          TEXT NOT NULL DEFAULT '',
                        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    // Per-user backfill step: must run on every construction (not just once globally), since new
    // users still need their existing instructions backfilled. Kept cheap by the app_settings flag.
    private function backfillMedicationNotesForUser(): void
    {
        if ($this->userId <= 0) {
            return;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            return;
        }

        try {
            $flagStmt = $this->db->prepare(
                'SELECT 1 FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1'
            );
            $flagStmt->execute(['user_id' => $this->userId, 'key' => 'notes_backfill_done']);
            if ($flagStmt->fetchColumn()) {
                return;
            }

            $this->db->prepare(
                "INSERT INTO medication_notes (medication_id, note, created_at, updated_at)
                 SELECT id, instructions, created_at, updated_at
                 FROM medications
                 WHERE user_id = :user_id AND instructions IS NOT NULL AND TRIM(instructions) <> ''"
            )->execute(['user_id' => $this->userId]);

            if ($driver === 'mysql') {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :insert_value)
                     ON DUPLICATE KEY UPDATE setting_value = :update_value'
                )->execute(['user_id' => $this->userId, 'key' => 'notes_backfill_done', 'insert_value' => '1', 'update_value' => '1']);
            } else {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :value)
                     ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
                )->execute(['user_id' => $this->userId, 'key' => 'notes_backfill_done', 'value' => '1']);
            }
        } catch (Throwable) {
            // Keep app booting even if backfill fails.
        }
    }

    // Per-user backfill step: installs that had group memberships before this fix shipped
    // have live rows in medication_group_members with no corresponding group-owned row in
    // medication_schedule_times, so those medications were silently excluded from their
    // group's alert. Mirrors MedicationGroupRepository::syncGroupScheduleTime()'s claim/insert
    // logic; kept self-contained here (like backfillMedicationNotesForUser()) rather than
    // depending on that class. Gated by an app_settings flag so it only does work once.
    private function backfillGroupScheduleTimesForUser(): void
    {
        if ($this->userId <= 0) {
            return;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            return;
        }

        try {
            $flagStmt = $this->db->prepare(
                'SELECT 1 FROM app_settings WHERE user_id = :user_id AND setting_key = :key LIMIT 1'
            );
            $flagStmt->execute(['user_id' => $this->userId, 'key' => 'group_schedule_backfill_done']);
            if ($flagStmt->fetchColumn()) {
                return;
            }

            $members = $this->db->prepare(
                'SELECT mgm.group_id, mgm.medication_id, g.scheduled_time
                 FROM medication_group_members mgm
                 INNER JOIN medication_groups g ON g.id = mgm.group_id
                 INNER JOIN medications m ON m.id = mgm.medication_id
                 WHERE m.user_id = :user_id'
            );
            $members->execute(['user_id' => $this->userId]);

            foreach ($members->fetchAll() as $row) {
                $this->backfillOneGroupSchedule((int) $row['group_id'], (int) $row['medication_id'], (string) $row['scheduled_time']);
            }

            if ($driver === 'mysql') {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :insert_value)
                     ON DUPLICATE KEY UPDATE setting_value = :update_value'
                )->execute(['user_id' => $this->userId, 'key' => 'group_schedule_backfill_done', 'insert_value' => '1', 'update_value' => '1']);
            } else {
                $this->db->prepare(
                    'INSERT INTO app_settings (user_id, setting_key, setting_value)
                     VALUES (:user_id, :key, :value)
                     ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value'
                )->execute(['user_id' => $this->userId, 'key' => 'group_schedule_backfill_done', 'value' => '1']);
            }
        } catch (Throwable) {
            // Keep app booting even if backfill fails.
        }
    }

    // Ensures medicationId has a schedule row owned by groupId at scheduledTime: reuses an
    // exact-time individual row if one exists, else claims the medication's earliest remaining
    // individual row (the dose the group is taking over), else inserts a fresh row. Any other
    // individual doses the medication has are left untouched.
    private function backfillOneGroupSchedule(int $groupId, int $medicationId, string $scheduledTime): void
    {
        $existing = $this->db->prepare(
            'SELECT 1 FROM medication_schedule_times
             WHERE medication_id = :medication_id AND group_id = :group_id AND reminder_time = :reminder_time LIMIT 1'
        );
        $existing->execute(['medication_id' => $medicationId, 'group_id' => $groupId, 'reminder_time' => $scheduledTime]);
        if ($existing->fetchColumn()) {
            return;
        }

        $this->db->prepare(
            'DELETE FROM medication_schedule_times WHERE medication_id = :medication_id AND group_id = :group_id'
        )->execute(['medication_id' => $medicationId, 'group_id' => $groupId]);

        $claim = $this->db->prepare(
            'UPDATE medication_schedule_times SET group_id = :group_id
             WHERE medication_id = :medication_id AND reminder_time = :reminder_time AND group_id IS NULL'
        );
        $claim->execute(['group_id' => $groupId, 'medication_id' => $medicationId, 'reminder_time' => $scheduledTime]);
        if ($claim->rowCount() > 0) {
            return;
        }

        $earliest = $this->db->prepare(
            'SELECT id FROM medication_schedule_times
             WHERE medication_id = :medication_id AND group_id IS NULL
             ORDER BY reminder_time ASC LIMIT 1'
        );
        $earliest->execute(['medication_id' => $medicationId]);
        $rowId = $earliest->fetchColumn();
        if ($rowId !== false) {
            $this->db->prepare(
                'UPDATE medication_schedule_times SET reminder_time = :reminder_time, group_id = :group_id WHERE id = :id'
            )->execute(['reminder_time' => $scheduledTime, 'group_id' => $groupId, 'id' => (int) $rowId]);
            return;
        }

        $this->db->prepare(
            'INSERT INTO medication_schedule_times (medication_id, reminder_time, quantity_per_dose, group_id)
             VALUES (:medication_id, :reminder_time, NULL, :group_id)'
        )->execute(['medication_id' => $medicationId, 'reminder_time' => $scheduledTime, 'group_id' => $groupId]);
    }

    private function ensureOnboardingColumns(): void
    {
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                // Per-column existence checks rather than a single "ADD COLUMN IF NOT EXISTS"
                // statement — that syntax requires MySQL 8.0.29+ and throws a hard syntax error
                // on older MySQL/MariaDB, silently skipping every column below it once caught.
                $onboardingColumns = [
                    'setup_status'           => "ENUM('draft','ready','active') NOT NULL DEFAULT 'active'",
                    'dashboard_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'reminders_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'adherence_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'inventory_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'tracking_started_at'    => 'DATETIME NULL',
                    'inventory_count_method' => "ENUM('counted','estimated','unknown') NOT NULL DEFAULT 'unknown'",
                    'inventory_as_of'        => 'DATETIME NULL',
                ];
                foreach ($onboardingColumns as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medications LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS profile_onboarding (
                        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id      INT UNSIGNED NOT NULL,
                        profile_id   INT UNSIGNED NOT NULL DEFAULT 0,
                        status       ENUM('not_started','in_progress','completed','skipped') NOT NULL DEFAULT 'not_started',
                        current_step VARCHAR(40) NOT NULL DEFAULT 'medications',
                        started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        completed_at DATETIME NULL,
                        UNIQUE KEY uq_onboarding (user_id, profile_id),
                        CONSTRAINT fk_onboarding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
                $this->db->exec(
                    "ALTER TABLE profile_onboarding
                        MODIFY COLUMN status ENUM('not_started','in_progress','completed','skipped')
                        NOT NULL DEFAULT 'not_started'"
                );
                $this->db->exec(
                    "UPDATE profile_onboarding SET status = 'skipped' WHERE status = ''"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS inventory_transactions (
                        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id    INT UNSIGNED NOT NULL,
                        dose_log_id      INT UNSIGNED NULL,
                        refill_id        INT UNSIGNED NULL,
                        transaction_type VARCHAR(30) NOT NULL,
                        quantity_delta   DECIMAL(10,3) NOT NULL,
                        balance_after    DECIMAL(10,3) NOT NULL,
                        effective_at     DATETIME NOT NULL,
                        count_method     VARCHAR(20) NULL,
                        note             VARCHAR(255) NOT NULL DEFAULT '',
                        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_inv_tx_med_effective (medication_id, effective_at),
                        CONSTRAINT fk_inv_tx_medication FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
                $refillColumns = [
                    'started_using_at'    => 'DATETIME NULL',
                    'carryover_quantity'  => 'DECIMAL(10,3) NOT NULL DEFAULT 0',
                ];
                foreach ($refillColumns as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medication_refills LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medication_refills ADD COLUMN {$col} {$def}");
                    }
                }
            } elseif ($driver === 'sqlite') {
                // Add onboarding columns to medications if missing (SQLite has no IF NOT EXISTS on ALTER TABLE)
                $existing = $this->db->query("SELECT name FROM pragma_table_info('medications')")->fetchAll(PDO::FETCH_COLUMN);
                $toAdd = [
                    'setup_status'           => "TEXT NOT NULL DEFAULT 'active'",
                    'dashboard_enabled'      => 'INTEGER NOT NULL DEFAULT 1',
                    'reminders_enabled'      => 'INTEGER NOT NULL DEFAULT 1',
                    'adherence_enabled'      => 'INTEGER NOT NULL DEFAULT 1',
                    'inventory_enabled'      => 'INTEGER NOT NULL DEFAULT 0',
                    'tracking_started_at'    => 'TEXT NULL',
                    'inventory_count_method' => "TEXT NOT NULL DEFAULT 'unknown'",
                    'inventory_as_of'        => 'TEXT NULL',
                ];
                foreach ($toAdd as $col => $def) {
                    if (!in_array($col, $existing, true)) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS profile_onboarding (
                        id           INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id      INTEGER NOT NULL,
                        profile_id   INTEGER NOT NULL DEFAULT 0,
                        status       TEXT NOT NULL DEFAULT 'not_started',
                        current_step TEXT NOT NULL DEFAULT 'medications',
                        started_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        completed_at TEXT NULL,
                        UNIQUE (user_id, profile_id)
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS inventory_transactions (
                        id               INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id    INTEGER NOT NULL,
                        dose_log_id      INTEGER NULL,
                        refill_id        INTEGER NULL,
                        transaction_type TEXT NOT NULL,
                        quantity_delta   REAL NOT NULL,
                        balance_after    REAL NOT NULL,
                        effective_at     TEXT NOT NULL,
                        count_method     TEXT NULL,
                        note             TEXT NOT NULL DEFAULT '',
                        created_at       TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $existingRefill = $this->db->query("SELECT name FROM pragma_table_info('medication_refills')")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('started_using_at', $existingRefill, true)) {
                    $this->db->exec('ALTER TABLE medication_refills ADD COLUMN started_using_at TEXT NULL');
                }
                if (!in_array('carryover_quantity', $existingRefill, true)) {
                    $this->db->exec('ALTER TABLE medication_refills ADD COLUMN carryover_quantity REAL NOT NULL DEFAULT 0');
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Non-fatal: new columns/tables added progressively.
        }
    }

    // Staging table for the multi-step "Add medication" wizard's "Save draft" action.
    // Deliberately separate from setup_status/profile_onboarding (the first-run onboarding
    // flow's own draft mechanism) so the two can't collide.
    private function ensureMedicationDraftsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_drafts (
                        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id        INT UNSIGNED NOT NULL,
                        profile_id     INT UNSIGNED NULL,
                        form_data      TEXT NOT NULL,
                        current_step   TINYINT UNSIGNED NOT NULL DEFAULT 1,
                        furthest_step  TINYINT UNSIGNED NOT NULL DEFAULT 1,
                        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_medication_drafts_user (user_id, profile_id),
                        CONSTRAINT fk_medication_drafts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } elseif ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_drafts (
                        id             INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id        INTEGER NOT NULL,
                        profile_id     INTEGER NULL,
                        form_data      TEXT NOT NULL,
                        current_step   INTEGER NOT NULL DEFAULT 1,
                        furthest_step  INTEGER NOT NULL DEFAULT 1,
                        created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if table setup fails.
        }
    }

    private function ensureEndDateColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'end_date'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN end_date DATE NULL AFTER start_date");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'end_date') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN end_date TEXT NULL");
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureNameAndBirthdateColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $columns = [
                    'users'           => ['first_name' => 'VARCHAR(50) NULL', 'last_name' => 'VARCHAR(50) NULL', 'birth_date' => 'DATE NULL'],
                    'family_profiles' => ['first_name' => 'VARCHAR(50) NULL', 'last_name' => 'VARCHAR(50) NULL', 'birth_date' => 'DATE NULL'],
                ];
                foreach ($columns as $table => $cols) {
                    foreach ($cols as $column => $definition) {
                        $check = $this->db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                        if ($check !== false && $check->fetchColumn() === false) {
                            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                        }
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $columns = [
                    'users'           => ['first_name' => 'TEXT NULL', 'last_name' => 'TEXT NULL', 'birth_date' => 'TEXT NULL'],
                    'family_profiles' => ['first_name' => 'TEXT NULL', 'last_name' => 'TEXT NULL', 'birth_date' => 'TEXT NULL'],
                ];
                foreach ($columns as $table => $cols) {
                    $check = $this->db->query("PRAGMA table_info({$table})");
                    if ($check === false) {
                        continue;
                    }
                    $existing = array_map(static fn(array $c): string => (string) ($c['name'] ?? ''), $check->fetchAll());
                    foreach ($cols as $column => $definition) {
                        if (!in_array($column, $existing, true)) {
                            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                        }
                    }
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureAllergyTables(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS allergy_catalog (
                        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        owner_user_id  INT UNSIGNED NULL,
                        name           VARCHAR(150) NOT NULL,
                        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_allergy_catalog_name_owner (name, owner_user_id),
                        CONSTRAINT fk_allergy_catalog_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS profile_allergies (
                        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        owner_user_id       INT UNSIGNED NOT NULL,
                        profile_id          INT UNSIGNED NULL,
                        allergy_catalog_id  INT UNSIGNED NOT NULL,
                        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_profile_allergy (owner_user_id, profile_id, allergy_catalog_id),
                        CONSTRAINT fk_profile_allergies_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
                        CONSTRAINT fk_profile_allergies_profile FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE,
                        CONSTRAINT fk_profile_allergies_catalog FOREIGN KEY (allergy_catalog_id) REFERENCES allergy_catalog(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
            } elseif ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS allergy_catalog (
                        id             INTEGER PRIMARY KEY AUTOINCREMENT,
                        owner_user_id  INTEGER NULL,
                        name           TEXT NOT NULL,
                        created_at     TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS profile_allergies (
                        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                        owner_user_id       INTEGER NOT NULL,
                        profile_id          INTEGER NULL,
                        allergy_catalog_id  INTEGER NOT NULL,
                        created_at          TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            } else {
                return;
            }

            $names = [
                'Penicillin', 'Sulfa Drugs', 'Aspirin/NSAIDs', 'Codeine/Opioids', 'Iodine/Contrast Dye',
                'Latex', 'Peanuts', 'Tree Nuts', 'Shellfish', 'Eggs', 'Milk/Dairy', 'Soy', 'Wheat/Gluten',
                'Pollen', 'Pet Dander (Cat/Dog)', 'Bee/Insect Stings',
            ];
            // Single atomic INSERT...SELECT...WHERE NOT EXISTS (same idiom as migration
            // 017_add_allergies.sql) instead of a select-then-loop-insert: MySQL doesn't
            // dedupe NULL-owner rows via the unique key, and a separate select-then-insert
            // leaves a race window where two concurrent sweeps can both insert the full seed
            // list, producing duplicate catalog rows.
            $params  = [];
            $selects = [];
            foreach ($names as $i => $name) {
                $selects[] = 'SELECT :name' . $i . ($i === 0 ? ' AS name' : '');
                $params['name' . $i] = $name;
            }
            $this->db->prepare(
                'INSERT INTO allergy_catalog (owner_user_id, name)
                 SELECT NULL, v.name FROM (' . implode(' UNION ALL ', $selects) . ') v
                 WHERE NOT EXISTS (
                     SELECT 1 FROM allergy_catalog ac WHERE ac.owner_user_id IS NULL AND ac.name = v.name
                 )'
            )->execute($params);
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureProfileExtrasColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $columns = [
                    'users'           => ['height_value' => 'DECIMAL(5,2) NULL', 'height_unit' => 'VARCHAR(4) NULL'],
                    'family_profiles' => ['height_value' => 'DECIMAL(5,2) NULL', 'height_unit' => 'VARCHAR(4) NULL', 'profile_picture' => 'VARCHAR(500) NULL'],
                ];
                foreach ($columns as $table => $cols) {
                    foreach ($cols as $column => $definition) {
                        $check = $this->db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                        if ($check !== false && $check->fetchColumn() === false) {
                            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                        }
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $columns = [
                    'users'           => ['height_value' => 'REAL NULL', 'height_unit' => 'TEXT NULL'],
                    'family_profiles' => ['height_value' => 'REAL NULL', 'height_unit' => 'TEXT NULL', 'profile_picture' => 'TEXT NULL'],
                ];
                foreach ($columns as $table => $cols) {
                    $check = $this->db->query("PRAGMA table_info({$table})");
                    if ($check === false) {
                        continue;
                    }
                    $existing = array_map(static fn(array $c): string => (string) ($c['name'] ?? ''), $check->fetchAll());
                    foreach ($cols as $column => $definition) {
                        if (!in_array($column, $existing, true)) {
                            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                        }
                    }
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }

    private function ensureAllergyDetailColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $columns = [
                    'allergy_type'     => "VARCHAR(12) NOT NULL DEFAULT 'allergy'",
                    'life_threatening' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'severity'         => 'VARCHAR(12) NULL',
                    'category'         => 'VARCHAR(20) NULL',
                    'notes'            => 'TEXT NULL',
                    'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
                ];
                foreach ($columns as $column => $definition) {
                    $check = $this->db->query("SHOW COLUMNS FROM profile_allergies LIKE '{$column}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE profile_allergies ADD COLUMN {$column} {$definition}");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $columns = [
                    'allergy_type'     => "TEXT NOT NULL DEFAULT 'allergy'",
                    'life_threatening' => 'INTEGER NOT NULL DEFAULT 0',
                    'severity'         => 'TEXT NULL',
                    'category'         => 'TEXT NULL',
                    'notes'            => 'TEXT NULL',
                    'is_active'        => 'INTEGER NOT NULL DEFAULT 1',
                ];
                $check = $this->db->query('PRAGMA table_info(profile_allergies)');
                if ($check === false) {
                    return;
                }
                $existing = array_map(static fn(array $c): string => (string) ($c['name'] ?? ''), $check->fetchAll());
                foreach ($columns as $column => $definition) {
                    if (!in_array($column, $existing, true)) {
                        $this->db->exec("ALTER TABLE profile_allergies ADD COLUMN {$column} {$definition}");
                    }
                }
            }
        } catch (Throwable) {
            $this->schemaSweepFailed = true;
            // Keep app booting even if migration fails.
        }
    }
}
