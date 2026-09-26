-- Add active-flag support to device_config_assignments.
-- Uses INFORMATION_SCHEMA checks for compatibility with MySQL 5.7 and MariaDB.

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'device_config_assignments'
      AND COLUMN_NAME = 'is_active'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE device_config_assignments ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_at',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'device_config_assignments'
      AND COLUMN_NAME = 'activated_at'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE device_config_assignments ADD COLUMN activated_at TIMESTAMP NULL DEFAULT NULL AFTER is_active',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'device_config_assignments'
      AND INDEX_NAME = 'idx_device_active'
);
SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_device_active ON device_config_assignments (device_id, is_active)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Backfill: mark the most recent assignment of each device as active.
UPDATE device_config_assignments dca
JOIN (
    SELECT device_id, MAX(id) AS max_id
    FROM device_config_assignments
    GROUP BY device_id
) latest ON latest.max_id = dca.id
SET dca.is_active = 1,
    dca.activated_at = COALESCE(dca.activated_at, dca.assigned_at);
