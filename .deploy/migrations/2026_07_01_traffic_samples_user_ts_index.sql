-- Adds a (username, ts) index to tbl_traffic_samples.
-- Speeds up the per-username MAX(ts) "last seen" lookup used by the customer
-- list's "Sort: Last usage" option and Last Seen column.
-- Idempotent: the ADD INDEX only runs when the index is absent.

SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_traffic_samples'
               AND INDEX_NAME = 'idx_user_ts');
SET @sql := IF(@idx = 0,
    "ALTER TABLE `tbl_traffic_samples` ADD INDEX `idx_user_ts` (`username`, `ts`)",
    "DO 0");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
