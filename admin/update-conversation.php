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
$action = $input['action'] ?? '';

if (!$conversation_id || !in_array($action, ['close', 'reopen'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    
    $stmt = $db->prepare("
        SELECT cc.*, c.user_id as customer_user_id
        FROM chat_conversations cc
        JOIN customers c ON cc.customer_id = c.customer_id
        WHERE cc.conversation_id = ?
    ");
    $stmt->execute([$conversation_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'message' => 'Conversation not found']);
        exit;
    }
    
    $newStatus = $action === 'close' ? 'Closed' : 'Open';
    
    $stmt = $db->prepare("UPDATE chat_conversations SET status = ?, updated_at = NOW() WHERE conversation_id = ?");
    $stmt->execute([$newStatus, $conversation_id]);
    
    
    $notifyTitle = $action === 'close' ? 'Chat Closed' : 'Chat Reopened';
    $notifyMessage = $action === 'close' 
        ? 'Your support chat has been closed by admin.'
        : 'Your support chat has been reopened.';
    
    createNotification(
        $conversation['customer_user_id'],
        $notifyTitle,
        $notifyMessage,
        $action === 'close' ? 'warning' : 'info'
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation ' . ($action === 'close' ? 'closed' : 'reopened')
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
