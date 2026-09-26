<?php
/**
 * Migration Runner
 * Automatically runs all SQL migration files
 */

// Load environment variables
$env_file = __DIR__ . '/../.env';
if (!file_exists($env_file)) {
    die("ERROR: .env file not found at: $env_file\n");
}

$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') !== false && $line[0] !== '#') {
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

// Get database credentials
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'admin_yealink';
$db_user = getenv('DB_USER') ?: 'admin_yealink';
$db_pass = getenv('DB_PASS') ?: '';

echo "=================================\n";
echo "Database Migration Runner\n";
echo "=================================\n";
echo "Host: $db_host\n";
echo "Database: $db_name\n";
echo "User: $db_user\n";
echo "=================================\n\n";

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✓ Database connection successful!\n\n";
} catch (PDOException $e) {
    die("ERROR: Could not connect to database: " . $e->getMessage() . "\n");
}

// Find all migration files (repository-level migrations directory)
$migration_dir = __DIR__ . '/../migrations';
if (!is_dir($migration_dir)) {
    die("ERROR: migrations directory not found at: $migration_dir\n");
}

$migration_files = glob($migration_dir . '/*.sql');
sort($migration_files);

if (empty($migration_files)) {
    echo "No migration files found in: $migration_dir\n";
    exit(0);
}

echo "Found " . count($migration_files) . " migration file(s):\n";
foreach ($migration_files as $file) {
    echo "  - " . basename($file) . "\n";
}
echo "\n";

// Run each migration
foreach ($migration_files as $file) {
    $filename = basename($file);
    echo "Running migration: $filename\n";
    
    $sql = file_get_contents($file);
    
    if (empty($sql)) {
        echo "  ⚠ Warning: File is empty, skipping...\n\n";
        continue;
    }
    
    // Split by semicolons but keep multi-line statements together
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos(trim($stmt), '--') !== 0;
        }
    );
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            $success_count++;
        } catch (PDOException $e) {
            // Check if error is "duplicate column" or "table already exists"
            $error_msg = $e->getMessage();
            if (
                strpos($error_msg, 'Duplicate column') !== false ||
                strpos($error_msg, 'already exists') !== false ||
                strpos($error_msg, 'Duplicate key') !== false
            ) {
                echo "  ⚠ Skipped (already exists)\n";
            } else {
                echo "  ✗ Error: " . $error_msg . "\n";
                $error_count++;
            }
        }
    }
    
    if ($error_count === 0) {
        echo "  ✓ Completed successfully ($success_count statement(s))\n\n";
    } else {
        echo "  ⚠ Completed with $error_count error(s)\n\n";
    }
}

echo "=================================\n";
echo "Migration process completed!\n";
echo "=================================\n";
