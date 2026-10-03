CREATE TABLE IF NOT EXISTS config_version_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    config_version_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    activated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_by INT,
    deactivated_at TIMESTAMP NULL DEFAULT NULL,
    duration_minutes INT,
    notes VARCHAR(500),
    INDEX idx_device (device_id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (config_version_id) REFERENCES config_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (activated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
