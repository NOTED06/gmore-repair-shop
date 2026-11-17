<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('Admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$conversation_id = (int)($_GET['conversation_id'] ?? 0);

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Missing conversation ID']);
    exit;
}

try {
    
    $stmt = $db->prepare("
        SELECT message_id, message, sender_role, created_at
        FROM chat_messages 
        WHERE conversation_id = ? AND sender_role = 'Customer' AND is_read = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute([$conversation_id]);
    $newMessages = $stmt->fetchAll();
    
    
    if (!empty($newMessages)) {
        $stmt = $db->prepare("
            UPDATE chat_messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_role = 'Customer' AND is_read = 0
        ");
        $stmt->execute([$conversation_id]);
    }
    
    
    $messages = [];
    foreach ($newMessages as $msg) {
        $messages[] = [
            'id' => $msg['message_id'],
            'message' => $msg['message'],
            'sender_role' => $msg['sender_role'],
            'time' => date('M j, g:i A', strtotime($msg['created_at']))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
