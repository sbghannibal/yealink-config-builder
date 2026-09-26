<?php
session_start();
require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/includes/rbac.php';

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
                <a class="btn" href="/admin/settings.php">✏️ Bewerk dashboard-tekst</a>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top:20px;">
            <div class="card">
                <h3>📱 Devices</h3>
                <p>Beheer je Yealink devices</p>
                <a href="devices/list.php" class="btn">Naar Devices</a>
            </div>

            <div class="card">
                <h3>⚙️ Config Builder</h3>
                <p>Bouw en beheer configuraties</p>
                <a href="settings/builder.php" class="btn">Config Builder</a>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Yealink Config Builder. All rights reserved.</p>
    </footer>
</body>
</html>
