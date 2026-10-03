<?php
ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/massupdate.php';

// The shared database loader exits on connection failure; suppress its body too.
$databaseLoaded = true;
$bufferLevel = ob_get_level();
$connect = static function () use (&$databaseLoaded, $bufferLevel): PDO {
    $databaseLoaded = false;
    ob_start();
    register_shutdown_function(static function () use (&$databaseLoaded, $bufferLevel): void {
        if (!$databaseLoaded) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            http_response_code(503);
        }
    });
    require __DIR__ . '/../settings/database.php';
    ob_end_clean();
    $databaseLoaded = true;
    return $pdo;
};

[$status, $body] = massupdate_respond(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '',
    $_GET,
    $_SERVER['HTTP_USER_AGENT'] ?? '',
    $connect,
    new DateTimeImmutable('now')
);
while (ob_get_level() > $bufferLevel) {
    ob_end_clean();
}
$databaseLoaded = true;
if ($status === 405) {
    header('Allow: GET, HEAD');
}
http_response_code($status);
echo $body;
