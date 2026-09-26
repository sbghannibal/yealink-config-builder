<?php
/**
 * Migration Runner (CLI)
 */

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/migrations.php';

$migrationDir = __DIR__ . '/../migrations';

if (!is_dir($migrationDir)) {
    fwrite(STDERR, "ERROR: migrations directory not found at: {$migrationDir}\n");
    exit(1);
}

echo "=================================\n";
echo "Database Migration Runner\n";
echo "=================================\n";
echo "Host: " . DB_HOST . "\n";
echo "Database: " . DB_NAME . "\n";
echo "User: " . DB_USER . "\n";
echo "=================================\n\n";

try {
    $results = run_pending_migrations($pdo, $migrationDir);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Migration runner crashed: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($results)) {
    echo "No migration files found in: {$migrationDir}\n";
    exit(0);
}

$failed = 0;

foreach ($results as $result) {
    $file = $result['filename'];
    $status = $result['status'];
    $statements = (int) ($result['statements'] ?? 0);
    $errors = $result['errors'] ?? [];

    if ($status === 'already_applied') {
        echo "↷ {$file}: already applied\n";
        continue;
    }

    if ($status === 'applied' || $status === 'empty') {
        $label = $status === 'empty' ? 'empty file' : "applied ({$statements} statement(s))";
        echo "✓ {$file}: {$label}\n";
        continue;
    }

    $failed++;
    echo "✗ {$file}: failed\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}

echo "\n=================================\n";
echo "Migration process completed\n";
echo "Failed files: {$failed}\n";
echo "=================================\n";

exit($failed > 0 ? 1 : 0);
