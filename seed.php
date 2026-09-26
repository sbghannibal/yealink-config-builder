<?php
// seed.php - run shared seed logic from CLI

require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/includes/seed.php';

echo PHP_EOL . "Running seed.php..." . PHP_EOL;

try {
    $deviceTypeResult = seed_default_device_types($pdo);
    echo "Device types ensured: {$deviceTypeResult['seeded']}/{$deviceTypeResult['total']} newly inserted" . PHP_EOL;

    $adminResult = seed_default_admin($pdo, [
        'username' => 'admin',
        'email' => 'admin@local',
        'password' => 'admin123',
    ]);

    if ($adminResult['status'] === 'admin_already_exists') {
        echo "Admin already exists; skipped admin creation." . PHP_EOL;
    } else {
        echo "Created admin '{$adminResult['admin_username']}' with Owner role (id: {$adminResult['role_id']})." . PHP_EOL;
        echo "Permissions newly added: {$adminResult['permissions_added']}" . PHP_EOL;
        echo "Default credentials: username='admin' password='admin123' (change immediately)" . PHP_EOL;
    }

    echo "Seeding complete." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Seed error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
