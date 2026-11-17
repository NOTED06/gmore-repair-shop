<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$since  = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-30 seconds'));

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    $since = date('Y-m-d H:i:s', strtotime('-30 seconds'));
}

$db = getDB();

$stmt = $db->prepare("
    SELECT notification_id, repair_id, title, message, type, date_sent, is_read
    FROM notifications
    WHERE user_id = ? AND date_sent > ?
    ORDER BY date_sent DESC
    LIMIT 10
");
$stmt->execute([$userId, $since]);
$newNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$countStmt->execute([$userId]);
$unreadCount = (int)$countStmt->fetchColumn();

echo json_encode([
    'success'       => true,
    'unread_count'  => $unreadCount,
    'notifications' => $newNotifications,
    'server_time'   => date('Y-m-d H:i:s')
]);
