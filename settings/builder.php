<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/partner_access.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('nav.config_builder');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Require role + partner assignment (or be owner)
require_any_role($pdo, $admin_id);
require_partner_or_owner($pdo, $admin_id);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Get default PABX ID
$default_pabx_id = 1;
try {
    $stmt = $pdo->query('SELECT id FROM pabx WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $pabx = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pabx) {
        $default_pabx_id = (int)$pabx['id'];
    }
} catch (Exception $e) {
    error_log('Error getting default PABX: ' . $e->getMessage());
}

// Handle AJAX request to load config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'load_config') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die(json_encode(['error' => 'CSRF token invalid']));
    }
    
    $config_id = (int)($_POST['config_id'] ?? 0);
    
    if (!$config_id) {
        die(json_encode(['error' => 'Config ID required']));
    }

    // Ownership check: verify config version belongs to an allowed device/customer
    assert_config_version_allowed($pdo, $admin_id, $config_id);

    try {
        $stmt = $pdo->prepare('
            SELECT cv.config_content, cv.version_number
            FROM config_versions cv
            WHERE cv.id = ?
        ');
        $stmt->execute([$config_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            die(json_encode(['error' => 'Config not found']));
        }
        
        die(json_encode([
            'success' => true,
            'config_content' => $config['config_content'],
            'version_number' => $config['version_number']
        ]));
    } catch (Exception $e) {
        die(json_encode(['error' => $e->getMessage()]));
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';

        // Activate existing config
        if ($action === 'activate_config') {
            $device_id = (int)($_POST['device_id'] ?? 0);
            $config_version_id = (int)($_POST['config_version_id'] ?? 0);

            if (!$device_id || !$config_version_id) {
                $error = __('error.device_config_id_required');
            } else {
                // Ownership checks
                assert_device_allowed($pdo, $admin_id, $device_id);
                assert_config_version_allowed($pdo, $admin_id, $config_version_id);
                try {
                    $pdo->beginTransaction();

                    // Deactivate all configs for device
                    $stmt = $pdo->prepare('
                        UPDATE device_config_assignments
                        SET is_active = 0, activated_at = NULL
                        WHERE device_id = ?
                    ');
                    $stmt->execute([$device_id]);

                    // Activate selected config
                    $stmt = $pdo->prepare('
                        UPDATE device_config_assignments
                        SET is_active = 1, activated_at = NOW()
                        WHERE device_id = ? AND config_version_id = ?
                    ');
                    $stmt->execute([$device_id, $config_version_id]);

                    $pdo->commit();
                    $success = __('success.config_activated');

                    header('Location: ?device_id=' . $device_id);
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Activate config error: ' . $e->getMessage());
                    $error = __('error.config_activate_failed');
                }
            }
        }

        // Create new config version
        if ($action === 'create_config') {
            $device_id = (int)($_POST['device_id'] ?? 0);
            $config_content = $_POST['config_content'] ?? '';
            $set_active = isset($_POST['set_active']) && $_POST['set_active'] === '1' ? true : false;

            if (!$device_id || empty($config_content)) {
                $error = __('error.device_config_content_required');
            } else {
                // Ownership check
                assert_device_allowed($pdo, $admin_id, $device_id);
                try {
                    $pdo->beginTransaction();

                    // Lock the device before checking its existing assignments.
                    $stmt = $pdo->prepare('SELECT device_type_id FROM devices WHERE id = ? FOR UPDATE');
                    $stmt->execute([$device_id]);
                    $device_row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $device_type_id = $device_row['device_type_id'] ?? null;

                    // Get highest version number for this device
                    $stmt = $pdo->prepare('
                        SELECT MAX(cv.version_number) as max_version
                        FROM device_config_assignments dca
                        INNER JOIN config_versions cv ON dca.config_version_id = cv.id
                        WHERE dca.device_id = ?
                    ');
                    $stmt->execute([$device_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $next_version = ((int)($result['max_version'] ?? 0)) + 1;
                    if ($result['max_version'] === null) {
                        $set_active = true;
                    }

                    // Create new config version with default PABX ID
                    $stmt = $pdo->prepare('
                        INSERT INTO config_versions (pabx_id, device_type_id, version_number, config_content, created_at, created_by)
                        VALUES (?, ?, ?, ?, NOW(), ?)
                    ');
                    $stmt->execute([$default_pabx_id, $device_type_id, $next_version, $config_content, $admin_id]);
                    $config_version_id = $pdo->lastInsertId();

                    // Deactivate old configs if set_active is true
                    if ($set_active) {
                        $stmt = $pdo->prepare('
                            UPDATE device_config_assignments
                            SET is_active = 0, activated_at = NULL
                            WHERE device_id = ?
                        ');
                        $stmt->execute([$device_id]);
                    }

                    // Assign new config to device
                    $stmt = $pdo->prepare('
                        INSERT INTO device_config_assignments (device_id, config_version_id, is_active, assigned_by, assigned_at, activated_at)
                        VALUES (?, ?, ?, ?, NOW(), CASE WHEN ? THEN NOW() ELSE NULL END)
                    ');
                    $stmt->execute([$device_id, $config_version_id, $set_active ? 1 : 0, $admin_id, $set_active ? 1 : 0]);

                    // Log to history if set active
                    if ($set_active) {
                        $stmt = $pdo->prepare('
                            INSERT INTO config_version_history (device_id, config_version_id, is_active, activated_at, activated_by)
                            VALUES (?, ?, 1, NOW(), ?)
                        ');
                        $stmt->execute([$device_id, $config_version_id, $admin_id]);
                    }

                    $pdo->commit();
                    $success = sprintf(__('success.config_created'), $next_version) . ($set_active ? ' ' . __('success.config_now_active') : '');
                    
                    // Redirect to keep selected device
                    header('Location: ?device_id=' . $device_id);
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Create config error: ' . $e->getMessage());
                    $error = __('error.config_create_failed');
                }
            }
        }
    }
}

// Get search/filter parameters
$search_device = isset($_GET['search_device']) ? trim($_GET['search_device']) : '';
$search_customer = isset($_GET['search_customer']) ? trim($_GET['search_customer']) : '';
$search_customer_code = isset($_GET['search_customer_code']) ? trim($_GET['search_customer_code']) : '';
$filter_type = isset($_GET['filter_type']) ? (int)$_GET['filter_type'] : 0;
$selected_device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;

// Ownership check when a device is pre-selected via GET parameter
if ($selected_device_id > 0) {
    assert_device_allowed($pdo, $admin_id, $selected_device_id);
}

// Get device types for filter
$device_types = [];
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Device types error: ' . $e->getMessage());
}

// Get allowed customer IDs for tenant filtering
$allowed_customers = get_allowed_customer_ids_for_admin($pdo, $admin_id);

// Build search query for devices - Get UNIQUE devices only
$devices = [];
$query = '
    SELECT DISTINCT
        d.id,
        d.device_name,
        d.mac_address,
        d.is_active,
        dt.type_name,
        c.company_name,
        c.customer_code,
        (SELECT dca.config_version_id FROM device_config_assignments dca WHERE dca.device_id = d.id AND dca.is_active = 1 LIMIT 1) as config_version_id,
        (SELECT cv.version_number FROM device_config_assignments dca INNER JOIN config_versions cv ON dca.config_version_id = cv.id WHERE dca.device_id = d.id AND dca.is_active = 1 LIMIT 1) as version_number,
        (SELECT COUNT(*) FROM device_config_assignments dca WHERE dca.device_id = d.id AND dca.is_active = 1) as has_active_config
    FROM devices d
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    LEFT JOIN customers c ON d.customer_id = c.id
    WHERE d.deleted_at IS NULL
';

$params = [];

// Tenant filter
$query .= build_customer_filter($allowed_customers, 'd.customer_id', $params);

if (!empty($search_device)) {
    $query .= ' AND d.device_name LIKE ?';
    $params[] = '%' . $search_device . '%';
}

if (!empty($search_customer)) {
    $query .= ' AND c.company_name LIKE ?';
    $params[] = '%' . $search_customer . '%';
}

if (!empty($search_customer_code)) {
    $query .= ' AND c.customer_code LIKE ?';
    $params[] = '%' . $search_customer_code . '%';
}

if ($filter_type > 0) {
    $query .= ' AND d.device_type_id = ?';
    $params[] = $filter_type;
}

$query .= ' ORDER BY d.device_name ASC';

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Device search error: ' . $e->getMessage());
    $error = __('error.devices_search_failed');
    $devices = [];
}

// If device is selected, get all its configs
$selected_device = null;
$device_configs = [];

if ($selected_device_id > 0) {
    try {
        // Get device info
        $stmt = $pdo->prepare('
            SELECT d.id, d.device_name, d.mac_address, d.is_active, dt.type_name, c.company_name, c.customer_code, dt.id as device_type_id
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            LEFT JOIN customers c ON d.customer_id = c.id
            WHERE d.id = ?
        ');
        $stmt->execute([$selected_device_id]);
        $selected_device = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_device) {
            // Get all configs for this device
            $stmt = $pdo->prepare('
                SELECT
                    cv.id,
                    cv.version_number,
                    cv.created_at,
                    cv.config_content,
                    dt.type_name,
                    dca.is_active,
                    dca.activated_at,
                    a.username as assigned_by_name
                FROM device_config_assignments dca
                INNER JOIN config_versions cv ON dca.config_version_id = cv.id
                LEFT JOIN device_types dt ON cv.device_type_id = dt.id
                LEFT JOIN admins a ON dca.assigned_by = a.id
                WHERE dca.device_id = ?
                ORDER BY dca.is_active DESC, cv.created_at DESC
            ');
            $stmt->execute([$selected_device_id]);
            $device_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Selected device error: ' . $e->getMessage());
        $error = __('error.device_load_failed');
    }
}

// Include header
if (file_exists(__DIR__ . '/../admin/_header.php')) {
    include __DIR__ . '/../admin/_header.php';
}
?>

<main class="container">
    <h2><?php echo __('label.configuration_editor'); ?> — <?php echo __('nav.config_builder'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <!-- Search & Filter Section -->
    <div class="card">
        <h3><?php echo __('label.search_filter_devices'); ?></h3>
        <form method="get" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 0;">
            <div>
                <label><?php echo __('form.device_name'); ?>:</label>
                <input type="text" name="search_device" placeholder="E.g. Reception Phone" value="<?php echo htmlspecialchars($search_device); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.customer_name'); ?>:</label>
                <input type="text" name="search_customer" placeholder="E.g. Acme Corp" value="<?php echo htmlspecialchars($search_customer); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.customer_code'); ?>:</label>
                <input type="text" name="search_customer_code" placeholder="E.g. CUST001" value="<?php echo htmlspecialchars($search_customer_code); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.device_type'); ?>:</label>
                <select name="filter_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value=""><?php echo __('form.all_types'); ?></option>
                    <?php foreach ($device_types as $type): ?>
                        <option value="<?php echo (int)$type['id']; ?>" <?php echo $filter_type == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 8px; align-items: flex-end;">
                <button type="submit" class="btn" style="flex: 1; background: #007bff;"><?php echo __('button.search'); ?></button>
                <a href="/settings/builder.php" class="btn" style="background: #6c757d; text-decoration: none; flex: 1; text-align: center;"><?php echo __('button.clear'); ?></a>
            </div>
        </form>
    </div>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">

        <!-- Device List -->
        <div class="card">
            <h3>📱 <?php echo __('nav.devices'); ?> (<?php echo count($devices); ?> <?php echo __('label.found'); ?>)</h3>

            <?php if (empty($devices)): ?>
                <p style="color: #999; padding: 20px; text-align: center;"><?php echo __('label.no_devices_found_search'); ?></p>
            <?php else: ?>
                <div style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                    <?php foreach ($devices as $idx => $device): ?>
                        <div
                            style="
                                padding: 12px;
                                border-bottom: 1px solid #eee;
                                cursor: pointer;
                                background: <?php echo $selected_device_id == $device['id'] ? '#e8f4f8' : '#fff'; ?>;
                                border-left: 4px solid <?php echo $device['has_active_config'] ? '#28a745' : '#ffc107'; ?>;
                                transition: background 0.2s;
                            "
                            onclick="window.location.href='?device_id=<?php echo (int)$device['id']; ?>&search_device=<?php echo urlencode($search_device); ?>&search_customer=<?php echo urlencode($search_customer); ?>&search_customer_code=<?php echo urlencode($search_customer_code); ?>&filter_type=<?php echo $filter_type; ?>';"
                            onmouseover="this.style.background='#f5f5f5';"
                            onmouseout="this.style.background='<?php echo $selected_device_id == $device['id'] ? '#e8f4f8' : '#fff'; ?>';"
                        >
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 4px;">
                                <strong><?php echo htmlspecialchars($device['device_name']); ?></strong>
                                <?php if ($device['has_active_config']): ?>
                                    <span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">✓ <?php echo __('label.active_badge'); ?></span>
                                <?php else: ?>
                                    <span style="background: #ffc107; color: #333; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">⚠ <?php echo __('label.no_config_badge'); ?></span>
                                <?php endif; ?>
                            </div>

                            <small style="color: #666; display: block; margin-bottom: 4px;">
                                <?php echo __('label.type_prefix'); ?> <?php echo htmlspecialchars($device['type_name'] ?? '-'); ?>
                            </small>

                            <?php if ($device['company_name']): ?>
                                <small style="color: #666; display: block;">
                                    <?php echo __('label.customer_prefix'); ?> <?php echo htmlspecialchars($device['company_name']); ?>
                                    <?php if ($device['customer_code']): ?>
                                        (<?php echo htmlspecialchars($device['customer_code']); ?>)
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>

                            <?php if ($device['has_active_config'] && $device['version_number']): ?>
                                <small style="color: #28a745; display: block; margin-top: 4px;">
                                    → Config v<?php echo (int)$device['version_number']; ?>
                                </small>
                            <?php else: ?>
                                <div style="margin-top: 6px;">
                                    <a class="btn" href="/devices/configure_wizard.php?device_id=<?php echo (int)$device['id']; ?>"
                                       style="background: #007bff; color: white; padding: 4px 10px; border-radius: 4px; font-size: 11px; text-decoration: none; display: inline-block;"
                                       onclick="event.stopPropagation();">
                                        🔧 <?php echo __('button.initialize'); ?>
                                    </a>
                                    <small style="color: #856404; display: block; margin-top: 4px;">
                                        ⚠️ <?php echo __('message.device_needs_init'); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Config Editor & Preview -->
        <div class="card">
            <h3><?php echo __('label.configuration_editor'); ?></h3>

            <?php if (!$selected_device): ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <p style="font-size: 40px; margin: 0;">👈</p>
                    <p><?php echo __('label.select_device_to_edit'); ?></p>
                </div>
            <?php else: ?>
                <!-- Device Info -->
                <div style="margin-bottom: 16px; padding: 12px; background: #f0f8ff; border-radius: 4px; border-left: 4px solid #007bff;">
                    <strong><?php echo htmlspecialchars($selected_device['device_name']); ?></strong>
                    <br>
                    <small style="color: #666;">
                        <?php echo __('label.type_prefix'); ?> <?php echo htmlspecialchars($selected_device['type_name'] ?? '-'); ?>
                    </small>
                    <?php if ($selected_device['company_name']): ?>
                        <br>
                        <small style="color: #666;">
                            <?php echo __('label.customer_prefix'); ?> <?php echo htmlspecialchars($selected_device['company_name']); ?>
                            <?php if ($selected_device['customer_code']): ?>
                                (<?php echo htmlspecialchars($selected_device['customer_code']); ?>)
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>

                <!-- Tabs for Existing Configs vs New Config -->
                <div style="margin-bottom: 16px;">
                    <button 
                        type="button"
                        class="btn"
                        style="background: #007bff; margin-right: 8px;"
                        onclick="document.getElementById('configs-list').style.display='block'; document.getElementById('new-config-form').style.display='none';"
                    >
                        <?php echo __('label.view_existing_configs'); ?> (<?php echo count($device_configs); ?>)
                    </button>
                    <button 
                        type="button"
                        class="btn"
                        style="background: #28a745;"
                        onclick="document.getElementById('configs-list').style.display='none'; document.getElementById('new-config-form').style.display='block';"
                    >
                        <?php echo __('button.create_new_config'); ?>
                    </button>
                </div>

                <!-- Existing Configs List -->
                <div id="configs-list" style="display: block;">
                    <?php if (empty($device_configs)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">
                            <?php echo __('label.no_configs_assigned'); ?>
                        </p>
                    <?php else: ?>
                        <div style="max-height: 800px; overflow-y: auto;">
                            <?php foreach ($device_configs as $config): ?>
                                <div style="
                                    padding: 12px;
                                    margin-bottom: 12px;
                                    border: 2px solid <?php echo $config['is_active'] ? '#28a745' : '#ddd'; ?>;
                                    border-radius: 4px;
                                    background: <?php echo $config['is_active'] ? '#f0fff4' : '#fff'; ?>;
                                ">
                                    <!-- Config Header -->
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <strong><?php echo __('label.version_prefix'); ?> <?php echo (int)$config['version_number']; ?></strong>
                                        <?php if ($config['is_active']): ?>
                                            <span style="background: #28a745; color: white; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: bold;">✓ <?php echo __('label.active_config'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Config Details -->
                                    <small style="color: #666; display: block;">
                                        <?php echo __('label.created_prefix'); ?> <?php echo date('Y-m-d H:i', strtotime($config['created_at'])); ?>
                                    </small>
                                    <?php if ($config['activated_at']): ?>
                                        <small style="color: #666; display: block;">
                                            <?php echo __('label.activated_prefix'); ?> <?php echo date('Y-m-d H:i', strtotime($config['activated_at'])); ?>
                                        </small>
                                    <?php endif; ?>

                                    <!-- Buttons -->
                                    <div style="display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                                        <button
                                            type="button"
                                            class="btn"
                                            style="background: #007bff; font-size: 12px; padding: 6px 12px; flex: 1; min-width: 120px;"
                                            onclick="
                                                const preview = document.getElementById('preview-<?php echo (int)$config['id']; ?>');
                                                preview.style.display = preview.style.display === 'none' ? 'block' : 'none';
                                            "
                                        >
                                            <?php echo __('button.preview'); ?>
                                        </button>
                                        <button
                                            type="button"
                                            class="btn"
                                            style="background: #17a2b8; font-size: 12px; padding: 6px 12px; flex: 1; min-width: 120px;"
                                            onclick="copyConfigToEditor(<?php echo (int)$config['id']; ?>, 'v<?php echo (int)$config['version_number']; ?>')"
                                        >
                                            <?php echo __('button.copy_edit'); ?>
                                        </button>
                                        <?php if (!$config['is_active']): ?>
                                        <button
                                            type="button"
                                            class="btn"
                                            style="background: #28a745; font-size: 12px; padding: 6px 12px; flex: 1; min-width: 120px;"
                                            onclick="setConfigActive(<?php echo (int)$selected_device['id']; ?>, <?php echo (int)$config['id']; ?>, <?php echo (int)$config['version_number']; ?>)"
                                        >
                                            <?php echo __('button.set_active'); ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Preview Content -->
                                    <div id="preview-<?php echo (int)$config['id']; ?>" style="
                                        display: none;
                                        background: #1e1e1e;
                                        color: #00ff00;
                                        padding: 12px;
                                        border-radius: 4px;
                                        max-height: 300px;
                                        overflow-y: auto;
                                        font-family: 'Courier New', monospace;
                                        font-size: 11px;
                                        white-space: pre-wrap;
                                        word-break: break-word;
                                        margin-top: 8px;
                                        border: 1px solid #444;
                                    ">
<?php echo htmlspecialchars($config['config_content'] ?? ''); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- New Config Form -->
                <div id="new-config-form" style="display: none;">
                    <form method="post" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="create_config">
                        <input type="hidden" name="device_id" value="<?php echo (int)$selected_device['id']; ?>">

                        <!-- Source Config Info -->
                        <div id="source-config-info" style="display: none; background: #d1ecf1; border: 1px solid #bee5eb; padding: 12px; border-radius: 4px;">
                            <small style="color: #0c5460; display: block;">
                                <?php echo __('label.based_on'); ?> <strong id="source-config-name"></strong>
                            </small>
                            <small style="color: #0c5460; display: block;">
                                <?php echo __('label.edit_creates_new_version'); ?>
                            </small>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                                <?php echo __('form.config_content_label'); ?>
                            </label>
                            <textarea
                                id="config-content"
                                name="config_content"
                                style="
                                    width: 100%;
                                    height: 400px;
                                    padding: 12px;
                                    font-family: 'Courier New', monospace;
                                    font-size: 12px;
                                    border: 1px solid #ddd;
                                    border-radius: 4px;
                                    resize: vertical;
                                    background: #1e1e1e;
                                    color: #00ff00;
                                "
                                placeholder="Paste or edit the device configuration here..."
                                required
                            ></textarea>
                        </div>

                        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px;">
                            <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer;">
                                <input type="checkbox" name="set_active" value="1" id="set_active_checkbox">
                                <strong><?php echo __('label.set_active_config'); ?></strong>
                            </label>
                            <small style="color: #666; display: block; margin-top: 6px;">
                                <?php echo __('label.set_active_config_help'); ?>
                            </small>
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn" style="flex: 1; background: #28a745; font-weight: 600;">
                                <?php echo __('button.create_save_config'); ?>
                            </button>
                            <button 
                                type="button" 
                                class="btn" 
                                style="flex: 1; background: #6c757d;"
                                onclick="
                                    document.getElementById('config-content').value = '';
                                    document.getElementById('source-config-info').style.display = 'none';
                                    document.getElementById('configs-list').style.display='block'; 
                                    document.getElementById('new-config-form').style.display='none';
                                "
                            >
                                <?php echo __('button.cancel'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>


<script>
function setConfigActive(deviceId, configVersionId, versionNumber) {
    if (!confirm('<?php echo __('confirm.activate_config'); ?>' + ' v' + versionNumber + '?')) {
        return;
    }

    const csrfToken = '<?php echo htmlspecialchars($csrf); ?>';
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    const fields = {
        'action': 'activate_config',
        'device_id': deviceId,
        'config_version_id': configVersionId,
        'csrf_token': csrfToken
    };

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

function copyConfigToEditor(configId, versionName) {
    const csrfToken = '<?php echo htmlspecialchars($csrf); ?>';
    
    // Show loading
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '⏳ Loading...';
    btn.disabled = true;
    
    fetch('/settings/builder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'action': 'load_config',
            'config_id': configId,
            'csrf_token': csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        btn.textContent = originalText;
        btn.disabled = false;
        
        if (data.error) {
            alert('Error: ' + data.error);
            return;
        }
        
        // Load config into editor
        document.getElementById('config-content').value = data.config_content;
        document.getElementById('source-config-name').textContent = 'Config ' + versionName;
        document.getElementById('source-config-info').style.display = 'block';
        
        // Switch to editor tab
        document.getElementById('configs-list').style.display = 'none';
        document.getElementById('new-config-form').style.display = 'block';
        
        // Scroll to editor
        document.getElementById('new-config-form').scrollIntoView({ behavior: 'smooth' });
    })
    .catch(error => {
        btn.textContent = originalText;
        btn.disabled = false;
        alert('Error loading config: ' + error);
    });
}
</script>

<style>
    .alert {
        padding: 12px;
        margin-bottom: 16px;
        border-radius: 4px;
        border-left: 4px solid;
    }

    .alert-error {
        background: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }

    .alert-success {
        background: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }

    .btn {
        padding: 10px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: white;
        transition: opacity 0.2s;
    }

    .btn:hover:not(:disabled) {
        opacity: 0.9;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .card {
        background: white;
        padding: 16px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .card h3 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 18px;
    }

    label {
        font-size: 13px;
        font-weight: 500;
        color: #333;
    }

    input[type="text"],
    input[type="email"],
    select,
    textarea {
        font-size: 14px;
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    h2 {
        margin-top: 0;
        margin-bottom: 20px;
        font-size: 28px;
    }
</style>
<?php if ($selected_device_id > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var deviceElement = document.querySelector('[onclick*="device_id=<?php echo (int)$selected_device_id; ?>"]');
    if (deviceElement) {
        deviceElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
