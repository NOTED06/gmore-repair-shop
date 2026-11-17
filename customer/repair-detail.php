<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();
$repairId = (int)($_GET['id'] ?? 0);
if ($repairId > 0) {
    redirect('repairs.php?view_id=' . $repairId);
} else {
    redirect('repairs.php');
}

$stmt = $db->prepare("
    SELECT r.*, t.full_name AS technician_name, t.contact_no AS tech_phone, t.specialization
    FROM repairs r
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    WHERE r.repair_id = ? AND r.customer_id = ?
");
$stmt->execute([$repairId, $customerId]);
$repair = $stmt->fetch();

if (!$repair) redirect('repairs.php', 'Repair not found.', 'danger');

$hasPayment = (($repair['total_cost'] ?? 0) > 0 || ($repair['estimated_cost'] ?? 0) > 0);
$displayAmount = ($repair['total_cost'] ?? 0) > 0 ? (float)$repair['total_cost'] : (float)$repair['estimated_cost'];
$isDownpaymentOnly = empty($repair['total_cost']) && ($repair['estimated_cost'] ?? 0) > 0;

$isDp = ($repair['payment_type'] ?? '') === 'downpayment';
$paidAmt = (float)($repair['amount_paid'] ?? 0);
$totalCost = (float)($repair['total_cost'] ?: $displayAmount);
$remBalance = max(0, $totalCost - $paidAmt);
$isDpVerified = $isDp && ($repair['payment_status'] ?? '') === 'Verified' && $remBalance > 0;
$canPayBalance = $isDpVerified && in_array($repair['repair_status'], ['Completed', 'Returned']);
$canPay = $hasPayment && (empty($repair['payment_method']) || $canPayBalance || ($repair['payment_status'] ?? '') === 'Rejected') && !in_array($repair['repair_status'], ['Cancelled']);
$modalDisplayAmount = $canPayBalance ? $remBalance : $displayAmount;

$stmt = $db->prepare("
    SELECT rl.*, COALESCE(c.full_name, t.full_name, u.username) AS full_name
    FROM repair_logs rl
    LEFT JOIN users u ON rl.user_id = u.user_id
    LEFT JOIN customers c ON u.user_id = c.user_id
    LEFT JOIN technicians t ON u.user_id = t.user_id
    WHERE rl.repair_id = ?
    ORDER BY rl.created_at DESC
");
$stmt->execute([$repairId]);
$logs = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM schedules WHERE repair_id = ? ORDER BY scheduled_date DESC LIMIT 1");
$stmt->execute([$repairId]);
$schedule = $stmt->fetch();

$stmt = $db->prepare("
    SELECT w.*,
           DATEDIFF(w.warranty_end_date, CURDATE()) AS warranty_days_left,
           DATEDIFF(w.grace_period_end_date, CURDATE()) AS grace_days_left
    FROM warranty_tracking w WHERE w.repair_id = ?
");
$stmt->execute([$repairId]);
$warranty = $stmt->fetch();

if ($warranty) {
    
    $paymentVerified = ($repair['payment_status'] === 'Verified' || (float)$repair['total_cost'] <= 0);
    
    if ($paymentVerified) {
        $today = date('Y-m-d');
        if ($today <= $warranty['warranty_end_date'])             $warranty['warranty_status'] = 'Under Warranty';
        elseif ($today <= $warranty['grace_period_end_date'])     $warranty['warranty_status'] = 'Grace Period';
        else                                                       $warranty['warranty_status'] = 'Expired';
    } else {
        $warranty = null; 
    }
}

$etc = $schedule['estimated_completion'] ?? null;

$stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND message LIKE ?");
$stmt->execute([$_SESSION['user_id'], "%#$repairId%"]);

$pageTitle    = 'Repair #' . str_pad($repairId, 5, '0', STR_PAD_LEFT);
$activePage   = 'repairs';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

function repairStatusBadge($s) {
    return match($s) {
        'Pending'     => 'badge-amber',
        'Assigned'    => 'badge-blue',
        'In Progress' => 'badge-teal',
        'Completed','Returned' => 'badge-emerald',
        'Cancelled'   => 'badge-rose',
        default       => 'badge-teal',
    };
}

$statuses     = ['Pending', 'Assigned', 'In Progress', 'Completed', 'Returned'];
$currentIndex = array_search($repair['repair_status'], $statuses);
if ($repair['repair_status'] === 'On Hold')  $currentIndex = 2;
if ($repair['repair_status'] === 'Cancelled') $currentIndex = -1;
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <style>
    .highlight-glow {
        animation: glow 2s ease-in-out infinite alternate;
        border: 2px solid var(--accent);
    }
    @keyframes glow {
        0% { box-shadow: 0 0 10px rgba(0,229,200,0.2); }
        100% { box-shadow: 0 0 25px rgba(0,229,200,0.8); }
    }
    </style>

    
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">
                <i data-lucide="file-search"></i>
                Repair <span style="color:var(--accent)">#<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?></span>
            </h1>
            <p class="page-sub"><?= sanitize($repair['appliance_type']) ?> &mdash; <?= sanitize($repair['repair_status']) ?></p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="repairs.php" class="btn-ghost"><i data-lucide="arrow-left"></i> Back</a>
            <?php 
            $hasReceipt = (!empty($repair['payment_method']) && in_array($repair['payment_status'] ?? '', ['Verified', 'Pending'])) || ((float)($repair['amount_paid'] ?? 0) > 0) || in_array($repair['repair_status'], ['Completed', 'Returned']);
            if ($hasReceipt):
            ?>
            <a href="receipt.php?id=<?= $repairId ?>" target="_blank" class="btn-ghost" style="color:#0284c7; border-color:#bae6fd; background:#f0f9ff; text-decoration:none;"><i data-lucide="file-text"></i> Download Receipt</a>
            <?php endif; ?>
            <?php if ($canPay): ?>
            <button class="btn-primary" onclick="openPaymentModal()"><i data-lucide="credit-card"></i> <?= $canPayBalance ? 'Pay Remaining Balance' : 'Pay' ?></button>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="card" style="margin-bottom:20px;padding:20px 24px">
        <div style="display:flex;align-items:center;gap:0;overflow-x:auto">
            <?php foreach ($statuses as $i => $step):
                $cls = '';
                if ($i < $currentIndex)       $cls = 'done';
                elseif ($i === $currentIndex)  $cls = 'active';
            ?>
            <div style="display:flex;align-items:center;flex:<?= $i < count($statuses)-1 ? '1' : '0' ?>;min-width:80px">
                <div style="display:flex;flex-direction:column;align-items:center;gap:5px;flex-shrink:0">
                    <div style="
                        width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;
                        <?= $cls === 'done'   ? 'background:var(--emerald);color:#fff;border:2px solid var(--emerald)' : '' ?>
                        <?= $cls === 'active' ? 'background:var(--accent);color:#0A0E14;border:2px solid var(--accent);box-shadow:0 0 0 4px rgba(0,229,200,0.15)' : '' ?>
                        <?= $cls === ''       ? 'background:var(--bg-hover);color:var(--text-tertiary);border:2px solid var(--border)' : '' ?>
                    ">
                        <?php if ($cls === 'done'): ?><i data-lucide="check" style="width:14px;height:14px"></i><?php else: ?><?= $i + 1 ?><?php endif; ?>
                    </div>
                    <span style="font-size:11px;color:<?= $cls === 'active' ? 'var(--accent)' : ($cls === 'done' ? 'var(--emerald)' : 'var(--text-tertiary)') ?>;white-space:nowrap;font-weight:<?= $cls !== '' ? '600' : '400' ?>"><?= $step ?></span>
                </div>
                <?php if ($i < count($statuses)-1): ?>
                <div style="flex:1;height:2px;background:<?= $i < $currentIndex ? 'var(--emerald)' : 'var(--border)' ?>;margin:0 8px;margin-bottom:20px"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($repair['repair_status'] === 'On Hold'): ?>
        <div class="alert alert-info" style="margin-top:14px;margin-bottom:0"><i data-lucide="pause-circle"></i> <span>This repair is currently <strong>On Hold</strong>. We will contact you with more information.</span></div>
        <?php elseif ($repair['repair_status'] === 'Cancelled'): ?>
        <div class="alert alert-danger" style="margin-top:14px;margin-bottom:0"><i data-lucide="x-circle"></i> <span>This repair request has been <strong>Cancelled</strong>.</span></div>
        <?php elseif ($repair['repair_status'] === 'Returned'): ?>
        <div style="margin-top:14px;background:linear-gradient(135deg,rgba(16,185,129,0.12),rgba(5,150,105,0.08));border:1px solid rgba(16,185,129,0.25);border-radius:10px;padding:16px 20px;text-align:center">
            <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:18px;color:var(--emerald);margin-bottom:4px">Item Successfully Returned!</div>
            <p style="font-size:13px;color:var(--text-secondary);margin:0">Your repaired item has been picked up. Thank you for choosing <strong>RepairShop</strong>!</p>
            <?php if (!empty($repair['payment_method'])): ?>
            <span class="badge badge-emerald" style="margin-top:8px">
                <i data-lucide="check-circle-2"></i> Paid via <?= sanitize($repair['payment_method']) ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    
    <?php if ($repair['repair_status'] === 'Returned' && $warranty && in_array($warranty['warranty_status'], ['Under Warranty', 'Grace Period'])): ?>
    <div class="banner-amber" style="margin-bottom:20px">
        <i data-lucide="shield"></i>
        <div class="banner-text">
            <strong><?= $warranty['warranty_status'] === 'Under Warranty' ? 'Under Warranty' : 'Grace Period' ?></strong> —
            <?php if ($warranty['warranty_status'] === 'Under Warranty'): ?>
                <?= $warranty['warranty_days_left'] ?> day(s) remaining until <?= date('M d, Y', strtotime($warranty['warranty_end_date'])) ?>.
            <?php else: ?>
                Grace period ends <?= date('M d, Y', strtotime($warranty['grace_period_end_date'])) ?>.
            <?php endif; ?>
            Experiencing issues? Request a free warranty return.
        </div>
        <button class="btn-sm-ghost" onclick="openWarrantyModal()" style="flex-shrink:0"><i data-lucide="refresh-ccw"></i> Return</button>
    </div>
    <?php endif; ?>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">

        
        <div class="card <?= isset($_GET['highlight']) && $_GET['highlight'] == '1' ? 'highlight-glow' : '' ?>" id="applianceInfoBox">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="cpu" style="width:16px;height:16px;color:var(--accent)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Appliance Information</span>
            </div>
            <?php
            $details = [
                'Type'          => sanitize($repair['appliance_type']),
                'Brand'         => $repair['brand'] ? sanitize($repair['brand']) : null,
                'Model'         => $repair['model'] ? sanitize($repair['model']) : null,
                'Serial Number' => $repair['serial_number'] ? sanitize($repair['serial_number']) : null,
            ];
            foreach ($details as $label => $val): if (!$val) continue; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(48,54,61,0.5)">
                <span style="font-size:12px;color:var(--text-tertiary);font-weight:500"><?= $label ?></span>
                <span style="font-size:13px;color:var(--text-primary);font-weight:500"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        
        <div class="card">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="alert-triangle" style="width:16px;height:16px;color:var(--amber)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Problem Details</span>
            </div>
            <div style="margin-bottom:14px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-tertiary);margin-bottom:6px;font-weight:500">Description</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.7"><?= nl2br(sanitize($repair['problem_description'])) ?></div>
            </div>
            <?php if (!empty($repair['appliance_image'])): ?>
            <div style="margin-bottom:14px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-tertiary);margin-bottom:6px;font-weight:500">Photo</div>
                <img src="<?= SITE_URL . '/' . $repair['appliance_image'] ?>" alt="Appliance"
                     onclick="document.getElementById('imgModal').classList.add('open');document.getElementById('imgModalImg').src=this.src"
                     style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;cursor:pointer;border:1px solid var(--border)">
                <span style="font-size:11px;color:var(--text-tertiary);margin-top:4px;display:block">Click to enlarge</span>
            </div>
            <?php endif; ?>
            <?php if ($repair['diagnosis']): ?>
            <div style="margin-bottom:14px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-tertiary);margin-bottom:6px;font-weight:500">Diagnosis</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.7"><?= nl2br(sanitize($repair['diagnosis'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($repair['repair_notes']): ?>
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-tertiary);margin-bottom:6px;font-weight:500">Notes</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.7"><?= nl2br(sanitize($repair['repair_notes'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        
        <?php if ($repair['technician_name']): ?>
        <div class="card">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="user-cog" style="width:16px;height:16px;color:var(--accent)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Assigned Technician</span>
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(0,229,200,0.12);border:1px solid rgba(0,229,200,0.25);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;font-size:16px;color:var(--accent);flex-shrink:0"><?= strtoupper(substr($repair['technician_name'], 0, 1)) ?></div>
                <div>
                    <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:15px;color:var(--text-primary)"><?= sanitize($repair['technician_name']) ?></div>
                    <?php if ($repair['specialization']): ?>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:2px"><?= sanitize($repair['specialization']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        
        <?php if ($schedule): ?>
        <div class="card">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="calendar-check" style="width:16px;height:16px;color:var(--blue)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Scheduled Appointment</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="badge badge-blue"><i data-lucide="calendar"></i> <?= formatDate($schedule['scheduled_date']) ?></span>
                <?php if ($schedule['scheduled_time']): ?>
                <span class="badge badge-teal"><i data-lucide="clock"></i> <?= date('h:i A', strtotime($schedule['scheduled_time'])) ?></span>
                <?php endif; ?>
                <span class="badge <?= $schedule['schedule_status'] === 'Completed' ? 'badge-emerald' : 'badge-amber' ?>"><?= sanitize($schedule['schedule_status']) ?></span>
            </div>
            <?php if ($schedule['notes']): ?>
            <div style="margin-top:12px;font-size:13px;color:var(--text-secondary);line-height:1.6"><?= sanitize($schedule['notes']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        
        <div class="card">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="clock" style="width:16px;height:16px;color:var(--text-secondary)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Timeline &amp; Costs</span>
            </div>
            <?php
            $timeline = [
                'Date Requested'  => formatDateTime($repair['date_requested']),
                'Date Started'    => $repair['date_started'] ? formatDateTime($repair['date_started']) : null,
                'Date Completed'  => $repair['date_completed'] ? formatDateTime($repair['date_completed']) : null,
            ];
            foreach ($timeline as $label => $val): if (!$val) continue; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(48,54,61,0.5)">
                <span style="font-size:12px;color:var(--text-tertiary)"><?= $label ?></span>
                <span style="font-size:13px;color:var(--text-primary)"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($repair['estimated_cost'] > 0): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(48,54,61,0.5)">
                <span style="font-size:12px;color:var(--text-tertiary)">Downpayment</span>
                <span style="font-size:13px;color:var(--text-primary)"><?= formatCurrency($repair['estimated_cost']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($repair['total_cost'] > 0): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0 0">
                <span style="font-size:12px;color:var(--text-tertiary);font-weight:600">Final Cost</span>
                <span style="font-size:18px;font-family:'Syne',sans-serif;font-weight:800;color:var(--accent)"><?= formatCurrency($repair['total_cost']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        
        <div class="card" style="text-align: center;">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="qr-code" style="width:16px;height:16px;color:var(--accent)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Digital Service Ticket</span>
            </div>
            <div style="padding: 10px;">
                <p style="font-size: 11px; color: var(--text-tertiary); margin-bottom: 15px;">
                    Show this QR code to our staff when visiting the shop for quick identification.
                </p>
                <div style="width: 160px; height: 160px; margin: 0 auto; background: #fff; padding: 10px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: center;">
                    <?php 
                    $ticketData = [
                        'ID' => str_pad($repair['repair_id'], 5, '0', STR_PAD_LEFT),
                        'Type' => $repair['appliance_type'],
                        'Brand' => $repair['brand'],
                        'Customer' => $_SESSION['full_name'] ?? 'Customer',
                        'Status' => $repair['repair_status'],
                        'Amount' => $repair['total_cost'] ?? 'Pending'
                    ];
                    $qrString = "";
                    foreach($ticketData as $k => $v) $qrString .= "$k: $v | ";
                    ?>
                    <div id="serviceTicketQr" style="width: 140px; height: 140px; display: flex; align-items: center; justify-content: center;"></div>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof QRCode !== 'undefined') {
                            new QRCode(document.getElementById('serviceTicketQr'), {
                                text: <?= json_encode(rtrim($qrString, ' | ')) ?>,
                                width: 140,
                                height: 140,
                                colorDark: "#1a1730",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.M
                            });
                        }
                    });
                    </script>
                </div>
                <div style="margin-top: 15px; font-size: 14px; font-weight: 800; color: var(--primary); font-family: 'Syne', sans-serif;">
                    TICKET #<?= str_pad($repair['repair_id'], 5, '0', STR_PAD_LEFT) ?>
                </div>
            </div>
        </div>

        
        <?php if ($hasPayment && !in_array($repair['repair_status'], ['Cancelled'])): ?>
        <?php 
            $isDp = ($repair['payment_type'] ?? '') === 'downpayment';
            $paidAmt = (float)($repair['amount_paid'] ?? 0);
            $totalCost = (float)($repair['total_cost'] ?: $displayAmount);
            $remBalance = max(0, $totalCost - $paidAmt);
            $isDpVerified = $isDp && ($repair['payment_status'] ?? '') === 'Verified' && $remBalance > 0;
            $canPayBalance = $isDpVerified && in_array($repair['repair_status'], ['Completed', 'Returned']);
        ?>
        <div class="card" id="paymentSection" style="border-color:rgba(0,229,200,0.2);background:linear-gradient(135deg,rgba(0,229,200,0.04),rgba(0,229,200,0.01))">
            <div style="padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <i data-lucide="credit-card" style="width:16px;height:16px;color:var(--accent)"></i>
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Payment</span>
                <?php if ($isDpVerified): ?>
                <span class="badge badge-emerald" style="margin-left:auto"><i data-lucide="shield-check"></i> DP Verified</span>
                <?php elseif (!empty($repair['payment_status']) && $repair['payment_status'] === 'Verified'): ?>
                <span class="badge badge-emerald" style="margin-left:auto"><i data-lucide="shield-check"></i> Paid in Full</span>
                <?php elseif (!empty($repair['payment_method'])): ?>
                <span class="badge badge-amber" style="margin-left:auto"><i data-lucide="clock"></i> <?= ($repair['payment_type'] ?? '') === 'balance' ? 'Balance Pending' : ($isDp ? 'DP Pending' : 'Pending Verification') ?></span>
                <?php else: ?>
                <span class="badge badge-rose" style="margin-left:auto"><i data-lucide="alert-circle"></i> Unpaid</span>
                <?php endif; ?>
            </div>

            <div style="text-align:center;padding:8px 0 16px">
                <?php if ($isDpVerified): ?>
                    <div style="font-size:28px;font-family:'Syne',sans-serif;font-weight:800;color:var(--rose)"><?= formatCurrency($remBalance) ?></div>
                    <div style="font-size:12px;color:var(--text-tertiary);margin-top:3px">Remaining Balance Due (DP Paid: <?= formatCurrency($paidAmt) ?>)</div>
                <?php elseif (!empty($repair['payment_method']) && ($repair['payment_status'] ?? '') === 'Pending' && ($repair['payment_type'] ?? '') === 'balance'): ?>
                    <div style="font-size:28px;font-family:'Syne',sans-serif;font-weight:800;color:var(--accent)"><?= formatCurrency($paidAmt) ?></div>
                    <div style="font-size:12px;color:var(--text-tertiary);margin-top:3px">Remaining Balance Submitted (Pending Staff Verification)</div>
                <?php elseif (!empty($repair['payment_method']) && ($repair['payment_status'] ?? '') === 'Pending' && $isDp): ?>
                    <div style="font-size:28px;font-family:'Syne',sans-serif;font-weight:800;color:var(--accent)"><?= formatCurrency($paidAmt) ?></div>
                    <div style="font-size:12px;color:var(--text-tertiary);margin-top:3px">Downpayment Submitted (Total: <?= formatCurrency($totalCost) ?>)</div>
                <?php else: ?>
                    <div style="font-size:28px;font-family:'Syne',sans-serif;font-weight:800;color:var(--accent)"><?= formatCurrency($displayAmount) ?></div>
                    <div style="font-size:12px;color:var(--text-tertiary);margin-top:3px"><?= $isDownpaymentOnly ? 'Downpayment Amount' : 'Total Amount Due' ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($repair['payment_method']) && !$canPayBalance): ?>
            <div style="background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:12px 14px;margin-bottom:12px">
                <?php
                $payIcons = ['Cash'=>'💵','GCash'=>'📱','Bank Transfer'=>'🏦','Credit Card'=>'💳','Other'=>'🔄'];
                $icon = $payIcons[$repair['payment_method']] ?? '💳';
                ?>
                <div style="font-size:13px;color:var(--emerald);font-weight:600"><?= $icon ?> <?= sanitize($repair['payment_method']) ?></div>
                <?php if ($repair['payment_method'] === 'GCash'): ?>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:6px">Send to GCash: <strong>09XX-XXX-XXXX</strong> — ref: <strong>#<?= $repairId ?></strong></div>
                <?php elseif ($repair['payment_method'] === 'Bank Transfer'): ?>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:6px">Bank: [Bank Name] &nbsp;|&nbsp; Acct: XXXX-XXXX — ref: <strong>#<?= $repairId ?></strong></div>
                <?php elseif ($repair['payment_method'] === 'Cash'): ?>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:6px">Prepare exact amount on pickup / store counter.</div>
                <?php endif; ?>
            </div>
            <?php elseif (empty($repair['payment_method']) || ($repair['payment_status'] ?? '') === 'Rejected' || $canPayBalance): ?>
            <button class="btn-primary" style="width:100%;justify-content:center" onclick="openPaymentModal()">
                <i data-lucide="wallet"></i> <?= $canPayBalance ? 'Pay Remaining Balance (' . formatCurrency($remBalance) . ')' : 'Pay' ?>
            </button>
            <?php endif; ?>

            <?php if (!empty($repair['payment_method']) && in_array($repair['payment_status'] ?? '', ['Verified', 'Pending'])): ?>
            <a href="receipt.php?id=<?= $repairId ?>" target="_blank" class="btn-ghost" style="width:100%;justify-content:center;margin-top:10px;text-decoration:none;color:#0284c7;border-color:#bae6fd;background:#f0f9ff;font-size:12.5px;font-weight:600;">
                <i data-lucide="file-text"></i> Download Official Receipt
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    
    <?php if (!empty($logs)): ?>
    <div class="card" style="margin-top:20px">
        <div style="padding:0 0 14px;border-bottom:1px solid var(--border);margin-bottom:16px;display:flex;align-items:center;gap:8px">
            <i data-lucide="history" style="width:16px;height:16px;color:var(--text-secondary)"></i>
            <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Repair History</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:0;padding-left:16px;border-left:2px solid var(--border)">
            <?php foreach ($logs as $log): ?>
            <div style="position:relative;padding:0 0 16px 20px">
                <div style="position:absolute;left:-9px;top:4px;width:16px;height:16px;border-radius:50%;background:var(--bg-card);border:2px solid var(--accent);display:flex;align-items:center;justify-content:center">
                    <div style="width:6px;height:6px;border-radius:50%;background:var(--accent)"></div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px">
                    <span style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= sanitize($log['action']) ?></span>
                    <?php if ($log['new_status']): ?><span class="badge badge-teal"><?= sanitize($log['new_status']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($log['notes'])): ?>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:3px"><?= sanitize($log['notes']) ?></div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-tertiary);display:flex;align-items:center;gap:4px">
                    <i data-lucide="clock-3" style="width:11px;height:11px"></i>
                    <?= formatDateTime($log['created_at']) ?>
                    <?php if ($log['full_name']): ?>&mdash; <?= sanitize($log['full_name']) ?><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>
</div>

<div class="modal-overlay" id="imgModal">
    <div style="max-width:90vw;max-height:90vh;position:relative">
        <img id="imgModalImg" src="" alt="" style="max-width:90vw;max-height:85vh;border-radius:10px;border:1px solid var(--border)">
        <button onclick="document.getElementById('imgModal').classList.remove('open')"
                style="position:absolute;top:-12px;right:-12px;width:32px;height:32px;border-radius:50%;background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);cursor:pointer;display:flex;align-items:center;justify-content:center">
            <i data-lucide="x" style="width:14px;height:14px"></i>
        </button>
    </div>
</div>

<?php if ($canPay): ?>
<div class="modal-overlay" id="paymentModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-icon"><i data-lucide="wallet"></i></div>
            <div>
                <div class="modal-title"><?= $canPayBalance ? 'Pay Remaining Balance' : ($isDownpaymentOnly ? 'Pay Downpayment' : 'Select Payment Method') ?></div>
                <div class="modal-sub">Repair #<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?> &mdash; <span id="modalPayAmount"><?= formatCurrency($modalDisplayAmount) ?></span></div>
            </div>
            <button class="modal-close" onclick="closePaymentModal()"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="submit-payment.php" id="paymentForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="repair_id" value="<?= $repairId ?>">
            <div class="modal-body">
                <?php if ($canPayBalance): ?>
                <input type="hidden" name="payment_type" value="balance">
                <div style="margin-bottom:14px;padding:12px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2);border-radius:8px;">
                    <div style="font-size:12px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">Remaining Balance Due</div>
                    <div style="font-size:15px;font-weight:800;color:var(--rose);"><?= formatCurrency($remBalance) ?> <span style="font-size:11px;font-weight:normal;color:var(--text-tertiary);">(Total: <?= formatCurrency($totalCost) ?>, DP Paid: <?= formatCurrency($paidAmt) ?>)</span></div>
                </div>
                <?php elseif (($repair['total_cost'] ?? 0) > 0 && ($repair['estimated_cost'] ?? 0) > 0): ?>
                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Payment Option</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-base);border:2px solid var(--border);border-radius:8px;cursor:pointer;">
                            <input type="radio" name="payment_type" value="full" checked onchange="document.getElementById('modalPayAmount').textContent = '<?= formatCurrency($repair['total_cost']) ?>'; currentPaymentAmount = '<?= $repair['total_cost'] ?>'; updatePaymentQR(document.querySelector('input[name=payment_method]:checked')?.value || 'GCash');">
                            <div>
                                <div style="font-size:12px;font-weight:700;color:var(--text-primary);">Full Payment</div>
                                <div style="font-size:11px;color:var(--accent);"><?= formatCurrency($repair['total_cost']) ?></div>
                            </div>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-base);border:2px solid var(--border);border-radius:8px;cursor:pointer;">
                            <input type="radio" name="payment_type" value="downpayment" onchange="document.getElementById('modalPayAmount').textContent = '<?= formatCurrency($repair['estimated_cost']) ?>'; currentPaymentAmount = '<?= $repair['estimated_cost'] ?>'; updatePaymentQR(document.querySelector('input[name=payment_method]:checked')?.value || 'GCash');">
                            <div>
                                <div style="font-size:12px;font-weight:700;color:var(--text-primary);">Downpayment</div>
                                <div style="font-size:11px;color:var(--emerald);"><?= formatCurrency($repair['estimated_cost']) ?></div>
                            </div>
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <div style="margin-bottom:8px;font-size:12px;font-weight:600;color:var(--text-secondary);">Select Method</div>
                <?php
                $payOptions = [
                    'Cash'          => ['💵', 'Pay at counter'],
                    'GCash'         => ['📱', 'Mobile payment'],
                ];
                foreach ($payOptions as $method => [$emoji, $desc]):
                ?>
                <label style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg-base);border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color .2s;margin-bottom:8px;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='var(--border)'">
                    <input type="radio" name="payment_method" value="<?= $method ?>" required style="accent-color:var(--accent);width:16px;height:16px" onchange="updatePaymentQR(this.value); document.querySelectorAll('#paymentForm label').forEach(l=>l.style.borderColor='var(--border)');this.closest('label').style.borderColor='var(--accent)'">
                    <span style="font-size:18px"><?= $emoji ?></span>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= $method ?></div>
                        <div style="font-size:11px;color:var(--text-tertiary)"><?= $desc ?></div>
                    </div>
                </label>
                <?php endforeach; ?>

                
                <div id="modalQrContainer" style="display:none; margin-top:16px; padding:16px; border:1px solid var(--border); border-radius:12px; text-align:center; background:#FAFAF9; animation:nr-in .3s ease;">
                    <p id="modalQrTitle" style="font-weight:700; color:var(--text-primary); font-size:13px; margin-bottom:4px;">Scan to Pay</p>
                    <p id="modalQrDesc" style="font-size:11px; color:var(--text-tertiary); margin-bottom:12px;">Open your app and scan this code.</p>
                    <div style="width:160px; height:160px; margin:0 auto; background:#fff; padding:8px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:center;">
                        <div id="modalQrBox" style="width:144px; height:144px; display:flex; align-items:center; justify-content:center;"></div>
                    </div>
                    <p id="modalQrHint" style="margin-top:10px; font-size:11px; color:var(--accent); font-weight:600; display:none;">Present this to the shop staff</p>
                </div>

                
                <div id="modalProofSection" style="display:none; margin-top:14px; padding:14px; border:1px solid var(--border); border-radius:12px; background:#fff;">
                    <label style="font-size:12px; font-weight:700; color:var(--text-primary); display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span><i class="fas fa-upload" style="color:#0ea5e9;"></i> Upload Proof of Payment</span>
                        <span style="font-size:11px; font-weight:normal; color:var(--rose);">Required for manual GCash</span>
                    </label>
                    <input type="file" name="payment_proof" id="detailPaymentProof" accept="image/png, image/jpeg, image/jpg, image/webp" onchange="scanDetailReceipt(this)" style="font-size:12px; width:100%; padding:6px; border:1px dashed var(--border); border-radius:8px; background:var(--bg-base); cursor:pointer;">
                    <div style="font-size:10px; color:var(--text-tertiary); margin-top:3px;">PNG, JPG, WEBP (Max 10MB)</div>
                    <div id="detailProofScanStatus" style="display:none;margin-top:7px;padding:7px 9px;border-radius:6px;font-size:11px;font-weight:600;"></div>
                </div>

                <script>
                let currentPaymentAmount = '<?= $modalDisplayAmount ?>';
                let detailReceiptScanValid = false;

                function scanDetailReceipt(input) {
                    const status = document.getElementById('detailProofScanStatus');
                    const file = input.files[0];
                    detailReceiptScanValid = false;
                    if (!file || !status) return;
                    const show = (bg, color, border, html) => {
                        status.style.display = 'block'; status.style.background = bg;
                        status.style.color = color; status.style.border = '1px solid ' + border;
                        status.innerHTML = html;
                    };
                    show('#eff6ff', '#1d4ed8', '#bfdbfe', '<i class="fas fa-circle-notch fa-spin"></i> Scanning uploaded receipt image...');
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) {
                        input.value = '';
                        show('#fef2f2', '#b91c1c', '#fecaca', '<i class="fas fa-times-circle"></i> Not a valid GCash receipt image.');
                        return;
                    }
                    const image = new Image();
                    image.onload = () => {
                        if (image.naturalWidth < 300 || image.naturalHeight < 300) {
                            input.value = '';
                            show('#fef2f2', '#b91c1c', '#fecaca', '<i class="fas fa-times-circle"></i> Not valid: image must be at least 300 × 300.');
                            return;
                        }
                        detailReceiptScanValid = true;
                        show('#ecfdf5', '#047857', '#a7f3d0', '<i class="fas fa-check-circle"></i> Image passed the receipt scan; pending staff verification.');
                    };
                    image.onerror = () => {
                        input.value = '';
                        show('#fef2f2', '#b91c1c', '#fecaca', '<i class="fas fa-times-circle"></i> Not a valid GCash receipt image.');
                    };
                    image.src = URL.createObjectURL(file);
                }

                function updatePaymentQR(method) {
                    const container = document.getElementById('modalQrContainer');
                    const proofSec = document.getElementById('modalProofSection');
                    const proofInput = document.getElementById('detailPaymentProof');
                    const title = document.getElementById('modalQrTitle');
                    const desc = document.getElementById('modalQrDesc');
                    const hint = document.getElementById('modalQrHint');
                    const qrBox = document.getElementById('modalQrBox');
                    const repairId = '<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?>';
                    const customer = '<?= sanitize($repair['customer_name'] ?? '') ?>';

                    container.style.display = 'block';
                    qrBox.innerHTML = '';
                    
                    let qrData = '';
                    if (method === 'GCash') {
                        title.textContent = 'Scan to Pay (GCash)';
                        desc.textContent = 'Use GCash app to scan and pay.';
                        hint.style.display = 'none';
                        if (proofSec) proofSec.style.display = 'block';
                        if (proofInput) proofInput.required = true;
                        qrData = 'GCash Payment for Repair #' + repairId + ' - P' + currentPaymentAmount;
                    } else {
                        title.textContent = 'Cashier Reference QR';
                        desc.textContent = 'Show this code at the store counter.';
                        hint.style.display = 'block';
                        if (proofSec) proofSec.style.display = 'none';
                        if (proofInput) proofInput.required = false;
                        qrData = 'RepairID: ' + repairId + ' | Amount: ' + currentPaymentAmount + ' | Customer: ' + customer;
                    }

                    if (typeof QRCode !== 'undefined') {
                        new QRCode(qrBox, {
                            text: qrData,
                            width: 144,
                            height: 144,
                            colorDark: "#1a1730",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                }

                document.getElementById('paymentForm')?.addEventListener('submit', function(event) {
                    const method = this.querySelector('input[name="payment_method"]:checked')?.value;
                    if (method === 'GCash' && !detailReceiptScanValid) {
                        event.preventDefault();
                        alert('Upload a valid receipt image and wait for scanning to finish.');
                    }
                });
                </script>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn-primary"><i data-lucide="check-circle-2"></i> Confirm Payment</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($repair['repair_status'] === 'Returned' && $warranty && in_array($warranty['warranty_status'], ['Under Warranty', 'Grace Period'])): ?>
<div class="modal-overlay" id="warrantyModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-icon"><i data-lucide="shield"></i></div>
            <div>
                <div class="modal-title">Request Warranty Return</div>
                <div class="modal-sub">Repair #<?= str_pad($repairId, 5, '0', STR_PAD_LEFT) ?> is still <?= strtolower($warranty['warranty_status']) ?></div>
            </div>
            <button class="modal-close" onclick="closeWarrantyModal()"><i data-lucide="x"></i></button>
        </div>
        <div id="warrantyModalBody" class="modal-body">
            <div class="field-group">
                <label class="field-label">Describe the Issue <span style="color:var(--rose)">*</span></label>
                <textarea class="input" id="warrantyReason" rows="4" placeholder="Describe the problem (e.g. same issue reoccurred, new problem after repair...) — minimum 10 characters"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost" onclick="closeWarrantyModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitWarrantyReturn()"><i data-lucide="refresh-ccw"></i> Submit Return</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
<script>
function openPaymentModal()  { document.getElementById('paymentModal')?.classList.add('open'); }
function closePaymentModal() { document.getElementById('paymentModal')?.classList.remove('open'); }
function openWarrantyModal()  { document.getElementById('warrantyModal')?.classList.add('open'); }
function closeWarrantyModal() { document.getElementById('warrantyModal')?.classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', function(e){ if (e.target === this) this.classList.remove('open'); });
});

function submitWarrantyReturn() {
    const reason = document.getElementById('warrantyReason')?.value.trim();
    if (!reason || reason.length < 10) { alert('Please describe the issue (at least 10 characters).'); return; }
    const btn = document.querySelector('#warrantyModal .btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }

    const fd = new FormData();
    fd.append('csrf_token', '<?= generateCSRFToken() ?>');
    fd.append('repair_id', <?= $repairId ?>);
    fd.append('reason', reason);

    fetch('<?= SITE_URL ?>/customer/warranty-return.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeWarrantyModal();
                location.reload();
            } else {
                alert(data.message || 'Error submitting return.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="refresh-ccw"></i> Submit Return'; lucide.createIcons(); }
            }
        })
        .catch(() => { alert('Network error. Please try again.'); if (btn) { btn.disabled = false; } });
}
</script>
</body>
</html>
