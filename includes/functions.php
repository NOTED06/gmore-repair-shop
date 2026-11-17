<?php

function sanitize(?string $data): string {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getUserRole(): ?string {
    return $_SESSION['role'] ?? null;
}

function hasRole(string $role): bool {
    return strcasecmp(getUserRole() ?? '', $role) === 0;
}

function isAdmin(): bool {
    return isLoggedIn() && hasRole('Admin');
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

function requireRole(string $role): void {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: ' . SITE_URL . '/unauthorized.php');
        exit;
    }
    
    if (isset($_SESSION['user_id'])) {
        $db = getDB();
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
        $chk->execute([$_SESSION['user_id']]);
        if (!$chk->fetchColumn()) {
            session_destroy();
            header('Location: ' . SITE_URL . '/login.php?reason=session_expired');
            exit;
        }
    }
}

function setFlashMessage(string $message, string $type = 'info'): void {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getFlashMessage(): ?array {
    if (isset($_SESSION['flash_message'])) {
        $message = [
            'text' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return $message;
    }
    return null;
}

function redirect(string $url, string $message = '', string $type = 'info'): void {
    if ($message) {
        setFlashMessage($message, $type);
    }
    header("Location: $url");
    exit;
}

function formatDate(?string $date, string $format = 'M d, Y'): string {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime, string $format = 'M d, Y h:i A'): string {
    if (!$datetime) return 'N/A';
    return date($format, strtotime($datetime));
}

function formatCurrency(?float $amount): string {
    if ($amount === null) return 'N/A';
    return '₱' . number_format($amount, 2);
}

function getStatusBadgeClass(string $status): string {
    $classes = [
        'Pending' => 'badge-warning',
        'Assigned' => 'badge-info',
        'Diagnosed' => 'badge-info',
        'In Progress' => 'badge-primary',
        'Completed' => 'badge-success',
        'Cancelled' => 'badge-danger',
        'On Hold' => 'badge-secondary',
        'Available' => 'badge-success',
        'Busy' => 'badge-warning',
        'On Leave' => 'badge-secondary',
        'Active' => 'badge-success',
        'Inactive' => 'badge-secondary',
        'Suspended' => 'badge-danger',
        'Sold Out' => 'badge-danger',
        'Under Warranty' => 'badge-success',
        'Grace Period' => 'badge-warning',
        'Expired' => 'badge-secondary',
        'Eligible for Resale' => 'badge-danger',
        'Returned' => 'badge-info',
        'Item Received' => 'badge-info',
        'Under Repair' => 'badge-primary',
        'Ready for Pickup' => 'badge-success',
        
        'Scheduled' => 'badge-info',
        'Confirmed' => 'badge-success',
        'Rescheduled' => 'badge-warning',
        
        'Verified' => 'badge-success',
        'Rejected' => 'badge-danger',
    ];
    return $classes[$status] ?? 'badge-secondary';
}

function createNotification(int $userId, string $title, string $message, string $type = 'Info', ?int $repairId = null): bool {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, repair_id, title, message, type) 
        VALUES (?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$userId, $repairId, $title, $message, $type]);
}

function logRepairAction(int $repairId, int $userId, string $action, ?string $oldStatus = null, ?string $newStatus = null, ?string $description = null): bool {
    try {
        $db = getDB();
        
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) return false;

        $stmt = $db->prepare("
            INSERT INTO repair_logs (repair_id, user_id, action, old_status, new_status, description) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$repairId, $userId, $action, $oldStatus, $newStatus, $description]);
    } catch (Exception $e) {
        
        error_log('logRepairAction failed: ' . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

function escapeLike(string $value): string {
    return str_replace(['%', '_'], ['\\%', '\\_'], $value);
}

function getNotificationIcon(string $type): string {
    $icons = [
        'repair_update' => 'wrench',
        'repair_assigned' => 'user-check',
        'repair_completed' => 'check-circle',
        'schedule_update' => 'calendar-alt',
        'payment' => 'dollar-sign',
        'info' => 'info-circle',
        'success' => 'check-circle',
        'warning' => 'exclamation-triangle',
        'alert' => 'exclamation-circle'
    ];
    return $icons[strtolower($type)] ?? 'bell';
}

function timeAgo(string $datetime): string {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return 'Just now';
    } elseif ($difference < 3600) {
        $minutes = floor($difference / 60);
        return $minutes . ' ' . ($minutes == 1 ? 'minute' : 'minutes') . ' ago';
    } elseif ($difference < 86400) {
        $hours = floor($difference / 3600);
        return $hours . ' ' . ($hours == 1 ? 'hour' : 'hours') . ' ago';
    } elseif ($difference < 604800) {
        $days = floor($difference / 86400);
        return $days . ' ' . ($days == 1 ? 'day' : 'days') . ' ago';
    } elseif ($difference < 2592000) {
        $weeks = floor($difference / 604800);
        return $weeks . ' ' . ($weeks == 1 ? 'week' : 'weeks') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}

function getApplianceIcon(string $type): string {
    $type = strtolower($type);
    if (strpos($type, 'refrigerator') !== false || strpos($type, 'fridge') !== false) return 'fa-refrigerator';
    if (strpos($type, 'washing') !== false || strpos($type, 'dryer') !== false) return 'fa-soap';
    if (strpos($type, 'ac') !== false || strpos($type, 'aircon') !== false || strpos($type, 'conditioner') !== false) return 'fa-wind';
    if (strpos($type, 'tv') !== false || strpos($type, 'television') !== false) return 'fa-tv';
    if (strpos($type, 'laptop') !== false || strpos($type, 'computer') !== false) return 'fa-laptop';
    if (strpos($type, 'phone') !== false || strpos($type, 'mobile') !== false) return 'fa-mobile-alt';
    if (strpos($type, 'microwave') !== false || strpos($type, 'oven') !== false) return 'fa-sink';
    if (strpos($type, 'fan') !== false) return 'fa-fan';
    
    return 'fa-tools'; 
}
