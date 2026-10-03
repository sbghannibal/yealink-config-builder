<?php
/**
 * Run with: php tests/test_massupdate_access.php
 */
require_once __DIR__ . '/../includes/massupdate_access.php';
require_once __DIR__ . '/../includes/i18n.php';

function massupdate_access_assert(bool $condition, string $label): void
{
    echo $label . ': ' . ($condition ? "✓ PASS\n" : "✗ FAIL\n");
    if (!$condition) {
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, is_active INTEGER)');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT)');
$pdo->exec('CREATE TABLE admin_roles (admin_id INTEGER, role_id INTEGER)');
$pdo->exec('CREATE TABLE role_permissions (role_id INTEGER, permission TEXT)');
$pdo->exec("INSERT INTO roles VALUES (1, 'Owner'), (2, 'oWnEr'), (3, 'Expert'), (4, 'Tech')");
$pdo->exec('INSERT INTO admins VALUES (1, 1), (2, 1), (3, 1), (4, 1), (5, 0), (6, 1)');
$pdo->exec('INSERT INTO admin_roles VALUES (1, 1), (2, 2), (3, 3), (4, 4), (5, 1)');
$pdo->exec("INSERT INTO role_permissions VALUES (3, 'massupdate.manage'), (3, 'config.manage'), (4, 'massupdate.manage')");

foreach ([1 => true, 2 => true, 3 => false, 4 => false, 5 => false, 6 => false, 999 => false] as $admin_id => $allowed) {
    massupdate_access_assert(massupdate_owner_allowed($pdo, $admin_id) === $allowed, 'Owner-only active account ' . $admin_id);
}

$header = file_get_contents(__DIR__ . '/../admin/_header.php');
$start = strpos($header, '<?php if (massupdate_owner_allowed($pdo, $admin_id)): ?>');
$end = strpos($header, '<?php endif; ?>', $start);
massupdate_access_assert($start !== false && $end !== false, 'Owner-only navigation guard');
$navigation = substr($header, $start, $end + strlen('<?php endif; ?>') - $start);
$current_page = 'massupdate.php';
foreach ([1 => true, 2 => true, 3 => false, 5 => false] as $admin_id => $visible) {
    ob_start();
    eval('?>' . $navigation);
    $html = ob_get_clean();
    massupdate_access_assert(str_contains($html, '/admin/massupdate.php') === $visible, 'Navigation visibility ' . $admin_id);
}

$source = file_get_contents(__DIR__ . '/../admin/massupdate.php');
$guard = strpos($source, 'require_massupdate_owner($pdo, $admin_id);');
$read = strpos($source, 'SELECT c.*');
$write = strpos($source, '$pdo->beginTransaction();');
$csrf = strpos($source, '!hash_equals($csrf, $token)');
massupdate_access_assert($guard !== false && $guard < $read && $guard < $write, 'All management reads and writes require Owner');
massupdate_access_assert($csrf !== false && $csrf < $write, 'CSRF verified before mutations');
massupdate_access_assert(strpos($source, 'INSERT INTO audit_logs') < strpos($source, '$pdo->commit();'), 'Audit is atomic with campaign mutations');
massupdate_access_assert(!str_contains($source, 'has_permission('), 'Generic permissions cannot bypass Owner role');

foreach (['nl', 'en', 'fr'] as $language) {
    $translations = load_translations($language);
    preg_match_all("/__\\('(massupdate\\.[a-z_]+|nav\\.massupdate)'\\)/", $source . $navigation, $matches);
    foreach (array_unique($matches[1]) as $key) {
        massupdate_access_assert(!empty($translations[$key]), $language . ' translation ' . $key);
    }
}
