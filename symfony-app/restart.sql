-- Manual restart batch for the MTF pipeline.
-- Run with: mysql -u <user> -p <database> < symfony-app/restart.sql

SET @now_utc := UTC_TIMESTAMP();

-- Reactivate cooldowns that have expired.
UPDATE tf_eligibility
SET status = 'ACTIVE',
    priority = GREATEST(priority, 100),
    cooldown_until = NULL,
    reason = 'restart_cooldown_expired',
    updated_at = @now_utc
WHERE status = 'COOLDOWN'
  AND (cooldown_until IS NULL OR cooldown_until <= @now_utc);

-- Unlock stale order/position locks older than 4 hours.
UPDATE tf_eligibility
SET status = 'ACTIVE',
    priority = GREATEST (priority, 100),
    cooldown_until = NULL,
    reason = 'restart_unlock_stale_lock',
    updated_at = @now_utc
WHERE status IN ('LOCKED_ORDER', 'LOCKED_POSITION')
  AND updated_at < @now_utc - INTERVAL 4 HOUR;

-- Purge pending child signals older than 12 hours.
DELETE FROM pending_child_signals
WHERE created_at < @now_utc - INTERVAL 12 HOUR;

-- Reset failed retry counters older than 2 hours.
UPDATE tf_retry_status
SET retry_count = 0,
    last_result = 'NONE',
    updated_at = @now_utc
WHERE last_result = 'FAILED'
  AND updated_at < @now_utc - INTERVAL 2 HOUR;
