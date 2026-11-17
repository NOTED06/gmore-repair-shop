<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customerId = $stmt->fetchColumn();

$status = $_GET['status'] ?? '';
$viewId = (int)($_GET['view_id'] ?? 0);

$stmtAll = $db->prepare("
    SELECT r.*, 
           t.full_name AS technician_name,
           t.contact_no AS tech_phone,
           t.specialization AS tech_specialization,
           s.scheduled_date,
           s.scheduled_time,
           s.end_time,
           s.estimated_completion,
           w.warranty_status, 
           w.warranty_end_date, 
           w.grace_period_end_date,
           DATEDIFF(w.warranty_end_date, CURDATE()) AS warranty_days_left
    FROM repairs r
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    LEFT JOIN (
        SELECT s1.* FROM schedules s1
        INNER JOIN (
            SELECT repair_id, MAX(schedule_id) as max_id 
            FROM schedules 
            GROUP BY repair_id
        ) s2 ON s1.schedule_id = s2.max_id
    ) s ON r.repair_id = s.repair_id
    LEFT JOIN warranty_tracking w ON r.repair_id = w.repair_id
    WHERE r.customer_id = ?
    ORDER BY r.date_requested DESC
");
$stmtAll->execute([$customerId]);
$allCustomerRepairs = $stmtAll->fetchAll();

$repairsMap = [];
foreach ($allCustomerRepairs as &$rep) {
    $rep['computed_warranty'] = null;
    $paymentVerified = (($rep['payment_status'] ?? '') === 'Verified' || (float)($rep['total_cost'] ?? 0) <= 0);
    
    if (!empty($rep['warranty_end_date']) && $paymentVerified) {
        $today = date('Y-m-d');
        if ($today <= $rep['warranty_end_date']) {
            $rep['computed_warranty'] = 'Under Warranty';
        } elseif (!empty($rep['grace_period_end_date']) && $today <= $rep['grace_period_end_date']) {
            $rep['computed_warranty'] = 'Grace Period';
        } else {
            $rep['computed_warranty'] = 'Expired';
        }
    }

    $rep['formatted_date_requested'] = !empty($rep['date_requested']) ? date('M d, Y • h:i A', strtotime($rep['date_requested'])) : 'N/A';
    $rep['formatted_date_started']   = !empty($rep['date_started']) ? date('M d, Y', strtotime($rep['date_started'])) : 'N/A';
    $rep['formatted_date_completed'] = !empty($rep['date_completed']) ? date('M d, Y', strtotime($rep['date_completed'])) : 'N/A';
    $rep['formatted_scheduled_date'] = !empty($rep['scheduled_date']) ? date('M d, Y', strtotime($rep['scheduled_date'])) : '';
    $rep['formatted_scheduled_time'] = !empty($rep['scheduled_time']) ? date('h:i A', strtotime($rep['scheduled_time'])) : '';
    $rep['formatted_end_time']        = !empty($rep['end_time']) ? date('h:i A', strtotime($rep['end_time'])) : '';
    $rep['formatted_etc']             = !empty($rep['estimated_completion']) ? date('M d, Y', strtotime($rep['estimated_completion'])) : '';
    $rep['formatted_estimated_cost'] = $rep['estimated_cost'] !== null ? number_format((float)$rep['estimated_cost'], 2) : null;
    $rep['formatted_total_cost']     = $rep['total_cost'] !== null ? number_format((float)$rep['total_cost'], 2) : null;
    $rep['appliance_icon']           = getApplianceIcon($rep['appliance_type'] ?? '');
    
    $repairsMap[$rep['repair_id']] = $rep;
}
unset($rep);

$repairs = [];
if ($status && in_array($status, ['Pending', 'Assigned', 'In Progress', 'On Hold', 'Completed', 'Returned', 'Cancelled'])) {
    foreach ($allCustomerRepairs as $r) {
        if ($r['repair_status'] === $status) {
            $repairs[] = $r;
        }
    }
} else {
    $repairs = $allCustomerRepairs;
}

$stmt = $db->prepare("
    SELECT repair_status, COUNT(*) AS cnt
    FROM repairs WHERE customer_id = ?
    GROUP BY repair_status
");
$stmt->execute([$customerId]);
$statusCounts = [];
while ($row = $stmt->fetch()) {
    $statusCounts[$row['repair_status']] = $row['cnt'];
}
$totalCount      = array_sum($statusCounts);
$pendingCount    = $statusCounts['Pending'] ?? 0;
$inProgressCount = $statusCounts['In Progress'] ?? 0;
$completedCount  = $statusCounts['Completed'] ?? 0;

$pageTitle    = 'My Repairs';
$activePage   = 'repairs';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

function statusBadge($s) {
    return match($s) {
        'Pending'     => 'badge-amber',
        'Assigned'    => 'badge-blue',
        'In Progress' => 'badge-teal',
        'Completed','Returned' => 'badge-emerald',
        'Cancelled'   => 'badge-rose',
        default       => 'badge-teal',
    };
}

function statusSlug($s) {
    return strtolower(str_replace(' ', '-', $s));
}
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><i data-lucide="wrench"></i> Your Repair Journey</h1>
            <p class="page-sub">Track every device from drop-off to pick-up.</p>
        </div>
    </div>

    
    <div class="stat-cards">
        <div class="stat-card total">
            <div class="stat-icon"><i data-lucide="layers"></i></div>
            <div class="stat-info">
                <span class="stat-num" data-target="<?= $totalCount ?>"><?= $totalCount ?></span>
                <span class="stat-label">Total Repairs</span>
            </div>
        </div>
        <div class="stat-card pending">
            <div class="stat-icon"><i data-lucide="clock"></i></div>
            <div class="stat-info">
                <span class="stat-num" data-target="<?= $pendingCount ?>"><?= $pendingCount ?></span>
                <span class="stat-label">Pending</span>
            </div>
        </div>
        <div class="stat-card inprogress">
            <div class="stat-icon"><i data-lucide="activity"></i></div>
            <div class="stat-info">
                <span class="stat-num" data-target="<?= $inProgressCount ?>"><?= $inProgressCount ?></span>
                <span class="stat-label">In Progress</span>
            </div>
        </div>
        <div class="stat-card completed">
            <div class="stat-icon"><i data-lucide="check-circle-2"></i></div>
            <div class="stat-info">
                <span class="stat-num" data-target="<?= $completedCount ?>"><?= $completedCount ?></span>
                <span class="stat-label">Completed</span>
            </div>
        </div>
    </div>

    
    <div class="tab-group">
        <button class="tab <?= !$status ? 'active' : '' ?>" data-filter="all">All <span class="badge badge-teal" style="margin-left:4px"><?= $totalCount ?></span></button>
        <button class="tab" data-filter="pending">Pending <?php if($pendingCount): ?><span class="badge badge-amber" style="margin-left:4px"><?= $pendingCount ?></span><?php endif; ?></button>
        <button class="tab" data-filter="assigned">Assigned</button>
        <button class="tab" data-filter="in-progress">In Progress <?php if($inProgressCount): ?><span class="badge badge-teal" style="margin-left:4px"><?= $inProgressCount ?></span><?php endif; ?></button>
        <button class="tab" data-filter="completed">Completed</button>
        <button class="tab" data-filter="returned">Returned</button>
    </div>

    
    <?php if (empty($repairs)): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i data-lucide="clipboard-list"></i></div>
        <p class="empty-state-title">No repairs found</p>
        <p class="empty-state-sub">Submit a new repair request to get started.</p>
    </div>
    <?php else: ?>
    <div class="repairs-grid">
        <?php foreach ($repairs as $rep):
            $slug = statusSlug($rep['repair_status']);
        ?>
        <div class="repair-card status-<?= $slug ?>" data-status="<?= $slug ?>">
            <div class="repair-card-top">
                <div class="repair-id-badge">
                    <i class="fas fa-hashtag"></i>
                    <span><?= str_pad($rep['repair_id'], 5, '0', STR_PAD_LEFT) ?></span>
                </div>
                <div class="repair-status">
                    <span class="badge <?= statusBadge($rep['repair_status']) ?>"><?= sanitize($rep['repair_status']) ?></span>
                </div>
            </div>

            <div class="repair-device-row">
                <div class="repair-device-icon">
                    <i class="fas <?= getApplianceIcon($rep['appliance_type']) ?>"></i>
                </div>
                <div class="repair-device-info">
                    <h3 class="repair-device-name"><?= sanitize($rep['appliance_type']) ?></h3>
                    <div class="repair-device-meta">
                        <span><?= sanitize($rep['brand'] ?? '') ?></span>
                        <?php if ($rep['model']): ?>
                            <span class="model-badge"><?= sanitize($rep['model']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="repair-problem-section">
                <span class="repair-problem-label">Your Reported Issue</span>
                <p class="repair-problem-text"><?= sanitize($rep['problem_description']) ?></p>
            </div>

            <?php if (isset($rep['computed_warranty'])): ?>
            <div class="warranty-status-row">
                <span class="badge <?= $rep['computed_warranty'] === 'Under Warranty' ? 'badge-emerald' : ($rep['computed_warranty'] === 'Grace Period' ? 'badge-amber' : 'badge-rose') ?>" style="width: 100%; justify-content: center; padding: 8px;">
                    <i class="fas fa-shield-alt"></i> <?= $rep['computed_warranty'] ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="repair-card-footer">
                <div class="repair-meta-group">
                    <div class="repair-meta-item">
                        <i class="far fa-calendar-alt"></i>
                        <span>Requested: <?= formatDate($rep['date_requested']) ?></span>
                    </div>
                    <?php if ($rep['technician_name']): ?>
                    <div class="repair-meta-item">
                        <i class="fas fa-user-cog"></i>
                        <span>Tech: <?= sanitize($rep['technician_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($rep['total_cost'] > 0 || $rep['estimated_cost'] > 0): ?>
                    <div class="repair-meta-item" style="color: #10b981; font-weight: 700;">
                        <i class="fas fa-tag"></i>
                        <span>Quote: <?= formatCurrency($rep['total_cost'] ?: $rep['estimated_cost']) ?></span>
                        <?php if (!empty($rep['payment_method']) && ($rep['payment_status'] ?? '') === 'Pending'): ?>
                            <span style="font-size: 11px; color: #f59e0b; margin-left: 6px;">(Payment Pending Verification)</span>
                        <?php elseif (!empty($rep['payment_method']) && ($rep['payment_status'] ?? '') === 'Verified'): ?>
                            <?php if (($rep['payment_type'] ?? '') === 'downpayment' && (float)$rep['amount_paid'] < (float)$rep['total_cost']): ?>
                                <span style="font-size: 11px; color: #0284c7; margin-left: 6px;">(DP Verified - Bal: <?= formatCurrency((float)$rep['total_cost'] - (float)$rep['amount_paid']) ?>)</span>
                            <?php else: ?>
                                <span style="font-size: 11px; color: #10b981; margin-left: 6px;">(Paid & Verified)</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="repair-actions-row">
                    <button type="button" class="btn-sm-ghost" onclick="openRepairModal(<?= $rep['repair_id'] ?>)"><i class="fas fa-eye"></i> Details</button>
                    
                    <?php if ($rep['repair_status'] === 'Pending'): ?>
                        <a href="cancel-request.php?id=<?= $rep['repair_id'] ?>" class="btn-sm-rose" onclick="return confirm('Cancel this repair request?')"><i class="fas fa-times"></i> Cancel</a>
                    <?php endif; ?>

                    <?php 
                    $hasReceipt = (!empty($rep['payment_method']) && in_array($rep['payment_status'] ?? '', ['Verified', 'Pending'])) || ((float)($rep['amount_paid'] ?? 0) > 0) || in_array($rep['repair_status'], ['Completed', 'Returned']);
                    if ($hasReceipt):
                    ?>
                        <a href="receipt.php?id=<?= $rep['repair_id'] ?>" target="_blank" class="btn-sm-ghost" style="color: #0284c7; border-color: #bae6fd; background: #f0f9ff; font-weight: 600; text-decoration: none;" title="Download Official Receipt">
                            <i class="fas fa-file-invoice"></i> Receipt
                        </a>
                    <?php endif; ?>

                    <?php 
                    $repHasPay = ((float)($rep['total_cost'] ?? 0) > 0 || (float)($rep['estimated_cost'] ?? 0) > 0);
                    $repUnpaid = empty($rep['payment_method']) || ($rep['payment_status'] ?? '') === 'Rejected';
                    $repHasBalance = (($rep['payment_type'] ?? '') === 'downpayment' && ($rep['payment_status'] ?? '') === 'Verified' && ((float)$rep['total_cost'] - (float)$rep['amount_paid']) > 0 && in_array($rep['repair_status'], ['Completed', 'Returned']));
                    if ($repHasPay && ($repUnpaid || $repHasBalance) && !in_array($rep['repair_status'], ['Cancelled'])): 
                    ?>
                        <button type="button" class="btn-primary" onclick="openCustomerPayModal(<?= $rep['repair_id'] ?>)" style="padding: 6px 14px; font-size: 12.5px; font-weight: 600; cursor:pointer;">
                            <i class="fas fa-credit-card"></i> <?= $repHasBalance ? 'Pay Balance (' . formatCurrency((float)$rep['total_cost'] - (float)$rep['amount_paid']) . ')' : 'Pay' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</div>

<div id="customerRepairModal" class="crm-overlay" role="dialog" aria-modal="true" style="display:none;">
    <div class="crm-dialog">
        
        <div class="crm-header">
            <div class="crm-header-left">
                <span class="crm-id-badge" id="crmModalId"># 00000</span>
                <h2 class="crm-title" id="crmModalTitle">Appliance Name</h2>
            </div>
            <div class="crm-header-right">
                <span class="crm-status-pill" id="crmModalStatus">Status</span>
                <button type="button" class="crm-close-btn" onclick="closeRepairModal()" title="Close">&times;</button>
            </div>
        </div>

        
        <div class="crm-body">
            
            
            <div class="crm-hero-grid">
                
                <div class="crm-media-box" id="crmMediaBox">
                    <div id="crmImgWrap" class="crm-img-wrap" style="display:none;" onclick="expandRepairPhoto()">
                        <img id="crmModalImg" src="" alt="Repair Device" class="crm-device-img">
                        <div class="crm-img-zoom-tag"><i class="fas fa-search-plus"></i> Click to enlarge</div>
                    </div>
                    <div id="crmNoImgBox" class="crm-no-img-box">
                        <i id="crmFallbackIcon" class="fas fa-tools"></i>
                        <span>No photo attached</span>
                    </div>
                </div>

                
                <div class="crm-specs-box">
                    <div class="crm-spec-item">
                        <span class="crm-spec-label">Brand & Model</span>
                        <span class="crm-spec-val" id="crmModalBrandModel">—</span>
                    </div>
                    <div class="crm-spec-item">
                        <span class="crm-spec-label">Serial Number</span>
                        <span class="crm-spec-val" id="crmModalSerial">—</span>
                    </div>
                    <div class="crm-spec-item">
                        <span class="crm-spec-label">Date Requested</span>
                        <span class="crm-spec-val" id="crmModalDateReq">—</span>
                    </div>
                    <div class="crm-spec-item">
                        <span class="crm-spec-label">Priority Level</span>
                        <span class="crm-spec-val"><span id="crmModalPriority" class="crm-priority-tag crm-priority-normal">Normal</span></span>
                    </div>
                </div>
            </div>

            
            <div class="crm-section-box">
                <div class="crm-sec-head"><i class="fas fa-route"></i> Repair Journey</div>
                <div class="crm-stepper" id="crmStepper">
                    <div class="crm-step" data-step="Pending">
                        <div class="crm-step-circle"><i class="fas fa-file-alt"></i></div>
                        <span class="crm-step-label">Requested</span>
                    </div>
                    <div class="crm-step-line"></div>
                    <div class="crm-step" data-step="Assigned">
                        <div class="crm-step-circle"><i class="fas fa-user-check"></i></div>
                        <span class="crm-step-label">Assigned</span>
                    </div>
                    <div class="crm-step-line"></div>
                    <div class="crm-step" data-step="In Progress">
                        <div class="crm-step-circle"><i class="fas fa-tools"></i></div>
                        <span class="crm-step-label">In Progress</span>
                    </div>
                    <div class="crm-step-line"></div>
                    <div class="crm-step" data-step="Completed">
                        <div class="crm-step-circle"><i class="fas fa-check"></i></div>
                        <span class="crm-step-label">Completed</span>
                    </div>
                    <div class="crm-step-line"></div>
                    <div class="crm-step" data-step="Returned">
                        <div class="crm-step-circle"><i class="fas fa-box-check"></i></div>
                        <span class="crm-step-label">Returned</span>
                    </div>
                </div>
                <div id="crmStatusNotice" style="display:none;" class="crm-status-notice"></div>
            </div>

            
            <div class="crm-two-col">
                <div class="crm-card-detail">
                    <div class="crm-sec-head"><i class="fas fa-comment-dots text-amber"></i> Reported Issue</div>
                    <p class="crm-issue-text" id="crmModalProblem">—</p>
                </div>
                <div class="crm-card-detail" id="crmDiagnosisBox">
                    <div class="crm-sec-head"><i class="fas fa-stethoscope text-teal"></i> Diagnosis & Notes</div>
                    <p class="crm-issue-text" id="crmModalDiagnosis">No diagnosis entered yet.</p>
                </div>
            </div>

            
            <div class="crm-section-box">
                <div class="crm-sec-head"><i class="fas fa-user-cog" style="color:#4f46e5;"></i> Service & Technician Information</div>
                <div id="crmTechContent" class="crm-tech-info-row">
                    
                </div>
            </div>

            
            <div class="crm-section-box" id="crmBillingBox">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                    <div class="crm-sec-head" style="margin-bottom:0;"><i class="fas fa-receipt" style="color:#10b981;"></i> Billing & Warranty Overview</div>
                    <div id="crmReceiptLinkBox"></div>
                </div>
                <div class="crm-billing-grid">
                    <div class="crm-billing-col">
                        <span class="crm-bill-sub">Downpayment</span>
                        <span class="crm-bill-amount" id="crmModalEstCost">—</span>
                    </div>
                    <div class="crm-billing-col">
                        <span class="crm-bill-sub">Total Cost</span>
                        <span class="crm-bill-amount crm-total-amount" id="crmModalTotalCost">—</span>
                    </div>
                    <div class="crm-billing-col">
                        <span class="crm-bill-sub">Payment Status</span>
                        <span id="crmModalPaymentStatus" class="crm-pay-status">—</span>
                    </div>
                </div>
                <div id="crmWarrantyBadgeBox" style="display:none; margin-top:12px;"></div>
            </div>

        </div>

        
        <div class="crm-footer">
            <div id="crmFooterActions" class="crm-footer-actions">
                
            </div>
            <button type="button" class="crm-btn crm-btn-secondary" onclick="closeRepairModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<div id="customerPayModal" class="cpm-overlay" role="dialog" aria-modal="true" style="display:none;">
    <div class="cpm-dialog">
        
        <div class="cpm-header">
            <div class="cpm-header-left">
                <span class="cpm-badge" id="cpmModalId"># 00000</span>
                <div>
                    <h2 class="cpm-title">Pay for Repair</h2>
                    <p class="cpm-sub" id="cpmModalDevice">Appliance Details</p>
                </div>
            </div>
            <button type="button" class="cpm-close-btn" onclick="closeCustomerPayModal()" title="Close">&times;</button>
        </div>

        <form id="cpmPaymentForm" enctype="multipart/form-data" onsubmit="event.preventDefault(); submitCustomerPay();">
            <?= csrfField() ?>
            <input type="hidden" name="repair_id" id="cpmRepairId" value="">
            <input type="hidden" name="payment_type" id="cpmPaymentType" value="downpayment">
            <input type="hidden" name="receipt_validation_token" id="cpmReceiptValidationToken" value="">

            
            <div class="cpm-body-grid">
                
                <div class="cpm-col-left">
                    
                    <div class="cpm-section">
                        <label class="cpm-sec-title"><i class="fas fa-layer-group" style="color:#2563eb;"></i> 1. Choose Payment Option</label>
                        <div class="cpm-plan-list">
                            
                            <div class="cpm-plan-card active" id="cpmCardDp" onclick="selectCpmType('downpayment')">
                                <div class="cpm-radio-indicator"><i class="fas fa-check"></i></div>
                                <div class="cpm-plan-details">
                                    <div class="cpm-plan-row">
                                        <span class="cpm-plan-name">Downpayment</span>
                                        <span class="cpm-plan-badge badge-dp">Deposit (50%)</span>
                                    </div>
                                    <div class="cpm-plan-cost" id="cpmDpCost">₱0.00</div>
                                </div>
                            </div>

                            
                            <div class="cpm-plan-card" id="cpmCardFull" onclick="selectCpmType('full')">
                                <div class="cpm-radio-indicator"><i class="fas fa-check"></i></div>
                                <div class="cpm-plan-details">
                                    <div class="cpm-plan-row">
                                        <span class="cpm-plan-name">Full Payment</span>
                                        <span class="cpm-plan-badge badge-full">Full Settle</span>
                                    </div>
                                    <div class="cpm-plan-cost" id="cpmFullCost">₱0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    
                    <div class="cpm-section">
                        <label class="cpm-sec-title"><i class="fas fa-wallet" style="color:#10b981;"></i> 2. Select Payment Method</label>
                        <div class="cpm-methods-grid">
                            <label class="cpm-method-card active" id="cpmMethodCardGcash">
                                <input type="radio" name="payment_method" value="GCash" checked onchange="selectCpmMethod('GCash')">
                                <div class="cpm-method-icon gcash-icon">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div class="cpm-method-info">
                                    <div class="cpm-method-title">GCash</div>
                                    <div class="cpm-method-sub">Scan QR Code</div>
                                </div>
                            </label>

                            <label class="cpm-method-card" id="cpmMethodCardCash">
                                <input type="radio" name="payment_method" value="Cash" onchange="selectCpmMethod('Cash')">
                                <div class="cpm-method-icon cash-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="cpm-method-info">
                                    <div class="cpm-method-title">Cash</div>
                                    <div class="cpm-method-sub">Pay at counter</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    
                    <div class="cpm-summary-bar">
                        <span class="cpm-summary-sub" id="cpmSummaryLabel">Amount to Pay</span>
                        <span class="cpm-summary-val" id="cpmSummaryAmount">₱0.00</span>
                    </div>
                </div>

                
                <div class="cpm-col-right">
                    <div class="cpm-qr-panel">
                        <div class="cpm-qr-header">
                            <div class="cpm-qr-title" id="cpmQrTitle">Scan to Pay (GCash)</div>
                            <p class="cpm-qr-subtitle" id="cpmQrSubtitle">Open GCash and scan QR code</p>
                        </div>

                        <div class="cpm-qr-wrapper">
                            <div id="cpmQrContainer"></div>
                        </div>

                        
                        <div id="cpmGcashDetails" class="cpm-details-box">
                            <div class="cpm-detail-row">
                                <span class="cpm-detail-key">Name:</span>
                                <span class="cpm-detail-val"><?= getSetting('gcash_name', 'QuickFix Repair Services') ?></span>
                            </div>
                            <div class="cpm-detail-row">
                                <span class="cpm-detail-key">Number:</span>
                                <span class="cpm-detail-val"><?= getSetting('gcash_no', '0917-888-9999') ?></span>
                            </div>
                        </div>

                        
                        <div id="cpmGcashProofSection" class="cpm-proof-box">
                            <label class="cpm-proof-label">
                                <span><i class="fas fa-upload" style="color:#0ea5e9;"></i> Proof of Payment</span>
                                <span class="cpm-proof-optional">Required for manual GCash</span>
                            </label>
                            
                            <div class="cpm-upload-zone" id="cpmUploadZone" onclick="document.getElementById('cpmPaymentProof').click()">
                                <input type="file" name="payment_proof" id="cpmPaymentProof" accept="image/png, image/jpeg, image/jpg, image/webp" style="display:none;" onchange="handleCpmProofSelect(event)">
                                <div id="cpmUploadPlaceholder" class="cpm-upload-placeholder">
                                    <i class="fas fa-cloud-upload-alt" style="font-size: 18px; color: #2563eb; margin-bottom: 2px;"></i>
                                    <div style="font-size: 11px; font-weight: 700; color: #1e293b;">Click to upload receipt</div>
                                    <div style="font-size: 9.5px; color: #64748b;">PNG, JPG, WEBP (Max 10MB)</div>
                                </div>
                                <div id="cpmUploadPreview" class="cpm-upload-preview" style="display:none;">
                                    <img id="cpmProofImg" src="" alt="Proof Preview">
                                    <div class="cpm-upload-preview-overlay">
                                        <span id="cpmProofFileName" style="font-size: 10px; font-weight: 600; color: #ffffff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 140px;"></span>
                                        <button type="button" class="cpm-remove-proof-btn" onclick="removeCpmProof(event)" title="Remove image">&times;</button>
                                    </div>
                                </div>
                            </div>
                            <div id="cpmProofScanStatus" style="display:none;margin-top:7px;padding:7px 9px;border-radius:6px;font-size:10.5px;font-weight:600;"></div>

                        </div>

                        
                        <div id="cpmCashDetails" class="cpm-details-box" style="display:none;">
                            <div class="cpm-cash-info">
                                <i class="fas fa-store-alt" style="color:#0ea5e9;"></i>
                                <span>Show this reference QR code to cashier upon dropoff or pickup.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="cpm-footer">
                <button type="button" class="crm-btn crm-btn-secondary" onclick="closeCustomerPayModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="crm-btn cpm-btn-submit" id="cpmSubmitBtn" onclick="submitCustomerPay()" disabled style="opacity:.55;cursor:not-allowed;">
                    <i class="fas fa-check-circle"></i> Confirm & Submit Payment
                </button>
            </div>
        </form>
    </div>
</div>

<div id="customerImgLightbox" class="crm-lightbox" onclick="closeRepairPhoto()">
    <button type="button" class="crm-lightbox-close" onclick="closeRepairPhoto()">&times;</button>
    <img id="crmLightboxImg" src="" alt="Enlarged device photo">
</div>

<style>

.crm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.crm-overlay.open {
    display: flex !important;
}
.crm-dialog {
    background: #ffffff;
    border-radius: 18px;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
    animation: crmSlideUp 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes crmSlideUp {
    from { opacity: 0; transform: translateY(24px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.crm-header {
    background: #0f172a;
    color: #ffffff;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.crm-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.crm-id-badge {
    background: rgba(255, 255, 255, 0.12);
    color: #38bdf8;
    font-family: monospace;
    font-weight: 700;
    font-size: 13px;
    padding: 4px 10px;
    border-radius: 6px;
    letter-spacing: 0.5px;
    border: 1px solid rgba(56, 189, 248, 0.25);
    white-space: nowrap;
}
.crm-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    font-family: 'Syne', sans-serif;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.crm-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.crm-status-pill {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}
.crm-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #94a3b8;
    font-size: 22px;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: all 0.15s;
}
.crm-close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}
.crm-body {
    padding: 22px 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.crm-body::-webkit-scrollbar {
    width: 5px;
}
.crm-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.crm-hero-grid {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px;
}
@media (max-width: 540px) {
    .crm-hero-grid { grid-template-columns: 1fr; }
}
.crm-media-box {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 140px;
}
.crm-img-wrap {
    position: relative;
    width: 100%;
    height: 145px;
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    background: #0f172a;
}
.crm-device-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.25s ease;
}
.crm-img-wrap:hover .crm-device-img {
    transform: scale(1.05);
}
.crm-img-zoom-tag {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(15, 23, 42, 0.75);
    color: #fff;
    font-size: 11px;
    padding: 4px 8px;
    text-align: center;
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}
.crm-no-img-box {
    width: 100%;
    height: 145px;
    background: #f1f5f9;
    border: 1.5px dashed #cbd5e1;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #94a3b8;
}
.crm-no-img-box i {
    font-size: 38px;
    color: #64748b;
}
.crm-no-img-box span {
    font-size: 12px;
    font-weight: 500;
}
.crm-specs-box {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    align-content: center;
}
.crm-spec-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.crm-spec-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #64748b;
}
.crm-spec-val {
    font-size: 13.5px;
    font-weight: 600;
    color: #0f172a;
}
.crm-priority-tag {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}
.crm-priority-normal { background: #e0f2fe; color: #0369a1; }
.crm-priority-high { background: #fef3c7; color: #b45309; }
.crm-priority-urgent { background: #fee2e2; color: #b91c1c; }

.crm-section-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
}
.crm-sec-head {
    font-size: 12.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.crm-sec-head i {
    font-size: 14px;
}

.crm-stepper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
}
.crm-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    min-width: 55px;
    position: relative;
    z-index: 1;
}
.crm-step-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #f1f5f9;
    border: 2px solid #cbd5e1;
    color: #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.2s;
}
.crm-step-label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    white-space: nowrap;
}
.crm-step-line {
    flex: 1;
    height: 3px;
    background: #e2e8f0;
    margin: 0 4px;
    margin-bottom: 22px;
    transition: background 0.2s;
}
.crm-step.done .crm-step-circle {
    background: #10b981;
    border-color: #10b981;
    color: #ffffff;
}
.crm-step.done + .crm-step-line {
    background: #10b981;
}
.crm-step.active .crm-step-circle {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #ffffff;
    box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.2);
}
.crm-step.active .crm-step-label {
    color: #4f46e5;
    font-weight: 700;
}
.crm-status-notice {
    margin-top: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.crm-notice-cancelled { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.crm-notice-returned { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

.crm-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 600px) {
    .crm-two-col { grid-template-columns: 1fr; }
}
.crm-card-detail {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
}
.crm-issue-text {
    margin: 0;
    font-size: 13px;
    color: #334155;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
}

.crm-tech-card-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}
.crm-tech-profile {
    display: flex;
    align-items: center;
    gap: 12px;
}
.crm-tech-avatar-circle {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4f46e5, #3b82f6);
    color: #ffffff;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.crm-tech-name-box {
    display: flex;
    flex-direction: column;
}
.crm-tech-name {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
}
.crm-tech-spec {
    font-size: 12px;
    color: #64748b;
}
.crm-tech-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.crm-schedule-tag {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 12px;
    color: #334155;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}
.crm-phone-btn {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.15s;
}
.crm-phone-btn:hover {
    background: #dbeafe;
    color: #1e40af;
}

.crm-billing-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
@media (max-width: 500px) {
    .crm-billing-grid { grid-template-columns: 1fr; }
}
.crm-billing-col {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.crm-bill-sub {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
}
.crm-bill-amount {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
}
.crm-total-amount {
    color: #10b981;
}
.crm-pay-status {
    font-size: 13px;
    font-weight: 700;
}

.crm-footer {
    padding: 14px 24px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.crm-footer-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.crm-btn {
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}
.crm-btn-secondary {
    background: #ffffff;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.crm-btn-secondary:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.crm-btn-pay {
    background: #10b981;
    color: #ffffff;
}
.crm-btn-pay:hover {
    background: #059669;
}
.crm-btn-danger {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.crm-btn-danger:hover {
    background: #fecaca;
}

.crm-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.88);
    backdrop-filter: blur(8px);
    z-index: 100000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    cursor: zoom-out;
}
.crm-lightbox.open {
    display: flex !important;
}
.crm-lightbox img {
    max-width: 90vw;
    max-height: 85vh;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    object-fit: contain;
    animation: crmZoom 0.25s ease;
}
@keyframes crmZoom {
    from { transform: scale(0.9); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.crm-lightbox-close {
    position: absolute;
    top: 20px;
    right: 24px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: #ffffff;
    font-size: 32px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.cpm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 12px;
}
.cpm-overlay.open {
    display: flex !important;
}
.cpm-dialog {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 670px;
    max-height: calc(100vh - 24px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
    animation: cpmSlideUp 0.24s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes cpmSlideUp {
    from { opacity: 0; transform: translateY(16px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.cpm-header {
    background: #0f172a;
    color: #ffffff;
    padding: 12px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-shrink: 0;
}
.cpm-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.cpm-badge {
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    font-family: monospace;
    font-weight: 700;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 5px;
    border: 1px solid rgba(56, 189, 248, 0.3);
    white-space: nowrap;
}
.cpm-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    font-family: 'Syne', sans-serif;
    color: #ffffff;
    line-height: 1.2;
}
.cpm-sub {
    margin: 1px 0 0;
    font-size: 11.5px;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cpm-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #94a3b8;
    font-size: 20px;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: all 0.15s;
}
.cpm-close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

.cpm-body-grid {
    padding: 14px 18px;
    overflow-y: auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    align-items: start;
}
.cpm-body-grid::-webkit-scrollbar {
    width: 4px;
}
.cpm-body-grid::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}
@media (max-width: 600px) {
    .cpm-body-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 12px 14px;
    }
}
.cpm-col-left {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.cpm-col-right {
    display: flex;
    flex-direction: column;
    height: 100%;
}
.cpm-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cpm-sec-title {
    font-size: 11.5px;
    font-weight: 700;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.cpm-plan-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cpm-plan-card {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    border-radius: 10px;
    padding: 9px 12px;
    cursor: pointer;
    transition: all 0.18s ease;
    user-select: none;
}
.cpm-plan-card:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.cpm-plan-card.active {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 0 0 1px #2563eb;
}
.cpm-radio-indicator {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    color: transparent;
    transition: all 0.18s;
    flex-shrink: 0;
}
.cpm-plan-card.active .cpm-radio-indicator {
    border-color: #2563eb;
    background: #2563eb;
    color: #ffffff;
}
.cpm-plan-details {
    flex: 1;
    min-width: 0;
}
.cpm-plan-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.cpm-plan-name {
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
}
.cpm-plan-badge {
    font-size: 9px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 9999px;
}
.badge-dp { background: #dcfce7; color: #166534; }
.badge-full { background: #dbeafe; color: #1e40af; }
.cpm-plan-cost {
    font-size: 15px;
    font-weight: 800;
    font-family: 'Syne', sans-serif;
    color: #2563eb;
    margin-top: 2px;
    letter-spacing: -0.3px;
}
.cpm-plan-card#cpmCardDp .cpm-plan-cost {
    color: #10b981;
}

.cpm-methods-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.cpm-method-card {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.18s;
    user-select: none;
}
.cpm-method-card input[type="radio"] {
    display: none;
}
.cpm-method-card:hover {
    border-color: #cbd5e1;
}
.cpm-method-card.active {
    border-color: #2563eb;
    background: #eff6ff;
}
.cpm-method-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.gcash-icon { background: #dbeafe; color: #2563eb; }
.cash-icon { background: #dcfce7; color: #16a34a; }
.cpm-method-info {
    min-width: 0;
}
.cpm-method-title {
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
}
.cpm-method-sub {
    font-size: 10px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cpm-summary-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #ffffff;
    border-radius: 10px;
    padding: 9px 12px;
    box-shadow: 0 3px 8px rgba(37, 99, 235, 0.16);
}
.cpm-summary-sub {
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 700;
    opacity: 0.9;
    letter-spacing: 0.3px;
}
.cpm-summary-val {
    font-size: 17px;
    font-weight: 800;
    font-family: 'Syne', sans-serif;
    letter-spacing: -0.3px;
}

.cpm-qr-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    text-align: center;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.cpm-qr-header {
    margin-bottom: 6px;
}
.cpm-qr-title {
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
}
.cpm-qr-subtitle {
    font-size: 10.5px;
    color: #64748b;
    margin: 1px 0 0;
}
.cpm-qr-wrapper {
    width: 136px;
    height: 136px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 10px;
    padding: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
}
.cpm-details-box {
    margin-top: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 11px;
}
.cpm-detail-row {
    display: flex;
    justify-content: space-between;
    padding: 1.5px 0;
}
.cpm-detail-key {
    color: #64748b;
}
.cpm-detail-val {
    font-weight: 700;
    color: #0f172a;
}
.cpm-cash-info {
    display: flex;
    align-items: center;
    gap: 8px;
    text-align: left;
    color: #334155;
    font-size: 11px;
    line-height: 1.35;
}
.cpm-proof-box {
    margin-top: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    text-align: left;
}
.cpm-proof-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}
.cpm-proof-optional {
    font-size: 10px;
    font-weight: normal;
    color: #64748b;
}
.cpm-upload-zone {
    border: 1.5px dashed #cbd5e1;
    border-radius: 6px;
    background: #f8fafc;
    padding: 7px 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}
.cpm-upload-zone:hover {
    border-color: #2563eb;
    background: #eff6ff;
}
.cpm-upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.cpm-upload-preview {
    position: relative;
    width: 100%;
    height: 75px;
    border-radius: 5px;
    overflow: hidden;
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
}
.cpm-upload-preview img {
    max-height: 100%;
    max-width: 100%;
    object-fit: contain;
}
.cpm-upload-preview-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(15, 23, 42, 0.75);
    padding: 2px 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.cpm-remove-proof-btn {
    background: #ef4444;
    color: #ffffff;
    border: none;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    cursor: pointer;
    line-height: 1;
}
.cpm-ref-input {
    width: 100%;
    padding: 5px 28px 5px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 11px;
    color: #0f172a;
    background: #ffffff;
    transition: border-color 0.2s ease;
    box-sizing: border-box;
}
.cpm-ref-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
}
.cpm-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 18px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    flex-shrink: 0;
}
.cpm-btn-submit {
    background: #2563eb;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 8px 18px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.18s;
}
.cpm-btn-submit:hover {
    background: #1d4ed8;
}
.cpm-btn-submit:disabled {
    opacity: 0.65;
}
</style>

<script>
const customerRepairsMap = <?= json_encode($repairsMap) ?>;
let activePhotoUrl = '';

function openRepairModal(repairId) {
    const rep = customerRepairsMap[repairId];
    if (!rep) return;

    
    document.getElementById('crmModalId').textContent = '#' + String(rep.repair_id).padStart(5, '0');
    document.getElementById('crmModalTitle').textContent = rep.appliance_type || 'Repair Item';
    
    
    const statusEl = document.getElementById('crmModalStatus');
    statusEl.textContent = rep.repair_status;
    statusEl.className = 'crm-status-pill badge ' + getStatusBadgeClass(rep.repair_status);

    
    const imgWrap = document.getElementById('crmImgWrap');
    const noImgBox = document.getElementById('crmNoImgBox');
    const modalImg = document.getElementById('crmModalImg');
    const fallbackIcon = document.getElementById('crmFallbackIcon');

    if (rep.appliance_image && rep.appliance_image.trim() !== '') {
        const fullImgPath = rep.appliance_image.startsWith('http') ? rep.appliance_image : (SITE_URL + '/' + rep.appliance_image.replace(/^\/+/, ''));
        modalImg.src = fullImgPath;
        activePhotoUrl = fullImgPath;
        imgWrap.style.display = 'block';
        noImgBox.style.display = 'none';
    } else {
        activePhotoUrl = '';
        imgWrap.style.display = 'none';
        noImgBox.style.display = 'flex';
        fallbackIcon.className = 'fas ' + (rep.appliance_icon || 'fa-tools');
    }

    
    const brandModel = [rep.brand, rep.model].filter(Boolean).join(' ') || 'Not specified';
    document.getElementById('crmModalBrandModel').textContent = brandModel;
    document.getElementById('crmModalSerial').textContent = rep.serial_number || 'N/A';
    document.getElementById('crmModalDateReq').textContent = rep.formatted_date_requested || 'N/A';
    
    
    const prioEl = document.getElementById('crmModalPriority');
    const priority = rep.priority || 'Normal';
    prioEl.textContent = priority;
    prioEl.className = 'crm-priority-tag crm-priority-' + priority.toLowerCase();

    
    updateModalStepper(rep.repair_status);

    
    document.getElementById('crmModalProblem').textContent = rep.problem_description || 'No description provided.';

    
    const diagText = [rep.diagnosis, rep.repair_notes].filter(Boolean).join('\n\n');
    document.getElementById('crmModalDiagnosis').textContent = diagText || 'No diagnosis or technician notes recorded yet.';

    
    const techBox = document.getElementById('crmTechContent');
    if (rep.technician_name) {
        const initials = rep.technician_name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        let schedHtml = '';
        if (rep.formatted_scheduled_date) {
            schedHtml += `<span class="crm-schedule-tag"><i class="fas fa-calendar-day" style="color:#4f46e5;"></i> ${rep.formatted_scheduled_date}</span>`;
            if (rep.formatted_scheduled_time) {
                schedHtml += `<span class="crm-schedule-tag"><i class="far fa-clock" style="color:#0ea5e9;"></i> ${rep.formatted_scheduled_time}${rep.formatted_end_time ? ' - ' + rep.formatted_end_time : ''}</span>`;
            }
        }
        if (rep.formatted_etc) {
            schedHtml += `<span class="crm-schedule-tag"><i class="fas fa-flag-checkered" style="color:#10b981;"></i> Est. Completion: ${rep.formatted_etc}</span>`;
        }

        let phoneHtml = '';
        if (rep.tech_phone) {
            phoneHtml = `<a href="tel:${rep.tech_phone}" class="crm-phone-btn"><i class="fas fa-phone-alt"></i> Call ${rep.tech_phone}</a>`;
        }

        techBox.innerHTML = `
            <div class="crm-tech-card-inner">
                <div class="crm-tech-profile">
                    <div class="crm-tech-avatar-circle">${initials}</div>
                    <div class="crm-tech-name-box">
                        <span class="crm-tech-name">${rep.technician_name}</span>
                        <span class="crm-tech-spec">${rep.tech_specialization || 'Assigned Technician'}</span>
                    </div>
                </div>
                <div class="crm-tech-meta">
                    ${phoneHtml}
                    ${schedHtml}
                </div>
            </div>
        `;
    } else {
        techBox.innerHTML = `
            <div style="display:flex; align-items:center; gap:10px; color:#64748b; font-size:13px; padding:6px 0;">
                <i class="fas fa-info-circle" style="color:#0ea5e9; font-size:16px;"></i>
                <span>Your repair request is received. A certified technician will be assigned shortly.</span>
            </div>
        `;
    }

    
    const hasDp = rep.estimated_cost && parseFloat(rep.estimated_cost) > 0;
    document.getElementById('crmModalEstCost').textContent = hasDp ? '₱' + rep.formatted_estimated_cost : 'Not Required';
    document.getElementById('crmModalTotalCost').textContent = rep.formatted_total_cost ? '₱' + rep.formatted_total_cost : '—';
    
    const payStatusEl = document.getElementById('crmModalPaymentStatus');
    let payStatus = rep.payment_status || (rep.total_cost > 0 ? 'Unpaid' : 'Free / Not Billed');
    if (rep.payment_status === 'Verified' && rep.payment_type === 'downpayment' && parseFloat(rep.amount_paid || 0) < parseFloat(rep.total_cost || 0)) {
        payStatus = 'DP Verified (Bal: ₱' + (parseFloat(rep.total_cost) - parseFloat(rep.amount_paid)).toFixed(2) + ')';
    } else if (rep.payment_status === 'Pending' && rep.payment_type === 'downpayment') {
        payStatus = 'DP Pending';
    } else if (rep.payment_status === 'Pending' && rep.payment_type === 'balance') {
        payStatus = 'Balance Pending';
    }
    payStatusEl.textContent = payStatus;
    if (rep.payment_status === 'Verified') {
        payStatusEl.style.color = '#10b981';
    } else if (rep.payment_status === 'Pending') {
        payStatusEl.style.color = '#f59e0b';
    } else {
        payStatusEl.style.color = '#64748b';
    }

    
    const receiptBox = document.getElementById('crmReceiptLinkBox');
    const hasReceipt = (rep.payment_method && ['Verified', 'Pending'].includes(rep.payment_status)) || (parseFloat(rep.amount_paid || 0) > 0) || ['Completed', 'Returned'].includes(rep.repair_status);
    if (receiptBox) {
        if (hasReceipt) {
            receiptBox.innerHTML = `
                <a href="receipt.php?id=${rep.repair_id}" target="_blank" style="font-size:12px; font-weight:700; color:#0284c7; text-decoration:none; display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; background:#eff6ff; border:1px solid #bfdbfe; transition:all .2s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                    <i class="fas fa-file-invoice"></i> Official Receipt
                </a>
            `;
        } else {
            receiptBox.innerHTML = '';
        }
    }

    
    const warrantyBox = document.getElementById('crmWarrantyBadgeBox');
    if (rep.computed_warranty) {
        warrantyBox.style.display = 'block';
        const wClass = rep.computed_warranty === 'Under Warranty' ? 'badge-emerald' : (rep.computed_warranty === 'Grace Period' ? 'badge-amber' : 'badge-rose');
        warrantyBox.innerHTML = `
            <span class="badge ${wClass}" style="padding:6px 12px; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-shield-alt"></i> ${rep.computed_warranty}${rep.warranty_days_left ? ' (' + rep.warranty_days_left + ' days left)' : ''}
            </span>
        `;
    } else {
        warrantyBox.style.display = 'none';
    }

    
    const footerActions = document.getElementById('crmFooterActions');
    footerActions.innerHTML = '';

    if (rep.repair_status === 'Pending') {
        footerActions.innerHTML += `
            <a href="cancel-request.php?id=${rep.repair_id}" class="crm-btn crm-btn-danger" onclick="return confirm('Cancel this repair request?')">
                <i class="fas fa-times"></i> Cancel Request
            </a>
        `;
    }

    const hasPayAmt = (parseFloat(rep.total_cost || 0) > 0 || parseFloat(rep.estimated_cost || 0) > 0);
    const isUnpaid = !rep.payment_method || rep.payment_status === 'Rejected';
    const hasBalance = (rep.payment_type === 'downpayment' && rep.payment_status === 'Verified' && (parseFloat(rep.total_cost || 0) - parseFloat(rep.amount_paid || 0)) > 0 && ['Completed', 'Returned'].includes(rep.repair_status));

    if (hasPayAmt && (isUnpaid || hasBalance) && rep.repair_status !== 'Cancelled') {
        const payBtnText = hasBalance ? ('Pay Balance (₱' + (parseFloat(rep.total_cost) - parseFloat(rep.amount_paid)).toFixed(2) + ')') : 'Pay';
        footerActions.innerHTML += `
            <button type="button" class="crm-btn crm-btn-pay" onclick="closeRepairModal(); openCustomerPayModal(${rep.repair_id})">
                <i class="fas fa-credit-card"></i> ${payBtnText}
            </button>
        `;
    }

    if (hasReceipt) {
        footerActions.innerHTML += `
            <a href="receipt.php?id=${rep.repair_id}" target="_blank" class="crm-btn crm-btn-secondary" style="color:#0284c7; border-color:#bae6fd; background:#f0f9ff; text-decoration:none;">
                <i class="fas fa-file-invoice"></i> Download Receipt
            </a>
        `;
    }

    
    document.getElementById('customerRepairModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeRepairModal() {
    const modal = document.getElementById('customerRepairModal');
    if (modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }
}

function expandRepairPhoto() {
    if (!activePhotoUrl) return;
    const lightbox = document.getElementById('customerImgLightbox');
    const lightboxImg = document.getElementById('crmLightboxImg');
    lightboxImg.src = activePhotoUrl;
    lightbox.classList.add('open');
}

function closeRepairPhoto() {
    const lightbox = document.getElementById('customerImgLightbox');
    if (lightbox) lightbox.classList.remove('open');
}

function getStatusBadgeClass(status) {
    switch (status) {
        case 'Pending': return 'badge-amber';
        case 'Assigned': return 'badge-blue';
        case 'In Progress': return 'badge-teal';
        case 'Completed':
        case 'Returned': return 'badge-emerald';
        case 'Cancelled': return 'badge-rose';
        default: return 'badge-teal';
    }
}

function updateModalStepper(status) {
    const steps = ['Pending', 'Assigned', 'In Progress', 'Completed', 'Returned'];
    const noticeEl = document.getElementById('crmStatusNotice');
    noticeEl.style.display = 'none';

    let currentIndex = steps.indexOf(status);
    if (status === 'On Hold') currentIndex = 2;
    if (status === 'Cancelled') currentIndex = -1;

    document.querySelectorAll('#crmStepper .crm-step').forEach((el, idx) => {
        el.classList.remove('done', 'active');
        if (currentIndex !== -1) {
            if (idx < currentIndex) el.classList.add('done');
            else if (idx === currentIndex) el.classList.add('active');
        }
    });

    if (status === 'Cancelled') {
        noticeEl.style.display = 'flex';
        noticeEl.className = 'crm-status-notice crm-notice-cancelled';
        noticeEl.innerHTML = '<i class="fas fa-times-circle"></i> This repair request has been cancelled.';
    } else if (status === 'Returned') {
        noticeEl.style.display = 'flex';
        noticeEl.className = 'crm-status-notice crm-notice-returned';
        noticeEl.innerHTML = '<i class="fas fa-check-circle"></i> This repair item has been completed and returned.';
    }
}

let cpmCurrentRepair = null;
let cpmCurrentType = 'downpayment';
let cpmCurrentMethod = 'GCash';
let cpmDpAmount = 0;
let cpmFullAmount = 0;

function openCustomerPayModal(repairId) {
    const rep = customerRepairsMap[repairId];
    if (!rep) return;
    cpmCurrentRepair = rep;

    
    document.getElementById('cpmModalId').textContent = '#' + String(rep.repair_id).padStart(5, '0');
    const deviceName = [rep.brand, rep.appliance_type, rep.model ? '(' + rep.model + ')' : ''].filter(Boolean).join(' ');
    document.getElementById('cpmModalDevice').textContent = deviceName || 'Repair Item';
    document.getElementById('cpmRepairId').value = rep.repair_id;

    
    const total = parseFloat(rep.total_cost || 0);
    const paid = parseFloat(rep.amount_paid || 0);
    const isDpPaid = (rep.payment_type === 'downpayment' && paid > 0 && rep.payment_status === 'Verified');
    const balance = isDpPaid ? Math.max(0, total - paid) : total;

    let dp = parseFloat(rep.estimated_cost || 0);
    let full = total;
    if (full <= 0 && dp > 0) full = dp;
    if (dp <= 0 && full > 0) dp = Math.round(full * 0.5 * 100) / 100;
    if (dp >= full && full > 0) dp = Math.round(full * 0.5 * 100) / 100;

    const cardDp = document.getElementById('cpmCardDp');
    const cardFull = document.getElementById('cpmCardFull');
    const fullPlanName = cardFull.querySelector('.cpm-plan-name');

    if (isDpPaid && balance > 0) {
        cpmDpAmount = paid;
        cpmFullAmount = balance;
        cardDp.style.display = 'none';
        fullPlanName.textContent = 'Remaining Balance';
        document.getElementById('cpmFullCost').textContent = '₱' + cpmFullAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        selectCpmType('full');
    } else {
        cardDp.style.display = 'block';
        fullPlanName.textContent = 'Full Payment';
        cpmDpAmount = dp > 0 ? dp : full;
        cpmFullAmount = full > 0 ? full : dp;
        document.getElementById('cpmDpCost').textContent = '₱' + cpmDpAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('cpmFullCost').textContent = '₱' + cpmFullAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const defaultType = (rep.repair_status === 'Completed' || cpmDpAmount <= 0) ? 'full' : 'downpayment';
        selectCpmType(defaultType);
    }

    
    removeCpmProof();

    
    selectCpmMethod('GCash');

    
    document.getElementById('customerPayModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCustomerPayModal() {
    const modal = document.getElementById('customerPayModal');
    if (modal) {
        modal.classList.remove('open');
        const crmModal = document.getElementById('customerRepairModal');
        if (!crmModal || !crmModal.classList.contains('open')) {
            document.body.style.overflow = '';
        }
    }
}

function selectCpmType(type) {
    const paymentTypeChanged = cpmCurrentType !== type;
    cpmCurrentType = type;
    document.getElementById('cpmPaymentType').value = type;

    const cardDp = document.getElementById('cpmCardDp');
    const cardFull = document.getElementById('cpmCardFull');
    const summaryLabel = document.getElementById('cpmSummaryLabel');
    const summaryAmount = document.getElementById('cpmSummaryAmount');

    const amount = (type === 'downpayment') ? cpmDpAmount : cpmFullAmount;
    const formattedAmt = '₱' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (type === 'downpayment') {
        cardDp.classList.add('active');
        cardFull.classList.remove('active');
        summaryLabel.textContent = 'Downpayment to Pay';
    } else if (type === 'balance') {
        cardFull.classList.add('active');
        cardDp.classList.remove('active');
        summaryLabel.textContent = 'Remaining Balance to Pay';
    } else {
        cardFull.classList.add('active');
        cardDp.classList.remove('active');
        summaryLabel.textContent = 'Full Amount to Pay';
    }

    summaryAmount.textContent = formattedAmt;
    renderCpmQr();

    
    const proofInput = document.getElementById('cpmPaymentProof');
    if (paymentTypeChanged && proofInput?.files?.length) {
        removeCpmProof();
        setCpmProofScanStatus('invalid', 'Payment amount changed. Upload the matching GCash receipt.');
    }
}

function selectCpmMethod(method) {
    cpmCurrentMethod = method;

    const gcashCard = document.getElementById('cpmMethodCardGcash');
    const cashCard = document.getElementById('cpmMethodCardCash');
    const radioGcash = gcashCard.querySelector('input[type="radio"]');
    const radioCash = cashCard.querySelector('input[type="radio"]');
    const gcashBox = document.getElementById('cpmGcashDetails');
    const cashBox = document.getElementById('cpmCashDetails');
    const proofSection = document.getElementById('cpmGcashProofSection');
    const qrTitle = document.getElementById('cpmQrTitle');
    const qrSub = document.getElementById('cpmQrSubtitle');

    if (method === 'GCash') {
        radioGcash.checked = true;
        gcashCard.classList.add('active');
        cashCard.classList.remove('active');
        gcashBox.style.display = 'block';
        cashBox.style.display = 'none';
        if (proofSection) proofSection.style.display = 'block';
        qrTitle.textContent = 'Scan to Pay (GCash)';
        qrSub.textContent = 'Open your GCash app and scan QR code';
        updateCpmSubmitState();
    } else {
        radioCash.checked = true;
        cashCard.classList.add('active');
        gcashCard.classList.remove('active');
        cashBox.style.display = 'block';
        gcashBox.style.display = 'none';
        if (proofSection) proofSection.style.display = 'none';
        qrTitle.textContent = 'Cashier Reference QR';
        qrSub.textContent = 'Show this code to cashier at counter';
        updateCpmSubmitState();
    }

    renderCpmQr();
}

let cpmProofScanValid = false;

function updateCpmSubmitState() {
    const button = document.getElementById('cpmSubmitBtn');
    if (!button) return;
    const enabled = cpmCurrentMethod === 'Cash' || cpmProofScanValid;
    button.disabled = !enabled;
    if (enabled) button.removeAttribute('disabled');
    else button.setAttribute('disabled', 'disabled');
    button.style.opacity = enabled ? '1' : '.55';
    button.style.cursor = enabled ? 'pointer' : 'not-allowed';
}

function setCpmProofScanStatus(type, message) {
    const status = document.getElementById('cpmProofScanStatus');
    if (!status) return;
    const styles = {
        loading: ['#eff6ff', '#1d4ed8', '#bfdbfe', '<i class="fas fa-circle-notch fa-spin"></i>'],
        valid: ['#ecfdf5', '#047857', '#a7f3d0', '<i class="fas fa-check-circle"></i>'],
        invalid: ['#fef2f2', '#b91c1c', '#fecaca', '<i class="fas fa-times-circle"></i>']
    };
    const style = styles[type];
    status.style.display = 'block';
    status.style.background = style[0];
    status.style.color = style[1];
    status.style.border = '1px solid ' + style[2];
    status.innerHTML = style[3] + ' ' + message;
    updateCpmSubmitState();
}

function handleCpmProofSelect(e) {
    const file = e.target.files[0];
    if (!file) return;
    cpmProofScanValid = false;
    setCpmProofScanStatus('loading', 'Scanning uploaded receipt image...');

    if (!file.type.match('image.*')) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid File',
            text: 'Please select an image file (PNG, JPG, or WEBP).',
            confirmButtonColor: '#2563eb'
        });
        e.target.value = '';
        setCpmProofScanStatus('invalid', 'Not a valid GCash receipt image. Use JPG, PNG, or WEBP.');
        return;
    }

    if (file.size > 10 * 1024 * 1024) {
        Swal.fire({
            icon: 'warning',
            title: 'File Too Large',
            text: 'Please choose an image under 10MB.',
            confirmButtonColor: '#2563eb'
        });
        e.target.value = '';
        setCpmProofScanStatus('invalid', 'Not a valid GCash receipt image. Maximum size is 10MB.');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(evt) {
        const imageCheck = new Image();
        imageCheck.onload = function() {
            if (imageCheck.naturalWidth < 300 || imageCheck.naturalHeight < 300) {
                e.target.value = '';
                setCpmProofScanStatus('invalid', 'Not a valid GCash receipt image. Minimum resolution is 300 × 300.');
                return;
            }
            const scanData = new FormData();
            scanData.append('csrf_token', document.querySelector('#cpmPaymentForm input[name="csrf_token"]').value);
            scanData.append('repair_id', document.getElementById('cpmRepairId').value);
            scanData.append('payment_type', document.getElementById('cpmPaymentType').value);
            scanData.append('payment_proof', file);
            fetch('validate-payment-proof.php', { method: 'POST', body: scanData })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        cpmProofScanValid = true;
                        document.getElementById('cpmReceiptValidationToken').value = result.validation_token || '';
                        setCpmProofScanStatus('valid', result.message || 'GCash receipt accepted and ready for confirmation.');
                    } else {
                        e.target.value = '';
                        setCpmProofScanStatus('invalid', result.message || 'Not a valid GCash receipt image.');
                    }
                })
                .catch(() => {
                    e.target.value = '';
                    setCpmProofScanStatus('invalid', 'Receipt scanning is unavailable. Please try again.');
                });
        };
        imageCheck.onerror = function() {
            e.target.value = '';
            setCpmProofScanStatus('invalid', 'Not a valid GCash receipt image.');
        };
        imageCheck.src = evt.target.result;
        const previewImg = document.getElementById('cpmProofImg');
        const previewFileName = document.getElementById('cpmProofFileName');
        const placeholder = document.getElementById('cpmUploadPlaceholder');
        const preview = document.getElementById('cpmUploadPreview');

        if (previewImg) previewImg.src = evt.target.result;
        if (previewFileName) previewFileName.textContent = file.name;
        if (placeholder) placeholder.style.display = 'none';
        if (preview) preview.style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

function removeCpmProof(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const input = document.getElementById('cpmPaymentProof');
    const previewImg = document.getElementById('cpmProofImg');
    const previewFileName = document.getElementById('cpmProofFileName');
    const preview = document.getElementById('cpmUploadPreview');
    const placeholder = document.getElementById('cpmUploadPlaceholder');

    if (input) input.value = '';
    if (previewImg) previewImg.src = '';
    if (previewFileName) previewFileName.textContent = '';
    if (preview) preview.style.display = 'none';
    if (placeholder) placeholder.style.display = 'flex';
    cpmProofScanValid = false;
    const validationToken = document.getElementById('cpmReceiptValidationToken');
    if (validationToken) validationToken.value = '';
    updateCpmSubmitState();
    const status = document.getElementById('cpmProofScanStatus');
    if (status) status.style.display = 'none';
}

function renderCpmQr() {
    const container = document.getElementById('cpmQrContainer');
    if (!container || !cpmCurrentRepair) return;
    container.innerHTML = '';

    const repId = String(cpmCurrentRepair.repair_id).padStart(5, '0');
    const amount = (cpmCurrentType === 'downpayment') ? cpmDpAmount : cpmFullAmount;
    const typeLabel = (cpmCurrentType === 'downpayment') ? 'Downpayment' : ((cpmCurrentType === 'balance') ? 'Remaining Balance' : 'Full Payment');
    const customer = <?= json_encode($userName) ?>;

    let qrPayload = '';
    if (cpmCurrentMethod === 'GCash') {
        qrPayload = `GCash Payment for Repair #${repId} - Amount: P${amount.toFixed(2)} (${typeLabel})`;
    } else {
        qrPayload = `RepairID: ${repId} | Amount: ${amount.toFixed(2)} | Type: ${typeLabel} | Customer: ${customer}`;
    }

    if (typeof QRCode !== 'undefined') {
        new QRCode(container, {
            text: qrPayload,
            width: 124,
            height: 124,
            colorDark: "#0f172a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.M
        });
    }
}

function submitCustomerPay() {
    const selectedMethod = document.querySelector('#cpmPaymentForm input[name="payment_method"]:checked')?.value;
    if (selectedMethod === 'GCash') {
        const proof = document.getElementById('cpmPaymentProof');
        if (!proof?.files?.length || !cpmProofScanValid) {
            Swal.fire({
                icon: 'warning',
                title: 'GCash proof required',
                text: 'Upload a clear GCash receipt image and wait for the image scan to finish.',
                confirmButtonColor: '#2563eb'
            });
            return;
        }
    }
    const btn = document.getElementById('cpmSubmitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';

    const form = document.getElementById('cpmPaymentForm');
    const formData = new FormData(form);
    formData.append('ajax', '1');

    fetch('submit-payment.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(async r => {
        const data = await r.json().catch(() => null);
        if (!data) throw new Error('The server returned an invalid response.');
        return data;
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Payment Submitted!',
                text: data.message || 'Payment method selected successfully! Please wait for staff verification.',
                confirmButtonColor: '#2563eb'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Payment Error',
                text: data.message || 'Could not process payment.',
                confirmButtonColor: '#ef4444'
            });
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Unable to reach the server. Please try again.',
            confirmButtonColor: '#ef4444'
        });
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('customerRepairModal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeRepairModal();
        });
    }

    const payModal = document.getElementById('customerPayModal');
    if (payModal) {
        payModal.addEventListener('click', (e) => {
            if (e.target === payModal) closeCustomerPayModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeRepairPhoto();
            closeRepairModal();
            closeCustomerPayModal();
        }
    });

    
    const urlParams = new URLSearchParams(window.location.search);
    const viewId = urlParams.get('view_id');
    if (viewId && customerRepairsMap[viewId]) {
        openRepairModal(parseInt(viewId, 10));
    }
});
</script>

<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
</body>
</html>
