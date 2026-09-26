<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/provision_error.log');

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../settings/generator.php';

error_log("=== PROVISION REQUEST START ===");
error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
error_log("USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'NONE'));

$user_agent  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$ip_address  = $_SERVER['REMOTE_ADDR'] ?? '';

// --- Helper: parse filename and extension from URI ---
function parse_requested_file($uri) {
    $path     = parse_url($uri, PHP_URL_PATH) ?? '';
    $filename = basename($path);
    $ext      = ($filename !== '' && strpos($filename, '.') !== false)
        ? strtolower(pathinfo($filename, PATHINFO_EXTENSION))
        : null;
    return [
        'filename' => $filename !== '' ? $filename : null,
        'ext'      => ($ext !== '') ? $ext : null,
    ];
}

// --- Helper: parse Yealink model from User-Agent ---
// UA examples: "Yealink W75DM 108.83.0.40 ...", "Yealink SIP-T46S 66.86.5.20 ..."
function parse_device_model($ua) {
    if (preg_match('/Yealink\s+(?:SIP-)?([A-Z][A-Z0-9]+)/i', $ua, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

// --- Helper: upsert provision_attempt with dedup (60-second window) ---
function log_provision_attempt($pdo, array $data) {
    $dedup_window = 60; // seconds

    $mac      = $data['mac_normalized'] ?? null;
    $status   = $data['status']         ?? 'unknown';
    $uri      = $data['request_uri']    ?? '';
    $filename = $data['requested_filename'] ?? null;

    try {
        // Dedup: same mac+status+requested_filename within window
        if ($mac !== null) {
            $stmt = $pdo->prepare('
                SELECT id FROM provision_attempts
                WHERE mac_normalized = ? AND status = ? AND requested_filename <=> ?
                  AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1
            ');
            $stmt->execute([$mac, $status, $filename, $dedup_window]);
        } else {
            $stmt = $pdo->prepare('
                SELECT id FROM provision_attempts
                WHERE mac_normalized IS NULL AND status = ? AND requested_filename <=> ?
                  AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1
            ');
            $stmt->execute([$status, $filename, $dedup_window]);
        }
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $upd = $pdo->prepare('
                UPDATE provision_attempts
                SET attempt_count     = attempt_count + 1,
                    last_seen_at      = NOW(),
                    ip_address        = ?,
                    proxy_ip_address  = ?,
                    device_id         = COALESCE(?, device_id),
                    config_version_id = COALESCE(?, config_version_id)
                WHERE id = ?
            ');
            $upd->execute([
                $data['ip_address'] ?? '',
                $data['proxy_ip_address'] ?? ($data['ip_address'] ?? ''),
                $data['device_id'] ?? null,
                $data['config_version_id'] ?? null,
                $existing['id'],
            ]);
        } else {
            $ins = $pdo->prepare('
                INSERT INTO provision_attempts
                  (mac_normalized, mac_formatted, mac_source, status, request_uri,
                   requested_filename, requested_ext,
                   ip_address, proxy_ip_address, user_agent,
                   device_model, device_id, config_version_id,
                   attempt_count, first_seen_at, last_seen_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ');
            $ins->execute([
                $data['mac_normalized'] ?? null,
                $data['mac_formatted'] ?? null,
                $data['mac_source'] ?? 'none',
                $status,
                $data['request_uri'] ?? '',
                $data['requested_filename'] ?? null,
                $data['requested_ext'] ?? null,
                $data['ip_address'] ?? '',
                $data['proxy_ip_address'] ?? ($data['ip_address'] ?? ''),
                $data['user_agent'] ?? '',
                $data['device_model'] ?? null,
                $data['device_id'] ?? null,
                $data['config_version_id'] ?? null,
            ]);
        }
    } catch (Exception $e) {
        error_log("provision_attempt log error: " . $e->getMessage());
    }
}

// --- Shared attempt data built up progressively ---
$file_info    = parse_requested_file($request_uri);
error_log("file_info: " . json_encode($file_info) . " ext_check=" . (($file_info["ext"] ?? "") ?: "NULL"));
$device_model = parse_device_model($user_agent);

  // Normalize noisy Yealink filenames so they dedup into a few buckets
  $requested_filename_raw  = $file_info['filename'] ?? null;
  $requested_filename_norm = $requested_filename_raw;

  if (is_string($requested_filename_norm)) {
      // y000000000000.boot / y000000000175.cfg etc
      if (preg_match('/^y\d{12}\.(boot|cfg)$/i', $requested_filename_norm, $m)) {
          $requested_filename_norm = '__yealink_generic__.' . strtolower($m[1]);
      }
      // 12-hex MAC filenames like 249ad8b38b35.boot/.cfg
      elseif (preg_match('/^[0-9a-f]{12}\.(boot|cfg)$/i', $requested_filename_norm, $m)) {
          $requested_filename_norm = '__mac__.' . strtolower($m[1]);
      }
  }


$attempt = [
    'mac_normalized'     => null,
    'mac_formatted'      => null,
    'mac_source'         => 'none',
    'request_uri'        => $request_uri,
    'requested_filename' => $requested_filename_norm,
    'requested_ext'      => $file_info['ext'],
    'ip_address'         => $ip_address,
    'proxy_ip_address'  => ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent'         => $user_agent,
    'device_model'       => $device_model,
    'status'             => 'unknown',
    'device_id'          => null,
    'config_version_id'  => null,
];

// Get MAC address from request
$mac        = null;
$mac_source = 'none';

// Method 1: From URL path (e.g., /provision/001565123456.cfg or .boot)
// Only match known Yealink provisioning file extensions to avoid matching unrelated URIs
if (preg_match('/([0-9a-f]{12})\.(cfg|boot|xml|ini)$/i', $_SERVER['REQUEST_URI'], $matches)) {
    $mac        = strtoupper($matches[1]);
    $mac_source = 'uri';
}

// Method 2: From query parameter
if (!$mac && isset($_GET['mac'])) {
    $mac        = strtoupper(preg_replace('/[^0-9A-F]/i', '', $_GET['mac']));
    $mac_source = 'query';
}

error_log("MAC extracted: " . ($mac ?? 'NONE'));

$attempt['mac_source'] = $mac_source;

if (!$mac) {
    $attempt['status'] = 'invalid_mac';
    log_provision_attempt($pdo, $attempt);
    http_response_code(400);
    exit('MAC address required');
}

// Validate MAC format
if (!preg_match('/^[0-9A-F]{12}$/', $mac)) {
    $attempt['status'] = 'invalid_mac';
    log_provision_attempt($pdo, $attempt);
    http_response_code(400);
    exit('Invalid MAC format');
}

// Format MAC with colons
$mac_formatted = substr($mac, 0, 2) . ':' . substr($mac, 2, 2) . ':' . substr($mac, 4, 2) . ':' .
                 substr($mac, 6, 2) . ':' . substr($mac, 8, 2) . ':' . substr($mac, 10, 2);

error_log("MAC formatted: $mac_formatted");

$attempt['mac_normalized'] = $mac;
$attempt['mac_formatted']  = $mac_formatted;

try {
    error_log("Querying database for device...");

    // Find device by MAC and get ACTIVE config version (FIXED)
    $stmt = $pdo->prepare('
        SELECT d.*, dt.type_name AS device_type_name, cv.id as config_version_id,
               cv.config_content, cv.pabx_id,
               p.pabx_name, p.pabx_ip, p.pabx_port, p.pabx_type
        FROM devices d
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        LEFT JOIN device_config_assignments dca ON d.id = dca.device_id AND dca.is_active = 1
        LEFT JOIN config_versions cv ON dca.config_version_id = cv.id
        LEFT JOIN pabx p ON cv.pabx_id = p.id
        WHERE d.mac_address = ? AND d.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$mac_formatted]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Device found: " . ($device ? 'YES' : 'NO'));

    if (!$device) {
        error_log("Device not found or inactive");
        $attempt['status'] = 'device_not_found';
        log_provision_attempt($pdo, $attempt);
        http_response_code(404);
        exit('Device not found');
    }

    $attempt['device_id'] = (int) $device['id'];
    error_log("Device ID: " . $device['id']);
    error_log("Config version ID: " . ($device['config_version_id'] ?? 'NONE'));

    $requires_yealink_agent = requires_yealink_user_agent($device['device_type_name'] ?? '');
    $is_yealink = stripos($user_agent, 'Yealink') !== false;
    error_log("Requires Yealink UA: " . ($requires_yealink_agent ? 'YES' : 'NO') . ", Is Yealink: " . ($is_yealink ? 'YES' : 'NO'));

    if ($requires_yealink_agent && !$is_yealink) {
        error_log("Provisioning blocked by user agent policy");
        $attempt['status'] = 'blocked_user_agent';
        log_provision_attempt($pdo, $attempt);
        http_response_code(403);
        exit('Access denied');
    }

    if (!$device['config_version_id']) {
        error_log("No ACTIVE config version assigned");
        $attempt['status'] = 'no_active_config';
        log_provision_attempt($pdo, $attempt);
        http_response_code(404);
        exit('No active configuration assigned');
    }

    $attempt['config_version_id'] = (int) $device['config_version_id'];
    $attempt['status']            = 'success';
    log_provision_attempt($pdo, $attempt);

    error_log("ACTIVE config found, sending response");

    // Log to legacy provision_logs table for backwards compatibility (CFG only)
    if (($file_info['ext'] ?? '') === 'cfg') {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO provision_logs (device_id, mac_address, ip_address, user_agent, provisioned_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$device["id"], $mac_formatted, $ip_address, $user_agent]);
    } catch (Exception $e) {
        error_log("Failed to log to provision_logs: " . $e->getMessage());
    }
    }


    // If .boot was requested: log it (already done above) but do NOT serve config.
    // Return 404 so the device continues with .cfg.
    if (($file_info['ext'] ?? '') === 'boot') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }

    $output_profile = get_device_output_profile($device['device_type_name'] ?? '', $device['mac_address'] ?? $mac);
    $config_variables = normalize_template_variables(
        build_device_template_variables($device, $device),
        $output_profile['format']
    );
    $config_content = apply_variables_to_content($device['config_content'], $config_variables);
    $config_content = format_generated_config($config_content, $output_profile['format']);

    header('Content-Type: ' . $output_profile['content_type']);
    header('Content-Disposition: attachment; filename="' . $output_profile['filename'] . '"');
    echo $config_content;

    error_log("=== PROVISION REQUEST SUCCESS - Config version {$device['config_version_id']} ===");

} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    $attempt['status'] = 'db_error';
    try {
        log_provision_attempt($pdo, $attempt);
    } catch (Exception $le) {
        error_log("Could not log db_error attempt: " . $le->getMessage());
    }
    http_response_code(500);
    exit('Database error');
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    $attempt['status'] = 'server_error';
    try {
        log_provision_attempt($pdo, $attempt);
    } catch (Exception $le) {
        error_log("Could not log server_error attempt: " . $le->getMessage());
    }
    http_response_code(500);
    exit('Server error');
}
?>
