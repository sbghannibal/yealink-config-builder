-- Add active-flag support to device_config_assignments
ALTER TABLE device_config_assignments
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_at,
    ADD COLUMN IF NOT EXISTS activated_at TIMESTAMP NULL DEFAULT NULL AFTER is_active;

-- Speeds up the "active config per device" lookups
CREATE INDEX IF NOT EXISTS idx_device_active ON device_config_assignments (device_id, is_active);

-- Backfill: mark the most recent assignment of each device as active
UPDATE device_config_assignments dca
JOIN (
    SELECT device_id, MAX(id) AS max_id
    FROM device_config_assignments
    GROUP BY device_id
) latest ON latest.max_id = dca.id
SET dca.is_active = 1,
    dca.activated_at = COALESCE(dca.activated_at, dca.assigned_at);
