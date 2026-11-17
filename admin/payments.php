<?php

require_once '../config/config.php';
requireRole('Admin');
header('Location: repairs.php?payment=pending');
exit;

$db = getDB();

$filter = $_GET['filter'] ?? 'pending';

$whereClause = "WHERE r.payment_method IS NOT NULL";
if ($filter === 'pending') {
    $whereClause .= " AND r.payment_status = 'Pending'";
} elseif ($filter === 'verified') {
    $whereClause .= " AND r.payment_status = 'Verified'";
} elseif ($filter === 'rejected') {
    $whereClause .= " AND r.payment_status = 'Rejected'";
}

$stmt = $db->query("
    SELECT r.repair_id, r.appliance_type, r.total_cost, r.payment_method, r.payment_status,
           r.repair_status, r.updated_at,
           c.full_name as customer_name, c.contact_no as customer_phone,
           cu.email as customer_email
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users cu ON c.user_id = cu.user_id
    $whereClause
    ORDER BY 
        CASE WHEN r.payment_status = 'Pending' THEN 0 ELSE 1 END,
        r.updated_at DESC
");
$payments = $stmt->fetchAll();

$pendingCount = $db->query("SELECT COUNT(*) FROM repairs WHERE payment_status = 'Pending'")->fetchColumn();
$verifiedCount = $db->query("SELECT COUNT(*) FROM repairs WHERE payment_status = 'Verified'")->fetchColumn();
$rejectedCount = $db->query("SELECT COUNT(*) FROM repairs WHERE payment_status = 'Rejected'")->fetchColumn();
?>

<div class="content-wrapper">
    <div class="page-header">
        <h1><i class="fas fa-credit-card"></i> Payment Verification</h1>
        <p class="text-muted">Manage and verify customer payments</p>
    </div>

    
    <div class="stats-grid">
        <a href="?filter=pending" class="stat-card stat-warning" style="text-decoration: none; <?= $filter === 'pending' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);' : '' ?>">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-content">
                <h3><?= $pendingCount ?></h3>
                <p>Pending</p>
            </div>
        </a>
        
        <a href="?filter=verified" class="stat-card stat-success" style="text-decoration: none; <?= $filter === 'verified' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);' : '' ?>">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-content">
                <h3><?= $verifiedCount ?></h3>
                <p>Verified</p>
            </div>
        </a>
        
        <a href="?filter=rejected" class="stat-card stat-danger" style="text-decoration: none; <?= $filter === 'rejected' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3);' : '' ?>">
            <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="stat-content">
                <h3><?= $rejectedCount ?></h3>
                <p>Rejected</p>
            </div>
        </a>
        
        <a href="?filter=all" class="stat-card stat-info" style="text-decoration: none; <?= $filter === 'all' ? 'transform: scale(1.02); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);' : '' ?>">
            <div class="stat-icon"><i class="fas fa-list"></i></div>
            <div class="stat-content">
                <h3><?= $pendingCount + $verifiedCount + $rejectedCount ?></h3>
                <p>All Payments</p>
            </div>
        </a>
    </div>

    
    <div class="card">
        <div class="card-header">
            <h2>
                <?php 
                $titles = ['pending' => '⏳ Pending Payments', 'verified' => '✅ Verified Payments', 'rejected' => '❌ Rejected Payments', 'all' => '📋 All Payments'];
                echo $titles[$filter] ?? 'Payments';
                ?>
            </h2>
        </div>
        <div class="card-body">
            <?php if (empty($payments)): ?>
            <div style="text-align: center; padding: 3rem; color: #64748b;">
                <i class="fas fa-inbox" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
                <p style="font-size: 1.1rem;">No payments found</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Repair ID</th>
                            <th>Customer</th>
                            <th>Appliance</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><strong>#<?= $payment['repair_id'] ?></strong></td>
                            <td>
                                <div style="display: flex; flex-direction: column;">
                                    <strong><?= sanitize($payment['customer_name']) ?></strong>
                                    <small style="color: #64748b;"><?= sanitize($payment['customer_phone']) ?></small>
                                </div>
                            </td>
                            <td><?= sanitize($payment['appliance_type']) ?></td>
                            <td><strong style="color: #10b981;"><?= formatCurrency($payment['total_cost']) ?></strong></td>
                            <td>
                                <?php 
                                $icons = ['Cash' => '💵', 'GCash' => '📱', 'Bank Transfer' => '🏦', 'Credit Card' => '💳', 'Other' => '🔄'];
                                echo ($icons[$payment['payment_method']] ?? '💳') . ' ' . $payment['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php 
                                $status = $payment['payment_status'] ?? 'Pending';
                                $statusStyles = [
                                    'Pending' => 'background: #fef3c7; color: #92400e;',
                                    'Verified' => 'background: #d1fae5; color: #065f46;',
                                    'Rejected' => 'background: #fee2e2; color: #991b1b;'
                                ];
                                $statusIcons = ['Pending' => 'fa-clock', 'Verified' => 'fa-check-circle', 'Rejected' => 'fa-times-circle'];
                                ?>
                                <span style="<?= $statusStyles[$status] ?? '' ?> padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                                    <i class="fas <?= $statusIcons[$status] ?? 'fa-question' ?>"></i> <?= $status ?>
                                </span>
                            </td>
                            <td><small><?= formatDateTime($payment['updated_at']) ?></small></td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if ($status === 'Pending'): ?>
                                    <button onclick="verifyPayment(<?= $payment['repair_id'] ?>, 'verify')" class="btn btn-sm btn-success" style="padding: 0.5rem 0.75rem;" title="Verify Payment">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button onclick="verifyPayment(<?= $payment['repair_id'] ?>, 'reject')" class="btn btn-sm btn-danger" style="padding: 0.5rem 0.75rem;" title="Reject Payment">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="repairs.php?view_id=<?= $payment['repair_id'] ?>" class="btn btn-sm btn-info" style="padding: 0.5rem 0.75rem;" title="View Repair">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
async function verifyPayment(repairId, action) {
    const actionText = action === 'verify' ? 'verify' : 'reject';
    const confirmMsg = action === 'verify' 
        ? 'Are you sure you want to verify this payment?'
        : 'Are you sure you want to reject this payment?';
    const confirmTitle = action === 'verify' ? 'Verify Payment' : 'Reject Payment';
    const confirmBtn = action === 'verify' ? 'Yes, verify it!' : 'Yes, reject it!';
    
    const swalResult = await Swal.fire({
        title: confirmTitle,
        text: confirmMsg,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#272343',
        cancelButtonColor: '#6c757d',
        confirmButtonText: confirmBtn
    });
    if (!swalResult.isConfirmed) return;
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?= generateCSRFToken() ?>');
        formData.append('repair_id', repairId);
        formData.append('action', action);
        
        const response = await fetch('verify-payment.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            await Swal.fire({icon:'success',title:'Success',text:result.message,confirmButtonColor:'#272343',timer:2500,timerProgressBar:true});
            location.reload();
        } else {
            Swal.fire({icon:'error',title:'Error',text:result.message,confirmButtonColor:'#272343'});
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({icon:'error',title:'Error',text:'An error occurred. Please try again.',confirmButtonColor:'#272343'});
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
