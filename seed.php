<?php
// seed.php - add sample data (admin user, roles, device types, sample devices)
// Run once after install: php seed.php
require_once __DIR__ . '/settings/database.php';

function ensure_role($pdo, $role_name, $description = '') {
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE role_name = ?');
    $stmt->execute([$role_name]);
    $row = $stmt->fetch();
    if ($row) return $row['id'];
    $stmt = $pdo->prepare('INSERT INTO roles (role_name, description) VALUES (?, ?)');
    $stmt->execute([$role_name, $description]);
    return $pdo->lastInsertId();
}

echo PHP_EOL . "Running seed.php - inserting sample data..." . PHP_EOL;

try {
    // Create default roles
    $roles = [
        ['Admin', 'Full system access'],
        ['Manager', 'Can manage configs and devices'],
        ['Operator', 'Can view and download configs'],
        ['Viewer', 'Read-only access'],
    ];
    foreach ($roles as $r) {
        $id = ensure_role($pdo, $r[0], $r[1]);
        echo "Role ensured: {$r[0]} (id: $id)" . PHP_EOL;
    }

    // Create admin user if not exists
    $adminUsername = 'admin';
    $adminPassword = 'admin123';
    $adminEmail = 'admin@local';

    $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
    $stmt->execute([$adminUsername]);
    $row = $stmt->fetch();
    if ($row) {
        $adminId = $row['id'];
        echo "Admin user already exists (id: $adminId)." . PHP_EOL;
    } else {
        $stmt = $pdo->prepare('INSERT INTO admins (username, password, email) VALUES (?, ?, ?)');
        $stmt->execute([$adminUsername, password_hash($adminPassword, PASSWORD_BCRYPT), $adminEmail]);
        $adminId = $pdo->lastInsertId();
        echo "Created admin user: $adminUsername (id: $adminId)" . PHP_EOL;
    }

    // Assign Admin role
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE role_name = ?');
    $stmt->execute(['Admin']);
    $roleRow = $stmt->fetch();
    if ($roleRow) {
        $roleId = $roleRow['id'];
        $stmt = $pdo->prepare('INSERT IGNORE INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
        $stmt->execute([$adminId, $roleId]);
        echo "Assigned Admin role to user id $adminId" . PHP_EOL;
    }

    // Insert device types
    $types = ['T19P','T21P','T23P','T27P','T29P','T41P','T42P','T43P','T46P','T48P','cisco_atabox_192','fasttel_ft600'];
    $stmtIns = $pdo->prepare('INSERT IGNORE INTO device_types (type_name, description) VALUES (?, ?)');
    foreach ($types as $t) {
        $description = str_starts_with($t, 'T') ? 'Yealink model ' . $t : (
            $t === 'cisco_atabox_192'
                ? 'Cisco ATABOX model 192'
                : 'Fasttel FT600 doorphone'
        );
        $stmtIns->execute([$t, $description]);
        echo "Ensured device type: $t" . PHP_EOL;
    }

    // Create a sample PABX
    $stmt = $pdo->prepare('INSERT INTO pabx (pabx_name, pabx_ip, pabx_port, pabx_type, description, created_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE pabx_name=pabx_name');
    $stmt->execute(['Main PABX', '192.168.1.100', 5060, 'Asterisk', 'Sample PABX', $adminId]);
    $pabxId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM pabx WHERE pabx_name='Main PABX'")->fetchColumn();
    echo "Ensured PABX (id: $pabxId)" . PHP_EOL;

    // Sample devices
    // Use device_type_id if column exists (new schema), otherwise fall back to legacy model column
    $devices = [
        ['Reception Phone','T48P','00:15:65:AA:BB:01','Main reception desk'],
        ['Manager Phone','T46P','00:15:65:AA:BB:02','Manager office'],
        ['Meeting Room Phone','T43P','00:15:65:AA:BB:03','Conference room'],
    ];
    
    // Check if device_type_id column exists
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM devices LIKE 'device_type_id'");
        $hasDeviceTypeId = $colCheck->rowCount() > 0;
    } catch (Exception $e) {
        $hasDeviceTypeId = false;
    }
    
    if ($hasDeviceTypeId) {
        // Use device_type_id (new schema)
        echo "Using device_type_id column (new schema)..." . PHP_EOL;
        
        // Fetch all device types once to avoid N+1 query
        $stmt = $pdo->query('SELECT id, type_name FROM device_types');
        $deviceTypes = [];
        while ($row = $stmt->fetch()) {
            $deviceTypes[$row['type_name']] = $row['id'];
        }
        
        foreach ($devices as $d) {
            $typeName = $d[1];
            $deviceTypeId = $deviceTypes[$typeName] ?? null;
            
            if ($deviceTypeId) {
                $stmtDev = $pdo->prepare('INSERT IGNORE INTO devices (device_name, device_type_id, mac_address, description) VALUES (?, ?, ?, ?)');
                $stmtDev->execute([$d[0], $deviceTypeId, $d[2], $d[3]]);
                echo "Ensured device: {$d[0]} (device_type_id: $deviceTypeId)" . PHP_EOL;
            } else {
                echo "WARNING: Device type '{$typeName}' not found. Skipping device: {$d[0]}" . PHP_EOL;
            }
        }
    } else {
        // Fall back to legacy model column
        echo "Using legacy model column (old schema)..." . PHP_EOL;
        $stmtDev = $pdo->prepare('INSERT IGNORE INTO devices (device_name, model, mac_address, description) VALUES (?, ?, ?, ?)');
        foreach ($devices as $d) {
            $stmtDev->execute($d);
            echo "Ensured device: {$d[0]} ({$d[1]})" . PHP_EOL;
        }
    }

    // Sample variables
    $vars = [
        ['SERVER_IP','192.168.1.100','Primary PABX Server'],
        ['SERVER_PORT','5060','SIP Port'],
        ['NTP_SERVER','time.nist.gov','NTP Server']
    ];
    $stmtVar = $pdo->prepare('INSERT IGNORE INTO variables (var_name, var_value, description, created_by) VALUES (?, ?, ?, ?)');
    foreach ($vars as $v) {
        $stmtVar->execute([$v[0], $v[1], $v[2], $adminId]);
        echo "Ensured variable: {$v[0]} = {$v[1]}" . PHP_EOL;
    }

    echo PHP_EOL . "Seeding complete. Admin credentials: username='$adminUsername' password='$adminPassword' (change immediately)" . PHP_EOL;
    echo "IMPORTANT: remove or secure seed.php after use." . PHP_EOL;
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
