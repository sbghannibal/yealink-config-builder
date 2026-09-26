<?php
/**
 * Create config from template
 *
 * @param PDO $pdo Database connection
 * @param int $template_id Template ID
 * @param array $variable_values Variable values to override
 * @return array ['success' => bool, 'content' => string, 'error' => string|null]
 */
function generate_config_from_template($pdo, $template_id, $variable_values = []) {
    try {
        $stmt = $pdo->prepare('
            SELECT ct.*, dt.type_name AS device_type_name
            FROM config_templates ct
            LEFT JOIN device_types dt ON ct.device_type_id = dt.id
            WHERE ct.id = ? AND ct.is_active = 1
        ');
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return ['success' => false, 'content' => '', 'error' => 'Template niet gevonden'];
        }

        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->prepare('SELECT var_name, default_value FROM template_variables WHERE template_id = ?');
        $stmt->execute([$template_id]);
        $template_vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $variables = array_merge($variables, $template_vars, $variable_values);
        $output_profile = get_device_output_profile($template['device_type_name'] ?? null);
        $variables = normalize_template_variables($variables, $output_profile['format']);

        $content = apply_variables_to_content($template['template_content'], $variables);
        $content = format_generated_config($content, $output_profile['format']);

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'template' => $template,
            'output_profile' => $output_profile,
        ];
    } catch (Exception $e) {
        error_log('Config generator error: ' . $e->getMessage());
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren configuratie'];
    }
}

/**
 * Generate a device-aware config from a stored config version.
 *
 * Safe defaults used for non-Yealink SIP templates:
 * - SIP_SERVER_PORT defaults to 5060
 * - SIP_RTP_PORT defaults to 5004
 * - SIP_USE_TLS defaults to 0
 * - SIP_DTMF_RECV_RTP defaults to 1
 * - outbound proxy defaults to empty unless explicitly provided
 */
function generate_device_config($pdo, $device_id, $config_version_id = null) {
    try {
        $stmt = $pdo->prepare('
            SELECT d.id, d.device_name, d.mac_address, d.ip_address, dt.type_name AS device_type_name
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            WHERE d.id = ?
            LIMIT 1
        ');
        $stmt->execute([$device_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            return ['success' => false, 'content' => '', 'error' => 'Device niet gevonden'];
        }

        if ($config_version_id) {
            $stmt = $pdo->prepare('
                SELECT cv.id, cv.config_content, cv.pabx_id,
                       p.pabx_name, p.pabx_ip, p.pabx_port, p.pabx_type
                FROM config_versions cv
                LEFT JOIN pabx p ON cv.pabx_id = p.id
                WHERE cv.id = ?
                LIMIT 1
            ');
            $stmt->execute([$config_version_id]);
        } else {
            $stmt = $pdo->prepare('
                SELECT cv.id, cv.config_content, cv.pabx_id,
                       p.pabx_name, p.pabx_ip, p.pabx_port, p.pabx_type
                FROM device_config_assignments dca
                INNER JOIN config_versions cv ON dca.config_version_id = cv.id
                LEFT JOIN pabx p ON cv.pabx_id = p.id
                WHERE dca.device_id = ? AND dca.is_active = 1
                LIMIT 1
            ');
            $stmt->execute([$device_id]);
        }

        $config_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config_data || empty($config_data['config_content'])) {
            return ['success' => false, 'content' => '', 'error' => 'Configuratie niet gevonden'];
        }

        $output_profile = get_device_output_profile($device['device_type_name'] ?? null, $device['mac_address'] ?? '');
        $variables = normalize_template_variables(
            build_device_template_variables($device, $config_data),
            $output_profile['format']
        );

        $content = apply_variables_to_content($config_data['config_content'], $variables);
        $content = format_generated_config($content, $output_profile['format']);

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'device' => $device,
            'config' => $config_data,
            'output_profile' => $output_profile,
        ];
    } catch (Exception $e) {
        error_log('Device config generator error: ' . $e->getMessage());
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren deviceconfiguratie'];
    }
}

function build_device_template_variables(array $device, array $config_data = []) {
    $mac_formatted = strtoupper((string)($device['mac_address'] ?? ''));
    $mac_plain = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac_formatted));
    $device_name = (string)($device['device_name'] ?? '');
    $pabx_ip = (string)($config_data['pabx_ip'] ?? '');
    $pabx_port = (string)($config_data['pabx_port'] ?? '');
    $sip_server_port = $pabx_port !== '' ? $pabx_port : '5060';

    return [
        'DEVICE_NAME' => $device_name,
        'DEVICE_MAC' => $mac_plain,
        'DEVICE_MAC_FORMATTED' => $mac_formatted,
        'DEVICE_IP' => (string)($device['ip_address'] ?? ''),
        'DEVICE_MODEL' => (string)($device['device_type_name'] ?? ''),
        'PABX_NAME' => (string)($config_data['pabx_name'] ?? ''),
        'PABX_IP' => $pabx_ip,
        'PABX_PORT' => $pabx_port,
        'PABX_TYPE' => (string)($config_data['pabx_type'] ?? ''),
        'DISPLAY_NAME' => $device_name,
        'USER_NAME' => '',
        'AUTH_NAME' => '',
        'USER_PASSWORD' => '',
        'PHONE_NUMBER' => '',
        'NUMBER' => '',
        'OUTBOUND_PROXY' => '',
        'OUTBOUND_PROXY_PORT' => '',
        'SIP_SERVER_ADDRESS' => $pabx_ip,
        'SIP_SERVER_PORT' => $sip_server_port,
        'SIP_OUTBOUND_PROXY_SERVER_1' => '',
        'SIP_OUTBOUND_PROXY_SERVER_1_PORT' => '',
        'SIP_DISPLAY_NAME' => $device_name,
        'SIP_PHONE_NUMBER' => '',
        'SIP_USERNAME' => '',
        'SIP_PASSWORD' => '',
        'SIP_RTP_PORT' => '5004',
        'SIP_USE_TLS' => '0',
        'SIP_DTMF_RECV_RTP' => '1',
    ];
}

function normalize_template_variables(array $variables, $format = 'cfg') {
    $normalized = [];

    foreach ($variables as $key => $value) {
        if (is_array($value)) {
            $value = implode(',', $value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }

        $value = str_replace("\0", '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if ($format === 'xml') {
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $normalized[$key] = $value;
    }

    return $normalized;
}

function get_device_output_profile($device_type_name = null, $mac_address = '') {
    $device_type_name = strtolower(trim((string) $device_type_name));
    $mac_clean = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string) $mac_address));

    switch ($device_type_name) {
        case 'cisco_atabox_192':
            return [
                'format' => 'cfg',
                'content_type' => 'text/plain; charset=utf-8',
                'filename' => 'init.cfg',
            ];

        case 'fasttel_ft600':
            return [
                'format' => 'xml',
                'content_type' => 'application/xml; charset=utf-8',
                'filename' => $mac_clean !== '' ? 'fasttel_ft600_' . $mac_clean . '.xml' : 'fasttel_ft600.xml',
            ];

        default:
            return [
                'format' => 'cfg',
                'content_type' => 'text/plain; charset=utf-8',
                'filename' => $mac_clean !== '' ? 'yealink_' . $mac_clean . '.cfg' : 'yealink.cfg',
            ];
    }
}

function requires_yealink_user_agent($device_type_name = null) {
    return !in_array(strtolower(trim((string) $device_type_name)), ['cisco_atabox_192', 'fasttel_ft600'], true);
}

function format_generated_config($content, $format = 'cfg') {
    $content = str_replace(["\r\n", "\r"], "\n", (string) $content);

    if ($format === 'xml') {
        $content = preg_replace('/[ \t]+$/m', '', $content);
        return rtrim($content) . "\n";
    }

    return apply_yealink_formatting($content);
}

/**
 * Apply variables to template content
 * Replaces {{VARIABLE_NAME}} placeholders with actual values
 *
 * @param string $content Template content with placeholders
 * @param array $variables Associative array of variable names and values
 * @return string Content with placeholders replaced by actual values
 */
function apply_variables_to_content($content, $variables) {
    foreach ($variables as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        $content = str_replace($placeholder, $value, $content);
    }

    preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $content, $matches);
    if (!empty($matches[1])) {
        error_log('WARNING: Unreplaced template placeholders: ' . implode(', ', $matches[1]));
    }

    return $content;
}

/**
 * Apply Yealink-specific formatting to config content
 * Ensures proper .cfg file format with Unix line endings
 *
 * @param string $content Configuration content to format
 * @return string Formatted content suitable for Yealink devices
 */
function apply_yealink_formatting($content) {
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = preg_replace('/^([a-zA-Z0-9._\\[\\]]+)\s*=\s*(.*)$/m', '$1=$2', $content);
    $content = preg_replace('/[ \t]+$/m', '', $content);
    $content = rtrim($content) . "\n";

    return $content;
}
?>
