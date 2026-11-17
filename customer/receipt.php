<?php

require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = getDB();
$repairId = (int)($_GET['id'] ?? 0);
$autoDownload = isset($_GET['download']) && $_GET['download'] == '1';
$autoPrint    = isset($_GET['print']) && $_GET['print'] == '1';
$autoImage    = (isset($_GET['image']) && $_GET['image'] == '1') || (isset($_GET['format']) && $_GET['format'] === 'image');

if ($repairId <= 0) {
    redirect('repairs.php', 'Invalid repair ID.', 'danger');
}

$userRole = getUserRole();
$userId   = $_SESSION['user_id'];

$customerId = null;
if ($userRole === 'Customer') {
    $stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $stmt->execute([$userId]);
    $customerId = $stmt->fetchColumn();
}

$stmt = $db->prepare("
    SELECT r.*,
           c.customer_id,
           c.full_name AS customer_name,
           c.contact_no AS customer_phone,
           c.address AS customer_address,
           u.email AS customer_email,
           t.full_name AS technician_name,
           t.contact_no AS technician_phone,
           t.specialization AS technician_specialization,
           w.warranty_status,
           w.completion_date,
           w.warranty_end_date,
           w.grace_period_end_date,
           DATEDIFF(w.warranty_end_date, CURDATE()) AS warranty_days_left
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users u ON c.user_id = u.user_id
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    LEFT JOIN warranty_tracking w ON r.repair_id = w.repair_id
    WHERE r.repair_id = ?
");
$stmt->execute([$repairId]);
$repair = $stmt->fetch();

if (!$repair) {
    redirect('repairs.php', 'Repair request not found.', 'danger');
}

if ($userRole === 'Customer' && (int)$repair['customer_id'] !== (int)$customerId) {
    redirect('repairs.php', 'Access denied. You can only view your own receipts.', 'danger');
}

$pStmt = $db->prepare("
    SELECT * FROM payments 
    WHERE repair_id = ? 
    ORDER BY payment_date ASC, payment_id ASC
");
$pStmt->execute([$repairId]);
$payments = $pStmt->fetchAll();

$totalQuoted = (float)($repair['total_cost'] ?: $repair['estimated_cost'] ?: 0);
$dpQuoted    = (float)($repair['estimated_cost'] ?: 0);

$sumPaid = 0.0;
foreach ($payments as $p) {
    if (in_array($p['payment_status'], ['Paid', 'Verified'])) {
        $sumPaid += (float)$p['amount'];
    }
}
$amountPaid = $sumPaid > 0 ? $sumPaid : (float)($repair['amount_paid'] ?? 0);
if ($amountPaid <= 0 && $repair['payment_status'] === 'Verified') {
    $amountPaid = $totalQuoted;
}
$remainingBalance = max(0, $totalQuoted - $amountPaid);

$isFullPaid   = ($repair['payment_status'] === 'Verified' && $remainingBalance <= 0 && $totalQuoted > 0);
$isDpVerified = ($repair['payment_status'] === 'Verified' && $remainingBalance > 0 && ($repair['payment_type'] === 'downpayment' || $amountPaid > 0));
$isPending    = ($repair['payment_status'] === 'Pending');

$shopName    = getSetting('site_name', SITE_NAME);
$receiptNo   = 'REC-' . date('Ym', strtotime($repair['created_at'] ?? 'now')) . '-' . str_pad($repairId, 5, '0', STR_PAD_LEFT);
$receiptDate = !empty($repair['payment_verified_at']) ? date('F d, Y • h:i A', strtotime($repair['payment_verified_at'])) : date('F d, Y • h:i A');

$qrStatus = $isFullPaid ? 'PAID IN FULL' : ($isDpVerified ? 'DOWNPAYMENT VERIFIED (50%)' : ($isPending ? 'PAYMENT PENDING VERIFICATION' : 'UNPAID'));
$offlineQrLines = [
    "=== " . strtoupper($shopName) . " ===",
    "OFFICIAL SERVICE RECEIPT",
    "Receipt: " . $receiptNo,
    "Ticket: #" . str_pad($repairId, 5, '0', STR_PAD_LEFT),
    "Customer: " . ($repair['customer_name'] ?? 'N/A'),
    "Phone: " . ($repair['customer_phone'] ?: 'N/A'),
    "Device: " . trim(($repair['brand'] ?? '') . ' ' . ($repair['appliance_type'] ?? '')),
    "Repair Status: " . ($repair['repair_status'] ?? 'N/A'),
    "Total Cost: PHP " . number_format($totalQuoted, 2),
    "Paid Amount: PHP " . number_format($amountPaid, 2),
    "Balance Due: PHP " . number_format($remainingBalance, 2),
    "Payment: " . $qrStatus,
    "Date: " . date('Y-m-d H:i', strtotime($repair['payment_verified_at'] ?? $repair['updated_at'] ?? 'now')),
];
if (!empty($repair['warranty_status']) && in_array($repair['warranty_status'], ['Under Warranty', 'Grace Period'])) {
    $offlineQrLines[] = "Warranty: " . $repair['warranty_status'] . " (Until " . date('Y-m-d', strtotime($repair['warranty_end_date'])) . ")";
}
$offlineQrLines[] = "=== VERIFIED AUTHENTIC ===";
$offlineQrText = implode("\n", $offlineQrLines);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Receipt #<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($shopName) ?></title>
    
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fonts/fonts.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    
    
    <script src="<?= SITE_URL ?>/assets/vendor/qrcode/qrcode.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/html2canvas/html2canvas.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/vendor/html2pdf/html2pdf.bundle.min.js"></script>

    <style>
        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #2563eb;
            --emerald: #059669;
            --amber: #d97706;
            --rose: #dc2626;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            padding: 30px 15px;
            min-height: 100vh;
        }

        
        .receipt-action-bar {
            max-width: 820px;
            margin: 0 auto 24px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .bar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .btn-back {
            background: #ffffff;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-back:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .btn-print {
            background: #ffffff;
            color: #2563eb;
            border-color: #bfdbfe;
        }
        .btn-print:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .btn-image {
            background: #0d9488;
            color: #ffffff;
            border-color: #0d9488;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
        }
        .btn-image:hover {
            background: #0f766e;
            box-shadow: 0 6px 16px rgba(13, 148, 136, 0.35);
            color: #ffffff;
        }

        .btn-download {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }
        .btn-download:hover {
            background: #1d4ed8;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
        }

        
        .receipt-wrapper {
            max-width: 820px;
            margin: 0 auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.06);
            padding: 42px 48px;
            position: relative;
            overflow: hidden;
        }

        
        .receipt-accent-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #2563eb 0%, #06b6d4 50%, #10b981 100%);
        }

        
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px dashed var(--border-color);
            padding-bottom: 24px;
            margin-bottom: 24px;
            gap: 20px;
        }

        .shop-info h1 {
            font-family: 'Syne', sans-serif;
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .shop-info p {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .receipt-meta {
            text-align: right;
        }

        .receipt-badge-title {
            font-family: 'Syne', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .receipt-code {
            font-family: monospace;
            font-size: 14px;
            font-weight: 700;
            color: var(--accent);
            margin-top: 3px;
        }

        .receipt-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        
        .status-stamp-box {
            margin-top: 10px;
            display: inline-block;
        }

        .status-stamp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stamp-paid {
            background: #dcfce7;
            color: #15803d;
            border: 1.5px solid #86efac;
        }

        .stamp-dp {
            background: #eff6ff;
            color: #1e40af;
            border: 1.5px solid #93c5fd;
        }

        .stamp-pending {
            background: #fef3c7;
            color: #b45309;
            border: 1.5px solid #fde68a;
        }

        .stamp-unpaid {
            background: #fee2e2;
            color: #b91c1c;
            border: 1.5px solid #fca5a5;
        }

        
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }

        .details-card {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px 20px;
        }

        .details-card-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .details-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .details-row:last-child {
            margin-bottom: 0;
        }

        .details-label {
            color: var(--text-muted);
            min-width: 100px;
        }

        .details-val {
            font-weight: 600;
            color: var(--text-main);
            text-align: right;
            word-break: break-word;
        }

        
        .section-heading {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .receipt-table th {
            background: #f1f5f9;
            color: #475569;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 14px;
            text-align: left;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .receipt-table td {
            padding: 12px 14px;
            font-size: 13px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .receipt-table .text-right {
            text-align: right;
        }

        .receipt-table .text-center {
            text-align: center;
        }

        
        .financial-summary-wrap {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            gap: 24px;
            flex-wrap: wrap;
        }

        .financial-notes-box {
            flex: 1;
            min-width: 260px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .financial-notes-box strong {
            color: var(--text-main);
        }

        .financial-summary-table {
            width: 320px;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
        }

        .financial-summary-table td {
            padding: 9px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
        }

        .financial-summary-table tr:last-child td {
            border-bottom: none;
        }

        .fin-total-row {
            background: #f8fafc;
            font-weight: 700;
            font-size: 14.5px !important;
            color: var(--primary);
        }

        .fin-balance-due {
            background: #fef2f2;
            color: var(--rose);
            font-weight: 800;
            font-size: 14.5px !important;
        }

        .fin-balance-settled {
            background: #f0fdf4;
            color: var(--emerald);
            font-weight: 700;
        }

        
        .warranty-callout {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 10px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .warranty-icon {
            font-size: 24px;
            color: var(--emerald);
        }

        .warranty-text h4 {
            font-size: 13.5px;
            font-weight: 700;
            color: #065f46;
            margin-bottom: 2px;
        }

        .warranty-text p {
            font-size: 12px;
            color: #047857;
        }

        
        .offline-qr-container {
            margin-top: 24px;
            padding: 16px 20px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .qr-canvas-wrap {
            flex-shrink: 0;
            background: #ffffff;
            padding: 8px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        #receiptQrCode {
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #receiptQrCode img, #receiptQrCode canvas {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .qr-info-wrap {
            flex: 1;
        }

        .qr-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .qr-info-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .qr-info-desc {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .qr-meta-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 11.5px;
            color: var(--text-main);
        }

        .qr-meta-row strong {
            color: var(--primary);
        }

        
        .receipt-terms {
            margin-top: 24px;
            text-align: center;
            font-size: 11.5px;
            color: #94a3b8;
            border-top: 1px dashed var(--border-color);
            padding-top: 16px;
        }

        
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .receipt-action-bar {
                display: none !important;
            }

            .receipt-wrapper {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
                border-radius: 0 !important;
            }

            .receipt-accent-bar {
                display: none !important;
            }

            .offline-qr-container {
                border: 1px solid #cbd5e1 !important;
                background: #f8fafc !important;
                break-inside: avoid;
            }

            @page {
                size: A4 portrait;
                margin: 14mm 12mm;
            }
        }

        @media (max-width: 640px) {
            .receipt-wrapper {
                padding: 24px 20px;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .receipt-header {
                flex-direction: column;
            }
            .receipt-meta {
                text-align: left;
            }
            .financial-summary-wrap {
                flex-direction: column;
            }
            .financial-summary-table {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    
    <div class="receipt-action-bar">
        <div class="bar-left">
            <a href="repairs.php" class="btn-action btn-back">
                <i class="fas fa-arrow-left"></i> Back to My Repairs
            </a>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn-action btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button type="button" class="btn-action btn-image" id="saveImageBtn" onclick="saveReceiptImage()">
                <i class="fas fa-image"></i> Save as Image
            </button>
            <button type="button" class="btn-action btn-download" id="downloadPdfBtn" onclick="downloadReceiptPDF()">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
        </div>
    </div>

    
    <div class="receipt-wrapper" id="receiptSheet">
        <div class="receipt-accent-bar"></div>

        
        <div class="receipt-header">
            <div class="shop-info">
                <h1><i class="fas fa-tools" style="color:var(--accent); font-size:20px;"></i> <?= htmlspecialchars($shopName) ?></h1>
                <p><strong>Official Service & Repair Invoice Receipt</strong></p>
                <p>Main Store Branch • Store Service Counter</p>
                <p>Contact: (02) 8123-4567 | Mobile: 09XX-XXX-XXXX</p>
                <p>Email: support@repairshop.ph</p>
            </div>
            <div class="receipt-meta">
                <div class="receipt-badge-title">OFFICIAL RECEIPT</div>
                <div class="receipt-code"><?= $receiptNo ?></div>
                <div class="receipt-date"><i class="far fa-calendar-alt"></i> <?= $receiptDate ?></div>
                <div class="receipt-date" style="font-weight:700; color:var(--primary); margin-top:3px;">
                    Ticket #<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?>
                </div>

                
                <div class="status-stamp-box">
                    <?php if ($isFullPaid): ?>
                        <span class="status-stamp stamp-paid"><i class="fas fa-check-circle"></i> Paid in Full</span>
                    <?php elseif ($isDpVerified): ?>
                        <span class="status-stamp stamp-dp"><i class="fas fa-coins"></i> Downpayment Paid (50%)</span>
                    <?php elseif ($isPending): ?>
                        <span class="status-stamp stamp-pending"><i class="fas fa-clock"></i> Payment Pending</span>
                    <?php else: ?>
                        <span class="status-stamp stamp-unpaid"><i class="fas fa-file-invoice-dollar"></i> Unpaid Invoice</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        
        <div class="details-grid">
            
            <div class="details-card">
                <div class="details-card-title"><i class="fas fa-user"></i> Customer Information</div>
                <div class="details-row">
                    <span class="details-label">Customer Name:</span>
                    <span class="details-val"><?= sanitize($repair['customer_name']) ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Contact No:</span>
                    <span class="details-val"><?= sanitize($repair['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Email:</span>
                    <span class="details-val"><?= sanitize($repair['customer_email'] ?: '—') ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Address:</span>
                    <span class="details-val"><?= sanitize($repair['customer_address'] ?: 'Store Walk-In') ?></span>
                </div>
            </div>

            
            <div class="details-card">
                <div class="details-card-title"><i class="fas fa-microchip"></i> Appliance & Service Info</div>
                <div class="details-row">
                    <span class="details-label">Appliance:</span>
                    <span class="details-val"><?= sanitize($repair['appliance_type']) ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Brand & Model:</span>
                    <span class="details-val"><?= sanitize(trim(($repair['brand'] ?? '') . ' ' . ($repair['model'] ?? '')) ?: 'N/A') ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Serial Number:</span>
                    <span class="details-val"><?= sanitize($repair['serial_number'] ?? 'N/A') ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Service Tech:</span>
                    <span class="details-val"><?= sanitize($repair['technician_name'] ?: 'Assigned Technician') ?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Repair Status:</span>
                    <span class="details-val" style="color:var(--accent); font-weight:700;"><?= sanitize($repair['repair_status']) ?></span>
                </div>
            </div>
        </div>

        
        <div style="background:#f8fafc; border:1px solid var(--border-color); border-radius:10px; padding:14px 18px; margin-bottom:24px; font-size:12.5px;">
            <div style="font-weight:700; color:var(--primary); margin-bottom:4px;">Reported Issue:</div>
            <div style="color:#475569; margin-bottom:8px;"><?= sanitize($repair['problem_description']) ?></div>
            <?php 
            $techNotes = !empty($repair['diagnosis']) ? $repair['diagnosis'] : (!empty($repair['repair_notes']) ? $repair['repair_notes'] : '');
            if (!empty($techNotes)): 
            ?>
            <div style="font-weight:700; color:var(--primary); margin-bottom:4px;">Technician Diagnosis / Findings:</div>
            <div style="color:#475569;"><?= nl2br(sanitize($techNotes)) ?></div>
            <?php endif; ?>
        </div>

        
        <div class="section-heading"><i class="fas fa-clipboard-list"></i> Service Breakdown</div>
        <table class="receipt-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Service Description</th>
                    <th class="text-center" style="width: 25%;">Scope</th>
                    <th class="text-right" style="width: 25%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Diagnostic, Labor & Repair Service</strong>
                        <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">
                            Repair service for <?= sanitize($repair['brand'] . ' ' . $repair['appliance_type']) ?>
                        </div>
                    </td>
                    <td class="text-center">Complete Repair</td>
                    <td class="text-right" style="font-weight:700;">₱<?= number_format($totalQuoted, 2) ?></td>
                </tr>
            </tbody>
        </table>

        
        <div class="section-heading"><i class="fas fa-history"></i> Payment Transactions Recorded</div>
        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Method</th>
                    <th>Reference / Trans ID</th>
                    <th>Payment Plan / Notes</th>
                    <th class="text-right">Amount</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $pmt): ?>
                    <tr>
                        <td><?= !empty($pmt['payment_date']) ? date('M d, Y h:i A', strtotime($pmt['payment_date'])) : '—' ?></td>
                        <td><strong><?= sanitize($pmt['payment_method']) ?></strong></td>
                        <td style="font-family:monospace; font-size:11.5px;"><?= sanitize($pmt['transaction_id'] ?: 'REF-' . str_pad($repairId, 5, '0', STR_PAD_LEFT)) ?></td>
                        <td><?= sanitize($pmt['notes'] ?: 'Repair Payment') ?></td>
                        <td class="text-right" style="font-weight:700; color:var(--emerald);">₱<?= number_format((float)$pmt['amount'], 2) ?></td>
                        <td class="text-center">
                            <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10.5px; font-weight:700; background:<?= $pmt['payment_status'] === 'Paid' ? '#dcfce7' : '#fef3c7' ?>; color:<?= $pmt['payment_status'] === 'Paid' ? '#15803d' : '#b45309' ?>;">
                                <?= sanitize($pmt['payment_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td><?= !empty($repair['payment_verified_at']) ? date('M d, Y h:i A', strtotime($repair['payment_verified_at'])) : date('M d, Y') ?></td>
                        <td><strong><?= sanitize($repair['payment_method'] ?: 'Cash') ?></strong></td>
                        <td style="font-family:monospace; font-size:11.5px;"><?= sanitize($repair['payment_reference'] ?: ('REF-' . str_pad($repairId, 5, '0', STR_PAD_LEFT))) ?></td>
                        <td><?= $isDpVerified ? 'Downpayment (50%)' : 'Full Service Payment' ?></td>
                        <td class="text-right" style="font-weight:700; color:var(--emerald);">₱<?= number_format($amountPaid, 2) ?></td>
                        <td class="text-center">
                            <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10.5px; font-weight:700; background:<?= $repair['payment_status'] === 'Verified' ? '#dcfce7' : '#fef3c7' ?>; color:<?= $repair['payment_status'] === 'Verified' ? '#15803d' : '#b45309' ?>;">
                                <?= sanitize($repair['payment_status'] ?: 'Pending') ?>
                            </span>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        
        <div class="financial-summary-wrap">
            <div class="financial-notes-box">
                <p><strong>Payment Terms & Verification Notice:</strong></p>
                <p style="margin-top:4px;">
                    This document serves as the official acknowledgment of repair services rendered and payments received by <strong><?= htmlspecialchars($shopName) ?></strong>.
                </p>
                <?php if ($remainingBalance > 0): ?>
                <p style="margin-top:6px; color:var(--rose); font-weight:600;">
                    <i class="fas fa-exclamation-circle"></i> Outstanding remaining balance of ₱<?= number_format($remainingBalance, 2) ?> must be settled upon appliance inspection and pickup.
                </p>
                <?php else: ?>
                <p style="margin-top:6px; color:var(--emerald); font-weight:600;">
                    <i class="fas fa-check-circle"></i> This repair service ticket has been fully settled and paid in full.
                </p>
                <?php endif; ?>
            </div>

            <table class="financial-summary-table">
                <tr>
                    <td style="color:var(--text-muted);">Total Quoted Cost:</td>
                    <td class="text-right" style="font-weight:600;">₱<?= number_format($totalQuoted, 2) ?></td>
                </tr>
                <?php if ($dpQuoted > 0 && $remainingBalance > 0): ?>
                <tr>
                    <td style="color:var(--text-muted);">Required Downpayment (50%):</td>
                    <td class="text-right" style="color:var(--accent); font-weight:600;">₱<?= number_format($dpQuoted, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="color:var(--text-muted); font-weight:600;">Total Amount Received:</td>
                    <td class="text-right" style="color:var(--emerald); font-weight:700;">₱<?= number_format($amountPaid, 2) ?></td>
                </tr>
                <?php if ($remainingBalance > 0): ?>
                <tr class="fin-balance-due">
                    <td>Remaining Balance Due:</td>
                    <td class="text-right">₱<?= number_format($remainingBalance, 2) ?></td>
                </tr>
                <?php else: ?>
                <tr class="fin-balance-settled">
                    <td>Remaining Balance:</td>
                    <td class="text-right">₱0.00 (Fully Paid)</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        
        <?php if (!empty($repair['warranty_status']) && in_array($repair['warranty_status'], ['Under Warranty', 'Grace Period'])): ?>
        <div class="warranty-callout">
            <div class="warranty-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="warranty-text">
                <h4>Service Warranty Active (<?= htmlspecialchars($repair['warranty_status']) ?>)</h4>
                <p>
                    Covered under service warranty until <strong><?= date('F d, Y', strtotime($repair['warranty_end_date'])) ?></strong>
                    <?php if (!empty($repair['warranty_days_left']) && $repair['warranty_days_left'] > 0): ?>
                        (<?= $repair['warranty_days_left'] ?> days remaining).
                    <?php endif; ?>
                    Please retain this receipt for warranty claims or free service returns.
                </p>
            </div>
        </div>
        <?php endif; ?>

        
        <div class="offline-qr-container">
            <div class="qr-canvas-wrap">
                <div id="receiptQrCode"></div>
            </div>
            <div class="qr-info-wrap">
                <div class="qr-pill"><i class="fas fa-qrcode"></i> Offline Scannable QR</div>
                <div class="qr-info-title">Receipt Verification Data</div>
                <p class="qr-info-desc">
                    Scan with any smartphone camera or QR scanner &mdash; <strong>works 100% offline without internet or Wi-Fi</strong>. Instant verification of receipt authenticity and payment state.
                </p>
                <div class="qr-meta-row">
                    <span><strong>Receipt:</strong> <?= $receiptNo ?></span>
                    <span><strong>Ticket:</strong> #<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?></span>
                    <span><strong>Payment:</strong> <?= $qrStatus ?></span>
                </div>
            </div>
        </div>

        
        <div class="receipt-terms">
            Thank you for choosing <strong><?= htmlspecialchars($shopName) ?></strong>! • Please retain this official receipt for warranty verification & claim purposes.
        </div>
    </div>

    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            const qrContainer = document.getElementById('receiptQrCode');
            if (qrContainer && typeof QRCode !== 'undefined') {
                new QRCode(qrContainer, {
                    text: <?= json_encode($offlineQrText) ?>,
                    width: 120,
                    height: 120,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }

            <?php if ($autoPrint): ?>
            setTimeout(function() { window.print(); }, 600);
            <?php elseif ($autoImage): ?>
            setTimeout(function() { saveReceiptImage(); }, 600);
            <?php elseif ($autoDownload): ?>
            setTimeout(function() { downloadReceiptPDF(); }, 600);
            <?php endif; ?>
        });

        
        function saveReceiptImage() {
            const btn = document.getElementById('saveImageBtn');
            const originalText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Saving Image...';
            }

            const element = document.getElementById('receiptSheet');
            const filename = 'Official_Receipt_<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?>.png';

            const opt = {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff',
                logging: false,
                scrollX: 0,
                scrollY: 0
            };

            function restoreBtn() {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }

            if (typeof html2canvas !== 'undefined') {
                html2canvas(element, opt).then(function(canvas) {
                    try {
                        if (canvas.toBlob) {
                            canvas.toBlob(function(blob) {
                                if (blob) {
                                    const link = document.createElement('a');
                                    link.download = filename;
                                    link.href = URL.createObjectURL(blob);
                                    document.body.appendChild(link);
                                    link.click();
                                    document.body.removeChild(link);
                                    setTimeout(function() { URL.revokeObjectURL(link.href); }, 1500);
                                } else {
                                    const dataUrl = canvas.toDataURL('image/png');
                                    triggerDownload(dataUrl, filename);
                                }
                                restoreBtn();
                            }, 'image/png', 0.98);
                        } else {
                            const dataUrl = canvas.toDataURL('image/png');
                            triggerDownload(dataUrl, filename);
                            restoreBtn();
                        }
                    } catch (e) {
                        console.error('Save image error:', e);
                        restoreBtn();
                    }
                }).catch(function(err) {
                    console.error('html2canvas capture error:', err);
                    alert('Unable to capture receipt image. Please try downloading as PDF.');
                    restoreBtn();
                });
            } else {
                alert('Image capture library is not ready. Please try downloading as PDF.');
                restoreBtn();
            }
        }

        function triggerDownload(url, filename) {
            const link = document.createElement('a');
            link.download = filename;
            link.href = url;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        
        function downloadReceiptPDF() {
            const btn = document.getElementById('downloadPdfBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Generating PDF...';

            const element = document.getElementById('receiptSheet');
            const opt = {
                margin:       [8, 8, 8, 8],
                filename:     'Official_Receipt_<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            if (typeof html2pdf !== 'undefined') {
                html2pdf().set(opt).from(element).save().then(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }).catch(function(err) {
                    console.error('PDF generation error:', err);
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    
                    window.print();
                });
            } else {
                btn.disabled = false;
                btn.innerHTML = originalText;
                window.print();
            }
        }
    </script>
</body>
</html>
