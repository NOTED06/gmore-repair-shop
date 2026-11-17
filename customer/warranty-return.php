<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$repairId = (int)($_POST['repair_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if (!$repairId || strlen($reason) < 10) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid repair ID and describe the issue (at least 10 characters).']);
    exit;
}

try {
    $db = getDB();
    
    
    $stmt = $db->prepare("SELECT customer_id, full_name FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found.']);
        exit;
    }
    
    
    $stmt = $db->prepare("
        SELECT r.* FROM repairs r
        WHERE r.repair_id = ? AND r.customer_id = ? AND r.repair_status = 'Returned'
    ");
    $stmt->execute([$repairId, $customer['customer_id']]);
    $originalRepair = $stmt->fetch();
    
    if (!$originalRepair) {
        echo json_encode(['success' => false, 'message' => 'Repair not found or not eligible for warranty return.']);
        exit;
    }
    
    
    $stmt = $db->prepare("
        SELECT w.*,
               DATEDIFF(w.warranty_end_date, CURDATE()) as warranty_days_left,
               DATEDIFF(w.grace_period_end_date, CURDATE()) as grace_days_left
        FROM warranty_tracking w
        WHERE w.repair_id = ?
    ");
    $stmt->execute([$repairId]);
    $warranty = $stmt->fetch();
    
    if (!$warranty) {
        echo json_encode(['success' => false, 'message' => 'No warranty record found for this repair.']);
        exit;
    }
    
    
    $today = date('Y-m-d');
    if ($today <= $warranty['warranty_end_date']) {
        $warrantyStatus = 'Under Warranty';
    } elseif ($today <= $warranty['grace_period_end_date']) {
        $warrantyStatus = 'Grace Period';
    } else {
        echo json_encode(['success' => false, 'message' => 'Warranty has expired. This item is no longer eligible for a free return.']);
        exit;
    }
    
    $db->beginTransaction();
    
    
    $stmt = $db->prepare("
        INSERT INTO repairs (
            customer_id, appliance_type, brand, model, serial_number,
            problem_description, repair_status, estimated_cost
        ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', 0.00)
    ");
    
    $warrantyNote = "[WARRANTY RETURN - Original Repair #$repairId]\n";
    $warrantyNote .= "Warranty Status: $warrantyStatus\n";
    $warrantyNote .= "Customer Issue: $reason";
    
    $stmt->execute([
        $customer['customer_id'],
        $originalRepair['appliance_type'],
        $originalRepair['brand'],
        $originalRepair['model'],
        $originalRepair['serial_number'],
        $warrantyNote
    ]);
    
    $newRepairId = $db->lastInsertId();
    
    
    logRepairAction(
        $newRepairId,
        $_SESSION['user_id'],
        'Warranty Return Request',
        null,
        'Pending',
        "Warranty return for original repair #$repairId. Status: $warrantyStatus. Reason: $reason"
    );
    
    
    logRepairAction(
        $repairId,
        $_SESSION['user_id'],
        'Warranty Return Requested',
        'Returned',
        'Returned',
        "Customer requested warranty return. New repair #$newRepairId created. Reason: $reason"
    );
    
    
    $stmt = $db->prepare("
        UPDATE warranty_tracking 
        SET notes = CONCAT(COALESCE(notes, ''), '\n[', NOW(), '] Warranty return requested. New repair #', ?, ' created.')
        WHERE warranty_id = ?
    ");
    $stmt->execute([$newRepairId, $warranty['warranty_id']]);
    
    
    $stmt = $db->prepare("SELECT user_id FROM users WHERE role = 'Admin'");
    $stmt->execute();
    $admins = $stmt->fetchAll();
    
    foreach ($admins as $admin) {
        createNotification(
            $admin['user_id'],
            'Warranty Return Request',
            "Customer {$customer['full_name']} has requested a warranty return for Repair #$repairId ({$originalRepair['appliance_type']}). New repair #$newRepairId created. Warranty Status: $warrantyStatus.",
            'Warning',
            $newRepairId
        );
    }
    
    
    createNotification(
        $_SESSION['user_id'],
        'Warranty Return Submitted',
        "Your warranty return request for Repair #$repairId has been submitted successfully. A new repair request #$newRepairId has been created. Our team will review it shortly.",
        'Success',
        $newRepairId
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Your warranty return request has been submitted!<br>A new repair <strong>#$newRepairId</strong> has been created. Our team will review it shortly.",
        'new_repair_id' => $newRepairId
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Warranty return error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Warranty return error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
