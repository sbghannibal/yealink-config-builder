<?php
/**
 * Config Download Endpoint
 *
 * Secure endpoint for downloading Yealink .cfg files
 * Features:
 * - Token validation
 * - MAC address verification
 * - Downloads ACTIVE config for device (not latest)
 * - Audit logging
 * - Proper .cfg Content-Type
 */

require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/settings/generator.php';

// Disable output buffering for clean file delivery
if (ob_get_level()) {
    ob_end_clean();
}

/**
 * Log download attempt
 */
function log_download($pdo, $config_version_id, $device_id, $mac_address, $success, $error = null) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO config_download_history
            (config_version_id, device_id, mac_address, ip_address, user_agent, download_time)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $config_version_id,
            $device_id,
            $mac_address,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log('Download logging error: ' . $e->getMessage());
    }
}

/**
 * Send error response
 */
function send_error($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Error: $message\n";
    exit;
}

// Get request parameters
$token = $_GET['token'] ?? '';
$mac = $_GET['mac'] ?? '';

// Validate token parameter
if (empty($token)) {
    send_error(400, 'Missing token parameter');
}

try {
    // Fetch and validate token
    $stmt = $pdo->prepare('
        SELECT dt.*, cv.config_content, cv.pabx_id, cv.device_type_id, cv.id as config_version_id,
               dvt.type_name AS device_type_name
        FROM download_tokens dt
        JOIN config_versions cv ON dt.config_version_id = cv.id
        LEFT JOIN device_types dvt ON cv.device_type_id = dvt.id
        WHERE dt.token = ? AND dt.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_data) {
        send_error(403, 'Invalid or expired token');
    }

    // Verify MAC address if specified in token
    if (!empty($token_data['mac_address']) && !empty($mac)) {
        // Normalize MAC addresses for comparison
        $token_mac = strtoupper(str_replace([':', '-', '.'], '', $token_data['mac_address']));
        $request_mac = strtoupper(str_replace([':', '-', '.'], '', $mac));

        if ($token_mac !== $request_mac) {
            send_error(403, 'MAC address mismatch');
        }
    }

    // Find device by MAC if provided
    $device_id = null;
    $config_version_id = $token_data['config_version_id']; // Default to token's config
    
    if (!empty($mac)) {
        $stmt = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? LIMIT 1');
        $stmt->execute([$mac]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device) {
            $device_id = $device['id'];
            
            // FIXED: Get ACTIVE config for this device, not the token's config
            $stmt = $pdo->prepare('
                SELECT config_version_id
                FROM device_config_assignments
                WHERE device_id = ? AND is_active = 1
                LIMIT 1
            ');
            $stmt->execute([$device_id]);
            $active_config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($active_config) {
                $config_version_id = $active_config['config_version_id'];
            }
        }
    }

    // Fetch the config to use (either active or token's default)
    $stmt = $pdo->prepare('
        SELECT id, config_content, device_type_id
        FROM config_versions
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$config_version_id]);
    $config_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config_data) {
        send_error(404, 'Configuration not found');
    }

    // Generate config
    if ($device_id) {
        $result = generate_device_config($pdo, $device_id, $config_version_id);
    } else {
        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $output_profile = get_device_output_profile($token_data['device_type_name'] ?? null, $mac);

        if (!empty($mac)) {
            $variables['DEVICE_MAC'] = $mac;
        }

        $variables = normalize_template_variables($variables, $output_profile['format']);
        $content = apply_variables_to_content($config_data['config_content'], $variables);
        $content = format_generated_config($content, $output_profile['format']);

        $result = [
            'success' => true,
            'content' => $content,
            'output_profile' => $output_profile,
        ];
    }

    if (!$result['success']) {
        send_error(500, $result['error'] ?? 'Config generation failed');
    }

    // Mark token as used
    try {
        $stmt = $pdo->prepare('UPDATE download_tokens SET used_at = NOW() WHERE token = ?');
        $stmt->execute([$token]);
    } catch (Exception $e) {
        error_log('Token update error: ' . $e->getMessage());
    }

    // Log download
    log_download($pdo, $config_version_id, $device_id, $mac, true);

    $output_profile = $result['output_profile'] ?? get_device_output_profile($token_data['device_type_name'] ?? null, $mac);

    header('Content-Type: ' . $output_profile['content_type']);
    header('Content-Disposition: attachment; filename="' . $output_profile['filename'] . '"');
    header('Content-Length: ' . strlen($result['content']));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output config content
    echo $result['content'];
    exit;

} catch (PDOException $e) {
    error_log('Download database error: ' . $e->getMessage());
    send_error(500, 'Database error');
} catch (Exception $e) {
    error_log('Download error: ' . $e->getMessage());
    send_error(500, 'Internal server error');
}
?>
