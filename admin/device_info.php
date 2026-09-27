<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';
$page_title = __('page.device_info.title');

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];
$permissions = get_admin_permissions($pdo, $admin_id);

if (!in_array('devices.view', $permissions, true)) {
    header('Location: /access_denied.php');
    exit;
}

function t(string $key): string {
    return htmlspecialchars(__($key), ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/_header.php';
?>

    <h2><?php echo t('page.device_info.heading'); ?></h2>
    <p><?php echo t('page.device_info.intro'); ?></p>

    <div class="card">
        <h3><?php echo t('page.device_info.yealink.title'); ?></h3>
        <p><?php echo t('page.device_info.yealink.description'); ?></p>
        <ul>
            <li><strong><?php echo t('label.provision_file'); ?>:</strong> <?php echo t('page.device_info.yealink.provision_file'); ?></li>
            <li><strong><?php echo t('label.use_for'); ?>:</strong> <?php echo t('page.device_info.yealink.use_for'); ?></li>
            <li><a href="https://support.yealink.com/" target="_blank" rel="noopener noreferrer"><?php echo t('page.device_info.link.vendor_support'); ?></a></li>
        </ul>
    </div>

    <div class="card">
        <h3><?php echo t('page.device_info.cisco.title'); ?></h3>
        <p><?php echo t('page.device_info.cisco.description'); ?></p>
        <ul>
            <li><strong><?php echo t('label.provision_file'); ?>:</strong> <?php echo t('page.device_info.cisco.provision_file'); ?></li>
            <li><strong><?php echo t('label.use_for'); ?>:</strong> <?php echo t('page.device_info.cisco.use_for'); ?></li>
            <li><a href="https://www.cisco.com/c/en/us/support/collaboration-endpoints/ata-190-series-adapter/model.html" target="_blank" rel="noopener noreferrer"><?php echo t('page.device_info.link.vendor_support'); ?></a></li>
            <li><a href="https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cata/19x/3-2/english/administration/guide/ata19x_admin_book/ata19x_introduction.html" target="_blank" rel="noopener noreferrer"><?php echo t('page.device_info.link.admin_guide'); ?></a></li>
        </ul>
    </div>

    <div class="card">
        <h3><?php echo t('page.device_info.fasttel.title'); ?></h3>
        <p><?php echo t('page.device_info.fasttel.description'); ?></p>
        <ul>
            <li><strong><?php echo t('label.provision_file'); ?>:</strong> <?php echo t('page.device_info.fasttel.provision_file'); ?></li>
            <li><strong><?php echo t('label.use_for'); ?>:</strong> <?php echo t('page.device_info.fasttel.use_for'); ?></li>
            <li><a href="https://www.fasttel.com/" target="_blank" rel="noopener noreferrer"><?php echo t('page.device_info.link.vendor_site'); ?></a></li>
            <li><a href="https://www.fasttel.com/support/" target="_blank" rel="noopener noreferrer"><?php echo t('page.device_info.link.vendor_support'); ?></a></li>
        </ul>
    </div>

    <?php
    $can_manage_device_types = in_array('admin.device_types.manage', $permissions, true);
    $can_manage_templates = in_array('config.manage', $permissions, true);
    ?>
    <?php if ($can_manage_device_types || $can_manage_templates): ?>
        <div class="card">
            <h3><?php echo t('page.device_info.internal_links_title'); ?></h3>
            <ul>
                <?php if ($can_manage_device_types): ?>
                    <li><a href="/admin/device_types.php"><?php echo t('nav.device_types'); ?></a></li>
                <?php endif; ?>
                <?php if ($can_manage_templates): ?>
                    <li><a href="/admin/templates.php"><?php echo t('nav.templates'); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
