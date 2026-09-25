<?php
/**
 * Seed Pre-built Yealink Config Templates
 * 
 * This script creates default configuration templates for common Yealink phone models.
 * Run after migrations: php scripts/seed_templates.php
 */

require_once __DIR__ . '/../settings/database.php';

echo PHP_EOL . "Seeding Yealink Config Templates..." . PHP_EOL;

function ensure_template_record(PDO $pdo, $admin_id, array $template) {
    $stmt = $pdo->prepare('SELECT id FROM device_types WHERE type_name = ? LIMIT 1');
    $stmt->execute([$template['device_type']]);
    $device_type_id = $stmt->fetchColumn();

    if (!$device_type_id) {
        throw new RuntimeException('Device type not found: ' . $template['device_type']);
    }

    $stmt = $pdo->prepare('SELECT id FROM config_templates WHERE template_name = ? AND device_type_id = ? LIMIT 1');
    $stmt->execute([$template['name'], $device_type_id]);
    $template_id = $stmt->fetchColumn();

    if ($template_id) {
        $stmt = $pdo->prepare('
            UPDATE config_templates
            SET category = ?, description = ?, template_content = ?, is_active = 1, is_default = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $template['category'],
            $template['description'],
            $template['content'],
            !empty($template['is_default']) ? 1 : 0,
            $template_id,
        ]);

        return (int) $template_id;
    }

    $stmt = $pdo->prepare('
        INSERT INTO config_templates
        (template_name, device_type_id, category, description, template_content, is_active, is_default, created_by)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)
    ');
    $stmt->execute([
        $template['name'],
        $device_type_id,
        $template['category'],
        $template['description'],
        $template['content'],
        !empty($template['is_default']) ? 1 : 0,
        $admin_id,
    ]);

    return (int) $pdo->lastInsertId();
}

function ensure_template_variable_record(PDO $pdo, $template_id, array $variable) {
    $stmt = $pdo->prepare('SELECT id FROM template_variables WHERE template_id = ? AND var_name = ? LIMIT 1');
    $stmt->execute([$template_id, $variable['var_name']]);
    $variable_id = $stmt->fetchColumn();

    $data = [
        $variable['var_label'] ?? null,
        $variable['var_type'] ?? 'text',
        $variable['default_value'] ?? null,
        !empty($variable['is_required']) ? 1 : 0,
        $variable['placeholder'] ?? null,
        $variable['help_text'] ?? null,
        $variable['min_value'] ?? null,
        $variable['max_value'] ?? null,
        $variable['regex_pattern'] ?? null,
        $variable['options'] ?? null,
        $variable['display_order'] ?? 0,
    ];

    if ($variable_id) {
        $stmt = $pdo->prepare('
            UPDATE template_variables
            SET var_label = ?, var_type = ?, default_value = ?, is_required = ?, placeholder = ?,
                help_text = ?, min_value = ?, max_value = ?, regex_pattern = ?, options = ?, display_order = ?
            WHERE id = ?
        ');
        $stmt->execute(array_merge($data, [$variable_id]));
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO template_variables
        (template_id, var_name, var_label, var_type, default_value, is_required, placeholder, help_text,
         min_value, max_value, regex_pattern, options, display_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute(array_merge([
        $template_id,
        $variable['var_name'],
    ], $data));
}

// Templates data: [name, device_type, category, description, content]
$templates = [
    // T20/T21 Basic Templates
    [
        'T20/T21 Basic Config',
        'T21P',
        'Basic',
        'Basic configuration for T20/T21 series phones',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
CFG
    ],
    
    // T40/T41/T42 Advanced Templates
    [
        'T40/T41/T42 Standard Config',
        'T42P',
        'Advanced',
        'Standard configuration for T40/T41/T42 series with BLF support',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}
static_dns_server={{DNS_SERVER}}

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}
account.1.codec.1.enable=1
account.1.codec.1.payload_type=PCMU
account.1.codec.2.enable=1
account.1.codec.2.payload_type=PCMA
account.1.codec.3.enable=1
account.1.codec.3.payload_type=G729

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
voice_mail.number.1={{VOICEMAIL_NUMBER}}
auto_answer.enable=0
screensaver.enable=1
screensaver.timeout=60

[PROGRAMMABLE_KEYS]
programablekey.1.type=16
programablekey.1.line={{BLF_LINE_1}}
programablekey.1.value={{BLF_EXT_1}}
programablekey.1.label={{BLF_LABEL_1}}
CFG
    ],
    
    // T46/T48 Executive Templates
    [
        'T46/T48 Executive Config',
        'T48P',
        'Executive',
        'Full-featured configuration for T46/T48 executive phones',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}
static_dns_server={{DNS_SERVER}}
vlan.internet_port_enable=1
vlan.internet_port_vid={{VLAN_VOICE_ID}}
vlan.internet_port_priority={{VLAN_VOICE_PRIORITY}}

[TIME]
ntp_server1={{NTP_SERVER}}
ntp_server2={{NTP_SERVER_2}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0
daylight_saving_time.enable=1

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}
account.1.transport=1
account.1.sip_trust_ctrl=0
account.1.codec.1.enable=1
account.1.codec.1.payload_type=PCMU
account.1.codec.2.enable=1
account.1.codec.2.payload_type=PCMA
account.1.codec.3.enable=1
account.1.codec.3.payload_type=G729
account.1.codec.4.enable=1
account.1.codec.4.payload_type=G722

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
voice_mail.number.1={{VOICEMAIL_NUMBER}}
auto_answer.enable=0
screensaver.enable=1
screensaver.timeout=120
backlight.active_level={{BACKLIGHT_LEVEL}}
backlight.idle_level={{BACKLIGHT_IDLE_LEVEL}}

[SECURITY]
security.user_password={{USER_PASSWORD}}
security.admin_password={{ADMIN_PASSWORD}}

[DIRECTORY]
remote_phonebook.data.1.url={{PHONEBOOK_URL}}
remote_phonebook.data.1.name={{PHONEBOOK_NAME}}
CFG
    ],
    
    // Hotel/Hospitality Template
    [
        'Hotel Guest Room Config',
        'T43P',
        'Hospitality',
        'Simplified configuration for hotel guest rooms',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}

[SIP]
account.1.enable=1
account.1.label=Room {{ROOM_NUMBER}}
account.1.display_name=Room {{ROOM_NUMBER}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}

[PHONE]
auto_answer.enable=0
voice_mail.number.1=*97
features.forward_mode=0
features.call_waiting=0

[PROGRAMMABLE_KEYS]
# Speed dial keys for hotel services
programablekey.1.type=13
programablekey.1.line=1
programablekey.1.value={{RECEPTION_NUMBER}}
programablekey.1.label=Reception

programablekey.2.type=13
programablekey.2.line=1
programablekey.2.value={{HOUSEKEEPING_NUMBER}}
programablekey.2.label=Housekeeping

programablekey.3.type=13
programablekey.3.line=1
programablekey.3.value={{ROOM_SERVICE_NUMBER}}
programablekey.3.label=Room Service

[SECURITY]
# Lock down settings
features.user_mode_password={{USER_MODE_PASSWORD}}
features.admin_mode_password={{ADMIN_MODE_PASSWORD}}
CFG
    ],
];

$extended_templates = [
    [
        'name' => 'Cisco ATABOX 192 init.cfg',
        'device_type' => 'cisco_atabox_192',
        'category' => 'Cisco',
        'description' => 'Dynamisch gegenereerde init.cfg voor Cisco ATABOX model 192',
        'is_default' => 1,
        'content' => <<<'CFG'
#!version:1.0.0.1
account.1.enable=1
account.1.display_name={{SIP_DISPLAY_NAME}}
account.1.auth_name={{SIP_USERNAME}}
account.1.user_name={{SIP_PHONE_NUMBER}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server.1.address={{SIP_SERVER_ADDRESS}}
account.1.sip_server.1.port={{SIP_SERVER_PORT}}
account.1.outbound_proxy.1.address={{SIP_OUTBOUND_PROXY_SERVER_1}}
account.1.outbound_proxy.1.port={{SIP_OUTBOUND_PROXY_SERVER_1_PORT}}
CFG,
        'variables' => [
            ['var_name' => 'SIP_SERVER_ADDRESS', 'var_label' => 'SIP server', 'var_type' => 'text', 'is_required' => 1, 'placeholder' => 'pbx.example.local', 'help_text' => 'Registrar/SIP server adres voor de ATABOX.', 'display_order' => 10],
            ['var_name' => 'SIP_SERVER_PORT', 'var_label' => 'SIP server port', 'var_type' => 'number', 'default_value' => '5060', 'is_required' => 1, 'min_value' => 1, 'max_value' => 65535, 'help_text' => 'Default 5060 wanneer geen aparte poort is ingevuld.', 'display_order' => 20],
            ['var_name' => 'SIP_USERNAME', 'var_label' => 'SIP username', 'var_type' => 'text', 'is_required' => 1, 'display_order' => 30],
            ['var_name' => 'SIP_PASSWORD', 'var_label' => 'SIP password', 'var_type' => 'password', 'is_required' => 1, 'display_order' => 40],
            ['var_name' => 'SIP_DISPLAY_NAME', 'var_label' => 'SIP display name', 'var_type' => 'text', 'placeholder' => 'Toestelnaam of extensie', 'help_text' => 'Valt terug op de toestelnaam als dit leeg blijft in de download/provisioning flow.', 'display_order' => 50],
            ['var_name' => 'SIP_PHONE_NUMBER', 'var_label' => 'SIP phone number', 'var_type' => 'text', 'is_required' => 1, 'display_order' => 60],
            ['var_name' => 'SIP_OUTBOUND_PROXY_SERVER_1', 'var_label' => 'SIP outbound proxy server 1', 'var_type' => 'text', 'display_order' => 70],
            ['var_name' => 'SIP_OUTBOUND_PROXY_SERVER_1_PORT', 'var_label' => 'SIP outbound proxy server 1 port', 'var_type' => 'number', 'min_value' => 1, 'max_value' => 65535, 'display_order' => 80],
        ],
    ],
    [
        'name' => 'Fasttel FT600 SIP XML',
        'device_type' => 'fasttel_ft600',
        'category' => 'Fasttel',
        'description' => 'XML-configuratie voor Fasttel FT600 doorphone in schema/table/row-formaat',
        'is_default' => 1,
        'content' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<schema>
  <table name="ft600_config">
    <row key="SIP server address" value="{{SIP_SERVER_ADDRESS}}" />
    <row key="SIP server port" value="{{SIP_SERVER_PORT}}" />
    <row key="SIP outbound proxy server 1" value="{{SIP_OUTBOUND_PROXY_SERVER_1}}" />
    <row key="SIP outbound proxy server 1 port" value="{{SIP_OUTBOUND_PROXY_SERVER_1_PORT}}" />
    <row key="SIP display name" value="{{SIP_DISPLAY_NAME}}" />
    <row key="SIP phone number" value="{{SIP_PHONE_NUMBER}}" />
    <row key="SIP username" value="{{SIP_USERNAME}}" />
    <row key="SIP password" value="{{SIP_PASSWORD}}" />
    <row key="SIP rtp port" value="{{SIP_RTP_PORT}}" />
    <row key="SIP use tls" value="{{SIP_USE_TLS}}" />
    <row key="SIP dtmf recv rtp" value="{{SIP_DTMF_RECV_RTP}}" />
  </table>
</schema>
XML,
        'variables' => [
            ['var_name' => 'SIP_SERVER_ADDRESS', 'var_label' => 'SIP server address', 'var_type' => 'text', 'placeholder' => 'pbx.example.local', 'display_order' => 10],
            ['var_name' => 'SIP_SERVER_PORT', 'var_label' => 'SIP server port', 'var_type' => 'number', 'default_value' => '5060', 'min_value' => 1, 'max_value' => 65535, 'help_text' => 'Leeg of 5060 volgt het aangeleverde voorbeeld.', 'display_order' => 20],
            ['var_name' => 'SIP_OUTBOUND_PROXY_SERVER_1', 'var_label' => 'SIP outbound proxy server 1', 'var_type' => 'text', 'display_order' => 30],
            ['var_name' => 'SIP_OUTBOUND_PROXY_SERVER_1_PORT', 'var_label' => 'SIP outbound proxy server 1 port', 'var_type' => 'number', 'min_value' => 1, 'max_value' => 65535, 'display_order' => 40],
            ['var_name' => 'SIP_DISPLAY_NAME', 'var_label' => 'SIP display name', 'var_type' => 'text', 'display_order' => 50],
            ['var_name' => 'SIP_PHONE_NUMBER', 'var_label' => 'SIP phone number', 'var_type' => 'text', 'display_order' => 60],
            ['var_name' => 'SIP_USERNAME', 'var_label' => 'SIP username', 'var_type' => 'text', 'display_order' => 70],
            ['var_name' => 'SIP_PASSWORD', 'var_label' => 'SIP password', 'var_type' => 'password', 'display_order' => 80],
            ['var_name' => 'SIP_RTP_PORT', 'var_label' => 'SIP rtp port', 'var_type' => 'number', 'default_value' => '5004', 'min_value' => 1, 'max_value' => 65535, 'help_text' => 'Logische default wanneer geen RTP-poort is meegegeven.', 'display_order' => 90],
            ['var_name' => 'SIP_USE_TLS', 'var_label' => 'SIP use tls', 'var_type' => 'boolean', 'default_value' => '0', 'options' => '[{"value":"0","label":"No"},{"value":"1","label":"Yes"}]', 'help_text' => 'Default 0 conform voorbeeldverwachting.', 'display_order' => 100],
            ['var_name' => 'SIP_DTMF_RECV_RTP', 'var_label' => 'SIP dtmf recv rtp', 'var_type' => 'boolean', 'default_value' => '1', 'options' => '[{"value":"0","label":"No"},{"value":"1","label":"Yes"}]', 'help_text' => 'Default 1 als logisch SIP-voorbeeldgedrag.', 'display_order' => 110],
        ],
    ],
];

try {
    $pdo->beginTransaction();
    
    // Get admin user
    $stmt = $pdo->query("SELECT id FROM admins WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $admin_id = $admin ? $admin['id'] : 1;
    
    $stmt = $pdo->prepare('
        INSERT INTO config_templates 
        (template_name, device_type_id, category, description, template_content, is_active, is_default, created_by)
        SELECT ?, dt.id, ?, ?, ?, 1, 0, ?
        FROM device_types dt
        WHERE dt.type_name = ?
        LIMIT 1
        ON DUPLICATE KEY UPDATE template_name = template_name
    ');
    
    foreach ($templates as $template) {
        list($name, $device_type, $category, $description, $content) = $template;
        
        $stmt->execute([
            $name,
            $category,
            $description,
            $content,
            $admin_id,
            $device_type
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo "✓ Created template: $name ($device_type - $category)" . PHP_EOL;
        } else {
            echo "- Template already exists: $name" . PHP_EOL;
        }
    }

    foreach ($extended_templates as $template) {
        $template_id = ensure_template_record($pdo, $admin_id, $template);
        foreach ($template['variables'] as $variable) {
            ensure_template_variable_record($pdo, $template_id, $variable);
        }
        echo "✓ Ensured template + variables: {$template['name']} ({$template['device_type']})" . PHP_EOL;
    }
    
    $pdo->commit();
    echo PHP_EOL . "Template seeding completed successfully!" . PHP_EOL;
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
?>
