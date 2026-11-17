<?php

require_once '../config/config.php';
$pageTitle = 'Notifications - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

if (isset($_GET['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    redirect('notifications.php', 'All notifications marked as read.', 'success');
}

if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['mark_read'], $_SESSION['user_id']]);
    redirect('notifications.php', 'Notification marked as read.', 'success');
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['delete'], $_SESSION['user_id']]);
    redirect('notifications.php', 'Notification deleted.', 'success');
}

if (isset($_GET['clear_read'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
    $stmt->execute([$_SESSION['user_id']]);
    redirect('notifications.php', 'Read notifications cleared.', 'success');
}

$filter = $_GET['filter'] ?? 'all';

$whereClause = "WHERE n.user_id = ?";
$params = [$_SESSION['user_id']];

if ($filter === 'unread') {
    $whereClause .= " AND n.is_read = 0";
} elseif ($filter === 'read') {
    $whereClause .= " AND n.is_read = 1";
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications n $whereClause");
$countStmt->execute($params);
$totalNotifications = $countStmt->fetchColumn();
$totalPages = ceil($totalNotifications / $perPage);

$stmt = $db->prepare("
    SELECT n.*, r.appliance_type, r.repair_status
    FROM notifications n
    LEFT JOIN repairs r ON n.repair_id = r.repair_id
    $whereClause
    ORDER BY n.date_sent DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$totalCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$totalCount->execute([$_SESSION['user_id']]);
$totalCount = $totalCount->fetchColumn();

$unreadCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCount->execute([$_SESSION['user_id']]);
$unreadCount = $unreadCount->fetchColumn();

$readCount = $totalCount - $unreadCount;
?>

<div class="content-wrapper">
    <div class="page-header">
        <h1><i class="fas fa-bell"></i> Notifications</h1>
        <p class="text-muted">View and manage all your notifications</p>
    </div>

    
    <div class="stats-grid">
        <a href="?filter=all" class="stat-card stat-primary" style="text-decoration: none; <?= $filter === 'all' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);' : '' ?>">
            <div class="stat-icon"><i class="fas fa-bell"></i></div>
            <div class="stat-content">
                <h3><?= $totalCount ?></h3>
                <p>Total Notifications</p>
            </div>
        </a>
        <a href="?filter=unread" class="stat-card stat-warning" style="text-decoration: none; <?= $filter === 'unread' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);' : '' ?>">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);"><i class="fas fa-envelope"></i></div>
            <div class="stat-content">
                <h3><?= $unreadCount ?></h3>
                <p>Unread</p>
            </div>
        </a>
        <a href="?filter=read" class="stat-card stat-success" style="text-decoration: none; <?= $filter === 'read' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);' : '' ?>">
            <div class="stat-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);"><i class="fas fa-envelope-open"></i></div>
            <div class="stat-content">
                <h3><?= $readCount ?></h3>
                <p>Read</p>
            </div>
        </a>
    </div>

    
    <div class="card" style="margin-bottom: 1rem; padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-weight: 600; font-size: 0.85rem; color: var(--text-secondary);">
                Showing <?= $filter === 'all' ? 'all' : $filter ?> notifications (<?= $totalNotifications ?>)
            </span>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($unreadCount > 0): ?>
            <a href="?mark_all_read=1" class="btn btn-sm btn-primary" onclick="return confirm('Mark all notifications as read?')">
                <i class="fas fa-check-double"></i> Mark All Read
            </a>
            <?php endif; ?>
            <?php if ($readCount > 0): ?>
            <a href="?clear_read=1" class="btn btn-sm btn-secondary" onclick="return confirm('Delete all read notifications?')">
                <i class="fas fa-trash-alt"></i> Clear Read
            </a>
            <?php endif; ?>
        </div>
    </div>

    
    <?php if ($notifications): ?>
    <div class="card">
        <div class="admin-notifications-list">
            <?php foreach ($notifications as $notif): ?>
            <div class="admin-notif-item <?= !$notif['is_read'] ? 'unread' : '' ?>">
                <div class="admin-notif-icon-wrap">
                    <?php
                    $iconClass = 'fa-bell';
                    $iconColor = '#667eea';
                    $type = strtolower($notif['type']);
                    if ($type === 'success') { $iconClass = 'fa-check-circle'; $iconColor = '#10b981'; }
                    elseif ($type === 'warning') { $iconClass = 'fa-exclamation-triangle'; $iconColor = '#f59e0b'; }
                    elseif ($type === 'alert') { $iconClass = 'fa-exclamation-circle'; $iconColor = '#ef4444'; }
                    elseif ($type === 'info') { $iconClass = 'fa-info-circle'; $iconColor = '#3b82f6'; }
                    
                    $title = strtolower($notif['title']);
                    if (strpos($title, 'repair') !== false || strpos($title, 'completed') !== false) { $iconClass = 'fa-wrench'; $iconColor = '#667eea'; }
                    if (strpos($title, 'assigned') !== false) { $iconClass = 'fa-user-check'; $iconColor = '#8b5cf6'; }
                    if (strpos($title, 'payment') !== false) { $iconClass = 'fa-dollar-sign'; $iconColor = '#10b981'; }
                    if (strpos($title, 'schedule') !== false) { $iconClass = 'fa-calendar-alt'; $iconColor = '#f59e0b'; }
                    if (strpos($title, 'cancelled') !== false || strpos($title, 'cancel') !== false) { $iconClass = 'fa-times-circle'; $iconColor = '#ef4444'; }
                    ?>
                    <div class="admin-notif-icon" style="background: <?= $iconColor ?>15; color: <?= $iconColor ?>;">
                        <i class="fas <?= $iconClass ?>"></i>
                    </div>
                    <?php if (!$notif['is_read']): ?>
                    <span class="admin-notif-unread-dot"></span>
                    <?php endif; ?>
                </div>
                <div class="admin-notif-body">
                    <div class="admin-notif-title">
                        <?= sanitize($notif['title']) ?>
                        <?php if ($notif['repair_id'] && $notif['appliance_type']): ?>
                        <a href="repair-view.php?id=<?= $notif['repair_id'] ?>" class="admin-notif-repair-link" title="View repair">
                            <i class="fas fa-external-link-alt"></i> #<?= $notif['repair_id'] ?> - <?= sanitize($notif['appliance_type']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="admin-notif-message"><?= sanitize($notif['message']) ?></div>
                    <div class="admin-notif-meta">
                        <span class="admin-notif-time">
                            <i class="fas fa-clock"></i> <?= timeAgo($notif['date_sent']) ?>
                        </span>
                        <span class="admin-notif-date" title="<?= formatDateTime($notif['date_sent']) ?>">
                            <?= formatDateTime($notif['date_sent']) ?>
                        </span>
                        <span class="admin-notif-type badge badge-<?= $type === 'success' ? 'success' : ($type === 'warning' ? 'warning' : ($type === 'alert' ? 'danger' : 'info')) ?>">
                            <?= sanitize(ucfirst($notif['type'])) ?>
                        </span>
                    </div>
                </div>
                <div class="admin-notif-actions">
                    <?php if (!$notif['is_read']): ?>
                    <a href="?mark_read=<?= $notif['notification_id'] ?>&filter=<?= $filter ?>" class="admin-notif-action-btn" title="Mark as read">
                        <i class="fas fa-check"></i>
                    </a>
                    <?php endif; ?>
                    <a href="?delete=<?= $notif['notification_id'] ?>&filter=<?= $filter ?>" class="admin-notif-action-btn delete" title="Delete" onclick="return confirm('Delete this notification?')">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    
    <?php if ($totalPages > 1): ?>
    <div class="pagination-wrapper" style="margin-top: 1rem; display: flex; justify-content: center; gap: 0.25rem; flex-wrap: wrap;">
        <?php if ($page > 1): ?>
        <a href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?filter=<?= $filter ?>&page=<?= $i ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="card" style="text-align: center; padding: 3rem 1rem;">
        <i class="fas fa-bell-slash fa-3x" style="color: var(--text-muted); margin-bottom: 1rem;"></i>
        <h3 style="margin-bottom: 0.5rem; color: var(--text-secondary);">No Notifications</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem;">
            <?php if ($filter === 'unread'): ?>
                You have no unread notifications. <a href="?filter=all">View all notifications</a>
            <?php elseif ($filter === 'read'): ?>
                No read notifications found. <a href="?filter=all">View all notifications</a>
            <?php else: ?>
                You don't have any notifications yet.
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>
</div>

<style>

.admin-notifications-list {
    display: flex;
    flex-direction: column;
}

.admin-notif-item {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s ease;
    position: relative;
}

.admin-notif-item:last-child {
    border-bottom: none;
}

.admin-notif-item:hover {
    background: rgba(102, 126, 234, 0.03);
}

.admin-notif-item.unread {
    background: rgba(102, 126, 234, 0.05);
    border-left: 3px solid #667eea;
}

.admin-notif-item.unread:hover {
    background: rgba(102, 126, 234, 0.08);
}

.admin-notif-icon-wrap {
    position: relative;
    flex-shrink: 0;
}

.admin-notif-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.admin-notif-unread-dot {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 10px;
    height: 10px;
    background: #667eea;
    border-radius: 50%;
    border: 2px solid white;
}

.admin-notif-body {
    flex: 1;
    min-width: 0;
}

.admin-notif-title {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--primary);
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.admin-notif-repair-link {
    font-size: 0.75rem;
    color: #667eea;
    font-weight: 500;
    text-decoration: none;
    padding: 0.1rem 0.4rem;
    background: rgba(102, 126, 234, 0.08);
    border-radius: 4px;
    transition: all 0.2s;
}

.admin-notif-repair-link:hover {
    background: rgba(102, 126, 234, 0.15);
    color: #5a67d8;
}

.admin-notif-message {
    font-size: 0.813rem;
    color: var(--text-secondary);
    margin-bottom: 0.35rem;
    line-height: 1.5;
}

.admin-notif-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.admin-notif-time {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.admin-notif-date {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.admin-notif-type {
    font-size: 0.65rem;
}

.admin-notif-actions {
    display: flex;
    gap: 0.35rem;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.admin-notif-item:hover .admin-notif-actions {
    opacity: 1;
}

.admin-notif-action-btn {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    color: var(--text-muted);
    background: var(--background);
    border: 1px solid var(--border-light);
    text-decoration: none;
    transition: all 0.2s;
}

.admin-notif-action-btn:hover {
    color: #667eea;
    border-color: #667eea;
    background: rgba(102, 126, 234, 0.05);
}

.admin-notif-action-btn.delete:hover {
    color: #ef4444;
    border-color: #ef4444;
    background: rgba(239, 68, 68, 0.05);
}

@media (max-width: 768px) {
    .admin-notif-item {
        padding: 0.75rem 0.85rem;
    }

    .admin-notif-actions {
        opacity: 1;
        flex-direction: column;
    }

    .admin-notif-title {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }

    .admin-notif-meta {
        gap: 0.5rem;
    }

    .admin-notif-date {
        display: none;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>
