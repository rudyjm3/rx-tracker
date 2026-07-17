-- Nearly every query filters medications by user_id (+ profile_id), but migration
-- 002 only created a single-column idx_medications_user (user_id). Add a covering
-- tenant index under a NEW name — reusing the existing name would be a no-op on
-- upgraded databases (IF NOT EXISTS sees the old index and skips the composite).
CREATE INDEX IF NOT EXISTS idx_medications_tenant ON medications (user_id, profile_id, active);
