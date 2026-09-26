CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(64) NOT NULL,
  `name` VARCHAR(64) DEFAULT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_roles_role_name` (`role_name`),
  KEY `idx_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_roles` (
  `admin_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  PRIMARY KEY (`admin_id`, `role_id`),
  KEY `idx_admin_roles_role_id` (`role_id`),
  FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission` VARCHAR(128) NOT NULL,
  UNIQUE KEY `unique_role_perm` (`role_id`, `permission`),
  KEY `idx_role_permissions_role_id` (`role_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `roles` (`role_name`, `name`, `description`) VALUES
('super_admin', 'super_admin', 'Volledige beheerdersrechten'),
('admin', 'admin', 'Admin rechten'),
('editor', 'editor', 'Kan variabelen en mappings bewerken'),
('viewer', 'viewer', 'Alleen-lezen toegang');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.users.create' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.users.edit' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.users.delete' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.roles.manage' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.backup.create' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.backup.restore' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.tokens.generate' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'pabx.manage' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'devices.manage' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'variables.manage' FROM roles WHERE role_name = 'super_admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'mappings.manage' FROM roles WHERE role_name = 'super_admin';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'pabx.manage' FROM roles WHERE role_name = 'admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'devices.manage' FROM roles WHERE role_name = 'admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'variables.manage' FROM roles WHERE role_name = 'admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'mappings.manage' FROM roles WHERE role_name = 'admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'admin';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.tokens.generate' FROM roles WHERE role_name = 'admin';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'variables.manage' FROM roles WHERE role_name = 'editor';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'mappings.manage' FROM roles WHERE role_name = 'editor';
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'editor';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'viewer';
