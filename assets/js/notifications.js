

(function() {
    'use strict';

    var siteUrl = '';
    var lastCheckTime = '';
    var pollInterval = null;
    var POLL_SECONDS = 15;
    var seenIds = {};
    var toastContainer = null;

    
    document.addEventListener('DOMContentLoaded', function() {
        siteUrl = (document.querySelector('meta[name="site-url"]') || {}).content || '';
        initNotificationDropdown();
        initToastContainer();
        requestBrowserPermission();
        startPolling();
    });

    

    function initNotificationDropdown() {
        var notifDropdown = document.getElementById('notificationDropdown');
        var notifWrapper = document.querySelector('.notification-wrapper');

        if (!notifWrapper || !notifDropdown) return;

        var hideTimeout;

        notifWrapper.addEventListener('mouseenter', function() {
            clearTimeout(hideTimeout);
            notifDropdown.classList.add('show');
        });

        notifWrapper.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(function() {
                notifDropdown.classList.remove('show');
            }, 200);
        });

        notifDropdown.addEventListener('mouseenter', function() {
            clearTimeout(hideTimeout);
        });

        notifDropdown.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(function() {
                notifDropdown.classList.remove('show');
            }, 200);
        });
    }

    

    function initToastContainer() {
        toastContainer = document.getElementById('toastNotifContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastNotifContainer';
            toastContainer.className = 'toast-notif-container';
            document.body.appendChild(toastContainer);
        }
    }

    

    function requestBrowserPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    

    function playNotifSound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            
            [660, 880].forEach(function(freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.18, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35 + i * 0.15);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + i * 0.15);
                osc.stop(ctx.currentTime + 0.5 + i * 0.15);
            });
        } catch (e) {  }
    }

    

    function showToast(notif) {
        if (!toastContainer) return;

        var iconMap = {
            'repair_update': 'fa-wrench',
            'repair_assigned': 'fa-user-check',
            'repair_completed': 'fa-check-circle',
            'schedule_update': 'fa-calendar-alt',
            'payment': 'fa-dollar-sign',
            'info': 'fa-info-circle',
            'success': 'fa-check-circle',
            'warning': 'fa-exclamation-triangle',
            'alert': 'fa-exclamation-circle'
        };
        var colorMap = {
            'repair_update': '#667eea',
            'repair_assigned': '#4facfe',
            'repair_completed': '#10b981',
            'schedule_update': '#f59e0b',
            'payment': '#30cfd0',
            'info': '#3b82f6',
            'success': '#10b981',
            'warning': '#f59e0b',
            'alert': '#ef4444'
        };
        var type = (notif.type || 'info').toLowerCase().replace(/\s+/g, '_');
        var icon = iconMap[type] || 'fa-bell';
        var color = colorMap[type] || '#667eea';

        var toast = document.createElement('div');
        toast.className = 'toast-notif toast-notif-enter';
        toast.innerHTML =
            '<div class="toast-notif-accent" style="background:' + color + '"></div>' +
            '<div class="toast-notif-icon" style="color:' + color + ';background:' + color + '15">' +
                '<i class="fas ' + icon + '"></i>' +
            '</div>' +
            '<div class="toast-notif-body">' +
                '<div class="toast-notif-title">' + escapeHtml(notif.title || 'Notification') + '</div>' +
                '<div class="toast-notif-msg">' + escapeHtml(truncate(notif.message || '', 100)) + '</div>' +
                '<div class="toast-notif-time"><i class="fas fa-clock"></i> Just now</div>' +
            '</div>' +
            '<button class="toast-notif-close" title="Dismiss">&times;</button>';

        toast.querySelector('.toast-notif-close').addEventListener('click', function() {
            dismissToast(toast);
        });

        
        toast.querySelector('.toast-notif-body').addEventListener('click', function() {
            var role = (document.body.getAttribute('data-role') || '').toLowerCase();
            if (!role) {
                
                var adminLink = document.querySelector('a[href*="/admin/"]');
                var techLink  = document.querySelector('a[href*="/technician/"]');
                var custLink  = document.querySelector('a[href*="/customer/"]');
                if (adminLink) role = 'admin';
                else if (techLink) role = 'technician';
                else role = 'customer';
            }
            if (notif.repair_id) {
                window.location.href = siteUrl + '/' + role + '/repair-detail.php?id=' + notif.repair_id + '&highlight=1';
            } else {
                window.location.href = siteUrl + '/' + role + '/notifications.php';
            }
        });

        toastContainer.appendChild(toast);

        
        requestAnimationFrame(function() {
            toast.classList.remove('toast-notif-enter');
            toast.classList.add('toast-notif-visible');
        });

        
        setTimeout(function() {
            dismissToast(toast);
        }, 6000);
    }

    function dismissToast(el) {
        if (!el || el._dismissed) return;
        el._dismissed = true;
        el.classList.remove('toast-notif-visible');
        el.classList.add('toast-notif-exit');
        setTimeout(function() {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 400);
    }

    

    function showBrowserNotif(notif) {
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification(notif.title || 'RepairShop', {
                    body: notif.message || '',
                    
                    tag: 'repairshop-' + (notif.notification_id || Date.now())
                });
            } catch (e) {  }
        }
    }

    

    function updateBadge(count) {
        var badge = document.querySelector('.notification-badge');
        var btn = document.getElementById('notificationBtn');
        if (!btn) return;

        if (count > 0) {
            var label = count > 9 ? '9+' : String(count);
            if (badge) {
                badge.textContent = label;
            } else {
                badge = document.createElement('span');
                badge.className = 'notification-badge';
                badge.textContent = label;
                btn.appendChild(badge);
            }
            
            var navBadges = document.querySelectorAll('.nav-link .nav-badge');
            navBadges.forEach(function(nb) {
                if (nb.closest('.nav-link') && nb.closest('.nav-link').href &&
                    nb.closest('.nav-link').href.indexOf('notifications.php') !== -1) {
                    nb.textContent = label;
                }
            });
        } else {
            if (badge) badge.remove();
        }
    }

    

    function startPolling() {
        
        if (!document.getElementById('notificationBtn')) return;

        
        lastCheckTime = new Date().toISOString().replace('T', ' ').substring(0, 19);

        
        var existing = document.querySelectorAll('.notification-item[onclick]');
        existing.forEach(function(el) {
            var match = (el.getAttribute('onclick') || '').match(/markAsRead\((\d+)\)/);
            if (match) seenIds[match[1]] = true;
        });

        pollInterval = setInterval(checkNewNotifications, POLL_SECONDS * 1000);
    }

    function checkNewNotifications() {
        if (!siteUrl) return;

        fetch(siteUrl + '/api/check-notifications.php?since=' + encodeURIComponent(lastCheckTime))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;

                
                updateBadge(data.unread_count);

                
                var newOnes = [];
                (data.notifications || []).forEach(function(n) {
                    if (!seenIds[n.notification_id]) {
                        seenIds[n.notification_id] = true;
                        newOnes.push(n);
                    }
                });

                if (newOnes.length > 0) {
                    playNotifSound();
                    
                    newOnes.slice(0, 3).forEach(function(n, i) {
                        setTimeout(function() {
                            showToast(n);
                            showBrowserNotif(n);
                        }, i * 300);
                    });
                    if (newOnes.length > 3) {
                        setTimeout(function() {
                            showToast({
                                title: 'And ' + (newOnes.length - 3) + ' more...',
                                message: 'Click to view all notifications',
                                type: 'info'
                            });
                        }, 1000);
                    }
                }

                
                if (data.server_time) {
                    lastCheckTime = data.server_time;
                }
            })
            .catch(function() {  });
    }

    

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function truncate(str, max) {
        return str.length > max ? str.substring(0, max) + '...' : str;
    }

    

    window.markAsRead = function(notificationId) {
        fetch(siteUrl + '/api/mark-notification-read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            }
        })
        .catch(function(e) { console.error('Error:', e); });
    };

    window.markAllAsRead = function() {
        fetch(siteUrl + '/api/mark-all-notifications-read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            }
        })
        .catch(function(e) { console.error('Error:', e); });
    };

})();
