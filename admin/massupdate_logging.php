<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/massupdate_access.php';
require_once __DIR__ . '/../includes/massupdate.php';
require_once __DIR__ . '/../includes/i18n.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int)$_SESSION['admin_id'];
require_massupdate_owner($pdo, $admin_id);
$_SESSION['language'] = get_user_language($pdo, $admin_id);
$page_title = __('nav.massupdate_logging');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
$error = '';
$retention = 30;
$logs = [];
$summary = [];
$total = 0;
$pages = 1;
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$filters = [];
foreach (['mac', 'model', 'date_from', 'date_to'] as $key) {
    $filters[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        http_response_code(403);
        $error = __('massupdate.csrf_error');
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'save_retention') {
                $days = filter_var($_POST['retention_days'] ?? null, FILTER_VALIDATE_INT);
                if ($days === false || $days < 1 || $days > 3650) {
                    throw new InvalidArgumentException('Invalid log retention.');
                }
                $stmt = $pdo->prepare('
                    INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ');
                $stmt->execute(['massupdate_log_retention_days', (string)$days]);
            } elseif ($action === 'cleanup') {
                massupdate_log_cleanup($pdo, massupdate_log_retention($pdo));
            } else {
                throw new InvalidArgumentException('Invalid action.');
            }
            header('Location: /admin/massupdate_logging.php?saved=1', true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            $error = __('massupdate_log.invalid');
        } catch (Throwable $e) {
            http_response_code(503);
            $error = __('massupdate_log.load_error');
        }
    }
}

try {
    $retention = massupdate_log_retention($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        massupdate_log_cleanup($pdo, $retention);
    }
    $conditions = [
        "status = 'served'",
        "firmware_old IS NOT NULL AND TRIM(firmware_old) <> ''",
        "firmware_new IS NOT NULL AND TRIM(firmware_new) <> ''",
        'firmware_old <> firmware_new',
    ];
    $params = [];
    if ($filters['mac'] !== '') {
        $mac = massupdate_normalize_mac($filters['mac']);
        if ($mac === null) {
            throw new InvalidArgumentException('Invalid MAC.');
        }
        $conditions[] = 'mac_address = ?';
        $params[] = $mac;
    }
    if ($filters['model'] !== '') {
        if (!preg_match('/\A[A-Z][A-Z0-9]{0,63}\z/i', $filters['model'])) {
            throw new InvalidArgumentException('Invalid model.');
        }
        $conditions[] = 'device_model = ?';
        $params[] = strtoupper($filters['model']);
    }
    foreach (['date_from', 'date_to'] as $key) {
        if ($filters[$key] === '') {
            continue;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
        if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
            throw new InvalidArgumentException('Invalid date.');
        }
        $conditions[] = $key === 'date_from' ? 'created_at >= ?' : 'created_at < ?';
        $params[] = ($key === 'date_to' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
    }
    if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
        throw new InvalidArgumentException('Invalid date range.');
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM massupdate_log' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / 50));
    $page = min($page, $pages);
    $offset = ($page - 1) * 50;
    $stmt = $pdo->prepare('SELECT * FROM massupdate_log' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 50 OFFSET ' . $offset);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('
        SELECT device_model, firmware_new, COUNT(*) AS requests, COUNT(DISTINCT mac_address) AS devices
        FROM massupdate_log' . $where . ' GROUP BY device_model, firmware_new ORDER BY device_model, firmware_new
    ');
    $stmt->execute($params);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (InvalidArgumentException $e) {
    if ($error === '') {
        http_response_code(400);
        $error = __('massupdate_log.invalid');
    }
} catch (Throwable $e) {
    if ($error === '') {
        http_response_code(503);
        $error = __('massupdate_log.load_error');
    }
}

function massupdate_log_html($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
require_once __DIR__ . '/_header.php';
?>
<h2><?php echo massupdate_log_html($page_title); ?></h2>
<p><?php echo massupdate_log_html(__('massupdate_log.intro')); ?></p>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo massupdate_log_html($error); ?></div>
<?php elseif (isset($_GET['saved'])): ?>
    <div class="alert alert-success"><?php echo massupdate_log_html(__('massupdate_log.saved')); ?></div>
<?php endif; ?>

<div class="card">
    <form method="post" action="/admin/massupdate_logging.php">
        <input type="hidden" name="csrf_token" value="<?php echo massupdate_log_html($csrf); ?>">
        <input type="hidden" name="action" value="save_retention">
        <label><?php echo massupdate_log_html(__('massupdate_log.retention')); ?>
            <input type="number" name="retention_days" min="1" max="3650" value="<?php echo $retention; ?>" required>
        </label>
        <button type="submit" class="btn btn-primary"><?php echo massupdate_log_html(__('button.save')); ?></button>
    </form>
    <form method="post" action="/admin/massupdate_logging.php">
        <input type="hidden" name="csrf_token" value="<?php echo massupdate_log_html($csrf); ?>">
        <input type="hidden" name="action" value="cleanup">
        <button type="submit" class="btn btn-danger"><?php echo massupdate_log_html(__('massupdate_log.cleanup')); ?></button>
    </form>
</div>
<form method="get" action="/admin/massupdate_logging.php">
    <label><?php echo massupdate_log_html(__('massupdate_log.mac')); ?>
        <input name="mac" maxlength="17" value="<?php echo massupdate_log_html($filters['mac']); ?>">
    </label>
    <label><?php echo massupdate_log_html(__('massupdate.model')); ?>
        <input name="model" maxlength="64" value="<?php echo massupdate_log_html($filters['model']); ?>">
    </label>
    <label><?php echo massupdate_log_html(__('massupdate_log.date_from')); ?>
        <input type="date" name="date_from" value="<?php echo massupdate_log_html($filters['date_from']); ?>">
    </label>
    <label><?php echo massupdate_log_html(__('massupdate_log.date_to')); ?>
        <input type="date" name="date_to" value="<?php echo massupdate_log_html($filters['date_to']); ?>">
    </label>
    <button type="submit" class="btn btn-primary"><?php echo massupdate_log_html(__('massupdate_log.filter')); ?></button>
</form>
<p><?php echo massupdate_log_html(__('massupdate_log.total')); ?>: <?php echo $total; ?></p>
<table>
    <thead><tr>
        <?php foreach (['massupdate_log.date', 'massupdate.model', 'massupdate_log.mac', 'massupdate_log.old_version', 'massupdate_log.new_version', 'massupdate_log.campaign', 'massupdate_log.status'] as $key): ?>
            <th><?php echo massupdate_log_html(__($key)); ?></th>
        <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
        <tr>
            <?php foreach (['created_at', 'device_model', 'mac_address', 'firmware_old', 'firmware_new', 'campaign_id'] as $key): ?>
                <td><?php echo massupdate_log_html($log[$key] ?? '—'); ?></td>
            <?php endforeach; ?>
            <td><?php echo massupdate_log_html(__('massupdate_log.status_' . str_replace('-', '_', $log['status']))); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (!$logs): ?>
    <p><?php echo massupdate_log_html(__('massupdate_log.empty')); ?></p>
<?php endif; ?>
<p>
    <?php if ($page > 1): ?>
        <a href="?<?php echo massupdate_log_html(http_build_query(array_merge($filters, ['page' => $page - 1]))); ?>"><?php echo massupdate_log_html(__('massupdate_log.previous')); ?></a>
    <?php endif; ?>
    <?php echo $page; ?> / <?php echo $pages; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?php echo massupdate_log_html(http_build_query(array_merge($filters, ['page' => $page + 1]))); ?>"><?php echo massupdate_log_html(__('massupdate_log.next')); ?></a>
    <?php endif; ?>
</p>
<h3><?php echo massupdate_log_html(__('massupdate_log.summary')); ?></h3>
<table>
    <thead><tr>
        <th><?php echo massupdate_log_html(__('massupdate.model')); ?></th>
        <th><?php echo massupdate_log_html(__('massupdate_log.new_version')); ?></th>
        <th><?php echo massupdate_log_html(__('massupdate_log.total')); ?></th>
        <th><?php echo massupdate_log_html(__('massupdate_log.devices')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($summary as $row): ?>
        <tr>
            <td><?php echo massupdate_log_html($row['device_model']); ?></td>
            <td><?php echo massupdate_log_html($row['firmware_new'] ?? '—'); ?></td>
            <td><?php echo (int)$row['requests']; ?></td>
            <td><?php echo (int)$row['devices']; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require_once __DIR__ . '/_footer.php'; ?>
