<?php

require_once '../config/config.php';
$pageTitle = 'Warranty Monitoring - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

$stmt = $db->query("
    SELECT r.repair_id, r.date_completed
    FROM repairs r
    LEFT JOIN warranty_tracking w ON r.repair_id = w.repair_id
    WHERE r.date_completed IS NOT NULL 
    AND r.repair_status IN ('Completed', 'Returned')
    AND w.warranty_id IS NULL
");
$missingWarranties = $stmt->fetchAll();

foreach ($missingWarranties as $mw) {
    $completionDate = date('Y-m-d', strtotime($mw['date_completed']));
    $warrantyEnd = date('Y-m-d', strtotime($completionDate . ' +30 days'));
    $graceEnd = date('Y-m-d', strtotime($completionDate . ' +60 days'));
    
    $ins = $db->prepare("
        INSERT IGNORE INTO warranty_tracking (repair_id, completion_date, warranty_end_date, grace_period_end_date)
        VALUES (?, ?, ?, ?)
    ");
    $ins->execute([$mw['repair_id'], $completionDate, $warrantyEnd, $graceEnd]);
}

$db->query("
    UPDATE warranty_tracking SET 
        days_since_completion = DATEDIFF(CURDATE(), completion_date),
        warranty_status = CASE
            WHEN CURDATE() <= warranty_end_date THEN 'Under Warranty'
            WHEN CURDATE() > warranty_end_date AND CURDATE() <= grace_period_end_date THEN 'Grace Period'
            WHEN CURDATE() > grace_period_end_date AND customer_agreement_resale = 0 THEN 'Expired'
            WHEN CURDATE() > grace_period_end_date AND customer_agreement_resale = 1 THEN 'Eligible for Resale'
            ELSE 'Expired'
        END,
        updated_at = CURRENT_TIMESTAMP
");

$stmt = $db->query("
    SELECT w.*, r.appliance_type, r.repair_id, c.full_name, c.contact_no, cu.user_id as customer_user_id
    FROM warranty_tracking w
    JOIN repairs r ON w.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users cu ON c.user_id = cu.user_id
    WHERE w.days_since_completion >= 30 AND w.admin_notified_30 = 0
");
$notify30 = $stmt->fetchAll();

foreach ($notify30 as $n) {
    
    $adminUser = $db->query("SELECT user_id FROM users WHERE role = 'Admin' LIMIT 1")->fetchColumn();
    if ($adminUser) {
        createNotification(
            $adminUser,
            'Warranty Expiring - Repair #' . $n['repair_id'],
            "Repair #{$n['repair_id']} ({$n['appliance_type']}) for {$n['full_name']} has reached 30 days. Service warranty has expired.",
            'Warning',
            $n['repair_id']
        );
    }
    
    createNotification(
        $n['customer_user_id'],
        'Service Warranty Notice',
        "Your repair #{$n['repair_id']} ({$n['appliance_type']}) service warranty period (30 days) has ended. You have an additional 30 days grace period to claim your item.",
        'Warning',
        $n['repair_id']
    );
    $db->prepare("UPDATE warranty_tracking SET admin_notified_30 = 1, customer_notified_30 = 1 WHERE warranty_id = ?")->execute([$n['warranty_id']]);
}

$stmt = $db->query("
    SELECT w.*, r.appliance_type, r.repair_id, c.full_name, c.contact_no, cu.user_id as customer_user_id
    FROM warranty_tracking w
    JOIN repairs r ON w.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users cu ON c.user_id = cu.user_id
    WHERE w.days_since_completion >= 60 AND w.admin_notified_60 = 0
");
$notify60 = $stmt->fetchAll();

foreach ($notify60 as $n) {
    $adminUser = $db->query("SELECT user_id FROM users WHERE role = 'Admin' LIMIT 1")->fetchColumn();
    if ($adminUser) {
        createNotification(
            $adminUser,
            'Grace Period Expired - Repair #' . $n['repair_id'],
            "Repair #{$n['repair_id']} ({$n['appliance_type']}) for {$n['full_name']} has exceeded 60 days. Item is now eligible for resale with customer agreement.",
            'Alert',
            $n['repair_id']
        );
    }
    createNotification(
        $n['customer_user_id'],
        'Urgent: Grace Period Expired',
        "Your repair #{$n['repair_id']} ({$n['appliance_type']}) has exceeded the 60-day holding period. Please contact us immediately to claim your item.",
        'Alert',
        $n['repair_id']
    );
    $db->prepare("UPDATE warranty_tracking SET admin_notified_60 = 1, customer_notified_60 = 1 WHERE warranty_id = ?")->execute([$n['warranty_id']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_resale'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('warranty.php', 'Invalid request. Please try again.', 'error');
    }
    $warrantyId = (int)$_POST['warranty_id'];
    $db->prepare("UPDATE warranty_tracking SET customer_agreement_resale = 1, warranty_status = 'Eligible for Resale', notes = CONCAT(COALESCE(notes,''), '\nMarked for resale on " . date('Y-m-d') . " by admin.') WHERE warranty_id = ?")
       ->execute([$warrantyId]);
    redirect('warranty.php', 'Item marked as eligible for resale.', 'success');
}

$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($statusFilter) {
    $where[] = "w.warranty_status = ?";
    $params[] = $statusFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT w.*, r.appliance_type, r.brand, r.repair_status, r.total_cost,
           c.full_name as customer_name, c.contact_no as customer_contact,
           cu.email as customer_email,
           DATEDIFF(CURDATE(), w.completion_date) as current_days
    FROM warranty_tracking w
    JOIN repairs r ON w.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users cu ON c.user_id = cu.user_id
    $whereClause
    ORDER BY w.days_since_completion DESC
");
$stmt->execute($params);
$warranties = $stmt->fetchAll();

$underWarranty = $db->query("SELECT COUNT(*) FROM warranty_tracking WHERE warranty_status = 'Under Warranty'")->fetchColumn();
$gracePeriod = $db->query("SELECT COUNT(*) FROM warranty_tracking WHERE warranty_status = 'Grace Period'")->fetchColumn();
$expired = $db->query("SELECT COUNT(*) FROM warranty_tracking WHERE warranty_status = 'Expired'")->fetchColumn();
$forResale = $db->query("SELECT COUNT(*) FROM warranty_tracking WHERE warranty_status = 'Eligible for Resale'")->fetchColumn();
?>

<div class="page-header">
    <h1><i class="fas fa-shield-alt"></i> Warranty Monitoring</h1>
    <p>Track warranty periods and grace periods for completed repairs</p>
</div>

<div class="alert alert-info" style="display:flex;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;">
    <i class="fas fa-info-circle" style="font-size:1.5rem;margin-top:0.25rem;"></i>
    <div>
        <strong>Warranty Policy:</strong>
        <ul style="margin:0.5rem 0 0 1rem;padding:0;">
            <li><strong>30 days</strong> service warranty from repair completion</li>
            <li><strong>+30 days</strong> additional grace period for item pickup</li>
            <li><strong>60 days total</strong> holding period</li>
            <li>Items not claimed within 60 days may be marked for resale (with customer agreement)</li>
        </ul>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
        <div class="stat-content">
            <h3><?= $underWarranty ?></h3>
            <p>Under Warranty</p>
        </div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-content">
            <h3><?= $gracePeriod ?></h3>
            <p>Grace Period</p>
        </div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-content">
            <h3><?= $expired ?></h3>
            <p>Expired</p>
        </div>
    </div>
    <div class="stat-card stat-dark">
        <div class="stat-icon"><i class="fas fa-recycle"></i></div>
        <div class="stat-content">
            <h3><?= $forResale ?></h3>
            <p>Eligible for Resale</p>
        </div>
    </div>
</div>

<div class="status-tabs" style="margin-bottom:1.5rem;">
    <a href="warranty.php" class="tab <?= !$statusFilter ? 'active' : '' ?>">
        All <span class="badge"><?= $underWarranty + $gracePeriod + $expired + $forResale ?></span>
    </a>
    <a href="warranty.php?status=Under Warranty" class="tab <?= $statusFilter === 'Under Warranty' ? 'active' : '' ?>">
        Under Warranty <span class="badge badge-success"><?= $underWarranty ?></span>
    </a>
    <a href="warranty.php?status=Grace Period" class="tab <?= $statusFilter === 'Grace Period' ? 'active' : '' ?>">
        Grace Period <span class="badge badge-warning"><?= $gracePeriod ?></span>
    </a>
    <a href="warranty.php?status=Expired" class="tab <?= $statusFilter === 'Expired' ? 'active' : '' ?>">
        Expired <span class="badge badge-info"><?= $expired ?></span>
    </a>
    <a href="warranty.php?status=Eligible for Resale" class="tab <?= $statusFilter === 'Eligible for Resale' ? 'active' : '' ?>">
        For Resale <span class="badge" style="background:#6b21a8;color:white;"><?= $forResale ?></span>
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-list"></i> Warranty Records</h2>
    </div>
    <div class="card-body">
        <?php if (empty($warranties)): ?>
        <div class="empty-state">
            <i class="fas fa-shield-alt fa-3x"></i>
            <h3>No warranty records found</h3>
            <p>Warranty tracking begins automatically when repairs are completed.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Repair</th>
                        <th>Customer</th>
                        <th>Appliance</th>
                        <th>Completed</th>
                        <th>Days</th>
                        <th>Warranty Ends</th>
                        <th>Grace Ends</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warranties as $w): ?>
                    <tr>
                        <td><a href="repair-view.php?id=<?= $w['repair_id'] ?>">#<?= $w['repair_id'] ?></a></td>
                        <td>
                            <strong><?= sanitize($w['customer_name']) ?></strong>
                            <br><small class="text-muted"><?= sanitize($w['customer_contact']) ?></small>
                        </td>
                        <td>
                            <?= sanitize($w['appliance_type']) ?>
                            <?php if ($w['brand']): ?>
                            <br><small class="text-muted"><?= sanitize($w['brand']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDate($w['completion_date']) ?></td>
                        <td>
                            <strong class="<?= $w['current_days'] > 60 ? 'text-danger' : ($w['current_days'] > 30 ? 'text-warning' : 'text-success') ?>">
                                <?= $w['current_days'] ?> days
                            </strong>
                            
                            <div style="width:80px;height:6px;background:var(--border-light);border-radius:3px;margin-top:4px;">
                                <div style="width:<?= min(100, ($w['current_days'] / 60) * 100) ?>%;height:100%;border-radius:3px;
                                    background:<?= $w['current_days'] > 60 ? 'var(--danger)' : ($w['current_days'] > 30 ? 'var(--warning)' : 'var(--success)') ?>;">
                                </div>
                            </div>
                        </td>
                        <td><?= formatDate($w['warranty_end_date']) ?></td>
                        <td><?= formatDate($w['grace_period_end_date']) ?></td>
                        <td>
                            <?php
                            $statusColors = [
                                'Under Warranty' => 'badge-success',
                                'Grace Period' => 'badge-warning',
                                'Expired' => 'badge-secondary',
                                'Eligible for Resale' => 'badge-danger'
                            ];
                            ?>
                            <span class="badge <?= $statusColors[$w['warranty_status']] ?? 'badge-secondary' ?>">
                                <?= $w['warranty_status'] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($w['warranty_status'] === 'Expired'): ?>
                            <form method="POST" style="display:inline;" data-confirm="Mark this item as eligible for resale? Make sure customer has agreed." data-confirm-title="Mark for Resale" data-confirm-btn="Yes, mark it!" data-confirm-icon="warning">
                                <?= csrfField() ?>
                                <input type="hidden" name="warranty_id" value="<?= $w['warranty_id'] ?>">
                                <button type="submit" name="mark_resale" class="btn btn-sm btn-warning" title="Mark for Resale">
                                    <i class="fas fa-recycle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="repair-view.php?id=<?= $w['repair_id'] ?>" class="btn btn-sm btn-info" title="View Repair">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
