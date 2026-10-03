<?php
/**
 * Test script for Builder and Wizard assignment activation
 * Run with: php tests/test_config_activation.php
 */

class ActivationTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // Translate MySQL locking/upsert syntax for the single-connection SQLite fixture.
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = str_replace(
            'ON DUPLICATE KEY UPDATE config_version_id = VALUES(config_version_id), assigned_at = NOW()',
            'ON CONFLICT(device_id, config_version_id) DO UPDATE SET config_version_id = excluded.config_version_id, assigned_at = NOW()',
            $query
        );
        return parent::prepare($query, $options);
    }
}

function assert_test(bool $condition, string $label): void
{
    echo $label . ': ' . ($condition ? "✓ PASS\n" : "✗ FAIL\n");
    if (!$condition) {
        exit(1);
    }
}

function save_block(string $path, string $marker): string
{
    $source = file_get_contents($path);
    $marker_position = strpos($source, $marker);
    $start = $marker_position === false ? false : strpos($source, '$pdo->beginTransaction();', $marker_position);
    $end = $start === false ? false : strpos($source, '$pdo->commit();', $start);
    assert_test($start !== false && $end !== false, basename($path) . ' transaction found');
    return substr($source, $start, $end + strlen('$pdo->commit();') - $start);
}

function run_save(PDO $pdo, string $block, int $device_id, bool $set_active = false, int $config_version_id = 0): int
{
    $admin_id = 7;
    $default_pabx_id = 1;
    $config_content = 'account.1.label = Test';
    $customer_id = 2;
    $wizard_data = ['device_type_id' => 1];
    $result = ['content' => $config_content, 'template' => ['template_name' => 'Test']];
    eval($block);
    return (int)$config_version_id;
}

function assignment(PDO $pdo, int $config_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM device_config_assignments WHERE config_version_id = ?');
    $stmt->execute([$config_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function assert_active(PDO $pdo, int $device_id, int $config_id, string $label): void
{
    $row = assignment($pdo, $config_id);
    assert_test((int)$row['is_active'] === 1 && $row['activated_at'] !== null, $label . ' activation metadata');
    $stmt = $pdo->prepare('SELECT config_version_id FROM device_config_assignments WHERE device_id = ? AND is_active = 1');
    $stmt->execute([$device_id]);
    assert_test($stmt->fetchAll(PDO::FETCH_COLUMN) === [$config_id], $label . ' provisionable assignment');
}

function assert_history(PDO $pdo, int $config_id, bool $expected, string $label): void
{
    $stmt = $pdo->prepare('SELECT * FROM config_version_history WHERE config_version_id = ?');
    $stmt->execute([$config_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assert_test(count($rows) === ($expected ? 1 : 0), $label . ' history count');
    if ($expected) {
        $row = assignment($pdo, $config_id);
        assert_test(
            (int)$rows[0]['is_active'] === 1 && (int)$rows[0]['activated_by'] === 7
            && $rows[0]['activated_at'] === $row['activated_at'],
            $label . ' matching activation history'
        );
    }
}

define('DEFAULT_CUSTOMER_PABX_NAME', 'Customer-Based');
$builder_path = __DIR__ . '/../settings/builder.php';
$builder = save_block($builder_path, "if (\$action === 'create_config')");
$activate = save_block($builder_path, "if (\$action === 'activate_config')");
$wizard_path = __DIR__ . '/../devices/configure_wizard.php';
$wizard = save_block($wizard_path, "if (\$action === 'select_customer'");
$wizard_source = file_get_contents($wizard_path);
foreach (['assert_device_allowed($pdo, $admin_id, $device_id);', 'assert_customer_allowed($pdo, $admin_id, $customer_id);'] as $guard) {
    $position = strpos($wizard_source, $guard);
    assert_test(
        $position !== false && $position < strpos($wizard_source, '$pdo->beginTransaction();'),
        'Wizard authorizes before saving: ' . $guard
    );
}

$pdo = new ActivationTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', fn() => '2026-01-01 12:00:00');
$pdo->exec('
    CREATE TABLE devices (id INTEGER PRIMARY KEY, device_type_id INTEGER, customer_id INTEGER);
    CREATE TABLE pabx (id INTEGER PRIMARY KEY, pabx_name TEXT, pabx_ip TEXT, pabx_type TEXT, is_active INTEGER, created_by INTEGER);
    CREATE TABLE config_versions (
        id INTEGER PRIMARY KEY, pabx_id INTEGER, device_type_id INTEGER, version_number INTEGER,
        config_content TEXT, changelog TEXT, is_active INTEGER DEFAULT 0, created_by INTEGER, created_at TEXT
    );
    CREATE TABLE device_config_assignments (
        id INTEGER PRIMARY KEY, device_id INTEGER, config_version_id INTEGER, assigned_by INTEGER,
        assigned_at TEXT, is_active INTEGER DEFAULT 0, activated_at TEXT,
        UNIQUE(device_id, config_version_id)
    );
    CREATE TABLE config_version_history (
        id INTEGER PRIMARY KEY, device_id INTEGER, config_version_id INTEGER,
        is_active INTEGER, activated_at TEXT, activated_by INTEGER
    );
    INSERT INTO devices VALUES (1, 1, 1), (2, 1, 1), (3, 1, 1), (4, 1, 1);
    INSERT INTO pabx VALUES (1, "Customer-Based", "0.0.0.0", "Generic", 1, 7);
');

foreach (['Builder' => $builder, 'Wizard' => $wizard] as $label => $block) {
    $device_id = $label === 'Builder' ? 1 : 2;
    $first = run_save($pdo, $block, $device_id);
    assert_active($pdo, $device_id, $first, $label . ' first save');
    assert_history($pdo, $first, true, $label . ' first save');

    $second = run_save($pdo, $block, $device_id);
    $row = assignment($pdo, $second);
    assert_test((int)$row['is_active'] === 0 && $row['activated_at'] === null, $label . ' later draft stays inactive');
    assert_active($pdo, $device_id, $first, $label . ' preserves current active config');
    assert_history($pdo, $second, false, $label . ' later draft');

    run_save($pdo, $activate, $device_id, false, $second);
    assert_active($pdo, $device_id, $second, $label . ' explicit Set as Active');
    $row = assignment($pdo, $first);
    assert_test((int)$row['is_active'] === 0 && $row['activated_at'] === null, $label . ' previous config deactivated');

    // An existing inactive assignment must not be treated as no assignment.
    $device_id += 2;
    $stmt = $pdo->prepare('INSERT INTO device_config_assignments (device_id, config_version_id) VALUES (?, ?)');
    $stmt->execute([$device_id, $first]);
    $draft = run_save($pdo, $block, $device_id);
    assert_test((int)assignment($pdo, $draft)['is_active'] === 0, $label . ' existing inactive assignment keeps draft inactive');
}

$selected = run_save($pdo, $builder, 1, true);
assert_active($pdo, 1, $selected, 'Builder explicit activation on creation');
assert_history($pdo, $selected, true, 'Builder explicit activation on creation');

$unassigned = run_save($pdo, $wizard, 0);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM device_config_assignments WHERE config_version_id = ?');
$stmt->execute([$unassigned]);
assert_test((int)$stmt->fetchColumn() === 0, 'Wizard without a device still saves an unassigned config');
assert_test(!$pdo->inTransaction(), 'Save transactions committed');

echo "\n=== All activation tests passed ===\n";
