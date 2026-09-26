<?php

function get_owner_permissions(): array
{
    return [
        'admin.accounts.manage',
        'admin.audit.view',
        'admin.backup.create',
        'admin.backup.restore',
        'admin.device_types.manage',
        'admin.manage',
        'admin.roles.manage',
        'admin.settings.edit',
        'admin.templates.manage',
        'admin.tokens.generate',
        'admin.tokens.manage',
        'admin.users.create',
        'admin.users.delete',
        'admin.users.edit',
        'admin.users.manage',
        'admin.users.view',
        'admin.variables.manage',
        'config.cleanup',
        'config.manage',
        'customers.create',
        'customers.delete',
        'customers.edit',
        'customers.manage',
        'customers.view',
        'devices.create',
        'devices.delete',
        'devices.edit',
        'devices.manage',
        'devices.restore',
        'devices.view',
        'pabx.manage',
        'partners.manage',
        'variables.manage',
    ];
}

function seed_default_device_types(PDO $pdo): array
{
    $types = ['T19P','T21P','T23P','T27P','T29P','T41P','T42P','T43P','T46P','T48P','cisco_atabox_192','fasttel_ft600'];
    $stmt = $pdo->prepare('INSERT IGNORE INTO device_types (type_name, description) VALUES (?, ?)');
    $seeded = 0;

    foreach ($types as $type) {
        $description = str_starts_with($type, 'T')
            ? 'Yealink model ' . $type
            : ($type === 'cisco_atabox_192' ? 'Cisco ATABOX model 192' : 'Fasttel FT600 doorphone');

        $stmt->execute([$type, $description]);
        $seeded += $stmt->rowCount() > 0 ? 1 : 0;
    }

    return [
        'status' => 'ok',
        'seeded' => $seeded,
        'total' => count($types),
    ];
}

function ensure_owner_role(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $stmt->execute(['Owner']);
    $roleId = $stmt->fetchColumn();

    if ($roleId) {
        $update = $pdo->prepare('UPDATE roles SET role_name = ?, name = ? WHERE id = ?');
        $update->execute(['Owner', 'Owner', $roleId]);
        return (int) $roleId;
    }

    $insert = $pdo->prepare('INSERT INTO roles (role_name, name, description) VALUES (?, ?, ?)');
    $insert->execute([
        'Owner',
        'Owner',
        'Full system access - can manage users, roles, and all features',
    ]);

    return (int) $pdo->lastInsertId();
}

function ensure_owner_permissions(PDO $pdo, int $roleId): int
{
    $permissions = get_owner_permissions();
    $stmt = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)');
    $added = 0;

    foreach ($permissions as $permission) {
        $stmt->execute([$roleId, $permission]);
        $added += $stmt->rowCount() > 0 ? 1 : 0;
    }

    return $added;
}

function seed_default_admin(PDO $pdo, array $adminData = []): array
{
    $result = [
        'status' => 'admin_already_exists',
        'message' => 'admin already exists',
        'admin_created' => false,
        'admin_username' => null,
        'role_name' => 'Owner',
        'role_id' => null,
        'permissions_added' => 0,
        'generated_password' => null,
    ];

    $username = trim((string)($adminData['username'] ?? 'admin'));
    $email = trim((string)($adminData['email'] ?? 'admin@local'));
    $password = (string)($adminData['password'] ?? '');

    if ($password === '') {
        $password = bin2hex(random_bytes(8));
        $result['generated_password'] = $password;
    }

    $lockName = 'seed_default_admin_lock';
    $lockAcquired = false;

    try {
        $lockStmt = $pdo->query("SELECT GET_LOCK('{$lockName}', 10)");
        $lockAcquired = ((int) $lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) {
            throw new RuntimeException('Could not acquire admin seed lock.');
        }

        $pdo->beginTransaction();
        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($adminCount > 0) {
            $pdo->commit();
            return $result;
        }

        $roleId = ensure_owner_role($pdo);
        $permissionsAdded = ensure_owner_permissions($pdo, $roleId);

        $stmt = $pdo->prepare('INSERT INTO admins (username, password, email) VALUES (?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $email]);
        $adminId = (int) $pdo->lastInsertId();

        $assignStmt = $pdo->prepare('INSERT IGNORE INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
        $assignStmt->execute([$adminId, $roleId]);

        $pdo->commit();

        $result['status'] = 'admin_created';
        $result['message'] = 'admin created';
        $result['admin_created'] = true;
        $result['admin_username'] = $username;
        $result['role_id'] = $roleId;
        $result['permissions_added'] = $permissionsAdded;

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        if ($lockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }
}
