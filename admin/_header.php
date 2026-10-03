<?php
/**
 * Reusable header with dynamic navigation based on permissions
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/massupdate_access.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

// Ensure language is initialized (session -> DB -> default)
try {
    $_SESSION['language'] = get_user_language($pdo, $admin_id);
} catch (Exception $e) {
    // keep existing session language or default
    if (empty($_SESSION['language'])) {
        $_SESSION['language'] = 'nl';
    }
}

// Determine current page
$current_page = basename($_SERVER['PHP_SELF']);

// Get admin info
$stmt = $pdo->prepare('SELECT username, email, is_active FROM admins WHERE id = ?');
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's role(s) for badge
$stmt = $pdo->prepare('
    SELECT r.role_name
    FROM admin_roles ar
    JOIN roles r ON r.id = ar.role_id
    WHERE ar.admin_id = ?
');
$stmt->execute([$admin_id]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
$user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : __('page.dashboard.no_role');

// Determine role badge color
$role_badge_color = '#6c757d'; // default gray
if (in_array('Owner', $user_roles)) {
    $role_badge_color = '#dc3545'; // red for owner
} elseif (in_array('Expert', $user_roles)) {
    $role_badge_color = '#28a745'; // green for expert
} elseif (in_array('Tech', $user_roles)) {
    $role_badge_color = '#007bff'; // blue for tech
}

// Get admin permissions for easier checking
$permissions = get_admin_permissions($pdo, $admin_id);
$permission_map = array_flip($permissions);

// Helper function to check permission
function can_access($permission, $permission_map) {
    return isset($permission_map[$permission]);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'nl'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?>Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
        }

        header {
            background: linear-gradient(135deg, #5d00b8 0%, #764ba2 100%);
            color: white;
            padding: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }

        .header-brand:hover {
            opacity: 0.9;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-user {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }

        .header-user-info {
            text-align: right;
        }

        .header-user strong {
            display: block;
            font-weight: 600;
            font-size: 14px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 2px;
            white-space: nowrap;
        }

        .badge-base {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .badge-active {
            background: rgba(40, 167, 69, 0.3) !important;
            border-color: rgba(40, 167, 69, 0.5) !important;
        }

        .badge-inactive {
            background: rgba(220, 53, 69, 0.3) !important;
            border-color: rgba(220, 53, 69, 0.5) !important;
        }

        .header-logout {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }

        .header-logout:hover {
            background: rgba(255,255,255,0.3);
        }

        .lang-selector {
            position: relative;
        }

        .lang-selector select {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }

        .lang-selector select:hover {
            background: rgba(255,255,255,0.3);
        }

        .lang-selector select option {
            background: #5a3d8a;
            color: white;
        }

        nav {
            display: flex;
            gap: 0;
            flex-wrap: wrap;
            padding: 0 24px;
            align-items: center;
        }

        nav a, nav button {
            padding: 12px 16px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
            position: relative;
        }

        nav a:hover, nav button:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        nav a.active, nav button.active {
            background: rgba(0,0,0,0.2);
            color: white;
            border-bottom: 3px solid white;
        }

        .nav-dropdown {
            position: relative;
            display: inline-block;
        }

        .nav-dropdown > a {
            cursor: pointer;
        }

        .dropdown-arrow {
            font-size: 10px;
            transition: transform 0.2s;
        }

        .nav-dropdown:hover .dropdown-arrow {
            transform: rotate(180deg);
        }

        .nav-dropdown-content {
            display: none;
            position: absolute;
            background: rgba(50, 50, 80, 0.95);
            min-width: 220px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1001;
            border-radius: 4px;
            top: 100%;
            left: 0;
            margin-top: 0;
            backdrop-filter: blur(10px);
        }

        .nav-dropdown:hover .nav-dropdown-content {
            display: block;
        }

        .nav-dropdown-content a {
            display: block;
            padding: 12px 16px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border: none;
            white-space: normal;
            border-radius: 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .nav-dropdown-content a:last-child {
            border-bottom: none;
        }

        .nav-dropdown-content a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-dropdown-content a.active {
            background: rgba(102, 126, 234, 0.3);
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        @media (max-width: 768px) {
            nav {
                padding: 0 12px;
            }

            nav a, nav button {
                padding: 10px 12px;
                font-size: 12px;
            }

            .nav-dropdown-content {
                position: static;
                display: none;
                background: rgba(0,0,0,0.3);
                box-shadow: none;
                border-radius: 0;
            }

            .nav-dropdown:hover .nav-dropdown-content {
                display: block;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="header-top">
        <a href="/admin/dashboard.php" class="header-brand">
            📱 Yealink Config
        </a>
        <div class="header-right">
            <div class="header-user">
                <div class="header-user-info">
                    <strong><?php echo htmlspecialchars($admin['username'] ?? 'Admin'); ?></strong>
                    <!-- Status badge -->
                    <?php if ($admin['is_active']): ?>
                        <span class="badge badge-base badge-active">✅ <?php echo __('status.active'); ?></span>
                    <?php else: ?>
                        <span class="badge badge-base badge-inactive">⏸️ <?php echo __('status.inactive'); ?></span>
                    <?php endif; ?>
                    <!-- Role badge -->
                    <span class="badge badge-base" style="background: <?php echo $role_badge_color; ?>; border-color: <?php echo $role_badge_color; ?>40;">
                        <?php echo htmlspecialchars($user_role_display); ?>
                    </span>
                </div>
            </div>
            <div class="lang-selector">
                <select onchange="window.location.href='/admin/set_language.php?lang=' + this.value;" title="Taal / Language">
                    <option value="nl" <?php echo ($_SESSION['language'] ?? 'nl') === 'nl' ? 'selected' : ''; ?>>🇳🇱 NL</option>
                    <option value="fr" <?php echo ($_SESSION['language'] ?? 'nl') === 'fr' ? 'selected' : ''; ?>>🇫🇷 FR</option>
                    <option value="en" <?php echo ($_SESSION['language'] ?? 'nl') === 'en' ? 'selected' : ''; ?>>🇺🇸 ENG</option>
                </select>
            </div>
            <form method="POST" action="/logout.php" style="display: inline;">
                <button type="submit" class="header-logout"><?php echo __('button.logout'); ?></button>
            </form>
        </div>
    </div>

    <nav>
        <a href="/admin/dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            📊 <?php echo __('nav.dashboard'); ?>
        </a>

        <!-- DEVICES -->
        <a href="/devices/list.php" class="<?php echo $current_page === 'list.php' ? 'active' : ''; ?>">
            📱 <?php echo __('nav.devices'); ?>
        </a>

        <?php if (can_access('devices.view', $permission_map)): ?>
        <a href="/admin/device_info.php" class="<?php echo $current_page === 'device_info.php' ? 'active' : ''; ?>">
            ℹ️ <?php echo __('nav.device_info'); ?>
        </a>
        <?php endif; ?>

        <!-- RESTORE DELETED DEVICES (Owner/Expert only) -->
        <?php if (massupdate_owner_allowed($pdo, $admin_id)): ?>
        <a href="/admin/massupdate.php" class="<?php echo $current_page === 'massupdate.php' ? 'active' : ''; ?>">
            <?php echo __('nav.massupdate'); ?>
        </a>
        <?php endif; ?>

        <?php if (has_permission($pdo, $admin_id, 'devices.restore')): ?>
        <a href="/admin/devices_restore.php" class="<?php echo $current_page === 'devices_restore.php' ? 'active' : ''; ?>">
    ♻️ <?php echo __('nav.restore_devices'); ?>
        </a>
        <?php endif; ?>

        <!-- CONFIG DROPDOWN -->
        <?php if (can_access('admin.device_types.manage', $permission_map) || can_access('config.manage', $permission_map) || can_access('admin.templates.manage', $permission_map) || can_access('admin.variables.manage', $permission_map) || can_access('variables.manage', $permission_map)): ?>
        <div class="nav-dropdown">
            <a class="<?php echo in_array($current_page, ['device_types.php', 'device_types_edit.php', 'builder.php', 'device_mapping.php', 'templates.php', 'template_variables.php', 'config_cleanup.php', 'bulk_find_replace.php', 'variables.php', 'copy_template_variables.php']) ? 'active' : ''; ?>">
                ⚙️ <?php echo __('nav.config'); ?> <span class="dropdown-arrow">▼</span>
            </a>
            <div class="nav-dropdown-content">
                <?php if (can_access('admin.device_types.manage', $permission_map)): ?>
                <a href="/admin/device_types.php" class="<?php echo in_array($current_page, ['device_types.php', 'device_types_edit.php']) ? 'active' : ''; ?>">
                    📦 <?php echo __('nav.device_types'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('config.manage', $permission_map)): ?>
                <a href="/settings/builder.php" class="<?php echo $current_page === 'builder.php' ? 'active' : ''; ?>">
                    🛠️ <?php echo __('nav.config_builder'); ?>
                </a>
                <a href="/settings/device_mapping.php" class="<?php echo $current_page === 'device_mapping.php' ? 'active' : ''; ?>">
                    🔗 <?php echo __('nav.device_mapping'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.templates.manage', $permission_map)): ?>
                <a href="/admin/templates.php" class="<?php echo $current_page === 'templates.php' ? 'active' : ''; ?>">
                    📋 <?php echo __('nav.templates'); ?>
                </a>
                <a href="/admin/copy_template_variables.php" class="<?php echo $current_page === 'copy_template_variables.php' ? 'active' : ''; ?>">
                    📋 <?php echo __('nav.copy_variables'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.variables.manage', $permission_map) || can_access('variables.manage', $permission_map)): ?>
                <a href="/admin/variables.php" class="<?php echo $current_page === 'variables.php' ? 'active' : ''; ?>">
                    🌍 <?php echo __('nav.global_variables'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('config.cleanup', $permission_map)): ?>
                <a href="/admin/config_cleanup.php" class="<?php echo $current_page === 'config_cleanup.php' ? 'active' : ''; ?>">
                    🗑️ <?php echo __('nav.config_cleanup'); ?>
                </a>
                <?php endif; ?>

                <?php if (in_array('Expert', $user_roles) || in_array('Owner', $user_roles)): ?>
                <a href="/admin/bulk_find_replace.php" class="<?php echo $current_page === 'bulk_find_replace.php' ? 'active' : ''; ?>">
                    🔍 <?php echo __('nav.bulk_find_replace'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ADMIN DROPDOWN -->
        <?php if (can_access('admin.users.view', $permission_map) || can_access('admin.roles.manage', $permission_map) || can_access('admin.settings.edit', $permission_map) || can_access('admin.audit.view', $permission_map) || can_access('customers.view', $permission_map)): ?>
        <div class="nav-dropdown">
            <a class="<?php echo in_array($current_page, ['users.php', 'users_create.php', 'users_edit.php', 'users_delete.php', 'roles.php', 'roles_edit.php', 'roles_delete.php', 'customers.php', 'customers_add.php', 'customers_edit.php', 'customers_delete.php', 'settings.php', 'audit.php', 'provision_attempts.php']) ? 'active' : ''; ?>">
                👥 <?php echo __('nav.admin'); ?> <span class="dropdown-arrow">▼</span>
            </a>
            <div class="nav-dropdown-content">
                <?php if (can_access('admin.users.view', $permission_map)): ?>
                <a href="/admin/users.php" class="<?php echo in_array($current_page, ['users.php', 'users_create.php', 'users_edit.php', 'users_delete.php']) ? 'active' : ''; ?>">
                    👥 <?php echo __('nav.users'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.roles.manage', $permission_map)): ?>
                <a href="/admin/roles.php" class="<?php echo in_array($current_page, ['roles.php', 'roles_edit.php', 'roles_delete.php']) ? 'active' : ''; ?>">
                    🔐 <?php echo __('nav.roles_permissions'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('customers.view', $permission_map)): ?>
                <a href="/admin/customers.php" class="<?php echo in_array($current_page, ['customers.php', 'customers_add.php', 'customers_edit.php', 'customers_delete.php']) ? 'active' : ''; ?>">
                    🏢 <?php echo __('nav.customers'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.settings.edit', $permission_map)): ?>
                <a href="/admin/settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                    ⚙️ <?php echo __('nav.settings'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.audit.view', $permission_map)): ?>
                <a href="/admin/audit.php" class="<?php echo $current_page === 'audit.php' ? 'active' : ''; ?>">
                    📑 <?php echo __('nav.audit_logs'); ?>
                </a>
                <a href="/admin/provision_attempts.php" class="<?php echo $current_page === 'provision_attempts.php' ? 'active' : ''; ?>">
                    📡 <?php echo __('nav.provision_attempts'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- PARTNERS (Owner or partners.manage) -->
        <?php if (in_array('Owner', $user_roles) || can_access('partners.manage', $permission_map)): ?>
        <div class="nav-dropdown">
            <a class="<?php echo in_array($current_page, ['partners.php', 'partner_rights.php']) ? 'active' : ''; ?>">
                🤝 <?php echo __('nav.partners'); ?> <span class="dropdown-arrow">▼</span>
            </a>
            <div class="nav-dropdown-content">
                <a href="/admin/partners.php" class="<?php echo $current_page === 'partners.php' ? 'active' : ''; ?>">
                    🏢 <?php echo __('nav.partner_companies'); ?>
                </a>
                <a href="/admin/partner_rights.php" class="<?php echo $current_page === 'partner_rights.php' ? 'active' : ''; ?>">
                    🔑 <?php echo __('nav.partner_rights'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACCOUNT REQUESTS (Owner only) -->
        <?php if (can_access('admin.accounts.manage', $permission_map)): ?>
        <a href="/admin/approve_account.php" class="<?php echo $current_page === 'approve_account.php' ? 'active' : ''; ?>">
            📧 <?php echo __('nav.account_requests'); ?>
        </a>
        <?php endif; ?>

        <!-- SECURITY & CREDENTIALS (Owner only) -->
        <?php if (can_access('admin.tokens.manage', $permission_map) || can_access('admin.settings.edit', $permission_map)): ?>
        <div class="nav-dropdown">
            <a class="<?php echo in_array($current_page, ['tokens.php', 'staging_certificates.php', 'staging_credentials.php']) ? 'active' : ''; ?>" style="color: rgba(255, 200, 200, 0.9);">
                🔒 <?php echo __('nav.security'); ?> <span class="dropdown-arrow">▼</span>
            </a>
            <div class="nav-dropdown-content">
                <?php if (can_access('admin.tokens.manage', $permission_map)): ?>
                <a href="/admin/tokens.php" class="<?php echo $current_page === 'tokens.php' ? 'active' : ''; ?>">
                    🔑 <?php echo __('nav.api_tokens'); ?>
                </a>
                <?php endif; ?>

                <?php if (can_access('admin.settings.edit', $permission_map)): ?>
                <a href="/admin/staging_certificates.php" class="<?php echo $current_page === 'staging_certificates.php' ? 'active' : ''; ?>">
                    📜 <?php echo __('nav.staging_certs'); ?>
                </a>
                <a href="/admin/staging_credentials.php" class="<?php echo $current_page === 'staging_credentials.php' ? 'active' : ''; ?>">
                    🔐 <?php echo __('nav.staging_credentials'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
