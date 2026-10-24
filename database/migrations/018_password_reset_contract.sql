-- Additive reconciliation: deployed sites already use used_at; base installs used a boolean.
ALTER TABLE password_resets ADD COLUMN IF NOT EXISTS used_at DATETIME NULL;
-- Preserve historical used tokens. Never reactivate a consumed reset token.
SET @light_has_used = (SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='password_resets' AND column_name='used');
SET @light_reset_sql = IF(@light_has_used > 0,
 'UPDATE password_resets SET used_at=expires_at WHERE used=1 AND used_at IS NULL',
 'SELECT 1');
PREPARE light_reset_reconcile FROM @light_reset_sql;
EXECUTE light_reset_reconcile;
DEALLOCATE PREPARE light_reset_reconcile;
