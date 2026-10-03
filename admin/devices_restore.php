<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.devices_restore.title');

// Redirect to login if not authenticated
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

// Permission check: ONLY devices.restore permission required
if (!has_permission($pdo, $admin_id, 'devices.restore')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Pagination & Search
$per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
$per_page = in_array($per_page, [10, 25, 50, 100], true) ? $per_page : 10;

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

$sort_by = isset($_GET['sort_by']) ? (string) $_GET['sort_by'] : 'deleted_at';
$sort_order = (isset($_GET['sort_order']) && $_GET['sort_order'] === 'asc') ? 'asc' : 'desc';

$sort_column_map = [
    'id' => 'd.id',
    'device_name' => 'd.device_name',
    'deleted_at' => 'd.deleted_at',
    'customer_name' => 'c.company_name',
];
$sort_column = $sort_column_map[$sort_by] ?? 'd.deleted_at';

$error = '';
$success = '';
$deleted_devices = [];
$total_count = 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $posted_csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = __('error.csrf_invalid');
    } else {
        $device_id = (int) ($_POST['device_id'] ?? 0);
        $action = (string) $_POST['action'];

        $allowed_actions = ['restore', 'delete_config', 'delete_permanently'];
        if (!in_array($action, $allowed_actions, true)) {
            $error = __('error.action_failed');
        } elseif ($device_id <= 0) {
            $error = __('error.action_failed');
        } else {
            try {
                if ($action === 'restore') {
                    // === RESTORE DEVICE ===
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('SELECT id FROM devices WHERE id = ? AND deleted_at IS NOT NULL');
                    $stmt->execute([$device_id]);
                    $device = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$device) {
                        $pdo->rollBack();
                        $error = __('error.device_not_deleted');
                    } else {
                        $stmt = $pdo->prepare('UPDATE devices SET deleted_at = NULL, is_active = 1, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$device_id]);
                        $pdo->commit();
                        $success = __('success.device_restored');
                    }
                } elseif ($action === 'delete_config') {
                    // === DELETE DEVICE CONFIG ASSIGNMENT(S) ===
                    // Match behavior used in /devices/list.php bulk delete
                    $stmt = $pdo->prepare('DELETE FROM device_config_assignments WHERE device_id = ?');
                    $stmt->execute([$device_id]);
                    $success = __('success.device_config_deleted');
                } elseif ($action === 'delete_permanently') {
                    // === PERMANENTLY DELETE ===
                    // Match order used in /devices/list.php bulk delete
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('DELETE FROM device_config_assignments WHERE device_id = ?');
                    $stmt->execute([$device_id]);

                    $stmt = $pdo->prepare('DELETE FROM devices WHERE id = ?');
                    $stmt->execute([$device_id]);

                    $pdo->commit();
                    $success = __('success.device_deleted_permanently');
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('devices_restore error: ' . $e->getMessage());
                $error = __('error.action_failed');
            }
        }
    }
}

// Fetch deleted devices
try {
    $where = "d.deleted_at IS NOT NULL";
    $params = [];

    if ($search !== '') {
        $where .= " AND (d.device_name LIKE ? OR c.company_name LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) as count
        FROM devices d
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE {$where}");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_count = (int) ($row['count'] ?? 0);

    // Fetch page
    $query = "SELECT d.*, c.company_name
        FROM devices d
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE {$where}
        ORDER BY {$sort_column} {$sort_order}
        LIMIT {$per_page} OFFSET {$offset}";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $deleted_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('devices_restore fetch error: ' . $e->getMessage());
    $error = __('error.fetch_failed');
}

$total_pages = (int) ceil($total_count / $per_page);

require_once __DIR__ . '/_header.php';
?>

<style>
    .restore-container {
        max-width: 1200px;
        margin: 20px auto;
    }

    .card {
        background: white;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        padding: 20px;
        margin-bottom: 20px;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .search-box {
        margin-bottom: 20px;
    }

    .search-box input {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        width: 300px;
    }

    .search-box button {
        padding: 10px 20px;
        background: #5d00b8;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    th {
        background: #f8f9fa;
        font-weight: 600;
    }

    tr:hover {
        background: #f8f9fa;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 12px;
    }

    .btn-restore {
        background: #28a745;
        color: white;
    }

    .btn-delete-config {
        background: #ffc107;
        color: black;
    }

    .btn-delete-perm {
        background: #dc3545;
        color: white;
    }

    .pagination {
        text-align: center;
        margin-top: 20px;
    }

    .pagination a, .pagination span {
        display: inline-block;
        padding: 8px 12px;
        margin: 0 4px;
        border: 1px solid #ddd;
        border-radius: 4px;
        text-decoration: none;
        color: #5d00b8;
    }

    .pagination a:hover {
        background: #f8f9fa;
    }

    .pagination .active {
        background: #5d00b8;
        color: white;
        border-color: #5d00b8;
    }
</style>

<div class="restore-container">
    <h2><?php echo __('page.devices_restore.title'); ?></h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="search-box">
            <form method="get" style="display: inline;">
                <input type="text" name="search" placeholder="<?php echo __('form.search'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit"><?php echo __('button.search'); ?></button>
            </form>
        </div>

        <?php if (!empty($deleted_devices)): ?>
            <table>
                <thead>
                    <tr>
                        <th><?php echo __('table.device_name'); ?></th>
                        <th><?php echo __('table.customer'); ?></th>
                        <th><?php echo __('table.deleted_at'); ?></th>
                        <th><?php echo __('table.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deleted_devices as $device): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($device['device_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($device['company_name'] ?? '-')); ?></td>
                            <td>
                                <?php
                                echo !empty($device['deleted_at'])
                                    ? htmlspecialchars(date('d-m-Y H:i', strtotime($device['deleted_at'])))
                                    : '-';
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="device_id" value="<?php echo (int) ($device['id'] ?? 0); ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <button class="btn btn-restore" type="submit"><?php echo __('button.restore'); ?></button>
                                    </form>

                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="device_id" value="<?php echo (int) ($device['id'] ?? 0); ?>">
                                        <input type="hidden" name="action" value="delete_config">
                                        <button class="btn btn-delete-config" type="submit"><?php echo __('button.delete_configs'); ?></button>
                                    </form>

                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo __('confirm.delete_permanently'); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="device_id" value="<?php echo (int) ($device['id'] ?? 0); ?>">
                                        <input type="hidden" name="action" value="delete_permanently">
                                        <button class="btn btn-delete-perm" type="submit"><?php echo __('button.delete_permanently'); ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&per_page=<?php echo (int) $per_page; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p><?php echo __('page.devices_restore.no_deleted'); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>