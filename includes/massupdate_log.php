<?php

function massupdate_log_retention(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute(['massupdate_log_retention_days']);
    $days = filter_var($stmt->fetchColumn(), FILTER_VALIDATE_INT);
    return $days !== false && $days >= 1 && $days <= 3650 ? $days : 30;
}

function massupdate_log_cleanup(PDO $pdo, int $days): int
{
    if ($days < 1 || $days > 3650) {
        throw new InvalidArgumentException('Invalid log retention.');
    }
    $stmt = $pdo->prepare('DELETE FROM massupdate_log WHERE created_at < NOW() - INTERVAL ? DAY');
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

function massupdate_log_write(PDO $pdo, array $request, array $outcome, string $ua, string $ip): void
{
    $stmt = $pdo->prepare('
        INSERT INTO massupdate_log
            (mac_address, device_model, firmware_old, firmware_new, campaign_id, status, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $request['mac'], $request['model'], $request['version'],
        $outcome['firmware_new'], $outcome['campaign_id'], $outcome['status'],
        substr($ip, 0, 45), substr($ua, 0, 1024),
    ]);
}
