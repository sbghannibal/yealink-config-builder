CREATE TABLE IF NOT EXISTS massupdate_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mac_address CHAR(12) NOT NULL,
    device_model VARCHAR(64) NOT NULL,
    firmware_old VARCHAR(64) NULL,
    firmware_new VARCHAR(64) NULL,
    campaign_id INT NULL,
    status VARCHAR(32) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(1024) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_massupdate_log_created_at (created_at),
    INDEX idx_massupdate_log_mac_address (mac_address),
    FOREIGN KEY (campaign_id) REFERENCES massupdate_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value)
VALUES ('massupdate_log_retention_days', '30')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
