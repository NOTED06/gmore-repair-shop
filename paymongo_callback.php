<?php

if (isset($_GET['sid']) && preg_match('/^[a-zA-Z0-9,-]{22,128}$/', $_GET['sid'])) {
    session_id($_GET['sid']);
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/PayMongo.php';

$type = $_GET['type'] ?? '';
$repairId = (int)($_GET['repair_id'] ?? 0);

if (!$repairId) {
    header('Location: customer/repairs.php');
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT r.*, c.user_id as customer_user_id
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.repair_id = ?
");
$stmt->execute([$repairId]);
$repair = $stmt->fetch();

if (!$repair) {
    header('Location: customer/repairs.php');
    exit;
}

$sourceId = $repair['paymongo_source_id'] ?? '';
$paymongo = new PayMongo();

switch ($type) {
    case 'success':
        handleSuccess($db, $paymongo, $sourceId, $repairId, $repair);
        break;
        
    case 'failed':
        handleFailed($db, $sourceId, $repairId);
        break;
        
    default:
        setFlashMessage('Invalid payment callback.', 'danger');
        header('Location: customer/repairs.php');
        exit;
}

function handleSuccess($db, $paymongo, $sourceId, $repairId, $repair) {
    if (empty($sourceId)) {
        setFlashMessage('Payment verification failed: missing source reference.', 'danger');
        header('Location: customer/repairs.php');
        exit;
    }
    
    
    $source = $paymongo->getSource($sourceId);
    
    if ($source['status'] !== 'success') {
        error_log("PayMongo callback error: Failed to fetch source {$sourceId}");
        setFlashMessage('Failed to verify payment with gateway.', 'danger');
        header('Location: customer/repairs.php');
        exit;
    }
    
    $sourceStatus = $source['source_status'];
    
    if ($sourceStatus === 'chargeable') {
        
        $chargeAmount = (float)($source['amount'] ?? 0) / 100;
        if ($chargeAmount <= 0) {
            $chargeAmount = (float)($repair['amount_paid'] ?: (($repair['payment_type'] ?? '') === 'downpayment' ? $repair['estimated_cost'] : $repair['total_cost']));
        }
        $isDp = (($repair['payment_type'] ?? '') === 'downpayment') || ($chargeAmount < (float)$repair['total_cost']);
        $typeLabel = $isDp ? 'Downpayment' : 'Full Payment';
        
        $paymentResult = $paymongo->createPayment(
            $sourceId,
            $chargeAmount,
            "Repair Service Payment #" . str_pad($repairId, 5, '0', STR_PAD_LEFT) . " ({$typeLabel})"
        );
        
        if ($paymentResult['status'] === 'success' && $paymentResult['payment_status'] === 'paid') {
            $paymentId = $paymentResult['payment_id'];
            
            
            $stmt = $db->prepare("
                UPDATE repairs 
                SET payment_status = 'Verified',
                    amount_paid = ?,
                    payment_type = ?,
                    paymongo_payment_id = ?,
                    payment_verified_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE repair_id = ?
            ");
            $stmt->execute([$chargeAmount, ($isDp ? 'downpayment' : 'full'), $paymentId, $repairId]);
            
            
            $pStmt = $db->prepare("
                INSERT INTO payments (repair_id, customer_id, amount, payment_method, payment_status, payment_date, transaction_id, notes)
                VALUES (?, ?, ?, 'GCash', 'Paid', CURRENT_TIMESTAMP, ?, ?)
            ");
            $pNotes = "{$typeLabel} (₱" . number_format($chargeAmount, 2) . ") online payment via PayMongo";
            $pStmt->execute([$repairId, $repair['customer_id'], $chargeAmount, $paymentId, $pNotes]);
            
            
            logRepairAction($repairId, $repair['customer_user_id'], 'Payment Received', 'Pending', 'Verified', "GCash {$typeLabel} of ₱" . number_format($chargeAmount, 2) . " verified via PayMongo (ID: {$paymentId})");
            
            
            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, repair_id, title, message, type)
                VALUES (?, ?, 'Payment Received', ?, 'Info')
            ");
            $stmt->execute([
                $repair['customer_user_id'],
                $repairId,
                "Your {$typeLabel} of ₱" . number_format($chargeAmount, 2) . " for repair #" . str_pad($repairId, 5, '0', STR_PAD_LEFT) . " has been successfully received!"
            ]);
            
            
            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, repair_id, title, message, type)
                SELECT u.user_id, ?, 'Payment Received (Online)', ?, 'Info'
                FROM users u WHERE u.role = 'Admin'
            ");
            $stmt->execute([
                $repairId,
                "Online payment ({$typeLabel}) of ₱" . number_format($chargeAmount, 2) . " received via PayMongo for repair #" . str_pad($repairId, 5, '0', STR_PAD_LEFT)
            ]);
            
            setFlashMessage('Payment completed successfully! Thank you.', 'success');
            header('Location: customer/repairs.php');
            exit;
        } else {
            
            $stmt = $db->prepare("
                UPDATE repairs 
                SET payment_status = 'Rejected',
                    updated_at = CURRENT_TIMESTAMP
                WHERE repair_id = ?
            ");
            $stmt->execute([$repairId]);
            
            error_log("PayMongo payment capture failed: " . json_encode($paymentResult));
            setFlashMessage('Payment capture failed. Please try again.', 'danger');
            header('Location: customer/repairs.php');
            exit;
        }
    } elseif ($sourceStatus === 'paid') {
        
        $stmt = $db->prepare("
            UPDATE repairs 
            SET payment_status = 'Verified',
                payment_verified_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE repair_id = ?
        ");
        $stmt->execute([$repairId]);
        
        setFlashMessage('Payment has already been completed.', 'info');
        header('Location: customer/repairs.php');
        exit;
    } else {
        
        error_log("PayMongo source not chargeable: status = {$sourceStatus}");
        setFlashMessage('Payment status: ' . ucfirst($sourceStatus) . '.', 'warning');
        header('Location: customer/repairs.php');
        exit;
    }
}

function handleFailed($db, $sourceId, $repairId) {
    $stmt = $db->prepare("
        UPDATE repairs 
        SET payment_status = 'Rejected',
            updated_at = CURRENT_TIMESTAMP
        WHERE repair_id = ?
    ");
    $stmt->execute([$repairId]);
    
    error_log("PayMongo payment failed callback: repair {$repairId}, source {$sourceId}");
    setFlashMessage('Payment checkout was cancelled or failed.', 'danger');
    header('Location: customer/repairs.php');
    exit;
}
