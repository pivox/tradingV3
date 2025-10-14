-- ===============================================
-- MTF Pipeline — Maintenance Pack (MySQL 8)
-- One script with:
--   1) Stored procedure: sp_post_callback_fix(symbol, tf, has_open_order, has_open_position)
--   2) Stored procedure: sp_pipeline_restart()
-- Usage examples at bottom.
-- ===============================================

-- Safety switches
SET @OLD_SQL_NOTES=@@sql_notes; SET sql_notes=0;
SET @OLD_FK_CHECKS=@@foreign_key_checks; SET foreign_key_checks=0;
SET @OLD_UNIQUE_CHECKS=@@unique_checks; SET unique_checks=0;

-- Drop old versions (if any)
DROP PROCEDURE IF EXISTS sp_post_callback_fix;
DROP PROCEDURE IF EXISTS sp_pipeline_restart;

DELIMITER //

-- =========================================================
-- 1) sp_post_callback_fix
--    Auto-repair to run at the END of each callback(symbol, tf)
--    Args:
--      p_symbol           VARCHAR(32)
--      p_tf               VARCHAR(8)
--      p_has_open_order   TINYINT(1)  -- 1 if an open order exists, else 0
--      p_has_open_position TINYINT(1) -- 1 if an open position exists, else 0
-- =========================================================
CREATE PROCEDURE sp_post_callback_fix(
    IN p_symbol VARCHAR(32),
    IN p_tf VARCHAR(8),
    IN p_has_open_order TINYINT,
    IN p_has_open_position TINYINT
)
BEGIN
    DECLARE db_collation VARCHAR(255);
    SELECT DATABASE() INTO @db_name;
    SELECT CCSA.COLLATION_NAME INTO db_collation
    FROM INFORMATION_SCHEMA.SCHEMATA S
    JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
      ON S.DEFAULT_COLLATION_NAME = CCSA.COLLATION_NAME
    WHERE S.SCHEMA_NAME = @db_name;

    SET @now_utc = UTC_TIMESTAMP();
    SET @p_symbol = p_symbol;
    SET @p_tf = p_tf;
    SET @p_has_open_order = p_has_open_order;
    SET @p_has_open_position = p_has_open_position;

    -- 1) Reactivate expired cooldown (for this symbol only)
    SET @s = CONCAT('UPDATE tf_eligibility SET status=''ACTIVE'', priority=GREATEST(priority, 100), cooldown_until=NULL, reason=''auto_fix_cooldown_expired'', updated_at=? WHERE symbol = ? COLLATE ', db_collation, ' AND (status=''COOLDOWN'' AND (cooldown_until IS NULL OR cooldown_until <= ?))');
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @now_utc, @p_symbol, @now_utc;
    DEALLOCATE PREPARE stmt;

    -- 2) Unlock order/position if flags say none is open
    SET @s = CONCAT('UPDATE tf_eligibility SET status=''ACTIVE'', priority=GREATEST(priority,100), cooldown_until=NULL, reason=''auto_fix_unlock_order'', updated_at=? WHERE symbol= ? COLLATE ', db_collation, ' AND status=''LOCKED_ORDER'' AND ? = 0');
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @now_utc, @p_symbol, @p_has_open_order;
    DEALLOCATE PREPARE stmt;

    SET @s = CONCAT('UPDATE tf_eligibility SET status=''ACTIVE'', priority=GREATEST(priority,100), cooldown_until=NULL, reason=''auto_fix_unlock_position'', updated_at=? WHERE symbol= ? COLLATE ', db_collation, ' AND status=''LOCKED_POSITION'' AND ? = 0');
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @now_utc, @p_symbol, @p_has_open_position;
    DEALLOCATE PREPARE stmt;

    -- 3) Sync retry counter with latest signal fact for this TF
    SET @s = CONCAT('WITH last_evt AS (SELECT passed FROM signal_events WHERE symbol = ? COLLATE ', db_collation, ' AND tf = ? COLLATE ', db_collation, ' ORDER BY at_utc DESC LIMIT 1) UPDATE tf_retry_status r JOIN last_evt e ON 1=1 SET r.retry_count = IF(e.passed=1, 0, r.retry_count), r.last_result = IF(e.passed=1, ''SUCCESS'', r.last_result), r.updated_at  = ? WHERE r.symbol = ? COLLATE ', db_collation, ' AND r.tf = ? COLLATE ', db_collation);
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @p_symbol, @p_tf, @now_utc, @p_symbol, @p_tf;
    DEALLOCATE PREPARE stmt;

    -- 4) Anti late-write safety: re-project most recent event on latest_signal_by_tf
    SET @s = CONCAT('REPLACE INTO latest_signal_by_tf(symbol, tf, slot_start_utc, at_utc, side, passed, score, meta_json) SELECT se.symbol, se.tf, se.slot_start_utc, se.at_utc, se.side, se.passed, se.score, se.meta_json FROM signal_events se WHERE se.symbol= ? COLLATE ', db_collation, ' AND se.tf= ? COLLATE ', db_collation, ' ORDER BY se.at_utc DESC LIMIT 1');
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @p_symbol, @p_tf;
    DEALLOCATE PREPARE stmt;

    -- 5) Drain pending child signals when parent is fresh
    SET @parent_tf = (
      CASE p_tf
        WHEN '1m'  THEN '15m'
        WHEN '5m'  THEN '15m'
        WHEN '15m' THEN '1h'
        WHEN '1h'  THEN '4h'
        ELSE NULL
      END
    );

    IF @parent_tf IS NOT NULL THEN
        -- If parent has any row, consider it fresh enough to drain this child’s pendings
        SET @s = CONCAT('DELETE pcs FROM pending_child_signals pcs JOIN latest_signal_by_tf p ON p.symbol = pcs.symbol AND p.tf = ? WHERE pcs.symbol = ? COLLATE ', db_collation, ' AND pcs.tf = ? COLLATE ', db_collation);
        PREPARE stmt FROM @s;
        EXECUTE stmt USING @parent_tf, @p_symbol, @p_tf;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- 6) Normalize priority bounds and impossible ACTIVE+cooldown state
    SET @s = CONCAT('UPDATE tf_eligibility SET priority = LEAST(GREATEST(priority, 0), 1000) WHERE symbol = ? COLLATE ', db_collation);
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @p_symbol;
    DEALLOCATE PREPARE stmt;

    SET @s = CONCAT('UPDATE tf_eligibility SET status=''COOLDOWN'', reason=''auto_fix_cooldown_guard'', updated_at=? WHERE symbol= ? COLLATE ', db_collation, ' AND status=''ACTIVE'' AND cooldown_until IS NOT NULL AND cooldown_until > ?');
    PREPARE stmt FROM @s;
    EXECUTE stmt USING @now_utc, @p_symbol, @now_utc;
    DEALLOCATE PREPARE stmt;

    -- 7) (Optional) mark dispatch completed if you store a dispatch log
    -- UPDATE refresh_dispatch_log d
    -- JOIN signal_events e
    --   ON e.symbol=d.symbol AND e.tf=d.tf AND e.slot_start_utc=d.slot_start_utc
    -- SET d.completed_at = @now_utc
    -- WHERE d.symbol = p_symbol AND d.tf = p_tf
    --   AND d.completed_at IS NULL;

END//
-- End sp_post_callback_fix

-- =========================================================
-- 2) sp_pipeline_restart
--    To run after system startup to clean & re-arm pipeline
-- =========================================================
CREATE PROCEDURE sp_pipeline_restart()
BEGIN
    SET @now_utc = UTC_TIMESTAMP();

    -- A) Reactivate expired cooldowns
    UPDATE tf_eligibility
    SET status = 'ACTIVE',
        priority = GREATEST(priority, 100),
        cooldown_until = NULL,
        reason = 'restart_cooldown_expired',
        updated_at = @now_utc
    WHERE status = 'COOLDOWN'
      AND (cooldown_until IS NULL OR cooldown_until <= @now_utc);

    -- B) Unlock stale locks (order/position) older than 4 hours
    UPDATE tf_eligibility
    SET status = 'ACTIVE',
        priority = GREATEST(priority, 100),
        cooldown_until = NULL,
        reason = 'restart_unlock_stale_lock',
        updated_at = @now_utc
    WHERE status IN ('LOCKED_ORDER', 'LOCKED_POSITION')
      AND updated_at < @now_utc - INTERVAL 4 HOUR;

    -- C) Purge very old pending child signals (older than 12 hours)
    DELETE FROM pending_child_signals
    WHERE created_at < @now_utc - INTERVAL 12 HOUR;

    -- D) (Optional) Reset very old FAILED retries (older than 2 hours)
    UPDATE tf_retry_status
    SET retry_count = 0, last_result = 'NONE', updated_at = @now_utc
    WHERE last_result = 'FAILED'
      AND updated_at < @now_utc - INTERVAL 2 HOUR;

    -- E) No direct restart of callbacks here (depends on your scheduler),
    --     but you can select ACTIVE TFs and trigger refresh in your app:
    -- SELECT DISTINCT tf FROM tf_eligibility WHERE status='ACTIVE';
END//
-- End sp_pipeline_restart

DELIMITER ;

-- Restore safety switches
SET foreign_key_checks=@OLD_FK_CHECKS;
SET unique_checks=@OLD_UNIQUE_CHECKS;
SET sql_notes=@OLD_SQL_NOTES;

-- =====================
-- Usage examples
-- =====================
-- 1) End-of-callback auto-fix (symbol 'BTCUSDT', TF '15m', no open order, open position yes):
-- CALL sp_post_callback_fix('BTCUSDT', '15m', 0, 1);
--
-- 2) On system restart:
-- CALL sp_pipeline_restart();
