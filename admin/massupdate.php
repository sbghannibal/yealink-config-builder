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
$page_title = __('nav.massupdate');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        http_response_code(403);
        $error = __('massupdate.csrf_error');
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if (!in_array($action, ['create', 'update', 'delete'], true)) {
                throw new InvalidArgumentException('Invalid action');
            }
            if ($action === 'delete' && ($_POST['confirm_delete'] ?? '') !== '1') {
                throw new InvalidArgumentException('Delete not confirmed');
            }
            $new_value = null;
            if ($action === 'create') {
                $new_value = massupdate_validate_campaign($_POST);
            } elseif ($action === 'update') {
                $limit = filter_var($_POST['daily_limit'] ?? null, FILTER_VALIDATE_INT);
                if ($limit === false || $limit < 1 || $limit > 25000) {
                    throw new InvalidArgumentException('Invalid daily limit');
                }
                $new_value = [
                    'daily_limit' => $limit,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];
            }
            $pdo->beginTransaction();
            $old_value = null;
            if ($action === 'create') {
                $stmt = $pdo->prepare('
                    INSERT INTO massupdate_campaigns
                        (model, target_version, firmware_url, daily_limit, is_active, created_by)
                    VALUES (?, ?, ?, ?, 1, ?)
                ');
                $stmt->execute([
                    $new_value['model'], $new_value['target_version'],
                    $new_value['firmware_url'], $new_value['daily_limit'], $admin_id,
                ]);
                $campaign_id = (int)$pdo->lastInsertId();
            } else {
                $campaign_id = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
                if ($campaign_id === false || $campaign_id < 1) {
                    throw new InvalidArgumentException('Invalid campaign');
                }
                $stmt = $pdo->prepare('SELECT * FROM massupdate_campaigns WHERE id = ? FOR UPDATE');
                $stmt->execute([$campaign_id]);
                $old_value = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old_value) {
                    throw new InvalidArgumentException('Campaign not found');
                }
                if ($action === 'delete') {
                    $stmt = $pdo->prepare('DELETE FROM massupdate_campaigns WHERE id = ?');
                    $stmt->execute([$campaign_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE massupdate_campaigns SET daily_limit = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$new_value['daily_limit'], $new_value['is_active'], $campaign_id]);
                }
            }
            $stmt = $pdo->prepare('
                INSERT INTO audit_logs
                    (admin_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $admin_id, 'massupdate.' . $action, 'massupdate_campaign', $campaign_id,
                $old_value === null ? null : json_encode($old_value),
                $new_value === null ? null : json_encode($new_value),
                substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
            $pdo->commit();
            header('Location: /admin/massupdate.php?saved=1', true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(400);
            $error = __('massupdate.invalid');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Mass Update management error: ' . $e->getMessage());
            http_response_code(500);
            $error = __('massupdate.save_error');
        }
    }
}

$period = massupdate_period(new DateTimeImmutable('now'));
$campaigns = [];
try {
    $stmt = $pdo->prepare('
        SELECT c.*, (SELECT COUNT(*) FROM massupdate_downloads d
                    WHERE d.campaign_id = c.id AND d.period_date = ?) AS downloaded
        FROM massupdate_campaigns c ORDER BY c.model
    ');
    $stmt->execute([$period]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Mass Update list error: ' . $e->getMessage());
    http_response_code(503);
    $error = __('massupdate.load_error');
}
function massupdate_html($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
require_once __DIR__ . '/_header.php';
?>
<h2><?php echo massupdate_html($page_title); ?></h2>
<p><?php echo massupdate_html(__('massupdate.intro')); ?></p>
<p><?php echo massupdate_html(__('massupdate.reset')); ?></p>
<p><?php echo massupdate_html(__('massupdate.priority')); ?></p>
<p><?php echo massupdate_html(__('massupdate.route')); ?> <code>/massupdate/</code>,
    <code>/massupdate/?mac=001122AABBCC</code>, <code>/massupdate/001122AABBCC.cfg</code></p>
<p><?php echo massupdate_html(__('massupdate.request')); ?></p>
<p><?php echo massupdate_html(__('massupdate.install')); ?> <code>php setup/run_migrations.php</code></p>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo massupdate_html($error); ?></div>
<?php elseif (isset($_GET['saved'])): ?>
    <div class="alert alert-success"><?php echo massupdate_html(__('massupdate.saved')); ?></div>
<?php endif; ?>

<div class="card">
    <h3><?php echo massupdate_html(__('massupdate.create')); ?></h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo massupdate_html($csrf); ?>">
        <input type="hidden" name="action" value="create">
        <p><label><?php echo massupdate_html(__('massupdate.model')); ?>
            <input name="model" placeholder="T46S" maxlength="64" required>
        </label></p>
        <p><label><?php echo massupdate_html(__('massupdate.version')); ?>
            <input name="target_version" placeholder="66.86.0.160" maxlength="64" required>
        </label></p>
        <p><label><?php echo massupdate_html(__('massupdate.url')); ?>
            <input type="url" name="firmware_url" maxlength="2048" required>
        </label></p>
        <p><label><?php echo massupdate_html(__('massupdate.limit')); ?>
            <input type="number" name="daily_limit" value="500" min="1" max="25000" required>
        </label></p>
        <button type="submit" class="btn btn-primary"><?php echo massupdate_html(__('button.create')); ?></button>
    </form>
</div>
<h3><?php echo massupdate_html(__('massupdate.campaigns')); ?></h3>
<?php if (!$campaigns): ?>
    <p><?php echo massupdate_html(__('massupdate.empty')); ?></p>
<?php endif; ?>
<?php foreach ($campaigns as $campaign): ?>
    <div class="card">
        <h3><?php echo massupdate_html($campaign['model']); ?> — <?php echo massupdate_html($campaign['target_version']); ?></h3>
        <p><?php echo massupdate_html($campaign['firmware_url']); ?></p>
        <p><?php echo massupdate_html(__('massupdate.downloaded')); ?>:
            <?php echo (int)$campaign['downloaded']; ?> / <?php echo (int)$campaign['daily_limit']; ?>
            (<?php echo massupdate_html($period); ?>)</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo massupdate_html($csrf); ?>">
            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
            <input type="hidden" name="action" value="update">
            <label><?php echo massupdate_html(__('massupdate.limit')); ?>
                <input type="number" name="daily_limit" min="1" max="25000" value="<?php echo (int)$campaign['daily_limit']; ?>" required>
            </label>
            <label><input type="checkbox" name="is_active" value="1" <?php echo $campaign['is_active'] ? 'checked' : ''; ?>>
                <?php echo massupdate_html(__('massupdate.active')); ?></label>
            <button type="submit" class="btn btn-primary"><?php echo massupdate_html(__('button.save')); ?></button>
        </form>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo massupdate_html($csrf); ?>">
            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
            <input type="hidden" name="action" value="delete">
            <label><input type="checkbox" name="confirm_delete" value="1" required>
                <?php echo massupdate_html(__('massupdate.delete_warning')); ?></label>
            <button type="submit" class="btn btn-danger"><?php echo massupdate_html(__('button.delete')); ?></button>
        </form>
    </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/_footer.php'; ?>
