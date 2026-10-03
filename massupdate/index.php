<?php
ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if ($method === 'HEAD') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/massupdate.php';
$request = massupdate_parse_request(
    $_SERVER['REQUEST_URI'] ?? '',
    $_GET,
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);
if ($request === null) {
    http_response_code(204);
    exit;
}

// The shared database loader exits on connection failure; suppress its body too.
$databaseLoaded = false;
$bufferLevel = ob_get_level();
ob_start();
register_shutdown_function(static function () use (&$databaseLoaded, $bufferLevel): void {
    if (!$databaseLoaded) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        http_response_code(503);
    }
});
try {
    require __DIR__ . '/../settings/database.php';
    ob_end_clean();
    $databaseLoaded = true;
    $config = massupdate_admit($pdo, $request, new DateTimeImmutable('now'));
    if ($config === null) {
        http_response_code(204);
    } else {
        echo $config;
    }
} catch (Throwable $e) {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    $databaseLoaded = true;
    http_response_code(503);
}
