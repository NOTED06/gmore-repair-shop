<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('Customer')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customer = $stmt->fetch();

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
    exit;
}

$customer_id = $customer['customer_id'];
$conversation_id = (int)($_GET['conversation_id'] ?? 0);

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Missing conversation ID']);
    exit;
}

try {
    
    $stmt = $db->prepare("
        SELECT * FROM chat_conversations 
        WHERE conversation_id = ? AND customer_id = ?
    ");
    $stmt->execute([$conversation_id, $customer_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'message' => 'Conversation not found']);
        exit;
    }
    
    
    $fetchAll = isset($_GET['all']) && $_GET['all'] === '1';

    if ($fetchAll) {
        $stmt = $db->prepare("
            SELECT message_id, message, sender_role, created_at
            FROM chat_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversation_id]);
        $allMessages = $stmt->fetchAll();

        
        $stmt = $db->prepare("
            UPDATE chat_messages SET is_read = 1
            WHERE conversation_id = ? AND sender_role = 'Admin' AND is_read = 0
        ");
        $stmt->execute([$conversation_id]);

        $messages = [];
        foreach ($allMessages as $msg) {
            $messages[] = [
                'id' => $msg['message_id'],
                'message' => $msg['message'],
                'sender_role' => $msg['sender_role'],
                'time' => date('M j, g:i A', strtotime($msg['created_at']))
            ];
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
    } else {
        
        $stmt = $db->prepare("
            SELECT message_id, message, sender_role, created_at
            FROM chat_messages 
            WHERE conversation_id = ? AND sender_role = 'Admin' AND is_read = 0
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversation_id]);
        $newMessages = $stmt->fetchAll();
        
        
        if (!empty($newMessages)) {
            $stmt = $db->prepare("
                UPDATE chat_messages 
                SET is_read = 1 
                WHERE conversation_id = ? AND sender_role = 'Admin' AND is_read = 0
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
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
