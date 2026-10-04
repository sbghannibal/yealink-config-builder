<?php
/**
 * Run with: php tests/test_massupdate_access.php
 */
require_once __DIR__ . '/../includes/massupdate_access.php';
require_once __DIR__ . '/../includes/massupdate.php';
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
    massupdate_access_assert(str_contains($html, '/admin/massupdate_logging.php') === $visible, 'Logging navigation visibility ' . $admin_id);
    if ($visible) {
        massupdate_access_assert(str_contains($html, 'nav-dropdown-content')
            && strpos($html, '/admin/massupdate.php') < strpos($html, '/admin/massupdate_logging.php'), 'Config is first dropdown item');
    }
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

$logging = file_get_contents(__DIR__ . '/../admin/massupdate_logging.php');
$guard = strpos($logging, 'require_massupdate_owner($pdo, $admin_id);');
$csrf = strpos($logging, '!hash_equals($csrf, $token)');
massupdate_access_assert($guard !== false && $guard < strpos($logging, 'massupdate_log_retention($pdo)')
    && $guard < strpos($logging, 'INSERT INTO settings'), 'Logging reads and writes require Owner');
massupdate_access_assert($csrf !== false && $csrf < strpos($logging, 'INSERT INTO settings')
    && $csrf < strpos($logging, 'massupdate_log_cleanup($pdo'), 'Logging settings and manual cleanup are CSRF protected');

$pdo->exec('CREATE TABLE massupdate_log (
    id INTEGER PRIMARY KEY, mac_address TEXT, device_model TEXT, firmware_old TEXT,
    firmware_new TEXT, status TEXT, created_at TEXT
)');
$insert = $pdo->prepare('INSERT INTO massupdate_log
    (mac_address, device_model, firmware_old, firmware_new, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
$served = ['001122AABBCC', 'T46S', '66.85.0.10', '66.86.0.20', 'served', '2026-01-02 12:00:00'];
for ($i = 0; $i < 51; $i++) {
    $insert->execute($served);
}
$insert->execute(array_replace($served, [0 => '001122AABBDD', 5 => '2026-01-03 00:00:00']));
$insert->execute(['001122AABBEE', 'T54W', '96.85.0.10', '96.86.0.20', 'served', '2026-01-01 23:59:59']);
$insert->execute(array_replace($served, [3 => '66.100.0.1']));
foreach (['up-to-date', 'quota', 'inactive', 'no-campaign', '200'] as $status) {
    $insert->execute(array_replace($served, [4 => $status]));
}
$insert->execute(array_replace($served, [2 => $served[3]]));
foreach ([2, 3] as $version) {
    foreach ([null, '', '   '] as $missing) {
        $insert->execute(array_replace($served, [$version => $missing]));
    }
}

$queryStart = strpos($logging, '    $conditions = ');
$queryEnd = strpos($logging, "\n} catch (InvalidArgumentException", $queryStart);
massupdate_access_assert($queryStart !== false && $queryEnd !== false, 'Logging query block found');
$queryCode = substr($logging, $queryStart, $queryEnd - $queryStart);
$readLogs = static function (array $input = [], int $page = 1) use ($pdo, $queryCode): array {
    $filters = array_replace(['mac' => '', 'model' => '', 'date_from' => '', 'date_to' => ''], $input);
    eval($queryCode);
    return compact('logs', 'total', 'pages', 'page', 'summary');
};
$assertRead = static function (array $result, int $total, array $expectedSummary, string $label): void {
    massupdate_access_assert($result['total'] === $total && $result['pages'] === max(1, (int)ceil($total / 50)),
        $label . ' count and pagination');
    massupdate_access_assert($result['summary'] === $expectedSummary, $label . ' grouped requests and unique devices');
    foreach ($result['logs'] as $log) {
        massupdate_access_assert($log['status'] === 'served' && trim($log['firmware_old'] ?? '') !== ''
            && trim($log['firmware_new'] ?? '') !== '' && $log['firmware_old'] !== $log['firmware_new'],
            $label . ' visible successful version change ' . $log['id']);
    }
};
$t46 = ['device_model' => 'T46S', 'firmware_new' => '66.86.0.20', 'requests' => 52, 'devices' => 2];
$otherTarget = ['device_model' => 'T46S', 'firmware_new' => '66.100.0.1', 'requests' => 1, 'devices' => 1];
$t54 = ['device_model' => 'T54W', 'firmware_new' => '96.86.0.20', 'requests' => 1, 'devices' => 1];
$result = $readLogs();
$assertRead($result, 54, [$otherTarget, $t46, $t54], 'Unfiltered');
massupdate_access_assert(count($result['logs']) === 50 && (int)$result['logs'][0]['id'] === 52,
    'First page preserves date then ID descending order and page size');
$result = $readLogs([], 99);
$assertRead($result, 54, [$otherTarget, $t46, $t54], 'Last page');
massupdate_access_assert($result['page'] === 2 && count($result['logs']) === 4
    && (int)end($result['logs'])['id'] === 53, 'Page clamping and oldest row');
$assertRead($readLogs(['mac' => '00:11:22:aa:bb:cc']), 52,
    [$otherTarget, array_replace($t46, ['requests' => 51, 'devices' => 1])], 'MAC filter');
$assertRead($readLogs(['model' => 't54w']), 1, [$t54], 'Model filter');
$assertRead($readLogs(['date_from' => '2026-01-02', 'date_to' => '2026-01-02']), 52,
    [$otherTarget, array_replace($t46, ['requests' => 51, 'devices' => 1])], 'Inclusive date filter');
$assertRead($readLogs(['mac' => '00-11-22-aa-bb-dd', 'model' => 't46s',
    'date_from' => '2026-01-03', 'date_to' => '2026-01-03']), 1,
    [array_replace($t46, ['requests' => 1, 'devices' => 1])], 'Combined filters');
$result = $readLogs(['model' => 'T48S']);
$assertRead($result, 0, [], 'Empty filter result');
massupdate_access_assert($result['logs'] === [] && (int)$pdo->query('SELECT COUNT(*) FROM massupdate_log')->fetchColumn() === 66,
    'View filtering does not delete hidden diagnostic records');

foreach (['nl', 'en', 'fr'] as $language) {
    $translations = load_translations($language);
    preg_match_all("/__\\('(massupdate\\.[a-z_]+|nav\\.massupdate)'\\)/", $source . $navigation, $matches);
    foreach (array_unique($matches[1]) as $key) {
        massupdate_access_assert(!empty($translations[$key]), $language . ' translation ' . $key);
    }
    preg_match_all("/__\\('((?:massupdate_log|nav)\\.[a-z_]+)'\\)/", $logging . $navigation, $matches);
    foreach (array_unique($matches[1]) as $key) {
        massupdate_access_assert(!empty($translations[$key]), $language . ' logging translation ' . $key);
    }
    foreach (['served', 'up_to_date', 'quota', 'inactive', 'no_campaign'] as $status) {
        massupdate_access_assert(!empty($translations['massupdate_log.status_' . $status]), $language . ' logging status ' . $status);
    }
}
