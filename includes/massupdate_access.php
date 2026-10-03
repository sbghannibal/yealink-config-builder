<?php
require_once __DIR__ . '/partner_access.php';

function massupdate_owner_allowed(PDO $pdo, int $admin_id): bool
{
    $stmt = $pdo->prepare('SELECT is_active FROM admins WHERE id = ?');
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    return $admin && (int)$admin['is_active'] === 1 && is_owner($pdo, $admin_id);
}

function require_massupdate_owner(PDO $pdo, int $admin_id): void
{
    if (!massupdate_owner_allowed($pdo, $admin_id)) {
        header('Location: /access_denied.php', true, 403);
        exit;
    }
}
