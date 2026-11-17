<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('Admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();

$input = json_decode(file_get_contents('php://input'), true);
$conversation_id = (int)($input['conversation_id'] ?? 0);
$message = trim($input['message'] ?? '');

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    
    $stmt = $db->prepare("
        SELECT cc.*, c.user_id as customer_user_id, c.full_name as customer_name
        FROM chat_conversations cc
        JOIN customers c ON cc.customer_id = c.customer_id
        WHERE cc.conversation_id = ? AND cc.status = 'Open'
    ");
    $stmt->execute([$conversation_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'message' => 'Conversation not found or closed']);
        exit;
    }
    
    $db->beginTransaction();
    
    
    $stmt = $db->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, sender_role, message, is_read, created_at)
        VALUES (?, ?, 'Admin', ?, 0, NOW())
    ");
    $stmt->execute([$conversation_id, $_SESSION['user_id'], $message]);
    
    
    $stmt = $db->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);
    
    
    createNotification(
        $conversation['customer_user_id'],
        'New Message from Support',
        "You have a new reply: \"" . substr($message, 0, 50) . "...\"",
        'info'
    );

    
    $stmt = $db->prepare("SELECT contact_no FROM customers WHERE user_id = ?");
    $stmt->execute([$conversation['customer_user_id']]);
    $contactNo = $stmt->fetchColumn();

    if (!empty($contactNo)) {
        require_once '../includes/SmsQueue.php';
        $smsQueue = new SmsQueue($db);
        $siteName = getSetting('site_name', SITE_NAME);
        $smsMsg = "$siteName: Support replied to your chat: \"" . substr($message, 0, 60) . "...\"";
        $smsQueue->add($contactNo, $smsMsg);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
