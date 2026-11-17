<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();
$repairId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customerId = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT * FROM repairs 
    WHERE repair_id = ? AND customer_id = ? AND repair_status = 'Pending'
");
$stmt->execute([$repairId, $customerId]);
$repair = $stmt->fetch();

if (!$repair) {
    redirect('repairs.php', 'Repair not found or cannot be cancelled.', 'danger');
}

try {
    
    $stmt = $db->prepare("
        UPDATE repairs SET repair_status = 'Cancelled' WHERE repair_id = ?
    ");
    $stmt->execute([$repairId]);
    
    
    logRepairAction($repairId, $_SESSION['user_id'], 'Cancelled', 'Pending', 'Cancelled', 'Repair request cancelled by customer');
    
    redirect('repairs.php', 'Repair request has been cancelled.', 'success');
    
} catch (PDOException $e) {
    redirect('repairs.php', 'Error cancelling request. Please try again.', 'danger');
}
