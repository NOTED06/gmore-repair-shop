<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) || isset($_POST['ajax']);
    header('Content-Type: application/json');
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid form submission.');
        }
        
        $repairId = (int)($_POST['repair_id'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? '';
        
        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $customerId = $stmt->fetchColumn();
        
        if (!$customerId || !$repairId || !$paymentMethod) {
            throw new Exception('Missing required fields.');
        }
        
        
        $allowedMethods = ['GCash', 'Cash'];
        if (!in_array($paymentMethod, $allowedMethods)) {
            throw new Exception('Invalid payment method.');
        }
        
        $stmt = $db->prepare("
            SELECT repair_id, repair_status, estimated_cost, total_cost, payment_method, payment_status, payment_type, amount_paid
            FROM repairs
            WHERE repair_id = ? AND customer_id = ?
        ");
        $stmt->execute([$repairId, $customerId]);
        $repair = $stmt->fetch();
        
        if (!$repair) throw new Exception('Repair not found or access denied.');
        if ($repair['repair_status'] === 'Cancelled') throw new Exception('Payment cannot be submitted for cancelled repairs.');

        $isDpVerified = ($repair['payment_type'] === 'downpayment' && ($repair['payment_status'] ?? '') === 'Verified' && (float)$repair['amount_paid'] < (float)$repair['total_cost']);

        if (!empty($repair['payment_method']) && ($repair['payment_status'] ?? '') === 'Verified' && !$isDpVerified) {
            throw new Exception('Payment has already been verified.');
        }

        if (($repair['payment_status'] ?? '') === 'Pending') {
            throw new Exception('A payment has already been submitted and is pending staff verification.');
        }

        $hasPayment = ((float)($repair['total_cost'] ?? 0) > 0 || (float)($repair['estimated_cost'] ?? 0) > 0);
        if (!$hasPayment) throw new Exception('No payment amount is set for this repair yet.');

        $reqType = $_POST['payment_type'] ?? 'full';
        $fullCost = (float)($repair['total_cost'] ?? 0);
        $dpCost = (float)($repair['estimated_cost'] ?? 0);
        if ($fullCost <= 0 && $dpCost > 0) $fullCost = $dpCost;
        if ($dpCost <= 0 && $fullCost > 0) $dpCost = round($fullCost * 0.5, 2);
        if ($dpCost >= $fullCost && $fullCost > 0) $dpCost = round($fullCost * 0.5, 2);

        if ($isDpVerified || $reqType === 'balance') {
            $paymentType = 'balance';
            $amountToPay = max(0, $fullCost - (float)($repair['amount_paid'] ?? 0));
            if ($amountToPay <= 0) throw new Exception('Repair has already been fully paid.');
            $typeLabel = 'Remaining Balance';
        } elseif ($reqType === 'downpayment') {
            $paymentType = 'downpayment';
            $amountToPay = $dpCost;
            $typeLabel = 'Downpayment';
        } else {
            $paymentType = 'full';
            $amountToPay = $fullCost;
            $typeLabel = 'Full Payment';
        }

        if ($amountToPay <= 0) throw new Exception('No payment amount is set for this repair yet.');
        
        require_once '../includes/PayMongo.php';
        $redirectUrl = null;
        $sourceId = null;
        
        $hasManualProof = isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK;
        
        if ($paymentMethod === 'GCash' && !$hasManualProof) {
            
            try {
                $paymongo = new PayMongo();
                $successUrl = SITE_URL . "/paymongo_callback.php?type=success&repair_id=" . $repairId . "&sid=" . session_id();
                $failedUrl  = SITE_URL . "/paymongo_callback.php?type=failed&repair_id=" . $repairId . "&sid=" . session_id();
                
                $result = $paymongo->createGcashSource(
                    $amountToPay,
                    "Repair Payment #" . str_pad($repairId, 5, '0', STR_PAD_LEFT) . " (" . ucfirst($paymentType) . ")",
                    $successUrl,
                    $failedUrl,
                    ['repair_id' => $repairId, 'customer_id' => $customerId]
                );
                
                if ($result['status'] === 'success') {
                    $sourceId = $result['source_id'];
                    $redirectUrl = $result['checkout_url'];
                }
            } catch (Exception $pme) {
                
                $sourceId = null;
            }
        }
        
        $paymentReference = '';
        $paymentProofPath = null;

        
        
        $isManualGcash = $paymentMethod === 'GCash' && empty($sourceId);
        if ($isManualGcash) {
            if (!$hasManualProof) {
                throw new Exception('A GCash receipt image is required for manual verification.');
            }
        }

        
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['payment_proof'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExtensions)) {
                throw new Exception('Invalid proof image format. Please upload JPG, PNG, or WEBP.');
            }

            if ($file['size'] > 10 * 1024 * 1024) {
                throw new Exception('Proof image is too large. Maximum file size is 10MB.');
            }

            if ($file['size'] < 1024 || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception('The uploaded receipt is not a valid image upload.');
            }

            $imageInfo = @getimagesize($file['tmp_name']);
            $detectedMime = $imageInfo['mime'] ?? '';
            if (!$imageInfo || !isset($allowedMimeTypes[$detectedMime])) {
                throw new Exception('The uploaded file is not a valid JPG, PNG, or WEBP image.');
            }
            if (($imageInfo[0] ?? 0) < 300 || ($imageInfo[1] ?? 0) < 300) {
                throw new Exception('Receipt image is too small to verify. Minimum size is 300 x 300 pixels.');
            }

            if ($paymentMethod === 'GCash' && empty($sourceId)) {
                $validationToken = (string)($_POST['receipt_validation_token'] ?? '');
                $validation = $_SESSION['receipt_validations'][$validationToken] ?? null;
                $currentHash = hash_file('sha256', $file['tmp_name']);
                $validated = is_array($validation)
                    && (int)($validation['user_id'] ?? 0) === (int)$_SESSION['user_id']
                    && (int)($validation['repair_id'] ?? 0) === $repairId
                    && ($validation['payment_type'] ?? '') === $paymentType
                    && abs((float)($validation['amount'] ?? -1) - $amountToPay) < 0.01
                    && hash_equals((string)($validation['file_hash'] ?? ''), $currentHash)
                    && (int)($validation['expires_at'] ?? 0) >= time();

                if (!$validated && $validationToken !== '') {
                    throw new Exception('Receipt validation expired or changed. Please upload and scan the receipt again.');
                }
                if (!$validated) {
                    
                    require_once '../includes/GeminiReceiptValidator.php';
                    $scan = GeminiReceiptValidator::validate($file['tmp_name'], $detectedMime, $amountToPay);
                    if (!$scan['valid']) {
                        throw new Exception('Not a valid GCash receipt: ' . $scan['reason']);
                    }
                }
                if ($validationToken !== '') unset($_SESSION['receipt_validations'][$validationToken]);
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

            $uploadDir = __DIR__ . '/../uploads/payments/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $safeExt = $allowedMimeTypes[$detectedMime];
            $filename = 'proof_' . $repairId . '_' . bin2hex(random_bytes(12)) . '.' . $safeExt;
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $paymentProofPath = 'uploads/payments/' . $filename;
            } else {
                throw new Exception('Failed to save uploaded proof of payment image.');
            }
        }
        
        
        $stmt = $db->prepare("
            UPDATE repairs 
            SET payment_method = ?,
                payment_type = ?,
                amount_paid = ?,
                payment_status = 'Pending',
                paymongo_source_id = ?,
                payment_proof = ?,
                payment_reference = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE repair_id = ?
        ");
        $stmt->execute([
            $paymentMethod, 
            $paymentType, 
            $amountToPay, 
            $sourceId, 
            $paymentProofPath,
            $repairId
        ]);

        
        try {
            $typeLabel = ($paymentType === 'downpayment') ? 'Downpayment' : 'Full payment';
            $payNotes = "{$typeLabel} (â‚±" . number_format($amountToPay, 2) . ") via {$paymentMethod}";
            if ($paymentReference) {
                $payNotes .= " [Ref: {$paymentReference}]";
            }
            $transId = !empty($paymentReference) ? $paymentReference : ('REF-' . str_pad($repairId, 5, '0', STR_PAD_LEFT));

            $pStmt = $db->prepare("
                INSERT INTO payments (repair_id, customer_id, amount, payment_method, payment_status, payment_date, transaction_id, payment_proof, notes)
                VALUES (?, ?, ?, ?, 'Pending', NOW(), ?, ?, ?)
            ");
            $pStmt->execute([$repairId, $customerId, $amountToPay, $paymentMethod, $transId, $paymentProofPath, $payNotes]);
        } catch (Exception $pe) {  }
        
        $typeLabel = ($paymentType === 'downpayment') ? 'Downpayment' : 'Full payment';
        $proofMsg = $paymentProofPath ? " with uploaded GCash proof of payment" : "";
        $refMsg = $paymentReference ? " (Ref: {$paymentReference})" : "";
        logRepairAction($repairId, $_SESSION['user_id'], 'Payment Submitted', null, null, "Customer submitted {$paymentMethod} for {$typeLabel} (Amount: â‚±" . number_format($amountToPay, 2) . "){$refMsg}{$proofMsg}");
        
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, repair_id, title, message, type)
            SELECT u.user_id, ?, 'Payment Submitted for Verification', ?, 'Info'
            FROM users u WHERE u.role = 'Admin'
        ");
        $stmt->execute([$repairId, "Customer submitted {$paymentMethod} for {$typeLabel} (â‚±" . number_format($amountToPay, 2) . "){$refMsg}{$proofMsg} on repair #{$repairId}. Please review proof and verify."]);
        
        $successMsg = $paymentProofPath 
            ? 'Payment and GCash proof of payment submitted successfully! Please wait for staff verification.' 
            : 'Payment submitted successfully! Please wait for staff verification.';

        if ($isAjax) {
            echo json_encode([
                'success' => true, 
                'message' => $successMsg,
                'redirect_url' => $redirectUrl ?: (SITE_URL . "/customer/repairs.php")
            ]);
        } else {
            setFlashMessage($successMsg, 'success');
            if ($redirectUrl) {
                header("Location: " . $redirectUrl);
            } else {
                header("Location: repairs.php");
            }
        }
        exit;
    } catch (Exception $e) {
        if ($isAjax) {
            
            
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } else {
            setFlashMessage($e->getMessage(), 'danger');
            header("Location: repairs.php");
        }
        exit;
    }
}

$repairId = (int)($_GET['repair_id'] ?? 0);

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customerId = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT r.*, t.full_name AS technician_name, c.full_name AS customer_name
    FROM repairs r
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    LEFT JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.repair_id = ? AND r.customer_id = ?
");
$stmt->execute([$repairId, $customerId]);
$repair = $stmt->fetch();

$hasPayment = $repair && ((float)($repair['total_cost'] ?? 0) > 0 || (float)($repair['estimated_cost'] ?? 0) > 0);
if (!$repair || !$hasPayment || $repair['repair_status'] === 'Cancelled') {
    die("Invalid request or repair is not ready for payment.");
}

$fullCost = (float)($repair['total_cost'] ?? 0);
$dpCost = (float)($repair['estimated_cost'] ?? 0);
if ($fullCost <= 0 && $dpCost > 0) $fullCost = $dpCost;
if ($dpCost <= 0 && $fullCost > 0) $dpCost = round($fullCost * 0.5, 2);

$defaultType = ($repair['repair_status'] === 'Completed') ? 'full' : 'downpayment';
$initialAmount = ($defaultType === 'downpayment') ? $dpCost : $fullCost;

$pageTitle = 'Submit Payment';
$activePage = 'repairs';
$notifCount = getUnreadNotificationCount($_SESSION['user_id']);
$userName = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><i data-lucide="credit-card"></i> Pay for Repair</h1>
            <p class="page-sub">Repair #<?= str_pad($repair['repair_id'], 5, '0', STR_PAD_LEFT) ?> &mdash; <?= sanitize($repair['appliance_type']) ?></p>
        </div>
        <a href="repairs.php" class="btn-ghost"><i data-lucide="arrow-left"></i> Back</a>
    </div>

    <div style="max-width: 620px; margin: 0 auto;">
        
        
        <div class="card" style="margin-bottom: 24px; text-align: center; background: linear-gradient(135deg, #1E3A8A, #3B82F6); color: #fff; padding: 28px 20px;">
            <p id="summaryLabel" style="font-size: 14px; opacity: 0.85; margin-bottom: 8px;">
                <?= $defaultType === 'downpayment' ? 'Downpayment Amount to Pay' : 'Full Amount to Pay' ?>
            </p>
            <h2 id="summaryAmount" style="font-size: 44px; font-weight: 800; margin: 0 0 8px; font-family: 'Syne', sans-serif;">
                <?= formatCurrency($initialAmount) ?>
            </h2>
            <p style="font-size: 14px; opacity: 0.9;">Device: <?= sanitize($repair['appliance_type']) ?> <?= $repair['brand'] ? '('.sanitize($repair['brand']).')' : '' ?></p>
        </div>

        
        <div class="card">
            <form id="paymentForm">
                <?= csrfField() ?>
                <input type="hidden" name="repair_id" value="<?= $repair['repair_id'] ?>">
                
                
                <div style="margin-bottom: 24px;">
                    <label style="font-size: 14px; font-weight: 700; color: #0F172A; display: block; margin-bottom: 12px;">
                        1. Choose Payment Option
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        
                        <label class="pay-option-card" id="cardDp" style="display: block; cursor: pointer; border: 2px solid <?= $defaultType === 'downpayment' ? 'var(--primary, #1E3A8A)' : '#E2E8F0' ?>; background: <?= $defaultType === 'downpayment' ? '#EFF6FF' : '#fff' ?>; border-radius: 12px; padding: 16px; transition: all 0.2s;">
                            <input type="radio" name="payment_type" value="downpayment" <?= $defaultType === 'downpayment' ? 'checked' : '' ?> style="display:none;" onchange="selectPaymentType('downpayment', <?= $dpCost ?>)">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 13.5px; font-weight: 700; color: #0F172A;">Downpayment</span>
                                <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; background: #DCFCE7; color: #166534; padding: 2px 8px; border-radius: 9999px;">Deposit</span>
                            </div>
                            <div style="font-size: 22px; font-weight: 800; color: #10B981; font-family: 'Syne', sans-serif;">
                                <?= formatCurrency($dpCost) ?>
                            </div>
                            <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Initial deposit to proceed with repair</div>
                        </label>

                        
                        <label class="pay-option-card" id="cardFull" style="display: block; cursor: pointer; border: 2px solid <?= $defaultType === 'full' ? 'var(--primary, #1E3A8A)' : '#E2E8F0' ?>; background: <?= $defaultType === 'full' ? '#EFF6FF' : '#fff' ?>; border-radius: 12px; padding: 16px; transition: all 0.2s;">
                            <input type="radio" name="payment_type" value="full" <?= $defaultType === 'full' ? 'checked' : '' ?> style="display:none;" onchange="selectPaymentType('full', <?= $fullCost ?>)">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 13.5px; font-weight: 700; color: #0F172A;">Full Payment</span>
                                <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; background: #DBEAFE; color: #1E40AF; padding: 2px 8px; border-radius: 9999px;">Complete</span>
                            </div>
                            <div style="font-size: 22px; font-weight: 800; color: #2563EB; font-family: 'Syne', sans-serif;">
                                <?= formatCurrency($fullCost) ?>
                            </div>
                            <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">Pay full repair amount in advance</div>
                        </label>
                    </div>
                </div>

                
                <h3 style="font-size: 14px; font-weight: 700; color: #0F172A; margin-bottom: 12px;">2. Select Payment Method</h3>
                
                <div class="pay-methods">
                    
                    <label class="pay-method-label">
                        <input type="radio" name="payment_method" value="GCash" checked onchange="updateActiveQr(this.value)">
                        <div class="pay-method-card">
                            <div class="pay-icon" style="background: #E0E7FF; color: #3B82F6;">
                                <i data-lucide="smartphone"></i>
                            </div>
                            <div class="pay-info">
                                <span class="pay-name">GCash</span>
                                <span class="pay-desc">Pay via GCash QR Code</span>
                            </div>
                        </div>
                    </label>

                    
                    <label class="pay-method-label">
                        <input type="radio" name="payment_method" value="Cash" onchange="updateActiveQr(this.value)">
                        <div class="pay-method-card">
                            <div class="pay-icon" style="background: #D1FAE5; color: #059669;">
                                <i data-lucide="banknote"></i>
                            </div>
                            <div class="pay-info">
                                <span class="pay-name">Cash</span>
                                <span class="pay-desc">Pay at the store counter upon pickup or dropoff</span>
                            </div>
                        </div>
                    </label>
                </div>

                
                <div id="qrContainer" style="margin-top: 24px; padding: 24px; border: 1px solid var(--border); border-radius: 12px; text-align: center; background: #FAFAF9;">
                    <p id="qrTitle" style="font-weight: 700; color: #0F172A; margin-bottom: 8px;">Scan to Pay (GCash)</p>
                    <p id="qrDesc" style="font-size: 13px; color: #64748B; margin-bottom: 16px;">Open your GCash app and scan this QR code to pay.</p>
                    
                    <div style="width: 220px; height: 220px; margin: 0 auto; background: #fff; padding: 12px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); display: flex; align-items: center; justify-content: center;">
                        <div id="qrCodeContainer" style="width: 196px; height: 196px; display: flex; align-items: center; justify-content: center;"></div>
                    </div>
                    
                    <div id="cashInstructions" style="display:none; margin-top: 16px; font-size: 13px; color: #1E3A8A; background: #EFF6FF; padding: 12px; border-radius: 8px;">
                        <i data-lucide="info" style="width:16px; height:16px; vertical-align:middle; margin-right:4px;"></i>
                        Present this reference QR code to the cashier for quick processing.
                    </div>
                </div>

                <div id="gcashEvidence" style="margin-top:16px;padding:16px;border:1px solid #BFDBFE;border-radius:10px;background:#EFF6FF;">
                    <div style="font-size:13px;font-weight:700;color:#1E3A8A;margin-bottom:10px;">Receipt image verification</div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px;" for="payment_proof">Receipt image</label>
                    <input type="file" id="payment_proof" name="payment_proof" accept="image/jpeg,image/png,image/webp" style="width:100%;font-size:12px;">
                    <p style="font-size:11px;color:#64748B;margin:8px 0 0;">The system checks the image format, size, dimensions, and duplicate use. Staff must still confirm that the payment was received.</p>
                </div>

                <div id="payAlert" class="alert-danger" style="display:none; margin-top: 16px; border-radius: 8px; padding: 12px;"></div>

                <button type="submit" class="btn-primary" id="btnSubmit" style="width: 100%; margin-top: 24px; padding: 14px; font-size: 16px;">
                    Confirm & Submit Payment
                </button>
            </form>
        </div>
    </div>

</main>
</div>

<style>
.pay-methods { display: flex; flex-direction: column; gap: 12px; }
.pay-method-label { cursor: pointer; }
.pay-method-label input { display: none; }
.pay-method-card {
    display: flex; align-items: center; gap: 16px; padding: 16px;
    border: 2px solid var(--border); border-radius: 12px;
    transition: all 0.2s; background: #fff;
}
.pay-method-label input:checked + .pay-method-card {
    border-color: var(--primary); background: #EFF6FF;
}
.pay-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.pay-icon svg { width: 22px; height: 22px; }
.pay-info { display: flex; flex-direction: column; gap: 4px; }
.pay-name { font-weight: 700; font-size: 15px; color: #0F172A; }
.pay-desc { font-size: 13px; color: #64748B; }
</style>

<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<script>
let currentPayAmount = <?= $initialAmount ?>;
let currentPayType = '<?= $defaultType ?>';

function selectPaymentType(type, amount) {
    currentPayType = type;
    currentPayAmount = amount;

    const cardDp = document.getElementById('cardDp');
    const cardFull = document.getElementById('cardFull');
    if (type === 'downpayment') {
        cardDp.style.borderColor = 'var(--primary, #1E3A8A)';
        cardDp.style.background = '#EFF6FF';
        cardFull.style.borderColor = '#E2E8F0';
        cardFull.style.background = '#fff';
        document.getElementById('summaryLabel').textContent = 'Downpayment Amount to Pay';
    } else {
        cardFull.style.borderColor = 'var(--primary, #1E3A8A)';
        cardFull.style.background = '#EFF6FF';
        cardDp.style.borderColor = '#E2E8F0';
        cardDp.style.background = '#fff';
        document.getElementById('summaryLabel').textContent = 'Full Amount to Pay';
    }

    document.getElementById('summaryAmount').textContent = 'â‚±' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const activeMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'GCash';
    updateActiveQr(activeMethod);
}

function drawQr(text) {
    const qrCodeContainer = document.getElementById('qrCodeContainer');
    if (!qrCodeContainer) return;
    qrCodeContainer.innerHTML = '';
    if (typeof QRCode !== 'undefined') {
        new QRCode(qrCodeContainer, {
            text: text,
            width: 196,
            height: 196,
            colorDark: "#0F172A",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.M
        });
    }
}

function updateActiveQr(method) {
    const qrTitle = document.getElementById('qrTitle');
    const qrDesc = document.getElementById('qrDesc');
    const cashInfo = document.getElementById('cashInstructions');
    const gcashEvidence = document.getElementById('gcashEvidence');
    const repairId = '<?= str_pad($repair['repair_id'], 5, '0', STR_PAD_LEFT) ?>';
    const customer = '<?= sanitize($repair['customer_name'] ?? 'Customer') ?>';

    if (method === 'GCash') {
        qrTitle.textContent = 'Scan to Pay (GCash)';
        qrDesc.textContent = 'Open your GCash app and scan this QR code to pay.';
        cashInfo.style.display = 'none';
        gcashEvidence.style.display = 'block';
        drawQr('GCash Payment for Repair #' + repairId + ' - Amount: P' + currentPayAmount + ' (' + currentPayType + ')');
    } else {
        qrTitle.textContent = 'Cashier Reference Code';
        qrDesc.textContent = 'Show this QR to the shop staff when paying at the counter.';
        cashInfo.style.display = 'block';
        gcashEvidence.style.display = 'none';
        drawQr('RepairID: ' + repairId + ' | Amount: ' + currentPayAmount + ' | Type: ' + currentPayType + ' | Name: ' + customer);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('paymentForm');
    
    
    updateActiveQr('GCash');

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const alertBox = document.getElementById('payAlert');
        const btn = document.getElementById('btnSubmit');
        
        alertBox.style.display = 'none';
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin"></i> Processing...';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        fetch(window.location.href, {
            method: 'POST',
            body: new FormData(form)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else {
                    window.location.href = 'repairs.php';
                }
            } else {
                alertBox.textContent = data.message;
                alertBox.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = 'Confirm & Submit Payment';
            }
        })
        .catch(() => {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = 'Confirm & Submit Payment';
        });
    });
});
</script>
</body>
</html>
