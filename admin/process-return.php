<?php

require_once '../config/config.php';
require_once '../includes/functions.php';
requireRole('Admin');

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid form submission.');
    }
    
    $repairId = (int)($_POST['repair_id'] ?? 0);
    
    if (!$repairId) {
        throw new Exception('Invalid repair ID.');
    }
    
    $db = getDB();
    
    
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, c.user_id as customer_user_id, c.contact_no
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch();
    
    if (!$repair) {
        throw new Exception('Repair not found.');
    }
    
    if ($repair['repair_status'] !== 'Completed') {
        throw new Exception('Repair is not in Completed status.');
    }
    
    if (($repair['payment_status'] ?? '') !== 'Verified') {
        throw new Exception('Payment must be verified first.');
    }
    
    
    $receivedBy    = sanitize($_POST['received_by']   ?? $repair['customer_name']);
    $itemCondition = in_array($_POST['item_condition'] ?? '', ['Excellent','Good','Fair','As Received'])
                       ? $_POST['item_condition'] : 'Good';
    $warrantyPeriod = max(0, (int)($_POST['warranty_period'] ?? 30));
    $remarks        = sanitize($_POST['remarks'] ?? '');
    
    
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        UPDATE repairs 
        SET repair_status = 'Returned',
            updated_at = CURRENT_TIMESTAMP
        WHERE repair_id = ?
    ");
    $stmt->execute([$repairId]);
    
    
    $stmt = $db->prepare("SELECT return_id FROM repair_returns WHERE repair_id = ?");
    $stmt->execute([$repairId]);
    if (!$stmt->fetch()) {
        $warrantyExpiry = $warrantyPeriod > 0 ? date('Y-m-d', strtotime("+{$warrantyPeriod} days")) : null;
        $stmt = $db->prepare("
            INSERT INTO repair_returns (repair_id, received_by, item_condition, remarks, warranty_period, warranty_expiry)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$repairId, $receivedBy, $itemCondition, $remarks, $warrantyPeriod ?: null, $warrantyExpiry]);
    }
    
    
    $completionDate = date('Y-m-d', strtotime($repair['date_completed'] ?? 'now'));
    $warrantyEnd = date('Y-m-d', strtotime($completionDate . ' +30 days'));
    $graceEnd = date('Y-m-d', strtotime($completionDate . ' +60 days'));
    $stmt = $db->prepare("
        INSERT IGNORE INTO warranty_tracking (repair_id, completion_date, warranty_end_date, grace_period_end_date)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$repairId, $completionDate, $warrantyEnd, $graceEnd]);
    
    
    logRepairAction(
        $repairId,
        $_SESSION['user_id'],
        'Item Returned',
        'Completed',
        'Returned',
        "Received by: {$receivedBy} | Condition: {$itemCondition} | Warranty: {$warrantyPeriod} days"
    );
    
    
    $customerMsg = "Great news! Your repaired {$repair['appliance_type']} (Repair #{$repairId}) has been marked as returned. Thank you for choosing " . getSetting('site_name', 'RepairShop') . "!";
    createNotification(
        $repair['customer_user_id'],
        'Item Returned Successfully! 🎉',
        $customerMsg,
        'Success',
        $repairId
    );
    
    
    if (!empty($repair['contact_no'])) {
        require_once '../includes/SmsQueue.php';
        $smsQueue = new SmsQueue($db);
        $smsQueue->add($repair['contact_no'], getSetting('site_name', 'RepairShop') . ": " . $customerMsg);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Return recorded successfully! Customer has been notified.'
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
