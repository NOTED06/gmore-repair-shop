<?php

ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
requireRole('Technician');

ob_clean();

header('Content-Type: application/json');

$db = getDB();
$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid form submission.');
    }
    
    $repairId = (int)($_POST['repair_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $statusNotes = sanitize($_POST['status_notes'] ?? '');
    $diagnosis = isset($_POST['diagnosis']) ? sanitize($_POST['diagnosis']) : null;
    $totalCost = !empty($_POST['total_cost']) ? (float)$_POST['total_cost'] : null;
    $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : null;

    if ($totalCost !== null && $totalCost > 0) {
        if ($estimatedCost === null || $estimatedCost <= 0 || $estimatedCost > $totalCost) {
            $estimatedCost = round($totalCost * 0.5, 2);
        }
    }
    
    if (!$repairId) {
        throw new Exception('Repair ID is required.');
    }
    
    
    $allowedStatuses = ['In Progress', 'Completed', 'Cancelled', 'On Hold'];
    if (!in_array($newStatus, $allowedStatuses)) {
        throw new Exception('Invalid status selected.');
    }
    
    
    $stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $techId = $stmt->fetchColumn();
    
    if (!$techId) {
        throw new Exception('Technician profile not found.');
    }
    
    
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, c.user_id as customer_user_id, c.contact_no
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.repair_id = ? AND r.technician_id = ?
    ");
    $stmt->execute([$repairId, $techId]);
    $repair = $stmt->fetch();
    
    if (!$repair) {
        throw new Exception('Repair not found or not assigned to you.');
    }
    
    
    require_once '../includes/SmsQueue.php';
    $smsQueue = new SmsQueue($db);
    
    
    if (in_array($repair['repair_status'], ['Completed', 'Returned'])) {
        throw new Exception('This repair is ' . $repair['repair_status'] . '. No further updates allowed.');
    }
    
    $db->beginTransaction();
    
    $oldStatus = $repair['repair_status'];
    $dateStarted = $repair['date_started'];
    $dateCompleted = $repair['date_completed'];
    $dateCancelled = null;
    
    
    if ($newStatus === 'In Progress' && !$dateStarted) {
        $dateStarted = date('Y-m-d H:i:s');
    }
    
    
    if ($newStatus === 'Completed' && !$dateCompleted) {
        $dateCompleted = date('Y-m-d H:i:s');
    }
    
    
    if ($newStatus === 'Cancelled') {
        $dateCancelled = date('Y-m-d H:i:s');
    }
    
    
    $updateFields = [
        'repair_status' => $newStatus,
        'date_started' => $dateStarted,
        'date_completed' => $dateCompleted
    ];
    
    if ($totalCost !== null && $newStatus !== 'Completed') {
        $updateFields['total_cost'] = $totalCost;
    }
    if ($estimatedCost !== null && $newStatus !== 'Completed') {
        $updateFields['estimated_cost'] = $estimatedCost;
    }
    if ($diagnosis !== null && trim($diagnosis) !== '') {
        $updateFields['diagnosis'] = trim($diagnosis);
    }
    
    if ($statusNotes) {
        $updateFields['repair_notes'] = $repair['repair_notes'] 
            ? $repair['repair_notes'] . "\n\n[" . date('Y-m-d H:i:s') . "] " . $statusNotes
            : "[" . date('Y-m-d H:i:s') . "] " . $statusNotes;
    }
    
    
    $setClause = [];
    $params = [];
    foreach ($updateFields as $field => $value) {
        $setClause[] = "$field = ?";
        $params[] = $value;
    }
    $params[] = $repairId;
    
    $stmt = $db->prepare("UPDATE repairs SET " . implode(', ', $setClause) . " WHERE repair_id = ?");
    $stmt->execute($params);
    
    
    logRepairAction(
        $repairId, 
        $_SESSION['user_id'], 
        'Status Updated', 
        $oldStatus, 
        $newStatus, 
        $statusNotes
    );
    
    
    if ($newStatus === 'In Progress') {
        $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'In Progress' WHERE repair_id = ?");
        $stmt->execute([$repairId]);
    } elseif ($newStatus === 'Completed') {
        $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'Completed', actual_completion = CURDATE() WHERE repair_id = ?");
        $stmt->execute([$repairId]);
        
        
        $wStmt = $db->prepare("INSERT IGNORE INTO warranty_tracking (repair_id, completion_date, warranty_end_date, grace_period_end_date) VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 60 DAY))");
        $wStmt->execute([$repairId]);
    } elseif ($newStatus === 'Cancelled') {
        $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'Cancelled' WHERE repair_id = ?");
        $stmt->execute([$repairId]);
    }
    
    
    $priceInfo = "";
    if ($totalCost !== null && $totalCost > 0) {
        $priceInfo .= " Quoted Cost: ₱" . number_format($totalCost, 2) . ".";
        if ($estimatedCost !== null && $estimatedCost > 0) {
            $priceInfo .= " (Downpayment: ₱" . number_format($estimatedCost, 2) . ").";
        }
        $priceInfo .= " You can review or settle payment in your portal.";
    }

    $finalTotal = !empty($repair['total_cost']) ? (float)$repair['total_cost'] : ($totalCost ?: 0);
    $statusMessages = [
        'In Progress' => 'Your repair (#{repair_id}) is now being worked on by our technician.' . $priceInfo,
        'Completed' => 'Great news! Your repair (#{repair_id}) has been completed' . ($finalTotal > 0 ? " with a total cost of ₱" . number_format($finalTotal, 2) : '') . '. Please pick up your item.',
        'Cancelled' => 'Your repair request (#{repair_id}) has been cancelled. Please contact us for more information.',
        'On Hold' => 'Your repair (#{repair_id}) has been put on hold. We will contact you shortly.'
    ];
    
    $message = str_replace('{repair_id}', $repairId, $statusMessages[$newStatus]);
    
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, repair_id, title, message, type)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $repair['customer_user_id'],
        $repairId,
        'Repair Status Updated',
        $message,
        'Info'
    ]);
    
    
    if (!empty($statusNotes)) {
        $notesMessage = "The technician added a note to your repair (#{$repairId}): \"{$statusNotes}\"";
        
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, repair_id, title, message, type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $repair['customer_user_id'],
            $repairId,
            'Technician Note Added',
            $notesMessage,
            'Info'
        ]);
    }
    
    $db->commit();
    
    
    if (!empty($repair['contact_no'])) {
        $smsMessage = getSetting('site_name', SITE_NAME) . ": " . $message;
        $smsQueue->add($repair['contact_no'], $smsMessage);
    }
    
    $response['success'] = true;
    $response['message'] = 'Status updated successfully!';
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
