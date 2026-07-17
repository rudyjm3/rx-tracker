-- Nearly every query filters medications by user_id (+ profile_id), but the base
-- schema only indexes (active, name). Add a covering tenant index so per-user
-- lookups don't fall back to a full scan.
CREATE INDEX IF NOT EXISTS idx_medications_user ON medications (user_id, profile_id, active);
