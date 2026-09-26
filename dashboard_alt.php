<?php
session_start();
require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/i18n.php';

// Fetch settings helper
function get_setting($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        error_log('Error loading setting ' . $key . ': ' . $e->getMessage());
        return $default;
    }
}

// If logged in get admin info
$logged_in = isset($_SESSION['admin_id']);
$admin_id = $logged_in ? (int) $_SESSION['admin_id'] : null;

// Load dashboard settings (fallbacks)
$dashboard_title = get_setting($pdo, 'dashboard_title', 'Welkom bij Yealink Config Builder');
$dashboard_text  = get_setting($pdo, 'dashboard_text', "Gebruik het menu om devices en configuraties te beheren.\n\nJe kunt deze tekst aanpassen via Admin → Instellingen.");

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dashboard_title); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="navbar">
                <h1>🔧 Yealink Config Builder</h1>
                <nav>
                    <a href="index.php">Dashboard</a>
                    <a href="admin/dashboard.php">Admin</a>
                    <a href="devices/list.php">Devices</a>
                    <a href="settings/builder.php">Config Builder</a>
                    <?php if ($logged_in): ?>
                        <a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>)</a>
                    <?php else: ?>
                        <a href="login.php">Login</a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>

    <main class="container">
        <h2><?php echo htmlspecialchars($dashboard_title); ?></h2>

        <div class="card">
            <?php
            // Render the dashboard text: preserve line breaks, escape HTML
            echo nl2br(htmlspecialchars($dashboard_text));
            ?>
        </div>

        <?php if ($logged_in && has_permission($pdo, $admin_id, 'admin.settings.edit')): ?>
            <div style="margin-top:12px;">
                <a class="btn" href="/admin/settings.php">✏️ <?php echo __('label.edit_dashboard_text'); ?></a>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top:20px;">
            <div class="card">
                <h3>📱 Devices</h3>
                <p><?php echo __('label.manage_devices'); ?></p>
                <a href="devices/list.php" class="btn"><?php echo __('label.to_devices'); ?></a>
            </div>

            <div class="card">
                <h3>⚙️ <?php echo __('label.config_builder'); ?></h3>
                <p><?php echo __('label.build_manage_configs'); ?></p>
                <a href="settings/builder.php" class="btn"><?php echo __('label.config_builder'); ?></a>
            </div>
        </div>
    </main>

    <footer style="background: linear-gradient(135deg, #5d00b8 0%, #764ba2 100%); color: rgba(255,255,255,0.85); padding: 14px 24px; font-size: 13px; margin-top: 40px;">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <span>&#9742;&#65039; Yealink Config Builder</span>
            <span>&copy; <?php echo date('Y') > 2026 ? '2026 &ndash; ' . date('Y') : '2026'; ?> &mdash; Alle rechten voorbehouden</span>
        </div>
    </footer>
</body>
</html>
