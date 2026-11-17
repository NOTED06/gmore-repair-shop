<?php

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

$flashMessage = getFlashMessage();
$notificationCount = isLoggedIn() ? getUnreadNotificationCount($_SESSION['user_id']) : 0;

$recentNotifications = [];
if (isLoggedIn()) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT notification_id, title, message, type, date_sent, is_read
        FROM notifications
        WHERE user_id = ?
        ORDER BY date_sent DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentNotifications = $stmt->fetchAll();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

$role = $_SESSION['role'] ?? 'customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Professional Repair Shop Services Management System">
    <meta name="theme-color" content="#272343">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= $pageTitle ?? SITE_NAME ?></title>
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fonts/fonts.css">
    
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/layout.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/notifications.css">
    
    
    <?php if ($role === 'Admin'): ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/css/admin.css">
    <?php elseif ($role === 'Technician'): ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/technician/css/technician.css">
    <?php if ($currentPage === 'repairs.php'): ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/technician-repairs.css">
    <?php endif; ?>
    <?php elseif ($role === 'Customer'): ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/customer/css/customer.css">
    <?php endif; ?>
    
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    
    
    <script src="<?= SITE_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/fullcalendar/index.global.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/lucide/lucide.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/gsap/gsap.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/qrcode/qrcode.min.js"></script>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const USER_ROLE = '<?= $_SESSION['role'] ?? '' ?>';
    </script>

</head>
<body>
    <?php if (isLoggedIn()): ?>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <div class="app-layout">
        
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <div class="brand-logo">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="brand-text">
                        <?php 
                        $fullSiteName = getSetting('site_name', SITE_NAME);
                        $nameParts = explode(' ', $fullSiteName, 2);
                        ?>
                        <span class="brand-name"><?= htmlspecialchars($nameParts[0] ?? 'RepairShop') ?></span>
                        <span class="brand-tagline"><?= htmlspecialchars($nameParts[1] ?? 'Management System') ?></span>
                    </div>
                </div>
                <button type="button" class="sidebar-toggle" id="sidebarCollapse" title="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <?php if (hasRole('Admin')): ?>
                
                <?php 
                
                $pendingRepairs = 0;
                $pendingPayments = 0;
                $unreadChats = 0;
                if (hasRole('Admin')) {
                    $pendingRepairs = $db->query("SELECT COUNT(*) FROM repairs WHERE repair_status = 'Pending'")->fetchColumn();
                    $pendingPayments = $db->query("SELECT COUNT(*) FROM repairs WHERE payment_status = 'Pending' AND payment_method IS NOT NULL")->fetchColumn();
                    $unreadChats = $db->query("SELECT COUNT(DISTINCT conversation_id) FROM chat_messages WHERE is_read = 0 AND sender_role = 'Customer'")->fetchColumn();
                } elseif (hasRole('Customer')) {
                    $stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $custId = $stmt->fetchColumn();
                    
                    if ($custId) {
                        
                        $pendingPaymentCount = $db->prepare("SELECT COUNT(*) FROM repairs WHERE customer_id = ? AND repair_status = 'Completed' AND (payment_status IS NULL OR payment_status != 'Verified')");
                        $pendingPaymentCount->execute([$custId]);
                        $custPendingPayments = $pendingPaymentCount->fetchColumn();
                    }
                }
                ?>
                <div class="nav-section">
                    <span class="nav-section-title">Admin Dashboard</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/dashboard.php" class="nav-link <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Overview</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/repairs.php" class="nav-link <?= ($currentPage == 'repairs.php' || $currentPage == 'repair-view.php') ? 'active' : '' ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Repair Requests</span>
                            <?php if ($pendingRepairs > 0): ?>
                                <span class="nav-badge badge-warning"><?= $pendingRepairs ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/schedules.php" class="nav-link <?= $currentPage == 'schedules.php' ? 'active' : '' ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Schedules</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/warranty.php" class="nav-link <?= $currentPage == 'warranty.php' ? 'active' : '' ?>">
                            <i class="fas fa-shield-alt"></i>
                            <span>Warranty</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/sales.php" class="nav-link <?= $currentPage == 'sales.php' ? 'active' : '' ?>">
                            <i class="fas fa-cash-register"></i>
                            <span>Sales</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/inventory.php" class="nav-link <?= $currentPage == 'inventory.php' ? 'active' : '' ?>">
                            <i class="fas fa-boxes"></i>
                            <span>Inventory</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/categories.php" class="nav-link <?= $currentPage == 'categories.php' ? 'active' : '' ?>">
                            <i class="fas fa-tags"></i>
                            <span>Categories</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/technicians.php" class="nav-link <?= $currentPage == 'technicians.php' ? 'active' : '' ?>">
                            <i class="fas fa-user-cog"></i>
                            <span>Technicians</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/customers.php" class="nav-link <?= $currentPage == 'customers.php' ? 'active' : '' ?>">
                            <i class="fas fa-users"></i>
                            <span>Customers</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/chat.php" class="nav-link <?= $currentPage == 'chat.php' ? 'active' : '' ?>">
                            <i class="fas fa-comment-dots"></i>
                            <span>Customer Chats</span>
                            <?php if ($unreadChats > 0): ?>
                                <span class="nav-badge"><?= $unreadChats ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/sms-logs.php" class="nav-link <?= $currentPage == 'sms-logs.php' ? 'active' : '' ?>">
                            <i class="fas fa-sms"></i>
                            <span>SMS Logs</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/reports.php" class="nav-link <?= $currentPage == 'reports.php' ? 'active' : '' ?>">
                            <i class="fas fa-chart-pie"></i>
                            <span>Reports</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/admin/system-settings.php" class="nav-link <?= $currentPage == 'system-settings.php' ? 'active' : '' ?>">
                            <i class="fas fa-cogs"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>

                
                <?php elseif (hasRole('Technician')): ?>
                
                <div class="nav-section">
                    <span class="nav-section-title">Work Portal</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/technician/dashboard.php" class="nav-link <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/technician/repairs.php" class="nav-link <?= $currentPage == 'repairs.php' || $currentPage == 'repair-detail.php' ? 'active' : '' ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Assigned Repairs</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/technician/schedule.php" class="nav-link <?= $currentPage == 'schedule.php' ? 'active' : '' ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Schedule</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/technician/settings.php" class="nav-link <?= $currentPage == 'settings.php' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
                
                <?php elseif (hasRole('Customer')): ?>
                
                <div class="nav-section">
                    <span class="nav-section-title">My Account</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/customer/dashboard.php" class="nav-link <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/customer/repairs.php" class="nav-link <?= $currentPage == 'repairs.php' || $currentPage == 'repair-detail.php' ? 'active' : '' ?>">
                            <i class="fas fa-tools"></i>
                            <span>My Repairs</span>
                            <?php if (isset($custPendingPayments) && $custPendingPayments > 0): ?>
                                <span class="nav-badge badge-warning"><?= $custPendingPayments ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/customer/new-request.php" class="nav-link <?= $currentPage == 'new-request.php' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i>
                            <span>New Request</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="<?= SITE_URL ?>/customer/chat.php" class="nav-link <?= $currentPage == 'chat.php' ? 'active' : '' ?>">
                            <i class="fas fa-comments"></i>
                            <span>Chat Support</span>
                            <?php 
                            $stmt = $db->prepare("SELECT c.customer_id FROM customers c WHERE c.user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $custInfo = $stmt->fetch();
                            if ($custInfo) {
                                $customerChatUnread = $db->prepare("
                                    SELECT COUNT(*) FROM chat_messages cm 
                                    JOIN chat_conversations cc ON cm.conversation_id = cc.conversation_id 
                                    WHERE cc.customer_id = ? AND cm.is_read = 0 AND cm.sender_role = 'Admin'
                                ");
                                $customerChatUnread->execute([$custInfo['customer_id']]);
                                $unreadChatCount = $customerChatUnread->fetchColumn();
                                if ($unreadChatCount > 0): 
                            ?>
                            <span class="nav-badge"><?= $unreadChatCount ?></span>
                            <?php endif; } ?>
                        </a>
                    </li>
                </ul>
                <?php endif; ?>
            </nav>
            
            
            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'], 0, 2)) ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?= sanitize($_SESSION['full_name'] ?? $_SESSION['username']) ?></div>
                        <div class="user-role"><?= sanitize($_SESSION['role']) ?></div>
                    </div>
                    <a href="<?= SITE_URL ?>/logout.php" class="logout-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>
        
        
        <div class="main-wrapper">
            
            <header class="top-header">
                <div class="header-left">
                    <button class="mobile-toggle" id="mobileToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
                </div>
                <div class="header-right">
                    <div class="header-search" id="globalSearch">
                        <i class="fas fa-search"></i>
                        <input type="text" id="globalSearchInput" placeholder="<?= ($_SESSION['role'] ?? '') === 'Admin' ? 'Search products, repairs, customers...' : 'Search products & repairs...' ?>" autocomplete="off">
                        <div class="live-search-dropdown" id="liveSearchDropdown"></div>
                    </div>
                    <?php if (isLoggedIn()): ?>
                    <div class="notification-wrapper">
                        <button class="header-icon-btn" id="notificationBtn" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?= $notificationCount > 9 ? '9+' : $notificationCount ?></span>
                            <?php endif; ?>
                        </button>
                        
                        
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h3>Notifications</h3>
                                <?php if ($notificationCount > 0): ?>
                                <a href="#" class="mark-all-read" onclick="markAllAsRead(); return false;">
                                    <i class="fas fa-check-double"></i> Mark all as read
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="notification-list">
                                <?php if (empty($recentNotifications)): ?>
                                    <div class="notification-empty">
                                        <i class="fas fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <div class="notification-item <?= $notif['is_read'] ? 'read' : 'unread' ?>" 
                                             onclick="markAsRead(<?= $notif['notification_id'] ?>)">
                                            <div class="notification-icon <?= strtolower(str_replace(' ', '_', $notif['type'])) ?>">
                                                <i class="fas fa-<?= getNotificationIcon(strtolower(str_replace(' ', '_', $notif['type']))) ?>"></i>
                                            </div>
                                            <div class="notification-content">
                                                <h4><?= sanitize($notif['title']) ?></h4>
                                                <p><?= sanitize(substr($notif['message'], 0, 80)) ?><?= strlen($notif['message']) > 80 ? '...' : '' ?></p>
                                                <span class="notification-time">
                                                    <i class="fas fa-clock"></i>
                                                    <?= timeAgo($notif['date_sent']) ?>
                                                </span>
                                            </div>
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="unread-dot"></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($recentNotifications)): ?>
                            <div class="notification-footer">
                                <a href="<?= SITE_URL ?>/<?= strtolower($role) ?>/notifications.php">
                                    View All Notifications
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="<?= SITE_URL ?>/<?= strtolower($role) ?>/settings.php" class="header-icon-btn" title="Settings">
                        <i class="fas fa-cog"></i>
                    </a>
                </div>
            </header>
            
            
            <main class="content-area">
                <?php if ($flashMessage): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var swalType = '<?= $flashMessage['type'] ?>';
                    if (swalType === 'danger') swalType = 'error';
                    if (swalType !== 'success' && swalType !== 'error' && swalType !== 'warning' && swalType !== 'info') swalType = 'info';
                    var swalTitles = { success: 'Success!', error: 'Error!', warning: 'Warning!', info: 'Info' };
                    Swal.fire({
                        icon: swalType,
                        title: swalTitles[swalType],
                        text: <?= json_encode($flashMessage['text']) ?>,
                        timer: swalType === 'success' ? 2500 : undefined,
                        timerProgressBar: swalType === 'success',
                        showConfirmButton: swalType !== 'success',
                        confirmButtonColor: '#272343'
                    });
                });
                </script>
                <?php endif; ?>

    <?php else: ?>
        
    <?php endif; ?>
