<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

if (isset($_GET['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    redirect('notifications.php', 'All notifications marked as read.', 'success');
}

$stmt = $db->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY date_sent DESC
    LIMIT 50
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unreadCount = $stmt->fetchColumn();

$pageTitle    = 'Notifications';
$activePage   = 'notifications';
$notifCount   = (int)$unreadCount;
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

function notifIcon($title) {
    $t = strtolower($title);
    if (strpos($t, 'complet') !== false || strpos($t, 'shield') !== false) return ['icon' => 'shield-check', 'class' => 'emerald'];
    if (strpos($t, 'assign') !== false)  return ['icon' => 'user-check', 'class' => ''];
    if (strpos($t, 'progress') !== false) return ['icon' => 'activity', 'class' => 'amber'];
    return ['icon' => 'bell', 'class' => ''];
}
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><i data-lucide="bell"></i> Stay in the loop.</h1>
            <p class="page-sub">
                <?php if ($unreadCount > 0): ?>
                You have <strong style="color:var(--accent)"><?= $unreadCount ?></strong> unread notification<?= $unreadCount !== 1 ? 's' : '' ?>.
                <?php else: ?>
                You&rsquo;re all caught up.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($unreadCount > 0): ?>
        <a href="notifications.php?mark_all_read=1" class="btn-ghost"><i data-lucide="check-check"></i> Mark All as Read</a>
        <?php endif; ?>
    </div>

    
    <?php if (empty($notifications)): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i data-lucide="bell-off"></i></div>
        <p class="empty-state-title">No notifications yet</p>
        <p class="empty-state-sub">You&rsquo;ll be notified here about repair updates and messages.</p>
    </div>
    <?php else: ?>
    <div class="notif-list">
        <?php foreach ($notifications as $notif):
            $ni = notifIcon($notif['title']);
        ?>
        <div class="notif-item <?= !$notif['is_read'] ? 'is-new' : '' ?>">
            <div class="notif-avatar <?= $ni['class'] ?>"><i data-lucide="<?= $ni['icon'] ?>"></i></div>
            <div class="notif-body">
                <div class="notif-title"><?= sanitize($notif['title']) ?></div>
                <div class="notif-desc"><?= sanitize($notif['message']) ?></div>
                <div class="notif-time"><i data-lucide="clock-3"></i> <?= timeAgo($notif['date_sent']) ?></div>
            </div>
            <div class="notif-right">
                <?php if (!$notif['is_read']): ?>
                <span class="badge badge-teal badge-pulse">NEW</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</div>
<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
</body>
</html>
