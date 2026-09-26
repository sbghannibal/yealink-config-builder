<?php
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.template_variables.title');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    echo __('error.no_permission');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Detect whether master/child columns are present (check all four required columns)
$has_master_child_columns = false;
try {
    $col_check = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'template_variables'
          AND COLUMN_NAME IN ('variable_group','is_group_master','parent_variable_id','show_when_parent')
    ");
    $has_master_child_columns = ((int)$col_check->fetchColumn() === 4);
} catch (Exception $e) {
    // Column check failed; treat as not available
}

// Get template ID from query string
$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : null;

if (!$template_id) {
    header('Location: /admin/templates.php');
    exit;
}

// Load template
try {
    $stmt = $pdo->prepare('
        SELECT ct.*, dt.type_name 
        FROM config_templates ct
        LEFT JOIN device_types dt ON ct.device_type_id = dt.id
        WHERE ct.id = ?
    ');
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        $error = __('error.template_not_found');
        $template_id = null;
    }
} catch (Exception $e) {
    error_log('Failed to load template: ' . $e->getMessage());
    $error = __('error.template_load_failed');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $template_id) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $var_name = trim($_POST['var_name'] ?? '');
            $var_label = trim($_POST['var_label'] ?? '');
            $var_type = $_POST['var_type'] ?? 'text';
            $default_value = trim($_POST['default_value'] ?? '');
            $is_required = !empty($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '');
            $help_text = trim($_POST['help_text'] ?? '');
            $min_value = !empty($_POST['min_value']) ? (int)$_POST['min_value'] : null;
            $max_value = !empty($_POST['max_value']) ? (int)$_POST['max_value'] : null;
            $regex_pattern = trim($_POST['regex_pattern'] ?? '');
            $options = trim($_POST['options'] ?? '');
            $display_order = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
            
            // Master/child fields (only when columns exist)
            $variable_group = $has_master_child_columns ? trim($_POST['variable_group'] ?? '') : null;
            $is_group_master = $has_master_child_columns ? (!empty($_POST['is_group_master']) ? 1 : 0) : null;
            $parent_variable_id = $has_master_child_columns ? (!empty($_POST['parent_variable_id']) ? (int)$_POST['parent_variable_id'] : null) : null;
            $show_when_parent = $has_master_child_columns ? ($_POST['show_when_parent'] ?? 'always') : null;
            
            if (empty($var_name)) {
                $error = __('error.var_name_required');
            } else {
                // Validate options JSON if provided
                if (!empty($options)) {
                    $decoded = json_decode($options, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error = __('error.invalid_json_options');
                    }
                }
                
                if (!$error) {
                    try {
                        if ($has_master_child_columns) {
                            $stmt = $pdo->prepare('
                                INSERT INTO template_variables 
                                (template_id, var_name, var_label, var_type, default_value, is_required, 
                                 placeholder, help_text, min_value, max_value, regex_pattern, options, display_order,
                                 variable_group, is_group_master, parent_variable_id, show_when_parent)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $template_id,
                                $var_name,
                                $var_label ?: null,
                                $var_type,
                                $default_value ?: null,
                                $is_required,
                                $placeholder ?: null,
                                $help_text ?: null,
                                $min_value,
                                $max_value,
                                $regex_pattern ?: null,
                                $options ?: null,
                                $display_order,
                                $variable_group ?: null,
                                $is_group_master,
                                $parent_variable_id,
                                $show_when_parent ?: 'always'
                            ]);
                        } else {
                            $stmt = $pdo->prepare('
                                INSERT INTO template_variables 
                                (template_id, var_name, var_label, var_type, default_value, is_required, 
                                 placeholder, help_text, min_value, max_value, regex_pattern, options, display_order)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $template_id,
                                $var_name,
                                $var_label ?: null,
                                $var_type,
                                $default_value ?: null,
                                $is_required,
                                $placeholder ?: null,
                                $help_text ?: null,
                                $min_value,
                                $max_value,
                                $regex_pattern ?: null,
                                $options ?: null,
                                $display_order
                            ]);
                        }
                        
                        $success = __('success.variable_created');
                    } catch (Exception $e) {
                        error_log('Variable create error: ' . $e->getMessage());
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            $error = __('error.variable_duplicate');
                        } else {
                            $error = __('error.variable_create_failed');
                        }
                    }
                }
            }
        }
        
        if ($action === 'update') {
            $var_id = !empty($_POST['var_id']) ? (int)$_POST['var_id'] : null;
            $var_name = trim($_POST['var_name'] ?? '');
            $var_label = trim($_POST['var_label'] ?? '');
            $var_type = $_POST['var_type'] ?? 'text';
            $default_value = trim($_POST['default_value'] ?? '');
            $is_required = !empty($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '');
            $help_text = trim($_POST['help_text'] ?? '');
            $min_value = !empty($_POST['min_value']) ? (int)$_POST['min_value'] : null;
            $max_value = !empty($_POST['max_value']) ? (int)$_POST['max_value'] : null;
            $regex_pattern = trim($_POST['regex_pattern'] ?? '');
            $options = trim($_POST['options'] ?? '');
            $display_order = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
            
            // Master/child fields (only when columns exist)
            $variable_group = $has_master_child_columns ? trim($_POST['variable_group'] ?? '') : null;
            $is_group_master = $has_master_child_columns ? (!empty($_POST['is_group_master']) ? 1 : 0) : null;
            $parent_variable_id = $has_master_child_columns ? (!empty($_POST['parent_variable_id']) ? (int)$_POST['parent_variable_id'] : null) : null;
            $show_when_parent = $has_master_child_columns ? ($_POST['show_when_parent'] ?? 'always') : null;
            
            if (!$var_id || empty($var_name)) {
                $error = __('error.variable_id_name_required');
            } else {
                // Validate options JSON if provided
                if (!empty($options)) {
                    $decoded = json_decode($options, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error = __('error.invalid_json_options');
                    }
                }
                
                if (!$error) {
                    try {
                        if ($has_master_child_columns) {
                            $stmt = $pdo->prepare('
                                UPDATE template_variables 
                                SET var_name = ?, var_label = ?, var_type = ?, default_value = ?, is_required = ?,
                                    placeholder = ?, help_text = ?, min_value = ?, max_value = ?, 
                                    regex_pattern = ?, options = ?, display_order = ?,
                                    variable_group = ?, is_group_master = ?, parent_variable_id = ?, show_when_parent = ?
                                WHERE id = ? AND template_id = ?
                            ');
                            $stmt->execute([
                                $var_name,
                                $var_label ?: null,
                                $var_type,
                                $default_value ?: null,
                                $is_required,
                                $placeholder ?: null,
                                $help_text ?: null,
                                $min_value,
                                $max_value,
                                $regex_pattern ?: null,
                                $options ?: null,
                                $display_order,
                                $variable_group ?: null,
                                $is_group_master,
                                $parent_variable_id,
                                $show_when_parent ?: 'always',
                                $var_id,
                                $template_id
                            ]);
                        } else {
                            $stmt = $pdo->prepare('
                                UPDATE template_variables 
                                SET var_name = ?, var_label = ?, var_type = ?, default_value = ?, is_required = ?,
                                    placeholder = ?, help_text = ?, min_value = ?, max_value = ?, 
                                    regex_pattern = ?, options = ?, display_order = ?
                                WHERE id = ? AND template_id = ?
                            ');
                            $stmt->execute([
                                $var_name,
                                $var_label ?: null,
                                $var_type,
                                $default_value ?: null,
                                $is_required,
                                $placeholder ?: null,
                                $help_text ?: null,
                                $min_value,
                                $max_value,
                                $regex_pattern ?: null,
                                $options ?: null,
                                $display_order,
                                $var_id,
                                $template_id
                            ]);
                        }
                        
                        $success = __('success.variable_updated');
                    } catch (Exception $e) {
                        error_log('Variable update error: ' . $e->getMessage());
                        $error = __('error.variable_update_failed');
                    }
                }
            }
        }
        
        if ($action === 'delete') {
            $var_id = !empty($_POST['var_id']) ? (int)$_POST['var_id'] : null;
            if ($var_id) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM template_variables WHERE id = ? AND template_id = ?');
                    $stmt->execute([$var_id, $template_id]);
                    $success = __('success.variable_deleted');
                } catch (Exception $e) {
                    error_log('Variable delete error: ' . $e->getMessage());
                    $error = __('error.variable_delete_failed');
                }
            }
        }
    }
}

// Load variables for this template
$variables = [];
if ($template_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT * FROM template_variables 
            WHERE template_id = ? 
            ORDER BY display_order, var_name
        ');
        $stmt->execute([$template_id]);
        $variables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to load variables: ' . $e->getMessage());
    }
}

// If editing, load variable
$editing = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$edit_variable = null;
if ($editing && $template_id) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM template_variables WHERE id = ? AND template_id = ?');
        $stmt->execute([$editing, $template_id]);
        $edit_variable = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to load variable: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .var-types-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .var-card { padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 12px; background: #f9f9f9; }
    .type-badge { display: inline-block; padding: 2px 8px; font-size: 11px; border-radius: 3px; background: #6c757d; color: white; margin-left: 8px; }
    .form-section { background: #f5f5f5; padding: 12px; border-radius: 4px; margin-top: 12px; }
    .form-section h4 { margin-top: 0; font-size: 14px; color: #666; }
    .type-specific-fields { display: none; }
    .type-specific-fields.active { display: block; }
</style>

<script>
function updateTypeFields() {
    const varType = document.getElementById('var_type').value;
    
    // Hide all type-specific sections
    document.querySelectorAll('.type-specific-fields').forEach(el => {
        el.classList.remove('active');
    });
    
    // Show relevant sections
    if (['select', 'multiselect', 'radio', 'checkbox_group', 'boolean'].includes(varType)) {
        document.getElementById('options-section').classList.add('active');
    }
    if (['number', 'range'].includes(varType)) {
        document.getElementById('number-section').classList.add('active');
    }
    if (['password', 'text', 'textarea', 'email', 'url', 'ip_address'].includes(varType)) {
        document.getElementById('regex-section').classList.add('active');
    }
}

// Run on page load
document.addEventListener('DOMContentLoaded', updateTypeFields);
</script>

    <h2><?php echo __('page.template_variables.title'); ?>: <?php echo htmlspecialchars($template['template_name'] ?? ''); ?></h2>
    
    <p>
        <a href="/admin/templates.php" class="btn" style="background:#6c757d;">← <?php echo __('button.back'); ?></a>
        <?php if ($template): ?>
            <span style="color:#666;"><?php echo __('form.device_type'); ?>: <?php echo htmlspecialchars($template['type_name']); ?></span>
        <?php endif; ?>
    </p>
    
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    
    <?php if ($template_id): ?>
    <div class="var-types-grid">
        <div>
            <div class="card">
                <h3><?php echo $edit_variable ? __('label.edit_variable') : __('label.new_variable'); ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="<?php echo $edit_variable ? 'update' : 'create'; ?>">
                    <?php if ($edit_variable): ?>
                        <input type="hidden" name="var_id" value="<?php echo (int)$edit_variable['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><?php echo __('form.var_name_template'); ?></label>
                        <input name="var_name" type="text" required value="<?php echo htmlspecialchars($edit_variable['var_name'] ?? ''); ?>" placeholder="DEVICE_NAME">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('form.var_label'); ?></label>
                        <input name="var_label" type="text" value="<?php echo htmlspecialchars($edit_variable['var_label'] ?? ''); ?>" placeholder="Device Naam">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('table.type'); ?> *</label>
                        <select name="var_type" id="var_type" required onchange="updateTypeFields()">
                            <?php
                            $types = [
                                'text'           => __('vartype.text'),
                                'textarea'       => __('vartype.textarea'),
                                'number'         => __('vartype.number'),
                                'range'          => __('vartype.range'),
                                'boolean'        => __('vartype.boolean'),
                                'select'         => __('vartype.select'),
                                'multiselect'    => __('vartype.multiselect'),
                                'radio'          => __('vartype.radio'),
                                'checkbox_group' => __('vartype.checkbox_group'),
                                'email'          => __('vartype.email'),
                                'url'            => __('vartype.url'),
                                'password'       => __('vartype.password'),
                                'date'           => __('vartype.date'),
                                'ip_address'     => __('vartype.ip_address'),
                            ];
                            
                            foreach ($types as $value => $label) {
                                $selected = ($edit_variable && $edit_variable['var_type'] === $value) ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . 
                                     htmlspecialchars($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('form.default_value'); ?></label>
                        <input name="default_value" type="text" value="<?php echo htmlspecialchars($edit_variable['default_value'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('form.placeholder_field'); ?></label>
                        <input name="placeholder" type="text" value="<?php echo htmlspecialchars($edit_variable['placeholder'] ?? ''); ?>" placeholder="Bijv. 192.168.1.1">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('form.help_text'); ?></label>
                        <textarea name="help_text" rows="2"><?php echo htmlspecialchars($edit_variable['help_text'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="options-section">
                        <h4><?php echo __('label.options_for_types'); ?></h4>
                        <div class="form-group">
                            <label><?php echo __('form.options_json'); ?></label>
                            <textarea name="options" rows="4" placeholder='[{"value":"opt1","label":"Optie 1"},{"value":"opt2","label":"Optie 2"}]'><?php echo htmlspecialchars($edit_variable['options'] ?? ''); ?></textarea>
                            <small style="color:#666;"><?php echo __('label.options_json_format'); ?></small>
                        </div>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="number-section">
                        <h4><?php echo __('label.number_range_settings'); ?></h4>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label><?php echo __('form.min_value'); ?></label>
                                <input name="min_value" type="number" value="<?php echo $edit_variable['min_value'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label><?php echo __('form.max_value'); ?></label>
                                <input name="max_value" type="number" value="<?php echo $edit_variable['max_value'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="regex-section">
                        <h4><?php echo __('label.validation'); ?></h4>
                        <div class="form-group">
                            <label><?php echo __('form.regex_pattern'); ?></label>
                            <input name="regex_pattern" type="text" value="<?php echo htmlspecialchars($edit_variable['regex_pattern'] ?? ''); ?>" placeholder="^[A-Z0-9]+$">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo __('form.display_order'); ?></label>
                        <input name="display_order" type="number" value="<?php echo $edit_variable['display_order'] ?? 0; ?>" min="0">
                    </div>
                    
                    <?php if ($has_master_child_columns): ?>
                    <div class="form-section" style="margin-top:12px;">
                        <h4 style="margin-top:0;font-size:14px;color:#666;">Master/Child Relatie</h4>
                        
                        <div class="form-group">
                            <label>Variabele groep</label>
                            <input name="variable_group" type="text" value="<?php echo htmlspecialchars($edit_variable['variable_group'] ?? ''); ?>" placeholder="bijv. sip_account">
                            <small style="color:#666;">Optionele groepsnaam voor gerelateerde variabelen.</small>
                        </div>
                        
                        <div class="form-group">
                            <label><input type="checkbox" name="is_group_master" value="1" <?php echo !empty($edit_variable['is_group_master']) ? 'checked' : ''; ?>> Is groepsmaster</label>
                            <small style="color:#666;">Aanvinken als deze variabele de zichtbaarheid van onderliggende variabelen bepaalt.</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Bovenliggende variabele</label>
                            <select name="parent_variable_id">
                                <option value="">-- Geen (altijd tonen) --</option>
                                <?php foreach ($variables as $v):
                                    // Skip self when editing
                                    if ($edit_variable && (int)$v['id'] === (int)$edit_variable['id']) continue;
                                    $sel = (!empty($edit_variable['parent_variable_id']) && (int)$edit_variable['parent_variable_id'] === (int)$v['id']) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo (int)$v['id']; ?>" <?php echo $sel; ?>>{{<?php echo htmlspecialchars($v['var_name']); ?>}}</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Tonen wanneer bovenliggende variabele</label>
                            <select name="show_when_parent">
                                <?php
                                $show_opts = [
                                    'always'    => 'Altijd tonen',
                                    'true'      => 'Waar / 1 / ja',
                                    'false'     => 'Onwaar / 0 / nee',
                                    'not_empty' => 'Niet leeg',
                                ];
                                $cur_show = $edit_variable['show_when_parent'] ?? 'always';
                                foreach ($show_opts as $val => $lbl):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $cur_show === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><input type="checkbox" name="is_required" value="1" <?php echo ($edit_variable['is_required'] ?? 0) ? 'checked' : ''; ?>> <?php echo __('label.required_field'); ?></label>
                    </div>
                    
                    <div style="display: flex; gap: 8px;">
                        <button class="btn" type="submit"><?php echo $edit_variable ? __('button.edit') : __('button.create'); ?></button>
                        <?php if ($edit_variable): ?>
                            <a class="btn" href="?template_id=<?php echo $template_id; ?>" style="background: #6c757d;"><?php echo __('button.cancel'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <div>
            <div class="card">
                <h3><?php echo __('page.template_variables.title'); ?> (<?php echo count($variables); ?>)</h3>
                
                <?php if (empty($variables)): ?>
                    <p style="color: #666;"><?php echo __('label.no_variables_defined'); ?></p>
                <?php else: ?>
                    <?php
                    // Build O(1) lookup: id -> var_name for parent display
                    $var_id_to_name = [];
                    foreach ($variables as $v) {
                        $var_id_to_name[(int)$v['id']] = $v['var_name'];
                    }
                    ?>
                    <?php foreach ($variables as $v): ?>
                        <div class="var-card">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div>
                                    <strong>{{<?php echo htmlspecialchars($v['var_name']); ?>}}</strong>
                                    <span class="type-badge"><?php echo htmlspecialchars($v['var_type']); ?></span>
                                    <?php if ($v['is_required']): ?><span style="color:red;margin-left:4px;">*</span><?php endif; ?>
                                    <?php if ($has_master_child_columns && !empty($v['is_group_master'])): ?>
                                        <span style="background:#007bff;color:white;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;">master</span>
                                    <?php endif; ?>
                                    <?php if ($has_master_child_columns && !empty($v['parent_variable_id'])):
                                        $parent_name = $var_id_to_name[(int)$v['parent_variable_id']] ?? '';
                                    ?>
                                        <span style="background:#6c757d;color:white;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;">child van {{<?php echo htmlspecialchars($parent_name); ?>}}</span>
                                    <?php endif; ?>
                                    <?php if ($v['var_label']): ?>
                                        <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                            <?php echo htmlspecialchars($v['var_label']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($v['default_value']): ?>
                                        <div style="font-size: 11px; color: #999; margin-top: 2px;">
                                            <?php echo __('label.default_prefix'); ?> <?php echo htmlspecialchars($v['default_value']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($v['help_text']): ?>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px; font-style: italic;">
                                            <?php echo htmlspecialchars($v['help_text']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; gap: 4px;">
                                    <a class="btn" href="?template_id=<?php echo $template_id; ?>&edit=<?php echo (int)$v['id']; ?>" style="font-size: 11px; padding: 4px 8px;"><?php echo __('button.edit'); ?></a>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo __('confirm.delete'); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="var_id" value="<?php echo (int)$v['id']; ?>">
                                        <button class="btn" type="submit" style="font-size: 11px; padding: 4px 8px; background: #dc3545;"><?php echo __('button.delete'); ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php require_once __DIR__ . '/_footer.php'; ?>
