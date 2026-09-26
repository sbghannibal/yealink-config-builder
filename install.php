<?php
session_start();

require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/seed.php';
require_once __DIR__ . '/includes/rbac.php';

if (file_exists(__DIR__ . '/settings/validator.php')) {
    require_once __DIR__ . '/settings/validator.php';
}

function load_install_env(string $envFile): void
{
    if (!file_exists($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        if (strpos($line, '=') === false || strpos(ltrim($line), '#') === 0) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

function create_install_pdo(): PDO
{
    load_install_env(__DIR__ . '/.env');

    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'admin_yealink';
    $dbUser = getenv('DB_USER') ?: 'admin_yealink';
    $dbPass = getenv('DB_PASS') ?: '';

    return new PDO(
        'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['setting_value'] : $default;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return $default;
        }
        throw $e;
    }
}

function get_admin_count(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return 0;
        }
        throw $e;
    }
}

function is_install_locked(PDO $pdo): bool
{
    $installedSetting = get_setting($pdo, 'installed_at', '') !== '';
    $installedFile = file_exists(__DIR__ . '/settings/.installed');
    return $installedSetting || $installedFile;
}

function set_install_lock(PDO $pdo): void
{
    $timestamp = date('Y-m-d H:i:s');
    $settingWritten = false;
    $fileWritten = false;

    try {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['installed_at', $timestamp]);
        $settingWritten = true;
    } catch (Throwable $e) {
        error_log('install.php failed to write installed_at setting: ' . $e->getMessage());
    }

    $bytes = @file_put_contents(__DIR__ . '/settings/.installed', $timestamp . PHP_EOL, LOCK_EX);
    if ($bytes !== false) {
        $fileWritten = true;
    }

    if (!$settingWritten && !$fileWritten) {
        throw new RuntimeException('Could not persist installation lock marker.');
    }
}

function validate_install_password_input(string $password): array
{
    if (function_exists('validate_password')) {
        $result = validate_password($password, ['min_value' => 8]);
        if (is_array($result) && isset($result['valid']) && !$result['valid']) {
            return [false, (string) ($result['error'] ?? 'Password is invalid.')];
        }
    }

    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }

    return [true, ''];
}

$pdo = null;
$connectionError = '';

try {
    $pdo = create_install_pdo();
} catch (Throwable $e) {
    $connectionError = 'Database connection failed. Please check your .env values and settings/database.php configuration.';
    error_log('install.php connection error: ' . $e->getMessage());
}

$migrationResults = [];
$seedResult = null;
$deviceTypeSeedResult = null;
$formError = '';
$adminCreated = false;
$adminExists = false;
$locked = false;
$runMigrationsAllowed = false;
$migrationFailures = false;
$maskedPost = [
    'username' => '',
    'email' => '',
];

if ($pdo instanceof PDO) {
    $locked = is_install_locked($pdo);
    $adminExists = get_admin_count($pdo) > 0;
    $csrf = $_SESSION['csrf_token'] ?? '';
    if ($csrf === '') {
        $csrf = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrf;
    }

    $currentAdminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
    if ($currentAdminId > 0) {
        try {
            $runMigrationsAllowed = has_permission($pdo, $currentAdminId, 'admin.settings.edit')
                && $_SERVER['REQUEST_METHOD'] === 'POST'
                && (($_POST['action'] ?? '') === 'run_migrations')
                && hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''));
        } catch (Throwable $e) {
            $runMigrationsAllowed = false;
        }
    }

    if ($adminExists && !$runMigrationsAllowed) {
        if (isset($_SESSION['admin_id'])) {
            header('Location: index.php');
        } else {
            header('Location: login.php');
        }
        exit;
    }

    try {
        $migrationResults = run_pending_migrations($pdo, __DIR__ . '/migrations');
    } catch (Throwable $e) {
        $migrationResults[] = [
            'filename' => 'migration_runner',
            'status' => 'failed',
            'statements' => 0,
            'errors' => [$e->getMessage()],
        ];
    }

    foreach ($migrationResults as $migrationResult) {
        if (($migrationResult['status'] ?? '') === 'failed') {
            $migrationFailures = true;
            break;
        }
    }

    try {
        $deviceTypeSeedResult = seed_default_device_types($pdo);
    } catch (Throwable $e) {
        $deviceTypeSeedResult = ['status' => 'failed', 'error' => $e->getMessage(), 'seeded' => 0, 'total' => 0];
    }

    if (!$adminExists && !$migrationFailures && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $maskedPost['username'] = $username;
        $maskedPost['email'] = $email;

        if (!hash_equals($csrf, $postedToken)) {
            $formError = 'Invalid CSRF token.';
        } elseif ($username === '' || $email === '' || $password === '') {
            $formError = 'Username, email and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Please provide a valid email address.';
        } elseif ($password !== $passwordConfirm) {
            $formError = 'Password confirmation does not match.';
        } else {
            [$validPassword, $passwordError] = validate_install_password_input($password);
            if (!$validPassword) {
                $formError = $passwordError;
            }
        }

        if ($formError === '') {
            try {
                $seedResult = seed_default_admin($pdo, [
                    'username' => $username,
                    'email' => $email,
                    'password' => $password,
                ]);
                $adminCreated = ($seedResult['status'] ?? '') === 'admin_created';
                $adminExists = get_admin_count($pdo) > 0;
                if ($adminCreated && $adminExists) {
                    set_install_lock($pdo);
                    $locked = true;
                }
            } catch (Throwable $e) {
                $formError = 'Could not create admin user.';
                $seedResult = ['status' => 'failed', 'message' => $e->getMessage()];
            }
        }
    } elseif (!$adminExists && !$migrationFailures && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $seedResult = ['status' => 'awaiting_admin_form', 'message' => 'Create your first admin account to complete installation.'];
    } elseif ($adminExists) {
        $seedResult = [
            'status' => 'admin_already_exists',
            'message' => 'An existing admin account was detected. First-admin bootstrap is disabled.',
        ];
    }

}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Yealink Config Builder</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<main class="container" style="max-width: 960px; margin-top: 24px; margin-bottom: 24px;">
    <h1>Install status</h1>

    <?php if ($connectionError !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($connectionError, ENT_QUOTES, 'UTF-8'); ?></div>
        <p>Please verify your database credentials in <code>.env</code> and <code>settings/database.php</code>.</p>
    <?php else: ?>
        <div class="card" style="margin-bottom: 16px;">
            <h2>Migrations</h2>
            <?php if (empty($migrationResults)): ?>
                <p>No migration files found.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                    <tr>
                        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Migration</th>
                        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Status</th>
                        <th style="text-align:right; border-bottom:1px solid #ddd; padding:8px;">Statements</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($migrationResults as $migrationResult): ?>
                        <tr>
                            <td style="padding:8px; border-bottom:1px solid #eee;"><?php echo htmlspecialchars((string) ($migrationResult['filename'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #eee;"><?php echo htmlspecialchars((string) ($migrationResult['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #eee; text-align:right;"><?php echo (int) ($migrationResult['statements'] ?? 0); ?></td>
                        </tr>
                        <?php if (!empty($migrationResult['errors']) && !$locked): ?>
                            <tr>
                                <td colspan="3" style="padding:8px; border-bottom:1px solid #eee; color:#a30000;">
                                    <?php foreach ($migrationResult['errors'] as $error): ?>
                                        <div><?php echo htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-bottom: 16px;">
            <h2>Seeding</h2>
            <p>
                Device types: <?php echo htmlspecialchars((string) ($deviceTypeSeedResult['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo (int) ($deviceTypeSeedResult['seeded'] ?? 0); ?>/<?php echo (int) ($deviceTypeSeedResult['total'] ?? 0); ?> new)
            </p>

            <?php if ($formError !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($migrationFailures): ?>
                <p>Admin creation is disabled until migration errors are fixed.</p>
            <?php elseif ($adminExists): ?>
                <p>Admin status: <?php echo htmlspecialchars((string) ($seedResult['message'] ?? 'admin already exists'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <p>Create the first admin account:</p>
                <form method="post" autocomplete="off" style="display:grid; gap:10px; max-width:420px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <label>
                        Username
                        <input type="text" name="username" required value="<?php echo htmlspecialchars($maskedPost['username'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label>
                        Email
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($maskedPost['email'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label>
                        Password
                        <input type="password" name="password" required>
                    </label>

                    <label>
                        Confirm password
                        <input type="password" name="password_confirm" required>
                    </label>

                    <button type="submit" class="btn">Create admin</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Next step</h2>
            <?php if (!$migrationFailures && $adminExists): ?>
                <p>Installation completed successfully.</p>
                <p><a class="btn" href="login.php">Go to login.php</a></p>
            <?php else: ?>
                <p>Complete migrations and create an admin to finish installation.</p>
            <?php endif; ?>

            <?php if (isset($_SESSION['admin_id'])): ?>
                <p style="margin-top:10px;">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="run_migrations">
                        <button type="submit" class="btn">Run pending migrations again (admin only)</button>
                    </form>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
