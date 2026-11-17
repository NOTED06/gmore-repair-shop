<?php
require_once '../config/config.php';
requireRole('Customer');
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid validation request.');
    }
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Select a receipt image first.');
    }

    $repairId = (int)($_POST['repair_id'] ?? 0);
    $paymentType = $_POST['payment_type'] ?? 'full';
    $db = getDB();
    $stmt = $db->prepare("SELECT r.total_cost, r.estimated_cost, r.amount_paid, r.payment_type FROM repairs r JOIN customers c ON r.customer_id = c.customer_id WHERE r.repair_id = ? AND c.user_id = ?");
    $stmt->execute([$repairId, $_SESSION['user_id']]);
    $repair = $stmt->fetch();
    if (!$repair) throw new Exception('Repair not found.');

    $full = (float)($repair['total_cost'] ?: $repair['estimated_cost']);
    $downpayment = (float)($repair['estimated_cost'] ?: round($full * 0.5, 2));
    if ($paymentType === 'balance') {
        $expected = max(0, $full - (float)$repair['amount_paid']);
    } else {
        $expected = $paymentType === 'downpayment' ? $downpayment : $full;
    }

    $file = $_FILES['payment_proof'];
    if ($file['size'] < 1024 || $file['size'] > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Not a valid GCash receipt image.');
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 300 || $info[1] < 300) {
        throw new Exception('Not a valid GCash receipt image.');
    }

    
    $proofHash = hash_file('sha256', $file['tmp_name']);
    $checkRepairs = $db->prepare("SELECT payment_proof FROM repairs WHERE repair_id <> ? AND payment_status IN ('Verified', 'Paid') AND payment_proof IS NOT NULL AND payment_proof <> ''");
    $checkRepairs->execute([$repairId]);
    $existingProofs = $checkRepairs->fetchAll(PDO::FETCH_COLUMN);

    $checkPayments = $db->prepare("SELECT payment_proof FROM payments WHERE repair_id <> ? AND payment_status IN ('Verified', 'Paid') AND payment_proof IS NOT NULL AND payment_proof <> ''");
    $checkPayments->execute([$repairId]);
    $existingProofs = array_merge($existingProofs, $checkPayments->fetchAll(PDO::FETCH_COLUMN));

    foreach ($existingProofs as $existingProof) {
        if (empty($existingProof)) continue;
        $existingPath = realpath(__DIR__ . '/../' . $existingProof);
        if ($existingPath && is_file($existingPath) && hash_file('sha256', $existingPath) === $proofHash) {
            throw new Exception('This receipt image has already been verified for another payment.');
        }
    }

    require_once '../includes/GeminiReceiptValidator.php';
    $scan = GeminiReceiptValidator::validate($file['tmp_name'], $mime, $expected);
    if (!$scan['valid']) {
        throw new Exception('Not a valid GCash receipt: ' . $scan['reason']);
    }

    $validationToken = bin2hex(random_bytes(24));
    $_SESSION['receipt_validations'][$validationToken] = [
        'user_id' => (int)$_SESSION['user_id'],
        'repair_id' => $repairId,
        'payment_type' => $paymentType,
        'amount' => $expected,
        'file_hash' => hash_file('sha256', $file['tmp_name']),
        'expires_at' => time() + 600,
    ];

    echo json_encode([
        'success' => true,
        'message' => 'GCash receipt recognized. Amount matches; ready to submit.',
        'validation_token' => $validationToken,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
