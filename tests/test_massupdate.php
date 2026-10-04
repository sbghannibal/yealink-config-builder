<?php
require_once __DIR__ . '/../includes/massupdate.php';

class MassupdateTestPDO extends PDO
{
    public array $queries = [];
    public bool $failLogging = false;
    public string $cleanupNow;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->failLogging && str_contains($query, 'INSERT INTO massupdate_log')) {
            throw new RuntimeException('logging unavailable');
        }
        $query = str_replace('NOW() - INTERVAL ? DAY', "datetime('$this->cleanupNow', '-' || ? || ' days')", $query);
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

function massupdate_test(bool $condition, string $label): void
{
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        exit(1);
    }
}

$ua = 'Yealink SIP-T46S 66.85.0.10';
$parsed = ['mac' => '001122AABBCC', 'model' => 'T46S', 'version' => '66.85.0.10'];
massupdate_test(massupdate_parse_request('/massupdate/001122aabbcc.cfg?x=1', [], $ua) === $parsed, 'MAC filename ignores query string');
massupdate_test(massupdate_parse_request('/massupdate/', ['mac' => '00:11:22:aa:bb:cc'], $ua) === $parsed, 'colon query MAC');
massupdate_test(massupdate_parse_request('/massupdate/index.php', ['mac' => '00-11-22-aa-bb-cc'], $ua) === $parsed, 'hyphen query MAC');
massupdate_test(massupdate_parse_request('/massupdate/', [], $ua . ' 001122aabbcc') === $parsed, 'UA MAC');
massupdate_test(massupdate_parse_request('/massupdate/y000000000028.cfg', [], $ua . ' (00:11:22:aa:bb:cc)') === $parsed, 'generic config with UA MAC');
massupdate_test(massupdate_parse_request('/massupdate/', ['mac' => '001122AABBCC'], 'Yealink W75DM 108.83.0.40')['model'] === 'W75DM', 'DECT model');
foreach ([
    ['/massupdate/001122aabbcc.boot', [], $ua . ' 001122aabbcc'],
    ['/massupdate/unknown.cfg', [], $ua . ' 001122aabbcc'],
    ['/massupdate/index.php/unknown', [], $ua . ' 001122aabbcc'],
    ['/massupdate/%30%30%31%31%32%32aabbcc.cfg', [], $ua],
    ['/massupdate/y000000000028.cfg', ['mac' => '001122aabbcc'], $ua],
    ['/massupdate/', [], $ua],
    ['/massupdate/', ['mac' => ['001122aabbcc']], $ua],
    ['/massupdate/', ['mac' => '001122aabbcc!'], $ua],
    ['/massupdate/', ['mac' => '00:11-22:aa:bb:cc'], $ua],
    ['/massupdate/', ['mac' => ' 001122aabbcc'], $ua],
    ['/massupdate/', ['mac' => '001122aabbcc'], 'Other T46S 66.85.0.10'],
    ['/massupdate/', ['mac' => '001122aabbcc'], 'Yealink SIP-T46S'],
    ['/massupdate/', ['mac' => '001122aabbcc'], 'Yealink SIP-T46S 66.85.0.10evil'],
    ['/massupdate/001122aabbcc.cfg', ['mac' => '001122aabbdd'], $ua],
    ['/massupdate/001122aabbcc.cfg', [], $ua . ' 001122aabbdd'],
    ['/massupdate/', [], $ua . ' 001122aabbcc 001122aabbdd'],
] as [$uri, $query, $agent]) {
    massupdate_test(massupdate_parse_request($uri, $query, $agent) === null, 'reject invalid request: ' . $uri . ' / ' . $agent);
}

foreach ([
    ['2026-01-02T00:00:00+01:00', '2026-01-01'],
    ['2026-01-02T07:59:59+01:00', '2026-01-01'],
    ['2026-01-02T08:00:00+01:00', '2026-01-02'],
    ['2026-03-29T05:59:59Z', '2026-03-28'],
    ['2026-03-29T06:00:00Z', '2026-03-29'],
    ['2026-10-25T06:59:59Z', '2026-10-24'],
    ['2026-10-25T07:00:00Z', '2026-10-25'],
    ['2026-10-25T02:30:00+02:00', '2026-10-24'],
    ['2026-10-25T02:30:00+01:00', '2026-10-24'],
] as [$time, $expected]) {
    massupdate_test(massupdate_period(new DateTimeImmutable($time)) === $expected, 'Belgian reset: ' . $time);
}

$input = [
    'model' => 'sip-t46s',
    'target_version' => '66.86.0.20',
    'firmware_url' => 'https://firmware.example/T46S.rom?token=abc&v=2',
    'daily_limit' => '2',
];
$valid = massupdate_validate_campaign($input);
massupdate_test($valid['model'] === 'T46S' && $valid['daily_limit'] === 2, 'campaign normalized');
foreach ([
    ['model', 'T46S;bad'],
    ['model', []],
    ['target_version', '66.86.0.20-beta'],
    ['target_version', '1'],
    ['target_version', str_repeat('1', 65) . '.2'],
    ['firmware_url', "https://firmware.example/a\naccount.1.password = bad"],
    ['firmware_url', 'https://firmware.example/a%0d%0afirmware.url=other'],
    ['firmware_url', 'https://firmware.example/a%250afirmware.url=other'],
    ['firmware_url', 'https://firmware.example/a%20b'],
    ['firmware_url', 'https://user@firmware.example/a'],
    ['firmware_url', 'https://@firmware.example/a'],
    ['firmware_url', 'ftp://firmware.example/a'],
    ['firmware_url', 'file:///firmware.rom'],
    ['firmware_url', '//firmware.example/a'],
    ['firmware_url', 'https://firmware.example/a b'],
    ['daily_limit', 0],
    ['daily_limit', -1],
    ['daily_limit', 25001],
    ['daily_limit', '2e1'],
    ['daily_limit', 2.5],
    ['daily_limit', true],
] as [$field, $value]) {
    $bad = $input;
    $bad[$field] = $value;
    try {
        massupdate_validate_campaign($bad);
        massupdate_test(false, 'invalid campaign rejected: ' . $field);
    } catch (InvalidArgumentException $e) {
        massupdate_test(true, 'invalid campaign rejected: ' . $field);
    }
}

$pdo = new MassupdateTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->cleanupNow = (string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('
    CREATE TABLE massupdate_campaigns (
        id INTEGER PRIMARY KEY, model TEXT UNIQUE, target_version TEXT, firmware_url TEXT,
        daily_limit INTEGER, is_active INTEGER
    );
    CREATE TABLE massupdate_downloads (
        campaign_id INTEGER, period_date TEXT, mac_address TEXT,
        PRIMARY KEY (campaign_id, period_date, mac_address),
        FOREIGN KEY (campaign_id) REFERENCES massupdate_campaigns(id) ON DELETE CASCADE
    );
    CREATE TABLE massupdate_log (
        id INTEGER PRIMARY KEY, mac_address TEXT, device_model TEXT, firmware_old TEXT,
        firmware_new TEXT, campaign_id INTEGER, status TEXT, ip TEXT, user_agent TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES massupdate_campaigns(id) ON DELETE SET NULL
    );
    CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    INSERT INTO massupdate_campaigns VALUES (1, "T46S", "66.86.0.20", "https://firmware.example/T46S.rom", 2, 1);
    INSERT INTO massupdate_campaigns VALUES (2, "T54W", "96.86.0.20", "https://firmware.example/T54W.rom", 1, 0);
');
$now = new DateTimeImmutable('2026-03-29T08:00:00+02:00');
$expected = "#!version:1.0.0.1\nfirmware.url = https://firmware.example/T46S.rom\n";
$count = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM massupdate_downloads')->fetchColumn();
$logCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM massupdate_log')->fetchColumn();
massupdate_test(massupdate_admit($pdo, array_replace($parsed, ['model' => 'T48S']), $now) === null && $count() === 0, 'unknown model has no output or slot');
massupdate_test(massupdate_admit($pdo, array_replace($parsed, ['version' => '66.86.0.20']), $now) === null && $count() === 0, 'equal target has no output or slot');
massupdate_test(massupdate_admit($pdo, array_replace($parsed, ['version' => '66.100.0.1']), $now) === null && $count() === 0, 'newer numeric version has no output or slot');
massupdate_test(massupdate_admit($pdo, array_replace($parsed, ['model' => 'T54W']), $now) === null && $count() === 0, 'inactive campaign has no output or slot');
massupdate_test(massupdate_admit($pdo, $parsed, $now) === $expected && $count() === 1, 'first device without inventory gets firmware-only config');
massupdate_test(in_array('SELECT * FROM massupdate_campaigns WHERE model = ? FOR UPDATE', $pdo->queries, true), 'campaign row locked');
$second = array_replace($parsed, ['mac' => '001122AABBDD']);
$third = array_replace($parsed, ['mac' => '001122AABBEE']);
massupdate_test(massupdate_admit($pdo, $parsed, $now) === $expected && $count() === 1, 'retry does not consume slot');
massupdate_test(massupdate_admit($pdo, $second, $now) === $expected && $count() === 2, 'second unique device admitted');
massupdate_test(massupdate_admit($pdo, $third, $now) === null && $count() === 2, 'third unique device denied');
massupdate_test(massupdate_admit($pdo, $parsed, $now) === $expected && $count() === 2, 'admitted device still served at quota');
$pdo->exec('UPDATE massupdate_campaigns SET daily_limit = 1 WHERE id = 1');
massupdate_test(massupdate_admit($pdo, $second, $now) === $expected && $count() === 2, 'reduced quota retains admissions');
$pdo->exec('UPDATE massupdate_campaigns SET is_active = 0 WHERE id = 1');
massupdate_test(massupdate_admit($pdo, $parsed, $now) === null && $count() === 2, 'pause blocks previously admitted device');
$pdo->exec('UPDATE massupdate_campaigns SET is_active = 1, daily_limit = 2 WHERE id = 1');
massupdate_test(massupdate_admit($pdo, $third, new DateTimeImmutable('2026-03-30T00:00:00+02:00')) === null, 'midnight does not reset quota');
massupdate_test(massupdate_admit($pdo, $third, new DateTimeImmutable('2026-03-30T07:59:59+02:00')) === null, 'before 08:00 does not reset quota');
massupdate_test(massupdate_admit($pdo, $third, new DateTimeImmutable('2026-03-30T08:00:00+02:00')) === $expected && $count() === 3, '08:00 starts new quota period');
massupdate_test(massupdate_admit($pdo, $parsed, new DateTimeImmutable('2026-03-30T08:00:00+02:00')) === $expected && $count() === 4, 'same MAC uses one slot in new period');
massupdate_test(massupdate_admit($pdo, $second, new DateTimeImmutable('2026-03-30T08:00:00+02:00')) === null, 'new period unique quota enforced');
massupdate_test(massupdate_admit($pdo, array_replace($parsed, ['version' => '66.86.0.20']), $now) === null, 'updated admitted device has no output');
massupdate_test(!$pdo->inTransaction(), 'all normal admissions close transactions');
massupdate_test(str_starts_with((string)massupdate_admit($pdo, $parsed, $now), "#!version:1.0.0.1\n"), 'config first line is #!version:1.0.0.1');

$connects = 0;
$connect = static function () use ($pdo, &$connects): PDO {
    $connects++;
    return $pdo;
};
$w70bUa = 'Yealink W70B 146.87.188.1 24:9a:d8:66:77:32';
foreach (['GET', 'HEAD'] as $method) {
    foreach (['/massupdate/249ad8667732.boot', '/massupdate/249ad8667732.enc', '/massupdate/unknown.cfg', '/massupdate/index.php/unknown'] as $uri) {
        $before = $count();
        massupdate_test(massupdate_respond($method, $uri, [], $w70bUa, $connect, $now) === [404, ''] && $connects === 0 && $count() === $before,
            $method . ' ' . $uri . ' returns 404 without database access');
    }
}
$before = $count();
massupdate_test(massupdate_respond('HEAD', '/massupdate/001122aabbcc.cfg', [], $ua, $connect, $now) === [204, ''] && $connects === 0 && $count() === $before,
    'supported HEAD returns 204 without body, database access or quota');
massupdate_test(massupdate_respond('POST', '/massupdate/001122aabbcc.cfg', [], $ua, $connect, $now) === [405, ''] && $connects === 0, 'unsupported method returns 405');
massupdate_test($logCount() === 0, 'HEAD, unsupported paths and methods never log');
massupdate_test(massupdate_respond('GET', '/massupdate/249ad8667732.cfg', [], $w70bUa, $connect, $now) === [404, ''] && $connects === 1, 'GET without campaign config returns 404');
massupdate_test($pdo->query('SELECT status FROM massupdate_log')->fetchColumn() === 'no-campaign', 'supported GET without campaign logs its result');
[$status, $body] = massupdate_respond('GET', '/massupdate/001122aabbcc.cfg', [], $ua, $connect, $now, '192.0.2.1');
massupdate_test($status === 200 && $body === $expected && str_starts_with($body, "#!version:1.0.0.1\n"), 'supported GET returns 200 with firmware-only config');
$log = $pdo->query('SELECT * FROM massupdate_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
massupdate_test($logCount() === 2 && $log['mac_address'] === $parsed['mac'] && $log['device_model'] === 'T46S'
    && $log['firmware_old'] === '66.85.0.10' && $log['firmware_new'] === '66.86.0.20'
    && (int)$log['campaign_id'] === 1 && $log['status'] === 'served' && $log['ip'] === '192.0.2.1'
    && $log['user_agent'] === $ua, '200 GET logs device, versions, campaign, result, IP and UA');
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbcc.cfg', [], 'Yealink SIP-T46S 66.86.0.20', $connect, $now) === [404, '']
    && $pdo->query('SELECT status FROM massupdate_log ORDER BY id DESC LIMIT 1')->fetchColumn() === 'up-to-date', 'up-to-date GET logs without changing 404');
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbee.cfg', [], $ua, $connect, $now) === [404, '']
    && $pdo->query('SELECT status FROM massupdate_log ORDER BY id DESC LIMIT 1')->fetchColumn() === 'quota', 'quota GET logs without changing 404');
$pdo->failLogging = true;
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbcc.cfg', [], $ua, $connect, $now) === [200, $expected], 'logging failure preserves 200 and config');
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbee.cfg', [], $ua, $connect, $now) === [404, ''], 'logging failure preserves 404');
$pdo->failLogging = false;
$beforeLogs = $logCount();
$pdo->exec("
    INSERT INTO massupdate_log (created_at) VALUES
        (datetime('$pdo->cleanupNow', '-31 days')), (datetime('$pdo->cleanupNow', '-30 days')), (datetime('$pdo->cleanupNow', '-29 days'));
");
massupdate_test(massupdate_log_retention($pdo) === 30, 'default log retention is 30 days');
massupdate_test(massupdate_log_cleanup($pdo, 30) === 1 && $logCount() === $beforeLogs + 2, 'retention removes only old rows, keeps boundary and recent rows');
$pdo->exec('INSERT INTO settings VALUES ("massupdate_log_retention_days", "7")');
massupdate_test(massupdate_log_retention($pdo) === 7 && massupdate_log_cleanup($pdo, 7) === 2, 'configured retention used for cleanup');
$pdo->exec('UPDATE settings SET setting_value = "0"');
massupdate_test(massupdate_log_retention($pdo) === 30, 'invalid stored retention uses safe default');
foreach ([0, -1, 3651] as $days) {
    try {
        massupdate_log_cleanup($pdo, $days);
        massupdate_test(false, 'invalid retention rejected');
    } catch (InvalidArgumentException $e) {
        massupdate_test($logCount() === $beforeLogs, 'invalid retention cannot delete logs');
    }
}
$pdo->exec('DROP TABLE massupdate_log');
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbcc.cfg', [], $ua, $connect, $now) === [200, $expected], 'missing log migration preserves provisioning');
$failing = static function (): PDO {
    throw new RuntimeException('down');
};
massupdate_test(massupdate_respond('GET', '/massupdate/001122aabbcc.cfg', [], $ua, $failing, $now) === [503, ''], 'database error returns 503');

$entry = static function (string $method, string $uri): array {
    $code = '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . '; $_SERVER["REQUEST_URI"] = ' . var_export($uri, true)
        . '; $_SERVER["HTTP_USER_AGENT"] = "Yealink W70B 146.87.188.1 24:9a:d8:66:77:32"; $_GET = [];'
        . ' register_shutdown_function(static function () { register_shutdown_function(static function () { fwrite(STDERR, (string)http_response_code()); }); });'
        . ' require ' . var_export(__DIR__ . '/../massupdate/index.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'error_log=/dev/null', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DB_HOST' => '127.0.0.1:1']);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);
    return [$err, $out];
};
foreach (['GET', 'HEAD'] as $method) {
    foreach (['/massupdate/249ad8667732.boot', '/massupdate/249ad8667732.enc'] as $uri) {
        massupdate_test($entry($method, $uri) === ['404', ''], 'endpoint ' . $method . ' ' . $uri . ' returns empty 404');
    }
}
massupdate_test($entry('PUT', '/massupdate/249ad8667732.cfg') === ['405', ''], 'endpoint PUT returns 405');
massupdate_test($entry('GET', '/massupdate/249ad8667732.cfg') === ['503', ''], 'endpoint database failure returns empty 503');

$pdo->exec('UPDATE massupdate_campaigns SET firmware_url = "https://firmware.example/a%0aevil" WHERE id = 1');
try {
    massupdate_admit($pdo, $parsed, $now);
    massupdate_test(false, 'unsafe persisted campaign rejected');
} catch (InvalidArgumentException $e) {
    massupdate_test(!$pdo->inTransaction() && $count() === 4, 'unsafe persisted campaign rolls back');
}
$pdo->exec('DELETE FROM massupdate_campaigns WHERE id = 1');
massupdate_test($count() === 0, 'campaign deletion cascades admissions');
echo "All massupdate tests passed.\n";
