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

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    if ($action === 'new_conversation') {
        
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
        
        if (empty($subject) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Subject and message are required']);
            exit;
        }
        
        $db->beginTransaction();
        
        
        $stmt = $db->prepare("
            INSERT INTO chat_conversations (customer_id, subject, status, created_at, updated_at)
            VALUES (?, ?, 'Open', NOW(), NOW())
        ");
        $stmt->execute([$customer_id, $subject]);
        $conversation_id = $db->lastInsertId();
        
        
        $stmt = $db->prepare("
            INSERT INTO chat_messages (conversation_id, sender_id, sender_role, message, is_read, created_at)
            VALUES (?, ?, 'Customer', ?, 0, NOW())
        ");
        $stmt->execute([$conversation_id, $_SESSION['user_id'], $message]);
        
        
        $stmt = $db->prepare("SELECT user_id FROM users WHERE role = 'Admin'");
        $stmt->execute();
        $admins = $stmt->fetchAll();
        
        
        $stmt = $db->prepare("SELECT full_name as name FROM customers WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $customerInfo = $stmt->fetch();
        
        foreach ($admins as $admin) {
            createNotification(
                $admin['user_id'],
                'New Chat Message',
                "Customer {$customerInfo['name']} started a new chat: \"{$subject}\"",
                'info'
            );
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Conversation started',
            'conversation_id' => $conversation_id
        ]);
        
    } elseif ($action === 'send_message') {
        
        $conversation_id = (int)($input['conversation_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        
        if (!$conversation_id || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        
        $stmt = $db->prepare("
            SELECT * FROM chat_conversations 
            WHERE conversation_id = ? AND customer_id = ? AND status = 'Open'
        ");
        $stmt->execute([$conversation_id, $customer_id]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            echo json_encode(['success' => false, 'message' => 'Conversation not found or closed']);
            exit;
        }
        
        $db->beginTransaction();
        
        
        $stmt = $db->prepare("
            INSERT INTO chat_messages (conversation_id, sender_id, sender_role, message, is_read, created_at)
            VALUES (?, ?, 'Customer', ?, 0, NOW())
        ");
        $stmt->execute([$conversation_id, $_SESSION['user_id'], $message]);
        
        
        $stmt = $db->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE conversation_id = ?");
        $stmt->execute([$conversation_id]);
        
        
        $stmt = $db->prepare("SELECT user_id FROM users WHERE role = 'Admin'");
        $stmt->execute();
        $admins = $stmt->fetchAll();
        
        $stmt = $db->prepare("SELECT full_name as name FROM customers WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $customerInfo = $stmt->fetch();
        
        foreach ($admins as $admin) {
            createNotification(
                $admin['user_id'],
                'New Chat Message',
                "New message from {$customerInfo['name']}: \"" . substr($message, 0, 50) . "...\"",
                'info'
            );
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Message sent'
        ]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
