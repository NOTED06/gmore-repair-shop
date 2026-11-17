<?php

$activePage   = $activePage   ?? '';
$notifCount   = (int)($notifCount   ?? 0);
$userName     = $userName     ?? 'User';
$userInitials = $userInitials ?? 'U';

$navLinks = [
    'dashboard'     => ['label' => 'Dashboard',      'href' => SITE_URL . '/customer/dashboard.php',     'icon' => 'layout-dashboard'],
    'repairs'       => ['label' => 'My Repairs',      'href' => SITE_URL . '/customer/repairs.php',       'icon' => 'wrench'],
    'new-request'   => ['label' => 'New Request',     'href' => SITE_URL . '/customer/new-request.php',   'icon' => 'plus-circle'],
];

$_navNotifs = [];
try {
    $_navDb = getDB();
    $_navStmt = $_navDb->prepare("
        SELECT notification_id, repair_id, title, message, type, date_sent, is_read
        FROM notifications
        WHERE user_id = ?
        ORDER BY date_sent DESC
        LIMIT 8
    ");
    $_navStmt->execute([$_SESSION['user_id']]);
    $_navNotifs = $_navStmt->fetchAll();
} catch (Exception $e) {  }

function _navNotifIcon($title) {
    $t = strtolower($title ?? '');
    if (strpos($t, 'complet') !== false || strpos($t, 'return') !== false) return ['icon' => 'check-circle', 'cls' => 'ni-emerald'];
    if (strpos($t, 'assign') !== false)   return ['icon' => 'user-check',  'cls' => 'ni-blue'];
    if (strpos($t, 'progress') !== false) return ['icon' => 'activity',    'cls' => 'ni-amber'];
    if (strpos($t, 'payment') !== false)  return ['icon' => 'credit-card', 'cls' => 'ni-emerald'];
    if (strpos($t, 'schedule') !== false) return ['icon' => 'calendar',    'cls' => 'ni-blue'];
    if (strpos($t, 'warranty') !== false) return ['icon' => 'shield',      'cls' => 'ni-amber'];
    return ['icon' => 'bell', 'cls' => ''];
}
?>
<nav class="navbar" id="mainNavbar" role="navigation" aria-label="Main navigation">
    <div class="navbar-inner">

        
        <a href="<?= SITE_URL ?>/customer/dashboard.php" class="navbar-logo" aria-label="<?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?> Home">
            <span class="navbar-logo-row">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
                <?php 
                $fullSiteName = getSetting('site_name', SITE_NAME);
                $nameParts = explode(' ', $fullSiteName, 2);
                ?>
                <span class="navbar-logo-name"><?= htmlspecialchars($nameParts[0] ?? 'RepairShop') ?></span>
            </span>
            <span class="navbar-logo-sub"><?= htmlspecialchars(strtoupper($nameParts[1] ?? 'MANAGEMENT SYSTEM')) ?></span>
        </a>

        
        <div class="navbar-right">

            
            <div class="navbar-search" style="position:relative;">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                     fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
                <input type="search" id="globalSearchInput" placeholder="Search repairs & products..." aria-label="Search" autocomplete="off">
                <div class="global-search-dropdown" id="globalSearchDropdown"></div>
            </div>

            
            <a href="<?= SITE_URL ?>/customer/repairs.php" class="nav-pill-btn <?= ($activePage === 'repairs') ? 'active' : '' ?>" aria-label="My Repairs" title="My Repairs">
                <i data-lucide="wrench"></i>
                <span>My Repairs</span>
            </a>
            <button type="button" class="nav-pill-btn nav-pill-primary" aria-label="New Request" title="New Request" onclick="_openNewRequest()">
                <i data-lucide="plus-circle"></i>
                <span>New Request</span>
            </button>

            
            <div class="notif-bell-wrap" id="notifBellWrap">
                <button class="icon-btn" id="notifBellBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                    <i data-lucide="bell"></i>
                    <?php if ($notifCount > 0): ?>
                    <span class="notif-count-badge"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
                    <?php endif; ?>
                </button>

                
                <div class="notif-dropdown" id="notifDropdown" role="menu">
                    <div class="notif-dd-header">
                        <span class="notif-dd-title">Notifications</span>
                        <?php if ($notifCount > 0): ?>
                        <button class="notif-dd-mark-all" id="notifMarkAll">Mark all as read</button>
                        <?php endif; ?>
                    </div>

                    <div class="notif-dd-list" id="notifDdList">
                        <?php if (empty($_navNotifs)): ?>
                        <div class="notif-dd-empty">
                            <i data-lucide="bell-off"></i>
                            <span>No notifications yet</span>
                        </div>
                        <?php else: ?>
                            <?php foreach ($_navNotifs as $n):
                                $ni = _navNotifIcon($n['title']);
                                $notifUrl = SITE_URL . '/customer/notifications.php';
                                if (!empty($n['repair_id'])) {
                                    $notifUrl = SITE_URL . '/customer/repair-detail.php?id=' . $n['repair_id'] . '&highlight=1';
                                }
                            ?>
                            <a href="<?= $notifUrl ?>" class="notif-dd-item<?= !$n['is_read'] ? ' unread' : '' ?>" data-nid="<?= $n['notification_id'] ?>">
                                <div class="notif-dd-icon <?= $ni['cls'] ?>"><i data-lucide="<?= $ni['icon'] ?>"></i></div>
                                <div class="notif-dd-body">
                                    <div class="notif-dd-item-title"><?= sanitize($n['title']) ?></div>
                                    <div class="notif-dd-item-msg"><?= sanitize(mb_strimwidth($n['message'], 0, 80, '…')) ?></div>
                                    <div class="notif-dd-item-time"><i data-lucide="clock-3"></i> <?= timeAgo($n['date_sent']) ?></div>
                                </div>
                                <?php if (!$n['is_read']): ?>
                                <span class="notif-dd-new-dot"></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <a href="<?= SITE_URL ?>/customer/notifications.php" class="notif-dd-footer">
                        View all notifications <i data-lucide="arrow-right"></i>
                    </a>
                </div>
            </div>

            
            <div style="position:relative">
                <button class="avatar-btn" id="avatarBtn" aria-haspopup="true" aria-expanded="false"
                        aria-label="Account menu" title="<?= htmlspecialchars($userName) ?>">
                    <?= htmlspecialchars($userInitials) ?>
                </button>
                <div class="avatar-dropdown" id="avatarDropdown" role="menu">
                    <div class="avatar-dropdown-header">
                        <span class="avatar-dropdown-name"><?= htmlspecialchars($userName) ?></span>
                        <span class="avatar-dropdown-role">Customer</span>
                    </div>
                    <div class="avatar-dropdown-divider"></div>
                    <a href="<?= SITE_URL ?>/customer/settings.php" role="menuitem">
                        <i data-lucide="user" aria-hidden="true"></i> My Profile
                    </a>
                    <div class="avatar-dropdown-divider"></div>
                    <a href="<?= SITE_URL ?>/logout.php" class="logout" role="menuitem">
                        <i data-lucide="log-out" aria-hidden="true"></i> Logout
                    </a>
                </div>
            </div>

        </div>
    </div>
<?php require_once __DIR__ . '/chat-widget.php'; ?>
<?php require_once __DIR__ . '/new-request-modal.php'; ?>

<?php $flash = getFlashMessage(); if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = '<?= $flash['type'] ?>' === 'danger' ? 'error' : '<?= $flash['type'] ?>';
    Swal.fire({
        icon: type,
        title: type.charAt(0).toUpperCase() + type.slice(1),
        text: <?= json_encode($flash['text']) ?>,
        confirmButtonColor: '#1E3A8A'
    });
});
</script>
<?php endif; ?>
</nav>

<style>

.nav-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 0 13px;
    height: 34px;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.92);
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
    line-height: 1;
    font-family: inherit;
    flex-shrink: 0;
}
.nav-pill-btn svg, .nav-pill-btn i {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}
.nav-pill-btn:hover {
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.38);
    transform: translateY(-1px);
}
.nav-pill-btn.active {
    background: #fff;
    color: var(--primary, #1E3A8A);
    border-color: #fff;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
}
.nav-pill-btn.nav-pill-primary {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.28);
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.35);
}
.nav-pill-btn.nav-pill-primary:hover {
    background: linear-gradient(135deg, #1D4ED8, #1E40AF);
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.5);
    transform: translateY(-1px);
    color: #fff;
}
@media (max-width: 640px) {
    .nav-pill-btn span {
        display: none;
    }
    .nav-pill-btn {
        padding: 0 9px;
        width: 34px;
        justify-content: center;
    }
}

.notif-bell-wrap { position: relative; }
.notif-count-badge {
    position: absolute; top: 0; right: 0;
    min-width: 18px; height: 18px; padding: 0 5px;
    background: #EF4444; color: #fff; font-size: 10px; font-weight: 700;
    border-radius: 9px; display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--primary);
    animation: badge-bounce 0.3s ease;
    pointer-events: none;
}
@keyframes badge-bounce { 0%{transform:scale(0)} 60%{transform:scale(1.2)} 100%{transform:scale(1)} }

.notif-dropdown {
    visibility: hidden; opacity: 0; position: absolute; top: calc(100% + 15px); right: -40px;
    width: 380px; max-height: 520px;
    background: #fff; border: 1px solid var(--border); border-radius: 12px;
    box-shadow: 0 16px 48px rgba(30,58,138,0.18);
    z-index: 500; overflow: hidden;
    transform: translateY(10px);
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    pointer-events: none;
}
.notif-bell-wrap:hover .notif-dropdown {
    visibility: visible; opacity: 1; transform: translateY(0); pointer-events: auto;
}

.notif-dropdown::before {
    content: ''; position: absolute; top: -7px; right: 48px;
    width: 14px; height: 14px; background: #fff;
    border-left: 1px solid var(--border); border-top: 1px solid var(--border);
    transform: rotate(45deg);
}

.notif-dropdown::after {
    content: ''; position: absolute; top: -20px; right: 0; left: 0; height: 20px; background: transparent;
}

.notif-dd-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 18px 12px; border-bottom: 1px solid var(--border);
}
.notif-dd-title { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 16px; color: #000; }
.notif-dd-mark-all {
    background: none; border: none; cursor: pointer;
    font-size: 12px; font-weight: 600; color: var(--primary);
    padding: 4px 10px; border-radius: 4px; transition: all 0.15s;
    font-family: 'DM Sans', sans-serif;
}
.notif-dd-mark-all:hover { background: #EFF6FF; }

.notif-dd-list {
    max-height: 380px; overflow-y: auto;
}
.notif-dd-list::-webkit-scrollbar { width: 4px; }
.notif-dd-list::-webkit-scrollbar-thumb { background: var(--gold-mid); border-radius: 2px; }

.notif-dd-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 18px; border-bottom: 1px solid #F5F5F0;
    text-decoration: none; transition: background 0.15s;
    position: relative; cursor: pointer;
}
.notif-dd-item:hover { background: #FAFAF5; }
.notif-dd-item.unread { background: #FEFDF5; }
.notif-dd-item.unread::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--gold); border-radius: 0 2px 2px 0;
}
.notif-dd-item:last-child { border-bottom: none; }

.notif-dd-icon {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; background: var(--gold-light); color: var(--gold);
}
.notif-dd-icon svg { width: 16px; height: 16px; }
.notif-dd-icon.ni-emerald { background: #F0FDF4; color: #059669; }
.notif-dd-icon.ni-blue    { background: #EFF6FF; color: var(--primary); }
.notif-dd-icon.ni-amber   { background: #FFF8E1; color: #D97706; }

.notif-dd-body { flex: 1; min-width: 0; }
.notif-dd-item-title {
    font-size: 13px; font-weight: 600; color: #1a1a1a; line-height: 1.3;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notif-dd-item-msg {
    font-size: 12px; color: #666; line-height: 1.4; margin-top: 2px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.notif-dd-item-time {
    font-size: 11px; color: #999; margin-top: 4px;
    display: inline-flex; align-items: center; gap: 3px;
}
.notif-dd-item-time svg { width: 10px; height: 10px; }

.notif-dd-new-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--gold); flex-shrink: 0; margin-top: 6px;
    box-shadow: 0 0 0 3px rgba(201,168,76,0.15);
}

.notif-dd-empty {
    padding: 40px 20px; text-align: center;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    color: #999;
}
.notif-dd-empty svg { width: 28px; height: 28px; opacity: 0.4; }
.notif-dd-empty span { font-size: 13px; }

.notif-dd-footer {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 12px 18px; border-top: 1px solid var(--border);
    font-size: 13px; font-weight: 600; color: var(--primary);
    text-decoration: none; transition: background 0.15s;
}
.notif-dd-footer:hover { background: #EFF6FF; }
.notif-dd-footer svg { width: 14px; height: 14px; }

@media (max-width: 640px) {
    .notif-dropdown { width: calc(100vw - 20px); right: -60px; }
    .notif-dropdown::before { right: 68px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const bellBtn  = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    const wrap     = document.getElementById('notifBellWrap');
    if (!bellBtn || !dropdown) return;

    
    wrap.addEventListener('mouseenter', () => {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });

    
    const markAllBtn = document.getElementById('notifMarkAll');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            fetch('<?= SITE_URL ?>/api/mark-all-notifications-read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    
                    document.querySelectorAll('.notif-dd-item.unread').forEach(el => {
                        el.classList.remove('unread');
                    });
                    document.querySelectorAll('.notif-dd-new-dot').forEach(el => el.remove());
                    
                    const badge = document.querySelector('.notif-count-badge');
                    if (badge) badge.remove();
                    
                    markAllBtn.style.display = 'none';
                }
            });
        });
    }
});
</script>

<style>
.global-search-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; margin-top: 8px;
    background: #fff; border: 1px solid var(--border); border-radius: 12px;
    box-shadow: 0 16px 48px rgba(30,58,138,0.18);
    z-index: 600; display: none; max-height: 400px; overflow-y: auto;
}
.global-search-dropdown.open { display: block; animation: nd-slide 0.2s ease; }
.search-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px;
    border-bottom: 1px solid #F5F5F0; text-decoration: none; color: inherit;
    transition: background 0.15s; cursor: pointer;
}
.search-item:hover { background: #FAFAF5; }
.search-item:last-child { border-bottom: none; }
.search-icon-wrap {
    width: 32px; height: 32px; border-radius: 8px; background: #F8FAFC;
    display: flex; align-items: center; justify-content: center; color: var(--primary);
    flex-shrink: 0;
}
.search-icon-wrap svg { width: 16px; height: 16px; }
.search-body { flex: 1; min-width: 0; }
.search-title { font-size: 13px; font-weight: 600; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.search-sub { font-size: 11px; color: #64748B; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.search-empty { padding: 20px; text-align: center; color: #64748B; font-size: 13px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('globalSearchInput');
    const searchDropdown = document.getElementById('globalSearchDropdown');
    let searchTimeout;

    if (searchInput && searchDropdown) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            clearTimeout(searchTimeout);

            if (query.length < 1) {
                searchDropdown.classList.remove('open');
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch('<?= SITE_URL ?>/api/search-global.php?q=' + encodeURIComponent(query))
                    .then(r => r.json())
                    .then(data => {
                        searchDropdown.innerHTML = '';
                        if (data.success && data.results.length > 0) {
                            data.results.forEach(item => {
                                const a = document.createElement('a');
                                a.href = item.url;
                                a.className = 'search-item';
                                const icon = item.type === 'product' ? 'package' : 'wrench';
                                a.innerHTML = `
                                    <div class="search-icon-wrap"><i data-lucide="${icon}"></i></div>
                                    <div class="search-body">
                                        <div class="search-title">${item.title}</div>
                                        <div class="search-sub">${item.subtitle}</div>
                                    </div>
                                `;
                                searchDropdown.appendChild(a);
                            });
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                            searchDropdown.classList.add('open');
                        } else {
                            searchDropdown.innerHTML = '<div class="search-empty">No results found.</div>';
                            searchDropdown.classList.add('open');
                        }
                    });
            }, 300);
        });

        
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
                searchDropdown.classList.remove('open');
            }
        });

        
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length > 0 && searchDropdown.innerHTML !== '') {
                searchDropdown.classList.add('open');
            }
        });
    }
});
</script>
