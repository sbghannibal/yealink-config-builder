<?php
/**
 * Download Device Config
 * Allows logged-in users to download device configurations directly
 * FIXED: Downloads ACTIVE config, not latest assigned
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/download_error.log');

session_start();
require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/settings/generator.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/partner_access.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$admin_id = (int) $_SESSION['admin_id'];

// Check permission
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    exit('Access denied. Insufficient permissions.');
}

// Get parameters
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;
$mac_param = isset($_GET['mac']) ? trim($_GET['mac']) : null;

if (!$device_id || !$mac_param) {
    http_response_code(400);
    exit('Missing required parameters: device_id and mac');
}

// Tenant check: verify the device belongs to an allowed customer
assert_device_allowed($pdo, $admin_id, $device_id);

try {
    error_log("Download request: device_id=$device_id, mac=$mac_param");

    // Fetch device from database - FIXED: Get ACTIVE config
    $stmt = $pdo->prepare('
        SELECT d.*, dt.type_name as model_name,
               (SELECT dca.config_version_id
                FROM device_config_assignments dca
                WHERE dca.device_id = d.id AND dca.is_active = 1
                LIMIT 1) as config_version_id
        FROM devices d
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        WHERE d.id = ?
    ');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        error_log("Device not found: $device_id");
        http_response_code(404);
        exit('Device not found');
    }

    error_log("Device found: {$device['device_name']}, MAC: {$device['mac_address']}");

    // Verify MAC address matches (security check)
    if (strtolower($device['mac_address']) !== strtolower($mac_param)) {
        error_log("MAC mismatch: expected {$device['mac_address']}, got $mac_param");
        http_response_code(403);
        exit('MAC address verification failed');
    }

    // Check if device has an ACTIVE config assigned
    if (!$device['config_version_id']) {
        error_log("No ACTIVE config assigned to device $device_id");
        http_response_code(404);
        exit('No active configuration assigned to this device');
    }

    error_log("Active config version ID: {$device['config_version_id']}");

    // Get config content and PABX info
    $stmt = $pdo->prepare('
        SELECT cv.config_content, cv.pabx_id,
               p.pabx_name, p.pabx_ip, p.pabx_port, p.pabx_type
        FROM config_versions cv
        LEFT JOIN pabx p ON cv.pabx_id = p.id
        WHERE cv.id = ?
    ');
    $stmt->execute([$device['config_version_id']]);
    $config_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config_data || !$config_data['config_content']) {
        error_log("Config content not found for version {$device['config_version_id']}");
        http_response_code(404);
        exit('Configuration not found');
    }

    error_log("Config content retrieved, length: " . strlen($config_data['config_content']));

    $config_content = $config_data['config_content'];
    $mac_no_colons = strtoupper(str_replace(':', '', $device['mac_address']));
    $output_profile = get_device_output_profile($device['model_name'] ?? '', $device['mac_address'] ?? '');
    $device['device_type_name'] = $device['model_name'] ?? '';
    $device_variables = normalize_template_variables(
        build_device_template_variables($device, $config_data),
        $output_profile['format']
    );

    error_log("Applying variables and formatting...");

    $config_content = apply_variables_to_content($config_content, $device_variables);
    $config_content = format_generated_config($config_content, $output_profile['format']);

    error_log("Config prepared, sending download...");

    // Log the download in database
    try {
        $stmt = $pdo->prepare('
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $admin_id,
            'config.download',
            'device',
            $device_id,
            json_encode(['device_name' => $device['device_name'], 'mac_address' => $device['mac_address'], 'config_version_id' => $device['config_version_id']]),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Failed to log download audit: " . $e->getMessage());
        // Don't fail the download if audit fails
    }

    // Send as download
    header('Content-Type: ' . $output_profile['content_type']);
    header('Content-Disposition: attachment; filename="' . $output_profile['filename'] . '"');
    header('Content-Length: ' . strlen($config_content));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo $config_content;
    error_log("Download sent successfully for active config version {$device['config_version_id']}");
    exit;

} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage());
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("Download error: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    http_response_code(500);
    exit('Server error: ' . $e->getMessage());
}
?>
