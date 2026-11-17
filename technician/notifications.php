<?php

require_once '../config/config.php';
$pageTitle = 'Notifications - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();

if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['mark_read'], $_SESSION['user_id']]);
    redirect('notifications.php', '', '');
}

if (isset($_GET['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    redirect('notifications.php', 'All notifications marked as read.', 'success');
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['delete'], $_SESSION['user_id']]);
    redirect('notifications.php', 'Notification deleted.', 'success');
}

$stmt = $db->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY date_sent DESC
    LIMIT 100
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$stmt->fetchColumn();
?>

<div class="page-header">
    <h1><i class="fas fa-bell"></i> Notifications
        <?php if ($unreadCount > 0): ?>
        <span class="badge badge-danger" style="font-size:0.75rem;vertical-align:middle;"><?= $unreadCount ?> new</span>
        <?php endif; ?>
    </h1>
    <?php if ($unreadCount > 0): ?>
    <a href="notifications.php?mark_all_read=1" class="btn btn-secondary btn-sm">
        <i class="fas fa-check-double"></i> Mark All as Read
    </a>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="empty-state">
    <i class="fas fa-bell-slash fa-4x text-muted"></i>
    <h3>No Notifications</h3>
    <p>You have no notifications yet. They will appear here when schedules are updated or repairs are assigned.</p>
</div>
<?php else: ?>
<div class="notifications-list" style="display:flex;flex-direction:column;gap:0.75rem;">
    <?php foreach ($notifications as $notif): ?>
    <?php
        
        if (stripos($notif['title'], 'Assigned') !== false) {
            $icon = 'fas fa-user-check'; $iconColor = 'var(--info, #17a2b8)';
        } elseif (stripos($notif['title'], 'Completed') !== false || stripos($notif['title'], 'complete') !== false) {
            $icon = 'fas fa-check-circle'; $iconColor = 'var(--success, #28a745)';
        } elseif (stripos($notif['title'], 'Schedule') !== false) {
            $icon = 'fas fa-calendar-alt'; $iconColor = 'var(--primary, #272343)';
        } elseif (stripos($notif['title'], 'Payment') !== false) {
            $icon = 'fas fa-peso-sign'; $iconColor = '#f39c12';
        } elseif (stripos($notif['type'], 'warning') !== false || stripos($notif['title'], 'Hold') !== false) {
            $icon = 'fas fa-exclamation-triangle'; $iconColor = '#f39c12';
        } else {
            $icon = 'fas fa-bell'; $iconColor = 'var(--accent, #6246ea)';
        }
    ?>
    <div class="notification-card <?= !$notif['is_read'] ? 'unread' : '' ?>"
         style="background:var(--background);border:1px solid <?= !$notif['is_read'] ? 'var(--accent, #6246ea)' : 'var(--border-light)' ?>;
                border-left:4px solid <?= !$notif['is_read'] ? 'var(--accent, #6246ea)' : 'var(--border)' ?>;
                border-radius:var(--radius, 8px);padding:1rem 1.25rem;
                display:flex;align-items:flex-start;gap:1rem;">
        <div style="flex-shrink:0;width:36px;height:36px;border-radius:50%;
                    background:var(--background-secondary);display:flex;align-items:center;justify-content:center;">
            <i class="<?= $icon ?>" style="color:<?= $iconColor ?>;font-size:1rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                <strong style="font-size:0.9rem;"><?= sanitize($notif['title']) ?></strong>
                <?php if (!$notif['is_read']): ?>
                <span class="badge badge-primary" style="font-size:0.65rem;">New</span>
                <?php endif; ?>
            </div>
            <p style="margin:0 0 0.5rem;font-size:0.85rem;color:var(--text-secondary);"><?= sanitize($notif['message']) ?></p>
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <small class="text-muted" style="font-size:0.75rem;">
                    <i class="fas fa-clock"></i> <?= formatDateTime($notif['date_sent']) ?>
                </small>
                <?php if ($notif['repair_id']): ?>
                <a href="repair-detail.php?id=<?= $notif['repair_id'] ?>" class="btn btn-xs btn-outline-primary"
                   style="font-size:0.7rem;padding:0.15rem 0.5rem;border:1px solid var(--primary);border-radius:4px;color:var(--primary);text-decoration:none;">
                    <i class="fas fa-wrench"></i> View Repair #<?= $notif['repair_id'] ?>
                </a>
                <?php endif; ?>
                <?php if (!$notif['is_read']): ?>
                <a href="notifications.php?mark_read=<?= $notif['notification_id'] ?>"
                   style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">
                    <i class="fas fa-check"></i> Mark read
                </a>
                <?php endif; ?>
                <a href="notifications.php?delete=<?= $notif['notification_id'] ?>"
                   style="font-size:0.75rem;color:var(--danger, #dc3545);text-decoration:none;"
                   onclick="return confirm('Delete this notification?')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
