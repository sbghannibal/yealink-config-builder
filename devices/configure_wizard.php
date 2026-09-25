<?php
$page_title = 'Config Wizard';
session_start();

// Set error log to app directory
ini_set('error_log', __DIR__ . '/../logs/wizard.log');
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../settings/generator.php';
require_once __DIR__ . '/../settings/validator.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/form_helpers.php';

// Default PABX name for customer-based configurations
define('DEFAULT_CUSTOMER_PABX_NAME', 'Customer-Based');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle wizard reset
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['wizard_data']);
    header('Location: /devices/configure_wizard.php?step=1');
    exit;
}

// Get current step
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$step = max(1, min(5, $step)); // Clamp between 1-5

// Get device ID if provided
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;

// Initialize wizard data in session
if (!isset($_SESSION['wizard_data'])) {
    $_SESSION['wizard_data'] = [
        'device_id' => $device_id,
        'device_type_id' => null,
        'template_id' => null,
        'variables' => [],
        'config_content' => '',
        'customer_id' => null
    ];
} elseif ($device_id && $_SESSION['wizard_data']['device_id'] != $device_id) {
    // Reset if device changed
    $_SESSION['wizard_data'] = [
        'device_id' => $device_id,
        'device_type_id' => null,
        'template_id' => null,
        'variables' => [],
        'config_content' => '',
        'customer_id' => null
    ];
}

$wizard_data = &$_SESSION['wizard_data'];

// Load device if ID provided
$device = null;
if ($device_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT d.*, dt.type_name, dt.id as device_type_id
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            WHERE d.id = ?
        ');
        $stmt->execute([$device_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($device) {
            $wizard_data['device_type_id'] = $device['device_type_id'];
            // Pre-select customer if device has one and customer not yet selected
            if (!empty($device['customer_id']) && empty($wizard_data['customer_id'])) {
                $wizard_data['customer_id'] = $device['customer_id'];
            }
        }
    } catch (Exception $e) {
        error_log('Failed to load device: ' . $e->getMessage());
        $error = 'Kon device niet laden.';
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';

        // Step 1: Device Type Selection
        if ($action === 'select_type' && $step === 1) {
            $wizard_data['device_type_id'] = !empty($_POST['device_type_id']) ? (int)$_POST['device_type_id'] : null;
            if ($wizard_data['device_type_id']) {
                header('Location: ?step=2' . ($device_id ? "&device_id=$device_id" : ''));
                exit;
            } else {
                $error = 'Selecteer een device type.';
            }
        }

        // Step 2: Template Selection
        if ($action === 'select_template' && $step === 2) {
            $wizard_data['template_id'] = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
            if ($wizard_data['template_id']) {
                header('Location: ?step=3' . ($device_id ? "&device_id=$device_id" : ''));
                exit;
            } else {
                $error = 'Selecteer een template.';
            }
        }

        // Step 3: Variable Input
        if ($action === 'set_variables' && $step === 3) {
            // Collect all variable inputs
            $wizard_data['variables'] = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'var_') === 0) {
                    $var_name = substr($key, 4); // Remove 'var_' prefix
                    // Handle multiselect arrays
                    if (is_array($value)) {
                        $wizard_data['variables'][$var_name] = implode(',', $value);
                    } else {
                        $wizard_data['variables'][$var_name] = $value;
                    }
                }
            }
            header('Location: ?step=4' . ($device_id ? "&device_id=$device_id" : ''));
            exit;
        }

        // Step 4: Customer Selection
        if ($action === 'select_customer' && $step === 4) {
            $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;

            if (!$customer_id) {
                $error = 'Selecteer een klant.';
            } else {
                try {
                    $result = generate_config_from_template($pdo, $wizard_data['template_id'], $wizard_data['variables']);

                    if (!$result['success']) {
                        $error = $result['error'];
                    } else {
                        // Check if we can save config - we need a dummy pabx_id for backward compatibility
                        $stmt = $pdo->prepare('SELECT id FROM pabx WHERE pabx_name = ? LIMIT 1');
                        $stmt->execute([DEFAULT_CUSTOMER_PABX_NAME]);
                        $default_pabx = $stmt->fetch();

                        if (!$default_pabx) {
                            // Create a default pabx entry for customer-based configs
                            $stmt = $pdo->prepare('INSERT INTO pabx (pabx_name, pabx_ip, pabx_type, is_active, created_by) VALUES (?, ?, ?, 1, ?)');
                            $stmt->execute([DEFAULT_CUSTOMER_PABX_NAME, '0.0.0.0', 'Generic', $admin_id]);
                            $pabx_id = $pdo->lastInsertId();
                        } else {
                            $pabx_id = $default_pabx['id'];
                        }

                        // Save as config version
                        $stmt = $pdo->prepare('
                            SELECT COALESCE(MAX(version_number), 0) + 1 AS next_ver
                            FROM config_versions
                            WHERE pabx_id = ? AND device_type_id = ?
                        ');
                        $stmt->execute([$pabx_id, $wizard_data['device_type_id']]);
                        $next_ver = (int) $stmt->fetchColumn();

                        $stmt = $pdo->prepare('
                            INSERT INTO config_versions
                            (pabx_id, device_type_id, version_number, config_content, changelog, is_active, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
                        ');
                        $changelog = 'Generated from wizard: ' . $result['template']['template_name'];
                        $stmt->execute([
                            $pabx_id,
                            $wizard_data['device_type_id'],
                            $next_ver,
                            $result['content'],
                            $changelog,
                            $admin_id
                        ]);
                        $config_version_id = $pdo->lastInsertId();

                        // Assign to device if device_id provided and update customer_id
                        if ($device_id) {
                            // Update device customer_id
                            $stmt = $pdo->prepare('UPDATE devices SET customer_id = ? WHERE id = ?');
                            $stmt->execute([$customer_id, $device_id]);

                            // Assign config to device
                            $stmt = $pdo->prepare('
                                INSERT INTO device_config_assignments
                                (device_id, config_version_id, assigned_by, assigned_at)
                                VALUES (?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE config_version_id = VALUES(config_version_id), assigned_at = NOW()
                            ');
                            $stmt->execute([$device_id, $config_version_id, $admin_id]);
                        }

                        $wizard_data['config_version_id'] = $config_version_id;
                        $wizard_data['config_content'] = $result['content'];
                        $wizard_data['customer_id'] = $customer_id;

                        header('Location: ?step=5' . ($device_id ? "&device_id=$device_id" : ''));
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('Wizard save error: ' . $e->getMessage());
                    $error = 'Kon configuratie niet opslaan: ' . $e->getMessage();
                }
            }
        }
    }
}

// Load data for current step
$device_types = [];
$templates = [];
$template_variables = [];
$customer_list = [];
$global_variables = [];

try {
    // Load device types for step 1
    if ($step === 1) {
        $stmt = $pdo->query('SELECT id, type_name, description FROM device_types ORDER BY type_name');
        $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Load templates for step 2
    if ($step === 2 && $wizard_data['device_type_id']) {
        $stmt = $pdo->prepare('
            SELECT * FROM config_templates
            WHERE device_type_id = ? AND is_active = 1
            ORDER BY is_default DESC, category, template_name
        ');
        $stmt->execute([$wizard_data['device_type_id']]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Load template variables for step 3
    if ($step === 3 && $wizard_data['template_id']) {
        $stmt = $pdo->prepare('
            SELECT tv.*,
                   pv.var_name AS parent_var_name
            FROM template_variables tv
            LEFT JOIN template_variables pv ON tv.parent_variable_id = pv.id
            WHERE tv.template_id = ?
            ORDER BY tv.display_order, tv.var_name
        ');
        $stmt->execute([$wizard_data['template_id']]);
        $template_variables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also load global variables for reference
        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $global_variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Load customer list for step 4
    if ($step === 4) {
        $stmt = $pdo->query('SELECT id, customer_code, company_name FROM customers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY company_name');
        $customer_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate preview - use stored variables from session
        if ($wizard_data['template_id']) {
            $result = generate_config_from_template($pdo, $wizard_data['template_id'], $wizard_data['variables']);
            if ($result['success']) {
                $wizard_data['config_content'] = $result['content'];
            } else {
                $error = $result['error'] ?? 'Kon configuratie niet genereren';
            }
        }
    }
} catch (Exception $e) {
    error_log('Wizard data load error: ' . $e->getMessage());
}

require_once __DIR__ . '/../admin/_header.php';
?>

    <style>
        .wizard-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding: 0;
            list-style: none;
        }
        .wizard-step {
            flex: 1;
            text-align: center;
            padding: 12px;
            background: #f5f5f5;
            border-right: 1px solid white;
            position: relative;
        }
        .wizard-step:last-child { border-right: none; }
        .wizard-step.active { background: #007bff; color: white; font-weight: bold; }
        .wizard-step.completed { background: #28a745; color: white; }
        .wizard-content { max-width: 800px; margin: 0 auto; }
        .template-option {
            border: 2px solid #ddd;
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .template-option:hover { border-color: #007bff; }
        .template-option input[type="radio"] { margin-right: 8px; }
        .template-option.default { border-color: #28a745; background: #f0fff4; }
        .config-preview {
            background: #f5f5f5;
            padding: 16px;
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        .var-input { margin-bottom: 16px; }
        .qr-code { text-align: center; padding: 20px; }
    </style>

    <h2>Device Configuratie Wizard <?php if ($device): ?><small>voor <?php echo htmlspecialchars($device['device_name']); ?></small><?php endif; ?></h2>

    <ul class="wizard-steps">
        <li class="wizard-step <?php echo $step === 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>">1. Device Type</li>
        <li class="wizard-step <?php echo $step === 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>">2. Template</li>
        <li class="wizard-step <?php echo $step === 3 ? 'active' : ($step > 3 ? 'completed' : ''); ?>">3. Variabelen</li>
        <li class="wizard-step <?php echo $step === 4 ? 'active' : ($step > 4 ? 'completed' : ''); ?>">4. Preview</li>
        <li class="wizard-step <?php echo $step === 5 ? 'active' : ''; ?>">5. Voltooid</li>
    </ul>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="wizard-content card">
        <?php if ($step === 1): ?>
            <!-- Step 1: Device Type Selection -->
            <h3>Stap 1: Selecteer Device Type</h3>
            <?php if ($device): ?>
                <p>Device: <strong><?php echo htmlspecialchars($device['device_name']); ?></strong> (<?php echo htmlspecialchars($device['type_name'] ?? 'Unknown'); ?>)</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="select_type">
                    <input type="hidden" name="device_type_id" value="<?php echo (int)$device['device_type_id']; ?>">
                    <p>Het device type is automatisch geselecteerd.</p>
                    <button class="btn" type="submit">Volgende →</button>
                </form>
            <?php else: ?>
                <p>Kies het toestel/model voor deze configuratie:</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="select_type">
                    <?php foreach ($device_types as $dt): ?>
                        <div class="template-option">
                            <label>
                                <input type="radio" name="device_type_id" value="<?php echo (int)$dt['id']; ?>" required>
                                <strong><?php echo htmlspecialchars($dt['type_name']); ?></strong>
                                <?php if ($dt['description']): ?><br><small><?php echo htmlspecialchars($dt['description']); ?></small><?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn" type="submit">Volgende →</button>
                </form>
            <?php endif; ?>

        <?php elseif ($step === 2): ?>
            <!-- Step 2: Template Selection -->
            <h3>Stap 2: Selecteer Config Template</h3>
            <?php if (empty($templates)): ?>
                <p style="color: #dc3545;">Geen templates gevonden voor dit device type.</p>
                <a class="btn" href="?step=1<?php echo $device_id ? "&device_id=$device_id" : ''; ?>">&larr; Terug</a>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="select_template">
                    <?php foreach ($templates as $tpl): ?>
                        <div class="template-option <?php echo $tpl['is_default'] ? 'default' : ''; ?>">
                            <label>
                                <input type="radio" name="template_id" value="<?php echo (int)$tpl['id']; ?>" <?php echo $tpl['is_default'] ? 'checked' : ''; ?> required>
                                <strong><?php echo htmlspecialchars($tpl['template_name']); ?></strong>
                                <?php if ($tpl['is_default']): ?><span class="badge" style="background:#28a745;color:white;padding:2px 6px;border-radius:3px;font-size:10px;">DEFAULT</span><?php endif; ?>
                                <?php if ($tpl['category']): ?><span style="color:#666;font-size:12px;"> - <?php echo htmlspecialchars($tpl['category']); ?></span><?php endif; ?>
                                <?php if ($tpl['description']): ?><br><small><?php echo htmlspecialchars($tpl['description']); ?></small><?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div style="display:flex;gap:8px;margin-top:16px;">
                        <a class="btn" href="?step=1<?php echo $device_id ? "&device_id=$device_id" : ''; ?>" style="background:#6c757d;">&larr; Terug</a>
                        <button class="btn" type="submit">Volgende →</button>
                    </div>
                </form>
            <?php endif; ?>

        <?php elseif ($step === 3): ?>
            <!-- Step 3: Variable Input -->
            <h3>Stap 3: Configureer Variabelen</h3>
            <p>Vul de variabelen in voor deze configuratie:</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="set_variables">

                <?php if (empty($template_variables)): ?>
                    <p style="color:#666;">Dit template heeft geen specifieke variabelen. Globale variabelen worden automatisch toegepast.</p>
                <?php else: ?>
                    <?php foreach ($template_variables as $var): ?>
                        <?php
                        // Get current value from wizard data, default value, or global variables
                        $current_value = $wizard_data['variables'][$var['var_name']] ??
                                       $var['default_value'] ??
                                       ($global_variables[$var['var_name']] ?? '');

                        // Render the input using the helper function
                        echo render_variable_input($var, $current_value, ['show_label' => true]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div style="display:flex;gap:8px;margin-top:16px;">
                    <a class="btn" href="?step=2<?php echo $device_id ? "&device_id=$device_id" : ''; ?>" style="background:#6c757d;">&larr; Terug</a>
                    <button class="btn" type="submit">Volgende →</button>
                </div>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function () {
                function updateChildVisibility() {
                    document.querySelectorAll('[data-parent-var]').forEach(function (child) {
                        var parentName = child.getAttribute('data-parent-var');
                        var showWhen = child.getAttribute('data-show-when') || 'always';

                        if (showWhen === 'always') {
                            child.style.display = '';
                            return;
                        }

                        var parentInput = document.querySelector('[name="var_' + parentName + '"]');
                        if (!parentInput) {
                            child.style.display = '';
                            return;
                        }

                        var parentValue = parentInput.value;
                        var show = false;

                        if (showWhen === 'not_empty') {
                            show = parentValue !== '';
                        } else if (showWhen === 'true') {
                            show = parentValue === '1' || parentValue === 'true' || parentValue === 'yes';
                        } else if (showWhen === 'false') {
                            show = parentValue === '0' || parentValue === 'false' || parentValue === 'no' || parentValue === '';
                        } else {
                            show = true;
                        }

                        child.style.display = show ? '' : 'none';
                        // Disable required validation on hidden fields
                        child.querySelectorAll('[required]').forEach(function (el) {
                            el.disabled = !show;
                        });
                    });
                }

                // Run on load
                updateChildVisibility();

                // Re-run whenever any input changes
                document.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.addEventListener('change', updateChildVisibility);
                    el.addEventListener('input', updateChildVisibility);
                });
            });
            </script> 
            <?php elseif ($step === 4): ?>
            <!-- Step 4: Customer Selection & Preview -->
            <h3>Stap 4: Selecteer Klant & Preview</h3>
            <p>Controleer de gegenereerde configuratie en selecteer een klant:</p>

            <div class="config-preview"><?php echo htmlspecialchars($wizard_data['config_content']); ?></div>

            <form method="post" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="select_customer">

                <div class="form-group">
                    <label>Selecteer Klant <?php if ($device_id): ?>*<?php endif; ?></label>
                    <select name="customer_id" <?php if ($device_id): ?>required<?php endif; ?> id="customer_select">
                        <option value="">-- Selecteer klant --</option>
                        <?php foreach ($customer_list as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"
                                <?php echo ((isset($wizard_data['customer_id']) && $wizard_data['customer_id'] == $c['id']) || (isset($device['customer_id']) && $device['customer_id'] == $c['id'])) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['customer_code'] . ' - ' . $c['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($wizard_data['customer_id']) && $device_id): ?>
                        <small style="color:#28a745; display:block; margin-top:4px;">
                            ✓ Automatisch geselecteerd van device
                        </small>
                    <?php elseif (!$device_id): ?>
                        <small style="color:#6c757d;">Optioneel wanneer geen device is geselecteerd</small>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:8px;margin-top:16px;">
                    <a class="btn" href="?step=3<?php echo $device_id ? "&device_id=$device_id" : ''; ?>" style="background:#6c757d;">&larr; Terug naar Variabelen</a>
                    <button class="btn" type="submit">Opslaan & Voltooien</button>
                </div>
            </form>

        <?php elseif ($step === 5): ?>
            <!-- Step 5: Complete -->
            <h3>✓ Configuratie Voltooid!</h3>
            <p>De configuratie is succesvol aangemaakt en opgeslagen.</p>

            <?php if (isset($wizard_data['config_version_id'])): ?>
                <div style="margin:16px 0;">
                    <p><strong>Config Versie ID:</strong> <?php echo (int)$wizard_data['config_version_id']; ?></p>
                    <?php if ($wizard_data['customer_id']): ?>
                        <p><strong>Klant:</strong> 
                            <?php 
                            $stmt = $pdo->prepare('SELECT customer_code, company_name FROM customers WHERE id = ?');
                            $stmt->execute([$wizard_data['customer_id']]);
                            $cust = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($cust['customer_code'] . ' - ' . $cust['company_name']);
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($device_id): ?>
                        <p>De configuratie is toegewezen aan device: <strong><?php echo htmlspecialchars($device['device_name']); ?></strong></p>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
                    <a class="btn" href="/devices/list.php">← Terug naar Devices</a>
                    <a class="btn" href="/settings/builder.php" style="background:#28a745;">Config Builder</a>
                    <a class="btn" href="?reset=1" style="background:#6c757d;">Nieuwe Wizard</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
<?php
// Clear wizard data after completion
if ($step === 5 && isset($_SESSION['wizard_data']) && isset($wizard_data['config_version_id'])) {
    unset($_SESSION['wizard_data']);
}
?>
