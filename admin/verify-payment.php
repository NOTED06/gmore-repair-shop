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
    $action = $_POST['action'] ?? ''; 
    
    if (!$repairId) {
        throw new Exception('Invalid repair ID.');
    }
    
    if (!in_array($action, ['verify', 'reject'])) {
        throw new Exception('Invalid action.');
    }
    
    $db = getDB();
    
    
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, cu.user_id as customer_user_id
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        JOIN users cu ON c.user_id = cu.user_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch();
    
    if (!$repair) {
        throw new Exception('Repair not found.');
    }
    
    if (empty($repair['payment_method'])) {
        throw new Exception('No payment method selected for this repair.');
    }
    
    if ($repair['payment_status'] === 'Verified') {
        throw new Exception('Payment is already verified.');
    }

    if ($action === 'verify' && $repair['payment_method'] === 'GCash') {
        $hasGatewayPayment = !empty($repair['paymongo_payment_id']);
        $hasManualEvidence = !empty($repair['payment_proof']);

        
        if (!empty($repair['paymongo_source_id']) && !$hasGatewayPayment && !$hasManualEvidence) {
            throw new Exception('This GCash checkout has not been confirmed by the payment gateway yet.');
        }
        if (!$hasGatewayPayment && !$hasManualEvidence) {
            throw new Exception('GCash payment cannot be verified without a receipt image.');
        }
    }
    
    
    $isDp = ($repair['payment_type'] === 'downpayment') 
            || (!empty($repair['estimated_cost']) && (float)($repair['amount_paid'] ?? 0) > 0 && (float)$repair['amount_paid'] == (float)$repair['estimated_cost'] && (float)$repair['amount_paid'] < (float)$repair['total_cost'] && ($repair['payment_type'] ?? '') !== 'balance');
    $isBal = ($repair['payment_type'] === 'balance');

    $payAmount = (float)($repair['amount_paid'] ?? 0);
    if ($payAmount <= 0) {
        $payAmount = $isDp ? (float)$repair['estimated_cost'] : (float)($repair['total_cost'] ?: $repair['estimated_cost']);
    }

    if ($isBal) {
        $typeLabel = 'Remaining Balance';
        $finalAmountPaid = (float)$repair['total_cost'];
        $finalPaymentType = 'full';
    } elseif ($isDp) {
        $typeLabel = 'Downpayment';
        $finalAmountPaid = $payAmount;
        $finalPaymentType = 'downpayment';
    } else {
        $typeLabel = 'Full Payment';
        $finalAmountPaid = $payAmount;
        $finalPaymentType = 'full';
    }

    
    $newStatus = $action === 'verify' ? 'Verified' : 'Rejected';
    if ($action === 'verify') {
        $stmt = $db->prepare("
            UPDATE repairs 
            SET payment_status = 'Verified',
                payment_type = ?,
                amount_paid = ?,
                paymongo_source_id = NULL,
                payment_verified_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE repair_id = ?
        ");
        $stmt->execute([$finalPaymentType, $finalAmountPaid, $repairId]);
    }
    
    
    if ($action === 'verify') {
        $allowedEnum = ['Cash', 'Credit Card', 'Debit Card', 'Bank Transfer', 'GCash', 'PayMaya', 'Online Banking'];
        $method = in_array($repair['payment_method'], $allowedEnum) ? $repair['payment_method'] : 'Cash';
        $refCode = !empty($repair['payment_reference']) ? $repair['payment_reference'] : ('REF-' . str_pad($repairId, 5, '0', STR_PAD_LEFT));
        $note = "{$typeLabel} verified by admin (â‚±" . number_format($payAmount, 2) . ")";
        
        $pStmt = $db->prepare("
            INSERT INTO payments (repair_id, customer_id, amount, payment_method, payment_status, payment_date, transaction_id, payment_proof, notes)
            VALUES (?, ?, ?, ?, 'Paid', CURRENT_TIMESTAMP, ?, ?, ?)
        ");
        $pStmt->execute([$repairId, $repair['customer_id'], $payAmount, $method, $refCode, $repair['payment_proof'] ?? null, $note]);
    }
    
    
    logRepairAction(
        $repairId,
        $_SESSION['user_id'],
        'Payment ' . $newStatus,
        null,
        null,
        "Admin " . ($action === 'verify' ? 'verified' : 'rejected') . " {$typeLabel} of â‚±" . number_format($payAmount, 2) . " via {$repair['payment_method']}"
    );
    
    
    $notificationTitle = $action === 'verify' ? "{$typeLabel} Verified" : 'Payment Rejected';
    if ($action === 'verify') {
        if ($isDp) {
            $balance = max(0, (float)$repair['total_cost'] - $payAmount);
            $notificationMessage = "Your downpayment of â‚±" . number_format($payAmount, 2) . " for repair #{$repairId} has been verified! Remaining balance: â‚±" . number_format($balance, 2) . ".";
        } elseif ($isBal) {
            $notificationMessage = "Your remaining balance of â‚±" . number_format($payAmount, 2) . " for repair #{$repairId} has been verified. Thank you for completing your payment!";
        } else {
            $notificationMessage = "Your payment of â‚±" . number_format($payAmount, 2) . " for repair #{$repairId} has been verified. Thank you!";
        }
    } else {
        $notificationMessage = "Your payment for repair #{$repairId} was rejected. Please contact us or submit a new payment.";
    }
    
    createNotification(
        $repair['customer_user_id'],
        $notificationTitle,
        $notificationMessage,
        $action === 'verify' ? 'Success' : 'Warning',
        $repairId
    );

    
    $stmt = $db->prepare("SELECT contact_no FROM customers WHERE customer_id = ?");
    $stmt->execute([$repair['customer_id']]);
    $contactNo = $stmt->fetchColumn();

    if (!empty($contactNo)) {
        require_once '../includes/SmsQueue.php';
        $smsQueue = new SmsQueue($db);
        $smsMessage = getSetting('site_name', SITE_NAME) . ": " . $notificationMessage;
        $smsQueue->add($contactNo, $smsMessage);
    }
    
    
    if ($action === 'reject') {
        if ($isBal) {
            $stmtPrev = $db->prepare("SELECT amount FROM payments WHERE repair_id = ? AND payment_status = 'Paid' ORDER BY payment_id DESC LIMIT 1");
            $stmtPrev->execute([$repairId]);
            $prevPaid = (float)$stmtPrev->fetchColumn();
            if ($prevPaid > 0) {
                $stmt = $db->prepare("
                    UPDATE repairs 
                    SET payment_type = 'downpayment',
                        amount_paid = ?,
                        payment_status = 'Verified',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE repair_id = ?
                ");
                $stmt->execute([$prevPaid, $repairId]);
            } else {
                $stmt = $db->prepare("
                    UPDATE repairs 
                    SET payment_method = NULL,
                        payment_status = NULL,
                        amount_paid = 0,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE repair_id = ?
                ");
                $stmt->execute([$repairId]);
            }
        } else {
            $stmt = $db->prepare("
                UPDATE repairs 
                SET payment_method = NULL,
                    payment_status = NULL,
                    amount_paid = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE repair_id = ?
            ");
            $stmt->execute([$repairId]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment ' . strtolower($newStatus) . ' successfully!'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
