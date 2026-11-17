<?php

require_once '../config/config.php';
$pageTitle = 'Manage Repairs - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

$repairDetails = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $stmt = $db->prepare("
        SELECT r.*, 
               c.full_name as customer_name, c.contact_no as customer_contact, c.address as customer_address,
               cu.email as customer_email,
               t.full_name as technician_name, t.contact_no as technician_contact,
               tu.email as technician_email
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        JOIN users cu ON c.user_id = cu.user_id
        LEFT JOIN technicians t ON r.technician_id = t.technician_id
        LEFT JOIN users tu ON t.user_id = tu.user_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$viewId]);
    $repairDetails = $stmt->fetch();
}

$editRepair = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$editId]);
    $editRepair = $stmt->fetch();
}

$assignRepair = null;
if (isset($_GET['assign_id'])) {
    $assignId = (int)$_GET['assign_id'];
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, c.contact_no as customer_contact
        FROM repairs r
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$assignId]);
    $assignRepair = $stmt->fetch();
}

$techStmt = $db->query("
    SELECT t.*, 
           (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status IN ('Assigned', 'In Progress')) as active_repairs
    FROM technicians t
    JOIN users u ON t.user_id = u.user_id
    WHERE u.status = 'Active'
    ORDER BY (t.availability = 'Available') DESC, active_repairs ASC, t.full_name ASC
");
$technicians = $techStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_technician'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('repairs.php', 'Invalid security token. Please try again.', 'danger');
    }
    $repairId = (int)($_POST['repair_id'] ?? 0);
    $technicianId = (int)($_POST['technician_id'] ?? 0);
    $totalCost = !empty($_POST['total_cost']) ? (float)$_POST['total_cost'] : null;
    $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : null;
    if ($totalCost !== null && $totalCost > 0) {
        if ($estimatedCost === null || $estimatedCost <= 0 || $estimatedCost > $totalCost) {
            $estimatedCost = round($totalCost * 0.5, 2);
        }
    }
    $notes = trim($_POST['notes'] ?? '');
    $scheduledDate = $_POST['scheduled_date'] ?? '';
    $scheduledTime = $_POST['scheduled_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';

    $checkStmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, c.contact_no 
        FROM repairs r 
        JOIN customers c ON r.customer_id = c.customer_id 
        WHERE r.repair_id = ?
    ");
    $checkStmt->execute([$repairId]);
    $repair = $checkStmt->fetch();

    if (!$repair) {
        redirect('repairs.php', 'Repair not found.', 'danger');
    }
    if ($repair['repair_status'] === 'Returned') {
        redirect('repairs.php', 'This repair has been returned. No further changes allowed.', 'warning');
    }

    $assignErrors = [];
    if (!$technicianId) {
        $assignErrors[] = 'Please select a technician.';
    }

    if (!empty($scheduledDate)) {
        $today = date('Y-m-d');
        if ($scheduledDate < $today) {
            $assignErrors[] = 'Scheduled date cannot be in the past.';
        }
        if (!empty($scheduledTime) && !empty($endTime) && $endTime <= $scheduledTime) {
            
            $endTime = date('H:i', strtotime($scheduledTime . ' +1 hour'));
        }
    }

    if (empty($assignErrors)) {
        try {
            $db->beginTransaction();
            $oldStatus = $repair['repair_status'];

            $existingDiag = $repair['diagnosis'] ?? '';
            $newDiagnosis = $notes !== '' ? (($existingDiag ? $existingDiag . "\n" : '') . $notes) : $existingDiag;

            $uStmt = $db->prepare("
                UPDATE repairs 
                SET technician_id = ?, 
                    repair_status = 'Assigned', 
                    estimated_cost = ?,
                    total_cost = COALESCE(?, total_cost),
                    diagnosis = ?
                WHERE repair_id = ?
            ");
            $uStmt->execute([$technicianId, $estimatedCost, $totalCost, $newDiagnosis, $repairId]);

            logRepairAction($repairId, $_SESSION['user_id'], 'Technician Assigned', $oldStatus, 'Assigned', $notes);

            if (!empty($scheduledDate)) {
                $checkSched = $db->prepare("
                    SELECT schedule_id FROM schedules 
                    WHERE repair_id = ? AND schedule_status NOT IN ('Completed', 'Cancelled') 
                    ORDER BY schedule_id DESC LIMIT 1
                ");
                $checkSched->execute([$repairId]);
                $existingSchedId = $checkSched->fetchColumn();

                if ($existingSchedId) {
                    $sStmt = $db->prepare("
                        UPDATE schedules 
                        SET technician_id = ?, scheduled_date = ?, scheduled_time = ?, end_time = ?, notes = ?, schedule_status = 'Scheduled'
                        WHERE schedule_id = ?
                    ");
                    $sStmt->execute([
                        $technicianId,
                        $scheduledDate,
                        $scheduledTime ?: null,
                        $endTime ?: null,
                        $notes ?: null,
                        $existingSchedId
                    ]);
                } else {
                    $sStmt = $db->prepare("
                        INSERT INTO schedules (repair_id, technician_id, scheduled_date, scheduled_time, end_time, notes, schedule_status)
                        VALUES (?, ?, ?, ?, ?, ?, 'Scheduled')
                    ");
                    $sStmt->execute([
                        $repairId,
                        $technicianId,
                        $scheduledDate,
                        $scheduledTime ?: null,
                        $endTime ?: null,
                        $notes ?: null
                    ]);
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Assign technician failed: ' . $e->getMessage());
            $_SESSION['errors'] = ['Failed to assign technician: ' . $e->getMessage()];
            redirect('repairs.php?assign_id=' . $repairId);
        }

        
        try {
            $tStmt = $db->prepare("SELECT user_id, full_name FROM technicians WHERE technician_id = ?");
            $tStmt->execute([$technicianId]);
            $tech = $tStmt->fetch();

            if ($tech) {
                $schedMsg = !empty($scheduledDate) ? " Scheduled: " . date('M d, Y', strtotime($scheduledDate)) . ($scheduledTime ? ' at ' . date('h:i A', strtotime($scheduledTime)) : '') . '.' : '';
                createNotification(
                    (int)$tech['user_id'],
                    'New Repair Assigned',
                    "You have been assigned to repair #{$repairId}: {$repair['appliance_type']}.{$schedMsg}",
                    'Info',
                    $repairId
                );
            }

            $cStmt = $db->prepare("SELECT user_id FROM customers WHERE customer_id = ?");
            $cStmt->execute([$repair['customer_id']]);
            $custUserId = $cStmt->fetchColumn();

            if ($custUserId) {
                $techName = $tech['full_name'] ?? 'Assigned Technician';
                $customerMsg = "A technician ({$techName}) has been assigned to your repair request #{$repairId}.";
                createNotification(
                    (int)$custUserId,
                    'Technician Assigned',
                    $customerMsg,
                    'Success',
                    $repairId
                );

                if (!empty($repair['contact_no'])) {
                    require_once '../includes/SmsQueue.php';
                    $smsQueue = new SmsQueue($db);
                    $smsMessage = getSetting('site_name', SITE_NAME) . ": " . $customerMsg;
                    $smsQueue->add($repair['contact_no'], $smsMessage);
                }
            }
        } catch (Throwable $e) {
            error_log('Post-assign notifications/SMS failed: ' . $e->getMessage());
        }

        redirect('repairs.php', 'Technician assigned successfully!', 'success');
    } else {
        $_SESSION['errors'] = $assignErrors;
        redirect('repairs.php?assign_id=' . $repairId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_repair_image'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('repairs.php', 'Invalid security token.', 'danger');
    }
    $repairId = (int)$_POST['repair_id'];
    if (isset($_FILES['appliance_image']) && $_FILES['appliance_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['appliance_image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 8 * 1024 * 1024;
        if (!in_array($file['type'], $allowedTypes)) {
            redirect('repairs.php?view_id=' . $repairId, 'Invalid image format. Allowed: JPG, PNG, GIF, WebP.', 'danger');
        } elseif ($file['size'] > $maxSize) {
            redirect('repairs.php?view_id=' . $repairId, 'Image is too large. Maximum size is 8MB.', 'danger');
        } else {
            $uploadDir = dirname(__DIR__) . '/uploads/repairs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'repair_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $imagePath = 'uploads/repairs/' . $filename;
                $stmt = $db->prepare("UPDATE repairs SET appliance_image = ? WHERE repair_id = ?");
                $stmt->execute([$imagePath, $repairId]);
                redirect('repairs.php?view_id=' . $repairId, 'Device photo updated successfully!', 'success');
            } else {
                redirect('repairs.php?view_id=' . $repairId, 'Failed to save uploaded image.', 'danger');
            }
        }
    } else {
        redirect('repairs.php?view_id=' . $repairId, 'No valid image file selected.', 'warning');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_repair_image'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('repairs.php', 'Invalid security token.', 'danger');
    }
    $repairId = (int)$_POST['repair_id'];
    $stmt = $db->prepare("UPDATE repairs SET appliance_image = NULL WHERE repair_id = ?");
    $stmt->execute([$repairId]);
    redirect('repairs.php?view_id=' . $repairId, 'Device photo removed.', 'info');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_repair'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('repairs.php', 'Invalid security token. Please try again.', 'danger');
    }
    $repairId = (int)$_POST['repair_id'];
    
    
    $checkStmt = $db->prepare("SELECT repair_status FROM repairs WHERE repair_id = ?");
    $checkStmt->execute([$repairId]);
    $currentStatus = $checkStmt->fetchColumn();
    
    if ($currentStatus === 'Returned') {
        redirect('repairs.php', 'This repair has been returned. No further changes allowed.', 'warning');
    }
    
    $applianceType = trim($_POST['appliance_type']);
    $brand = trim($_POST['brand']);
    $model = trim($_POST['model']);
    $serialNumber = trim($_POST['serial_number']);
    $problemDescription = trim($_POST['problem_description']);
    $diagnosis = trim($_POST['diagnosis']);
    $repairNotes = trim($_POST['repair_notes']);
    $repairStatus = $_POST['repair_status'];

    $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : null;
    $totalCost = !empty($_POST['total_cost']) ? (float)$_POST['total_cost'] : null;
    if ($totalCost !== null && $totalCost > 0) {
        if ($estimatedCost === null || $estimatedCost <= 0 || $estimatedCost > $totalCost) {
            $estimatedCost = round($totalCost * 0.5, 2);
        }
    }
    
    $errors = [];
    
    
    if (strlen($applianceType) < 2) {
        $errors[] = 'Appliance type is required.';
    }
    if (empty($problemDescription)) {
        $errors[] = 'Problem description is required.';
    }
    
    if (empty($errors)) {
        try {
            $imgClause = "";
            $imgParams = [];
            if (isset($_FILES['appliance_image']) && $_FILES['appliance_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['appliance_image'];
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (in_array($file['type'], $allowedTypes) && $file['size'] <= 8 * 1024 * 1024) {
                    $uploadDir = dirname(__DIR__) . '/uploads/repairs/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $filename = 'repair_' . time() . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        $imgClause = ", appliance_image = ?";
                        $imgParams[] = 'uploads/repairs/' . $filename;
                    }
                }
            }

            $stmt = $db->prepare("
                UPDATE repairs 
                SET appliance_type = ?, brand = ?, model = ?, serial_number = ?,
                    problem_description = ?, diagnosis = ?, repair_notes = ?,
                    repair_status = ?, estimated_cost = ?, total_cost = ?
                    $imgClause
                WHERE repair_id = ?
            ");
            $execParams = array_merge([
                $applianceType, $brand, $model, $serialNumber,
                $problemDescription, $diagnosis, $repairNotes,
                $repairStatus, $estimatedCost, $totalCost,
            ], $imgParams, [$repairId]);
            $stmt->execute($execParams);
            
            
            if ($repairStatus === 'In Progress') {
                $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'In Progress' WHERE repair_id = ?");
                $stmt->execute([$repairId]);
            } elseif ($repairStatus === 'Completed') {
                $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'Completed', actual_completion = CURDATE() WHERE repair_id = ?");
                $stmt->execute([$repairId]);
                
                
                $wStmt = $db->prepare("INSERT IGNORE INTO warranty_tracking (repair_id, completion_date, warranty_end_date, grace_period_end_date) VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 60 DAY))");
                $wStmt->execute([$repairId]);
            } elseif ($repairStatus === 'Cancelled') {
                $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'Cancelled' WHERE repair_id = ?");
                $stmt->execute([$repairId]);
            }
            
            
            if ($repairStatus !== $currentStatus) {
                $stmt = $db->prepare("SELECT user_id, customer_id FROM customers WHERE customer_id = (SELECT customer_id FROM repairs WHERE repair_id = ?)");
                $stmt->execute([$repairId]);
                $cust = $stmt->fetch();
                
                if ($cust) {
                    $message = "Your repair (#$repairId) status has been updated to: $repairStatus.";
                    createNotification($cust['user_id'], 'Repair Status Updated', $message, 'Info', $repairId);
                    
                    
                    $stmt = $db->prepare("SELECT contact_no FROM customers WHERE customer_id = ?");
                    $stmt->execute([$cust['customer_id']]);
                    $contactNo = $stmt->fetchColumn();
                    
                    if (!empty($contactNo)) {
                        require_once '../includes/SmsQueue.php';
                        $smsQueue = new SmsQueue($db);
                        $smsMessage = getSetting('site_name', SITE_NAME) . ": " . $message;
                        $smsQueue->add($contactNo, $smsMessage);
                    }
                }
            }
            
            redirect('repairs.php', 'Repair updated successfully!', 'success');
        } catch (Exception $e) {
            $errors[] = 'Failed to update repair: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        redirect('repairs.php?edit_id=' . $repairId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_repair'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('repairs.php', 'Invalid security token. Please try again.', 'danger');
    }
    $repairId = (int)$_POST['repair_id'];
    
    try {
        
        $checkStmt = $db->prepare("SELECT repair_id FROM repairs WHERE repair_id = ?");
        $checkStmt->execute([$repairId]);
        if (!$checkStmt->fetch()) {
            redirect('repairs.php', 'Repair record not found.', 'danger');
        }
        
        
        $stmt = $db->prepare("DELETE FROM repairs WHERE repair_id = ?");
        $stmt->execute([$repairId]);
        
        redirect('repairs.php', 'Repair record #' . $repairId . ' has been deleted successfully.', 'success');
    } catch (Exception $e) {
        redirect('repairs.php', 'Failed to delete repair: ' . $e->getMessage(), 'danger');
    }
}

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$paymentFilter = $_GET['payment'] ?? '';
$order = 'DESC';

$buildUrl = function($overrides = []) use ($status, $search, $paymentFilter) {
    $current = [
        'status' => $status,
        'search' => $search,
        'payment' => $paymentFilter
    ];
    $merged = array_merge($current, $overrides);
    $filtered = [];
    foreach ($merged as $k => $v) {
        if ($v !== '' && $v !== null) {
            $filtered[$k] = $v;
        }
    }
    return 'repairs.php' . ($filtered ? '?' . http_build_query($filtered) : '');
};

$where = [];
$params = [];

if ($status) {
    $where[] = "r.repair_status = ?";
    $params[] = $status;
}

if ($paymentFilter === 'pending') {
    $where[] = "r.payment_method IS NOT NULL AND r.payment_status = 'Pending'";
} elseif ($paymentFilter === 'verified') {
    $where[] = "r.payment_status = 'Verified'";
}

if ($search) {
    $escapedSearch = "%" . escapeLike($search) . "%";
    $where[] = "(c.full_name LIKE ? OR r.appliance_type LIKE ? OR r.repair_id = ?)";
    $params[] = $escapedSearch;
    $params[] = $escapedSearch;
    $params[] = $search;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$pendingPaymentsCount = (int)$db->query("SELECT COUNT(*) FROM repairs WHERE payment_status = 'Pending' AND payment_method IS NOT NULL")->fetchColumn();

$stmt = $db->prepare("
    SELECT r.*, r.payment_method, r.payment_status, 
           c.full_name as customer_name, c.contact_no as customer_contact,
           t.full_name as technician_name
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    $whereClause
    ORDER BY r.date_requested $order, r.repair_id $order
");
$stmt->execute($params);
$repairs = $stmt->fetchAll();

$stmt = $db->query("
    SELECT repair_status, COUNT(*) as count 
    FROM repairs 
    GROUP BY repair_status
");
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<style>

.admin-repairs-page {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    --accent-emerald: #10B981;
    font-family: 'Inter', system-ui, sans-serif;
}

.page-header-modern {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 32px;
}
.page-header-modern h1 {
    margin: 0; font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);
    font-size: 28px; display: flex; align-items: center; gap: 12px;
}

.modern-tabs {
    display: flex; gap: 12px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 8px;
}
.modern-tab {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 24px; border-radius: 12px;
    background: #fff; color: var(--text-muted); border: 1px solid var(--border-color);
    text-decoration: none; font-weight: 600; font-size: 14px;
    transition: all 0.2s; white-space: nowrap; box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.modern-tab i { font-size: 16px; }
.modern-tab:hover { background: #f8fafc; color: var(--text-dark); border-color: #cbd5e1; }
.modern-tab.active {
    background: var(--accent-indigo); color: #fff; border-color: var(--accent-indigo);
    box-shadow: 0 8px 20px rgba(79, 70, 229, 0.25);
}
.tab-badge {
    background: rgba(15, 23, 42, 0.1); padding: 2px 8px; border-radius: 20px; font-size: 12px;
}
.modern-tab.active .tab-badge { background: rgba(255,255,255,0.2); }

.modern-admin-card {
    background: #fff; border-radius: 16px; box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color); overflow: hidden;
}
.modern-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.modern-table th {
    background: #f8fafc; text-align: left; padding: 12px 12px;
    font-size: 11px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.modern-table td {
    padding: 12px 12px; border-bottom: 1px solid var(--border-color); vertical-align: middle;
}
.modern-table tr:last-child td { border-bottom: none; }
.modern-table tr:hover td { background: #f8fafc; }

.table-repair-id { font-weight: 700; color: var(--text-dark); font-size: 14px; }
.table-customer-name { font-weight: 600; color: var(--text-dark); margin-bottom: 2px; display: block; font-size: 13.5px; }
.table-meta-text { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }

.admin-badge {
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; display: inline-block; white-space: nowrap;
}
.bg-pending { background: #FFFBEB; color: #D97706; border: 1px solid #FDE68A; }
.bg-assigned { background: #EFF6FF; color: #1E3A8A; border: 1px solid #BFDBFE; }
.bg-in-progress { background: #ECFEFF; color: #0891B2; border: 1px solid #A5F3FC; }
.bg-completed { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
.bg-cancelled { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
.bg-on-hold { background: #FFFBEB; color: #D97706; border: 1px solid #FDE68A; }
.bg-returned { background: #F3F4F6; color: #4B5563; border: 1px solid #E5E7EB; }

.action-btn-group { display: inline-flex; gap: 4px; flex-wrap: nowrap; align-items: center; justify-content: flex-end; }
.admin-action-btn {
    width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;
    border: none; cursor: pointer; transition: all 0.2s; color: #fff; font-size: 13px; flex-shrink: 0;
}
.btn-view { background: #e0f2fe; color: #0284c7; }
.btn-view:hover { background: #bae6fd; }
.btn-assign { background: #ede9fe; color: #6d28d9; }
.btn-assign:hover { background: #ddd6fe; }
.btn-return { background: #d1fae5; color: #059669; }
.btn-return:hover { background: #a7f3d0; }
.btn-edit { background: #fef3c7; color: #d97706; }
.btn-edit:hover { background: #fde68a; }
.btn-delete { background: #fee2e2; color: #dc2626; }
.btn-delete:hover { background: #fecaca; }
.btn-disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
</style>

<div class="admin-repairs-page">
    <div class="page-header-modern">
        <h1><i class="fas fa-wrench" style="color: var(--accent-indigo);"></i> Manage Repairs</h1>
    </div>

<?php if (isset($_SESSION['errors'])): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($_SESSION['errors'] as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['errors']); ?>
<?php endif; ?>

<div class="modern-tabs">
    <a href="<?= $buildUrl(['status' => '', 'payment' => '']) ?>" class="modern-tab <?= !$status && !$paymentFilter ? 'active' : '' ?>">
        <i class="fas fa-th-large"></i> All <span class="tab-badge"><?= array_sum($statusCounts) ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => 'Pending', 'payment' => '']) ?>" class="modern-tab <?= $status === 'Pending' ? 'active' : '' ?>">
        <i class="fas fa-hourglass-half"></i> Pending <span class="tab-badge"><?= $statusCounts['Pending'] ?? 0 ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => 'Assigned', 'payment' => '']) ?>" class="modern-tab <?= $status === 'Assigned' ? 'active' : '' ?>">
        <i class="fas fa-user-check"></i> Assigned <span class="tab-badge"><?= $statusCounts['Assigned'] ?? 0 ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => 'In Progress', 'payment' => '']) ?>" class="modern-tab <?= $status === 'In Progress' ? 'active' : '' ?>">
        <i class="fas fa-cog fa-spin"></i> In Progress <span class="tab-badge"><?= $statusCounts['In Progress'] ?? 0 ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => 'Completed', 'payment' => '']) ?>" class="modern-tab <?= $status === 'Completed' ? 'active' : '' ?>">
        <i class="fas fa-check-circle"></i> Completed <span class="tab-badge"><?= $statusCounts['Completed'] ?? 0 ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => 'Returned', 'payment' => '']) ?>" class="modern-tab <?= $status === 'Returned' ? 'active' : '' ?>">
        <i class="fas fa-undo"></i> Returned <span class="tab-badge"><?= $statusCounts['Returned'] ?? 0 ?></span>
    </a>
    <a href="<?= $buildUrl(['status' => '', 'payment' => 'pending']) ?>" class="modern-tab <?= $paymentFilter === 'pending' ? 'active' : '' ?>" style="<?= $pendingPaymentsCount > 0 ? 'color: #D97706;' : '' ?>">
        <i class="fas fa-credit-card"></i> Payment Pending <span class="tab-badge" style="<?= $pendingPaymentsCount > 0 ? 'background: #F59E0B; color: #fff;' : '' ?>"><?= $pendingPaymentsCount ?></span>
    </a>
</div>

<div class="modern-admin-card">
    <?php if (empty($repairs)): ?>
        <div style="text-align: center; padding: 64px 24px;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 32px; color: #94a3b8;">
                <i class="fas fa-inbox"></i>
            </div>
            <h3 style="margin: 0 0 8px; font-size: 20px; font-weight: 700; color: var(--text-dark);">No Repairs Found</h3>
            <p style="margin: 0; color: var(--text-muted);">There are no repair requests matching your criteria.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 70px; text-align: center;">ID</th>
                        <th>Customer</th>
                        <th>Appliance</th>
                        <th style="max-width: 180px;">Problem</th>
                        <th style="text-align: center; width: 100px;">Status</th>
                        <th style="text-align: center; width: 155px;">Payment</th>
                        <th>Technician</th>
                        <th style="width: 135px;">Date Requested</th>
                        <th style="width: 145px; min-width: 145px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repairs as $repair): ?>
                        <?php 
                            $slug = strtolower(str_replace(' ', '-', $repair['repair_status']));
                            $statusBgClass = 'bg-' . $slug;
                        ?>
                        <tr>
                            <td style="text-align: center;"><span class="table-repair-id">#<?= $repair['repair_id'] ?></span></td>
                            <td>
                                <span class="table-customer-name"><?= sanitize($repair['customer_name']) ?></span>
                                <span class="table-meta-text"><i class="fas fa-phone"></i> <?= sanitize($repair['customer_contact']) ?></span>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: var(--text-dark); display: block; margin-bottom: 2px; font-size: 13px;"><?= sanitize($repair['appliance_type']) ?></span>
                                <?php if ($repair['brand']): ?>
                                    <span class="table-meta-text"><i class="fas fa-tag"></i> <?= sanitize($repair['brand']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 180px;">
                                <div style="color: var(--text-muted); font-size: 12.5px; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="<?= sanitize($repair['problem_description']) ?>">
                                    <?= sanitize($repair['problem_description']) ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <span class="admin-badge <?= $statusBgClass ?>">
                                    <?= $repair['repair_status'] ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if (!empty($repair['payment_method'])): ?>
                                    <?php 
                                        $isDp = ($repair['payment_type'] === 'downpayment') 
                                                || (!empty($repair['estimated_cost']) && (float)($repair['amount_paid'] ?? 0) > 0 && (float)$repair['amount_paid'] == (float)$repair['estimated_cost'] && (float)$repair['amount_paid'] < (float)$repair['total_cost'] && ($repair['payment_type'] ?? '') !== 'balance');
                                        $isBal = ($repair['payment_type'] === 'balance');
                                        
                                        $paidAmt = (float)($repair['amount_paid'] ?? 0);
                                        if ($paidAmt <= 0) {
                                            $paidAmt = $isDp ? (float)$repair['estimated_cost'] : (float)($repair['total_cost'] ?: $repair['estimated_cost']);
                                        }
                                        $totalCost = (float)($repair['total_cost'] ?: $paidAmt);
                                        $balance = max(0, $totalCost - $paidAmt);
                                        $payStatus = $repair['payment_status'] ?? 'Pending';
                                    ?>
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                                        <span style="font-weight: 700; color: #0F172A; font-size: 13.5px;">
                                            ₱<?= number_format($paidAmt, 2) ?>
                                        </span>
                                        <?php if ($isBal): ?>
                                            <span style="background: #FAF5FF; color: #6B21A8; border: 1px solid #E9D5FF; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 12px; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap;">
                                                <i class="fas fa-hand-holding-usd" style="font-size: 9px;"></i> Remaining Balance
                                            </span>
                                        <?php elseif ($isDp): ?>
                                            <span style="background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 12px; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap;">
                                                <i class="fas fa-coins" style="font-size: 9px;"></i> Downpayment (50%)
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 12px; white-space: nowrap;">
                                                Full Payment
                                            </span>
                                        <?php endif; ?>
                                        <span style="font-size: 11px; color: #64748B;">
                                            <?= sanitize($repair['payment_method']) ?>
                                        </span>
                                        <?php if ($payStatus === 'Verified'): ?>
                                            <span class="admin-badge" style="background: #DCFCE7; color: #15803D; font-size: 10.5px; padding: 2px 8px;">
                                                <i class="fas fa-check-circle"></i> <?= $isDp ? 'DP Verified' : 'Verified' ?>
                                            </span>
                                            <?php if ($isDp && $balance > 0): ?>
                                                <span style="font-size: 10.5px; color: #DC2626; font-weight: 600;">Bal: ₱<?= number_format($balance, 2) ?></span>
                                            <?php endif; ?>
                                        <?php elseif ($payStatus === 'Pending'): ?>
                                            <span class="admin-badge" style="background: #FEF3C7; color: #B45309; font-size: 10.5px; padding: 2px 8px;">
                                                <i class="fas fa-clock"></i> <?= $isBal ? 'Bal Pending' : ($isDp ? 'DP Pending' : 'Pending') ?>
                                            </span>
                                            <div style="display: flex; gap: 4px; margin-top: 4px;">
                                                <button type="button" class="btn btn-sm btn-success" style="padding: 2px 8px; font-size: 11px; border-radius: 6px; cursor: pointer;" onclick="verifyPayment(<?= $repair['repair_id'] ?>, 'verify')" title="Verify <?= $isBal ? 'Balance Payment' : ($isDp ? 'Downpayment' : 'Payment') ?>">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" style="padding: 2px 8px; font-size: 11px; border-radius: 6px; cursor: pointer;" onclick="verifyPayment(<?= $repair['repair_id'] ?>, 'reject')" title="Reject Payment">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        <?php elseif ($payStatus === 'Rejected'): ?>
                                            <span class="admin-badge" style="background: #FEE2E2; color: #B91C1C; font-size: 10.5px; padding: 2px 8px;">
                                                <i class="fas fa-times-circle"></i> Rejected
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($repair['payment_proof'])): ?>
                                            <button type="button" class="btn btn-sm" style="margin-top: 4px; padding: 2px 7px; font-size: 10px; font-weight: 600; background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 3px;" onclick="viewPaymentProof('<?= SITE_URL . '/' . htmlspecialchars($repair['payment_proof']) ?>', '<?= htmlspecialchars(addslashes($repair['payment_reference'] ?? '')) ?>', <?= (int)$repair['repair_id'] ?>)" title="View Customer Proof of Payment">
                                                <i class="fas fa-image"></i> Proof
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94A3B8; font-size: 12px; font-style: italic;">Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($repair['technician_name']): ?>
                                    <span style="font-weight: 500; color: var(--text-dark); font-size: 13px;"><i class="fas fa-user-cog" style="color: #94a3b8; margin-right: 4px;"></i> <?= sanitize($repair['technician_name']) ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-style: italic; font-size: 12.5px;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: var(--text-dark); font-size: 12.5px; font-weight: 500;"><?= date('M d, Y', strtotime($repair['date_requested'])) ?></span>
                                <br>
                                <span class="table-meta-text" style="font-size: 11px;"><?= date('h:i A', strtotime($repair['date_requested'])) ?></span>
                            </td>
                            <td style="text-align: right; width: 145px; min-width: 145px;">
                                <div class="action-btn-group" style="justify-content: flex-end;">
                                    <button type="button" class="admin-action-btn btn-view" onclick="viewRepair(<?= $repair['repair_id'] ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($repair['repair_status'] === 'Pending'): ?>
                                        <button type="button" class="admin-action-btn btn-assign" 
                                                onclick="openAssignModal(<?= $repair['repair_id'] ?>, '<?= htmlspecialchars(addslashes($repair['customer_name'])) ?>', '<?= htmlspecialchars(addslashes($repair['appliance_type'] . ($repair['brand'] ? ' (' . $repair['brand'] . ')' : ''))) ?>', '<?= htmlspecialchars(addslashes($repair['problem_description'])) ?>', '<?= $repair['estimated_cost'] ?? '' ?>', <?= (int)($repair['technician_id'] ?? 0) ?>, '<?= $repair['total_cost'] ?? '' ?>')" 
                                                title="Assign Technician">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($repair['repair_status'] === 'Completed'): ?>
                                        <?php if (($repair['payment_status'] ?? '') === 'Verified'): ?>
                                        <button type="button" class="admin-action-btn btn-return" title="Record Return"
                                                onclick="openReturnModal(<?= $repair['repair_id'] ?>, '<?= htmlspecialchars(addslashes($repair['customer_name'])) ?>', '<?= htmlspecialchars(addslashes($repair['appliance_type'])) ?>')">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        <?php else: ?>
                                        <button type="button" class="admin-action-btn btn-disabled" onclick="Swal.fire({icon:'info',title:'Payment Required',text:'Payment must be verified first before returning the item.',confirmButtonColor:'#272343'});" title="Payment Not Verified">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($repair['repair_status'] !== 'Returned'): ?>
                                    <button type="button" class="admin-action-btn btn-edit" onclick="editRepair(<?= $repair['repair_id'] ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php endif; ?>
                                    
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

<style>

.compact-premium-modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);
    z-index: 1050; align-items: center; justify-content: center; padding: 1rem;
}
.compact-premium-modal.show { display: flex !important; }

.cpm-content {
    background: #fff; border-radius: 16px; width: 100%; max-width: 650px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden;
    animation: cpmSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes cpmSlideUp { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

.cpm-header {
    background: #4F46E5; color: white; padding: 20px 24px;
    display: flex; justify-content: space-between; align-items: center;
}
.cpm-header h2 { margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.cpm-close { background: none; border: none; color: rgba(255,255,255,0.7); font-size: 24px; cursor: pointer; padding: 0; line-height: 1; transition: color 0.2s; }
.cpm-close:hover { color: white; }

.cpm-body { padding: 24px; max-height: 75vh; overflow-y: auto; }
.cpm-section { background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; padding: 16px; margin-bottom: 16px; }
.cpm-section h4 { margin: 0 0 12px 0; color: #0f172a; font-size: 14px; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px; }

.cpm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cpm-item { display: flex; flex-direction: column; gap: 4px; }
.cpm-item.full { grid-column: 1 / -1; }
.cpm-item label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; }
.cpm-item value { font-size: 14px; color: #0f172a; font-weight: 500; }

.cpm-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: flex-end; gap: 12px; }
.cpm-btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; border: none; }
.cpm-btn-secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
.cpm-btn-secondary:hover { background: #f1f5f9; color: #0f172a; }
.cpm-btn-primary { background: #4F46E5; color: white; border: 1px solid #4F46E5; }
.cpm-btn-primary:hover { background: #4338ca; }
.cpm-btn-success { background: #10b981; color: white; border: 1px solid #10b981; }
.cpm-btn-success:hover { background: #059669; }
.cpm-btn-warning { background: #f59e0b; color: white; border: 1px solid #f59e0b; }
.cpm-btn-warning:hover { background: #d97706; }

.cpm-input { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; transition: all 0.2s; background: #fff; }
.cpm-input:focus { outline: none; border-color: #4F46E5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
textarea.cpm-input { resize: vertical; min-height: 80px; }

.assign-tech-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
    gap: 10px;
    max-height: 220px;
    overflow-y: auto;
    padding: 2px;
}
.assign-tech-card {
    position: relative;
    border: 1.5px solid #E2E8F0;
    border-radius: 12px;
    padding: 10px 12px;
    cursor: pointer;
    background: #fff;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
}
.assign-tech-card input[type="radio"] {
    position: absolute;
    top: 10px;
    right: 10px;
    accent-color: #4F46E5;
    cursor: pointer;
}
.assign-tech-card:hover:not(.is-busy) {
    border-color: #4F46E5;
    background: #F8FAFC;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.08);
}
.assign-tech-card:has(input:checked),
.assign-tech-card.selected {
    border-color: #4F46E5 !important;
    background: #EEF2FF !important;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25) !important;
}
.assign-tech-card.is-busy {
    opacity: 0.6;
    background: #F8FAFC;
    cursor: not-allowed;
}
.assign-tech-top {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.assign-tech-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4F46E5, #818CF8);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}
.assign-tech-main {
    flex: 1;
    min-width: 0;
}
.assign-tech-name {
    font-size: 13px;
    font-weight: 700;
    color: #0F172A;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.assign-tech-spec {
    font-size: 11px;
    color: #64748B;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.assign-tech-badges {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: auto;
}
.assign-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-avail { background: #ECFDF5; color: #059669; }
.badge-busy { background: #FFFBEB; color: #D97706; }
.badge-active { background: #F1F5F9; color: #475569; }
</style>

<div id="viewRepairModal" class="compact-premium-modal <?= $repairDetails ? 'show' : '' ?>">
    <div class="cpm-content">
        <div class="cpm-header">
            <h2><i class="fas fa-tools"></i> Repair Details #<?= $repairDetails['repair_id'] ?? '' ?></h2>
            <button type="button" class="cpm-close" onclick="closeRepairModal()">&times;</button>
        </div>
        <div class="cpm-body">
            <?php if ($repairDetails): ?>
                <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                    <span class="admin-badge bg-<?= strtolower(str_replace(' ', '-', $repairDetails['repair_status'])) ?>">Status: <?= $repairDetails['repair_status'] ?></span>
                    <span class="admin-badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;"><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i> Requested: <?= formatDateTime($repairDetails['date_requested']) ?></span>
                </div>

                <div class="cpm-section">
                    <h4><i class="fas fa-user" style="color: #4F46E5;"></i> Customer Information</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label>Name</label><value><?= sanitize($repairDetails['customer_name']) ?></value></div>
                        <div class="cpm-item"><label>Contact</label><value><?= sanitize($repairDetails['customer_contact']) ?></value></div>
                        <div class="cpm-item"><label>Email</label><value><?= sanitize($repairDetails['customer_email']) ?></value></div>
                        <div class="cpm-item full"><label>Address</label><value><?= sanitize($repairDetails['customer_address'] ?: 'Not provided') ?></value></div>
                    </div>
                </div>

                <div class="cpm-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px; margin-bottom: 12px;">
                        <h4 style="margin: 0; border: none; padding: 0;"><i class="fas fa-tv" style="color: #4F46E5;"></i> Appliance & Device Photo</h4>
                        <?php if (!empty($repairDetails['appliance_image'])): ?>
                            <button type="button" onclick="document.getElementById('quickAppliancePhotoInput').click()" style="background: none; border: none; color: #4F46E5; font-size: 11.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-camera"></i> Change Photo
                            </button>
                        <?php endif; ?>
                    </div>
                    <div style="display: grid; grid-template-columns: 190px 1fr; gap: 16px; align-items: start;">
                        
                        <div>
                            <?php if (!empty($repairDetails['appliance_image'])): 
                                $appImgUrl = (strpos($repairDetails['appliance_image'], 'http') === 0) 
                                    ? $repairDetails['appliance_image'] 
                                    : SITE_URL . '/' . ltrim($repairDetails['appliance_image'], '/');
                            ?>
                                <div style="position: relative; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.06); cursor: pointer;" onclick="openAdminPhoto('<?= htmlspecialchars($appImgUrl) ?>')">
                                    <img src="<?= htmlspecialchars($appImgUrl) ?>" alt="Repair Item" 
                                         style="width: 100%; height: 145px; object-fit: cover; display: block; transition: transform 0.25s;"
                                         onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                    <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(15,23,42,0.85)); color: #fff; font-size: 11px; font-weight: 600; padding: 16px 6px 6px; text-align: center;">
                                        <i class="fas fa-search-plus"></i> Click to enlarge
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; margin-top: 6px;">
                                    <button type="button" onclick="document.getElementById('quickAppliancePhotoInput').click()" 
                                            style="flex: 1; padding: 5px 8px; font-size: 11px; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px;">
                                        <i class="fas fa-camera"></i> Change
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Remove this repair item photo?');" style="margin: 0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="remove_repair_image" value="1">
                                        <input type="hidden" name="repair_id" value="<?= $repairDetails['repair_id'] ?>">
                                        <button type="submit" 
                                                title="Remove photo"
                                                style="padding: 5px 8px; font-size: 11px; font-weight: 600; border-radius: 6px; border: 1px solid #fecaca; background: #fef2f2; color: #dc2626; cursor: pointer;">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; padding: 18px 10px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 145px;">
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #e0e7ff; color: #4F46E5; display: flex; align-items: center; justify-content: center; font-size: 16px; margin-bottom: 8px;">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                    <span style="font-size: 11.5px; font-weight: 600; color: #64748b; margin-bottom: 8px;">No Photo Attached</span>
                                    <button type="button" onclick="document.getElementById('quickAppliancePhotoInput').click()" 
                                            style="padding: 5px 12px; font-size: 11.5px; font-weight: 600; border-radius: 6px; border: none; background: #4F46E5; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 4px rgba(79,70,229,0.2);">
                                        <i class="fas fa-plus"></i> Add Image
                                    </button>
                                </div>
                            <?php endif; ?>

                            
                            <form method="POST" enctype="multipart/form-data" id="quickAppliancePhotoForm" style="display: none;">
                                <?= csrfField() ?>
                                <input type="hidden" name="upload_repair_image" value="1">
                                <input type="hidden" name="repair_id" value="<?= $repairDetails['repair_id'] ?>">
                                <input type="file" name="appliance_image" id="quickAppliancePhotoInput" accept="image/*" onchange="document.getElementById('quickAppliancePhotoForm').submit()">
                            </form>
                        </div>

                        
                        <div class="cpm-grid" style="margin-top: 0;">
                            <div class="cpm-item"><label>Type</label><value><?= sanitize($repairDetails['appliance_type']) ?></value></div>
                            <div class="cpm-item"><label>Brand</label><value><?= sanitize($repairDetails['brand'] ?: 'N/A') ?></value></div>
                            <div class="cpm-item"><label>Model</label><value><?= sanitize($repairDetails['model'] ?: 'N/A') ?></value></div>
                            <div class="cpm-item"><label>Serial Number</label><value><?= sanitize($repairDetails['serial_number'] ?: 'N/A') ?></value></div>
                        </div>
                    </div>
                </div>

                <?php if ($repairDetails['technician_id']): ?>
                <div class="cpm-section">
                    <h4><i class="fas fa-user-cog" style="color: #4F46E5;"></i> Assigned Technician</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label>Name</label><value><?= sanitize($repairDetails['technician_name']) ?></value></div>
                        <div class="cpm-item"><label>Contact</label><value><?= sanitize($repairDetails['technician_contact']) ?></value></div>
                        <div class="cpm-item full"><label>Email</label><value><?= sanitize($repairDetails['technician_email']) ?></value></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="cpm-section">
                    <h4><i class="fas fa-clipboard-list" style="color: #4F46E5;"></i> Repair Notes & Diagnosis</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item full"><label>Reported Problem</label><value style="line-height: 1.5;"><?= nl2br(sanitize($repairDetails['problem_description'])) ?></value></div>
                        <?php if ($repairDetails['diagnosis']): ?>
                            <div class="cpm-item full"><label>Diagnosis</label><value style="line-height: 1.5; color: #4338ca; font-weight: 500; background: #e0e7ff; padding: 12px; border-radius: 8px;"><?= nl2br(sanitize($repairDetails['diagnosis'])) ?></value></div>
                        <?php endif; ?>
                        <?php if ($repairDetails['repair_notes']): ?>
                            <div class="cpm-item full"><label>Technician Notes</label><value style="line-height: 1.5;"><?= nl2br(sanitize($repairDetails['repair_notes'])) ?></value></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php 
                    $isModalDp = ($repairDetails['payment_type'] === 'downpayment') 
                                || (!empty($repairDetails['estimated_cost']) && (float)($repairDetails['amount_paid'] ?? 0) > 0 && (float)$repairDetails['amount_paid'] == (float)$repairDetails['estimated_cost'] && (float)$repairDetails['amount_paid'] < (float)$repairDetails['total_cost'] && ($repairDetails['payment_type'] ?? '') !== 'balance');
                    $isModalBal = ($repairDetails['payment_type'] === 'balance');
                    $modalPaid = (float)($repairDetails['amount_paid'] ?? 0);
                    if ($modalPaid <= 0 && !empty($repairDetails['payment_method'])) {
                        $modalPaid = $isModalDp ? (float)$repairDetails['estimated_cost'] : (float)($repairDetails['total_cost'] ?: $repairDetails['estimated_cost']);
                    }
                    $modalTotal = (float)($repairDetails['total_cost'] ?: $modalPaid);
                    $modalBalance = max(0, $modalTotal - $modalPaid);
                ?>
                <div class="cpm-section">
                    <h4><i class="fas fa-receipt" style="color: #4F46E5;"></i> Financial Breakdown</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label>Total Quoted Cost</label><value style="font-weight: 700; color: #0F172A; font-size: 15px;">₱<?= number_format($modalTotal, 2) ?></value></div>
                        <div class="cpm-item"><label>Downpayment (50%)</label><value><?= ($repairDetails['estimated_cost'] ?? 0) > 0 ? '₱' . number_format($repairDetails['estimated_cost'], 2) : '<span style="color:#94a3b8; font-weight:normal;">None</span>' ?></value></div>
                        <div class="cpm-item"><label>Amount Submitted</label><value style="font-weight: 700; color: #059669; font-size: 15px;">₱<?= number_format($modalPaid, 2) ?></value></div>
                        <div class="cpm-item"><label>Remaining Balance</label><value style="font-weight: 700; color: <?= $modalBalance > 0 ? '#DC2626' : '#059669' ?>; font-size: 15px;">₱<?= number_format($modalBalance, 2) ?></value></div>
                    </div>
                </div>

                <?php if (!empty($repairDetails['payment_method'])): ?>
                <div class="cpm-section" style="background: #4F46E5; border-color: #4F46E5;">
                    <h4 style="color: white; border-bottom-color: rgba(255,255,255,0.2);"><i class="fas fa-credit-card" style="color: white;"></i> Payment Status</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label style="color: rgba(255,255,255,0.7);">Plan / Type</label><value style="color: white; font-weight: 700;"><?= $isModalBal ? 'Remaining Balance' : ($isModalDp ? 'Downpayment (50%)' : 'Full Payment') ?></value></div>
                        <div class="cpm-item"><label style="color: rgba(255,255,255,0.7);">Method</label><value style="color: white;"><?= $repairDetails['payment_method'] ?></value></div>
                        <div class="cpm-item"><label style="color: rgba(255,255,255,0.7);">Amount</label><value style="color: #6EE7B7; font-weight: 700; font-size: 15px;">₱<?= number_format($modalPaid, 2) ?></value></div>
                        <div class="cpm-item"><label style="color: rgba(255,255,255,0.7);">Status</label><value>
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; background: <?= $repairDetails['payment_status'] === 'Verified' ? '#10b981' : ($repairDetails['payment_status'] === 'Rejected' ? '#ef4444' : 'rgba(255,255,255,0.25)') ?>; color: white;">
                                <?= $repairDetails['payment_status'] ?? 'Pending' ?>
                            </span>
                        </value></div>
                    </div>
                    <?php if (!empty($repairDetails['payment_reference']) || !empty($repairDetails['payment_proof'])): ?>
                    <div style="margin-top: 12px; padding: 10px 14px; background: rgba(0, 0, 0, 0.2); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <?php if (!empty($repairDetails['payment_reference'])): ?>
                        <div style="font-size: 12px; color: #e2e8f0; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                            <span>GCash Reference No:</span>
                            <strong style="font-family: monospace; background: rgba(255,255,255,0.15); padding: 2px 8px; border-radius: 4px; color: #ffffff;"><?= htmlspecialchars($repairDetails['payment_reference']) ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($repairDetails['payment_proof'])): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 4px;">
                            <span style="font-size: 12px; color: #e2e8f0;">Proof of Payment:</span>
                            <button type="button" class="btn btn-sm" style="background: #ffffff; color: #4338ca; border: none; font-size: 11.5px; font-weight: 700; padding: 4px 10px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;" onclick="viewPaymentProof('<?= SITE_URL . '/' . htmlspecialchars($repairDetails['payment_proof']) ?>', '<?= htmlspecialchars(addslashes($repairDetails['payment_reference'] ?? '')) ?>', <?= (int)$repairDetails['repair_id'] ?>)">
                                <i class="fas fa-image"></i> View GCash Receipt
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (($repairDetails['payment_status'] ?? 'Pending') === 'Pending'): ?>
                    <div style="margin-top: 16px; display: flex; gap: 8px;">
                        <button type="button" onclick="verifyPayment(<?= $repairDetails['repair_id'] ?>, 'verify')" style="flex: 1; padding: 8px; background: #10b981; border: none; color: white; border-radius: 6px; font-weight: 600; cursor: pointer;"><i class="fas fa-check"></i> Verify <?= $isModalBal ? 'Balance Payment' : ($isModalDp ? 'Downpayment' : 'Payment') ?></button>
                        <button type="button" onclick="verifyPayment(<?= $repairDetails['repair_id'] ?>, 'reject')" style="flex: 1; padding: 8px; background: #ef4444; border: none; color: white; border-radius: 6px; font-weight: 600; cursor: pointer;"><i class="fas fa-times"></i> Reject</button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="cpm-footer">
            <button type="button" class="cpm-btn cpm-btn-secondary" onclick="closeRepairModal()"><i class="fas fa-times"></i> Close</button>
            <?php if ($repairDetails && (!empty($repairDetails['payment_method']) || (float)($repairDetails['amount_paid'] ?? 0) > 0 || in_array($repairDetails['repair_status'], ['Completed', 'Returned']))): ?>
                <a href="../customer/receipt.php?id=<?= (int)$repairDetails['repair_id'] ?>" target="_blank" class="cpm-btn cpm-btn-secondary" style="background:#eff6ff; color:#1e40af; border-color:#bfdbfe; text-decoration:none;"><i class="fas fa-file-invoice"></i> Print / Download Receipt</a>
            <?php endif; ?>
            <?php if ($repairDetails && $repairDetails['repair_status'] === 'Completed' && ($repairDetails['payment_status'] ?? '') === 'Verified'): ?>
                <button type="button" id="returnBtn" class="cpm-btn cpm-btn-success" onclick="closeRepairModal(); setTimeout(function(){ openReturnModal(<?= (int)$repairDetails['repair_id'] ?>, '<?= htmlspecialchars(addslashes($repairDetails['customer_name'])) ?>', '<?= htmlspecialchars(addslashes($repairDetails['appliance_type'])) ?>'); }, 200);"><i class="fas fa-undo"></i> Record Return</button>
            <?php elseif ($repairDetails && $repairDetails['repair_status'] === 'Returned'): ?>
                <span class="admin-badge bg-completed" style="padding: 10px 20px;"><i class="fas fa-check-circle"></i> Item Returned</span>
            <?php elseif ($repairDetails): ?>
                <button type="button" class="cpm-btn cpm-btn-warning" onclick="editRepair(<?= (int)$repairDetails['repair_id'] ?>)"><i class="fas fa-edit"></i> Edit Repair</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="returnModal" class="compact-premium-modal">
    <div class="cpm-content" style="max-width: 520px;">
        <div class="cpm-header" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
            <h2><i class="fas fa-undo"></i> Record Item Return</h2>
            <button type="button" class="cpm-close" onclick="closeReturnModal()">&times;</button>
        </div>
        <form id="returnForm">
            <?= csrfField() ?>
            <input type="hidden" name="repair_id" id="return_repair_id">
            <div class="cpm-body">

                
                <div style="background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 12px; color: #065f46;"><strong>Repair:</strong> <span id="return_repair_label" style="font-weight: 700; color: #047857;"></span></span>
                    <span style="font-size: 12px; color: #065f46;"><strong>Customer:</strong> <span id="return_customer_label"></span></span>
                    <span style="font-size: 12px; color: #065f46;"><strong>Appliance:</strong> <span id="return_appliance_label"></span></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 12px; color: #1e293b; margin-bottom: 4px;">
                            Received By <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" name="received_by" id="return_received_by" required
                               style="width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: #fff;"
                               placeholder="Person who received the item">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Item Condition</label>
                        <select name="item_condition" id="return_item_condition"
                                style="width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: #fff; height: 36px;">
                            <option value="Excellent">Excellent</option>
                            <option value="Good" selected>Good</option>
                            <option value="Fair">Fair</option>
                            <option value="As Received">As Received</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-weight: 600; font-size: 12px; color: #1e293b; margin-bottom: 4px;">
                        Warranty Period (Days)
                    </label>
                    <input type="number" name="warranty_period" id="return_warranty_period" min="0" value="30"
                           style="width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: #fff;">
                    <small style="color: #64748b; font-size: 11px;">Enter 0 for no warranty</small>
                </div>

                <div>
                    <label style="display: block; font-weight: 600; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Remarks / Notes</label>
                    <textarea name="remarks" id="return_remarks" rows="2"
                              style="width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: #fff; resize: vertical; min-height: 60px; font-family: inherit;"
                              placeholder="Any notes about the return..."></textarea>
                </div>

                <div id="returnErrorMsg" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 12px; margin-top: 10px; color: #dc2626; font-size: 12.5px;"></div>
            </div>
            <div class="cpm-footer">
                <button type="button" class="cpm-btn cpm-btn-secondary" onclick="closeReturnModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="returnSubmitBtn" class="cpm-btn cpm-btn-success"><i class="fas fa-check"></i> Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<div id="editRepairModal" class="compact-premium-modal <?= $editRepair ? 'show' : '' ?>">
    <div class="cpm-content">
        <div class="cpm-header" style="background: #0f172a;">
            <h2><i class="fas fa-edit"></i> Edit Repair #<?= $editRepair['repair_id'] ?? '' ?></h2>
            <button type="button" class="cpm-close" onclick="closeEditRepairModal()">&times;</button>
        </div>
        <form method="POST" id="editRepairForm" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="repair_id" value="<?= $editRepair['repair_id'] ?? '' ?>">
            <div class="cpm-body">
                <?php if ($editRepair): ?>
                
                <div class="cpm-section">
                    <h4><i class="fas fa-tv" style="color: #4F46E5;"></i> Appliance Details</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item full">
                            <label>Appliance Type <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="appliance_type" class="cpm-input" required value="<?= sanitize($editRepair['appliance_type']) ?>">
                        </div>
                        <div class="cpm-item">
                            <label>Brand</label>
                            <input type="text" name="brand" class="cpm-input" value="<?= sanitize($editRepair['brand']) ?>">
                        </div>
                        <div class="cpm-item">
                            <label>Model</label>
                            <input type="text" name="model" class="cpm-input" value="<?= sanitize($editRepair['model']) ?>">
                        </div>
                        <div class="cpm-item full">
                            <label>Serial Number</label>
                            <input type="text" name="serial_number" class="cpm-input" value="<?= sanitize($editRepair['serial_number']) ?>">
                        </div>
                        <div class="cpm-item full">
                            <label>Appliance Photo</label>
                            <?php if (!empty($editRepair['appliance_image'])): 
                                $editImgUrl = (strpos($editRepair['appliance_image'], 'http') === 0) 
                                    ? $editRepair['appliance_image'] 
                                    : SITE_URL . '/' . ltrim($editRepair['appliance_image'], '/');
                            ?>
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                                    <img src="<?= htmlspecialchars($editImgUrl) ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <span style="font-size: 12px; color: #64748b;">Current photo attached. Select a new file to change it.</span>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="appliance_image" class="cpm-input" accept="image/*">
                        </div>
                    </div>
                </div>

                <div class="cpm-section">
                    <h4><i class="fas fa-clipboard-list" style="color: #4F46E5;"></i> Problem & Diagnosis</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item full">
                            <label>Problem Description <span style="color: #ef4444;">*</span></label>
                            <textarea name="problem_description" class="cpm-input" required><?= sanitize($editRepair['problem_description']) ?></textarea>
                        </div>
                        <div class="cpm-item full">
                            <label>Diagnosis</label>
                            <textarea name="diagnosis" class="cpm-input"><?= sanitize($editRepair['diagnosis']) ?></textarea>
                        </div>
                        <div class="cpm-item full">
                            <label>Repair Notes</label>
                            <textarea name="repair_notes" class="cpm-input"><?= sanitize($editRepair['repair_notes']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="cpm-section">
                    <h4><i class="fas fa-cogs" style="color: #4F46E5;"></i> Status & Costs</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item full">
                            <label>Repair Status <span style="color: #ef4444;">*</span></label>
                            <select name="repair_status" class="cpm-input" required>
                                <option value="Pending" <?= $editRepair['repair_status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Assigned" <?= $editRepair['repair_status'] === 'Assigned' ? 'selected' : '' ?>>Assigned</option>
                                <option value="In Progress" <?= $editRepair['repair_status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= $editRepair['repair_status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="Cancelled" <?= $editRepair['repair_status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="On Hold" <?= $editRepair['repair_status'] === 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                            </select>
                        </div>
                        <div class="cpm-item">
                            <label>Downpayment (₱)</label>
                            <input type="number" name="estimated_cost" class="cpm-input" step="0.01" min="0" placeholder="0.00" value="<?= $editRepair['estimated_cost'] ?>">
                        </div>
                        <div class="cpm-item">
                            <label>Total Cost (₱)</label>
                            <input type="number" name="total_cost" class="cpm-input" step="0.01" min="0" value="<?= $editRepair['total_cost'] ?>">
                        </div>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
            <div class="cpm-footer">
                <button type="button" class="cpm-btn cpm-btn-secondary" onclick="closeEditRepairModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="edit_repair" class="cpm-btn cpm-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="assignTechnicianModal" class="compact-premium-modal <?= $assignRepair ? 'show' : '' ?>">
    <div class="cpm-content" style="max-width: 680px;">
        <div class="cpm-header" style="background: linear-gradient(135deg, #4F46E5, #6366F1);">
            <h2><i class="fas fa-user-plus"></i> Assign Technician <span id="assignModalTitleId"><?= $assignRepair ? '#' . $assignRepair['repair_id'] : '' ?></span></h2>
            <button type="button" class="cpm-close" onclick="closeAssignModal()">&times;</button>
        </div>
        
        <form method="POST" id="assignTechnicianForm">
            <?= csrfField() ?>
            <input type="hidden" name="assign_technician" value="1">
            <input type="hidden" name="repair_id" id="assignRepairIdInput" value="<?= $assignRepair['repair_id'] ?? '' ?>">
            
            <div class="cpm-body">
                <?php if (!empty($_SESSION['errors'])): ?>
                    <div style="background: #FEF2F2; border: 1.5px solid #FCA5A5; color: #991B1B; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px;">
                        <div style="font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-exclamation-circle" style="color: #DC2626;"></i> Unable to assign technician:
                        </div>
                        <ul style="margin: 0; padding-left: 18px;">
                            <?php foreach ($_SESSION['errors'] as $err): ?>
                                <li><?= sanitize($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                
                <div class="cpm-section" style="background: #F8FAFC; border-left: 4px solid #4F46E5; margin-bottom: 16px;">
                    <div class="cpm-grid" style="grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748B;">Customer</div>
                            <div id="assignModalCustomer" style="font-weight: 600; color: #0F172A; font-size: 13.5px;"><?= sanitize($assignRepair['customer_name'] ?? '—') ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748B;">Appliance</div>
                            <div id="assignModalAppliance" style="font-weight: 600; color: #0F172A; font-size: 13.5px;"><?= sanitize(($assignRepair['appliance_type'] ?? '—') . (!empty($assignRepair['brand']) ? ' (' . $assignRepair['brand'] . ')' : '')) ?></div>
                        </div>
                        <div class="full" style="grid-column: 1 / -1; margin-top: 4px;">
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748B;">Problem Description</div>
                            <div id="assignModalProblem" style="font-size: 12.5px; color: #475569; line-height: 1.4;"><?= htmlspecialchars_decode(sanitize($assignRepair['problem_description'] ?? '—'), ENT_QUOTES) ?></div>
                        </div>
                    </div>
                </div>

                
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #0F172A; margin-bottom: 8px;">
                        <i class="fas fa-user-cog" style="color: #4F46E5; margin-right: 4px;"></i> Select Technician <span style="color:#DC2626;">*</span>
                    </label>
                    <div class="assign-tech-grid">
                        <?php foreach ($technicians as $tech): ?>
                            <?php $isAvail = $tech['availability'] === 'Available'; ?>
                            <label class="assign-tech-card <?= !$isAvail ? 'is-busy' : '' ?>">
                                <input type="radio" name="technician_id" value="<?= $tech['technician_id'] ?>" 
                                       <?= !$isAvail ? 'disabled' : '' ?>
                                       <?= ($assignRepair && $assignRepair['technician_id'] == $tech['technician_id']) ? 'checked' : '' ?> required>
                                <div class="assign-tech-card-content">
                                    <div class="assign-tech-top">
                                        <div class="assign-tech-avatar"><?= strtoupper(substr($tech['full_name'], 0, 2)) ?></div>
                                        <div class="assign-tech-main">
                                            <div class="assign-tech-name"><?= sanitize($tech['full_name']) ?></div>
                                            <div class="assign-tech-spec"><?= sanitize($tech['specialization'] ?: 'General Appliance') ?></div>
                                        </div>
                                    </div>
                                    <div class="assign-tech-badges">
                                        <span class="assign-badge <?= $isAvail ? 'badge-avail' : 'badge-busy' ?>">
                                            <i class="fas <?= $isAvail ? 'fa-check-circle' : 'fa-clock' ?>"></i> <?= $tech['availability'] ?>
                                        </span>
                                        <span class="assign-badge badge-active">
                                            <?= (int)$tech['active_repairs'] ?> active
                                        </span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                
                <div class="cpm-section" style="margin-bottom: 0;">
                    <h4 style="margin: 0 0 12px 0; color: #0F172A; font-size: 13px; font-weight: 700; border-bottom: 1px dashed #CBD5E1; padding-bottom: 8px;">
                        <i class="fas fa-calendar-check" style="color: #4F46E5;"></i> Schedule & Estimation
                    </h4>
                    <?php
                    $curHour = (int)date('H');
                    $defStart = sprintf('%02d:00', max(8, min(18, $curHour)));
                    $defEnd   = sprintf('%02d:00', max(9, min(19, $curHour + 1)));
                    ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Date <span style="color:#DC2626;">*</span></label>
                            <input type="date" name="scheduled_date" id="assignModalDate" class="cpm-input" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Start Time</label>
                            <input type="time" name="scheduled_time" id="assignModalStartTime" class="cpm-input" value="<?= $defStart ?>" min="08:00" max="19:00">
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">End Time</label>
                            <input type="time" name="end_time" id="assignModalEndTime" class="cpm-input" value="<?= $defEnd ?>" min="08:00" max="19:00">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Total Cost (₱)</label>
                            <input type="number" step="0.01" min="0" name="total_cost" id="assignModalTotalCost" class="cpm-input" placeholder="0.00" value="<?= $assignRepair['total_cost'] ?? '' ?>">
                            <small style="color: #64748b; font-size: 11px;">Quoted repair cost</small>
                        </div>
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; margin: 0;">Downpayment (₱)</label>
                                <button type="button" onclick="calcAssignDp50()" style="background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; cursor: pointer;" title="Calculate 50% deposit">
                                    <i class="fas fa-calculator"></i> Auto 50%
                                </button>
                            </div>
                            <input type="number" step="0.01" min="0" name="estimated_cost" id="assignModalCost" class="cpm-input" placeholder="0.00" value="<?= $assignRepair['estimated_cost'] ?? '' ?>">
                            <small style="color: #64748b; font-size: 11px;">Initial deposit (auto 50%)</small>
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase;">Notes / Initial Diagnosis</label>
                        <textarea name="notes" id="assignModalNotes" class="cpm-input" placeholder="Enter instructions, initial diagnosis, or parts needed..." style="min-height: 60px;"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="cpm-footer">
                <button type="button" class="cpm-btn cpm-btn-secondary" onclick="closeAssignModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="assign_technician" class="cpm-btn cpm-btn-primary" style="background: #4F46E5; border-color: #4F46E5;">
                    <i class="fas fa-check"></i> Assign Technician
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewRepair(repairId) {
    const url = new URL(window.location);
    url.searchParams.set('view_id', repairId);
    window.location.href = url.toString();
}

function editRepair(repairId) {
    const url = new URL(window.location);
    url.searchParams.set('edit_id', repairId);
    window.location.href = url.toString();
}

function openAssignModal(repairId, customer, appliance, problem, cost, techId, totalCost) {
    document.getElementById('assignRepairIdInput').value = repairId;
    document.getElementById('assignModalTitleId').textContent = '#' + repairId;
    document.getElementById('assignModalCustomer').textContent = customer || '—';
    document.getElementById('assignModalAppliance').textContent = appliance || '—';
    
    
    const txt = document.createElement('textarea');
    txt.innerHTML = problem || '—';
    document.getElementById('assignModalProblem').textContent = txt.value;

    const tVal = (totalCost !== undefined && totalCost !== null && totalCost !== '') ? parseFloat(totalCost) : null;
    let dVal = (cost !== undefined && cost !== null && cost !== '') ? parseFloat(cost) : null;

    
    if (tVal && tVal > 0 && (!dVal || dVal === 0)) {
        dVal = Math.round((tVal * 0.5) * 100) / 100;
    }

    document.getElementById('assignModalTotalCost').value = (tVal !== null) ? tVal.toFixed(2) : '';
    document.getElementById('assignModalCost').value = (dVal !== null) ? dVal.toFixed(2) : '';
    
    const radios = document.querySelectorAll('#assignTechnicianModal input[name="technician_id"]');
    radios.forEach(r => {
        const card = r.closest('.assign-tech-card');
        if (techId && parseInt(r.value) === parseInt(techId)) {
            r.checked = true;
            if (card) card.classList.add('selected');
        } else if (!techId) {
            r.checked = false;
            if (card) card.classList.remove('selected');
        }
    });

    document.getElementById('assignTechnicianModal').classList.add('show');
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.assign-tech-card').forEach(card => {
        card.addEventListener('click', function() {
            const radio = this.querySelector('input[type="radio"]');
            if (radio && !radio.disabled) {
                radio.checked = true;
                document.querySelectorAll('.assign-tech-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
            }
        });
    });

    
    document.getElementById('assignTechnicianForm')?.addEventListener('submit', function(e) {
        const selectedTech = this.querySelector('input[name="technician_id"]:checked');
        if (!selectedTech) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Please Select a Technician',
                text: 'You must select an available technician before assigning.',
                confirmButtonColor: '#4F46E5'
            });
            return false;
        }
    });
});

document.getElementById('assignModalTotalCost')?.addEventListener('input', function() {
    const dpInput = document.getElementById('assignModalCost');
    const total = parseFloat(this.value);
    if (!isNaN(total) && total > 0) {
        dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
    } else if (this.value === '' || total === 0) {
        dpInput.value = '';
    }
});

function calcAssignDp50() {
    const totalInput = document.getElementById('assignModalTotalCost');
    const dpInput = document.getElementById('assignModalCost');
    const total = parseFloat(totalInput.value);
    if (!isNaN(total) && total > 0) {
        dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
    }
}

document.querySelector('#editRepairModal input[name="total_cost"]')?.addEventListener('input', function() {
    const dpInput = document.querySelector('#editRepairModal input[name="estimated_cost"]');
    const total = parseFloat(this.value);
    if (!isNaN(total) && total > 0 && dpInput) {
        dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
    } else if ((this.value === '' || total === 0) && dpInput) {
        dpInput.value = '';
    }
});

function closeAssignModal() {
    document.getElementById('assignTechnicianModal').classList.remove('show');
    const url = new URL(window.location);
    if (url.searchParams.has('assign_id')) {
        url.searchParams.delete('assign_id');
        window.history.replaceState({}, '', url.toString());
    }
}

function closeRepairModal() {
    const url = new URL(window.location);
    url.searchParams.delete('view_id');
    window.location.href = url.toString();
}

function closeEditRepairModal() {
    const url = new URL(window.location);
    url.searchParams.delete('edit_id');
    window.location.href = url.toString();
}

function viewPaymentProof(imageUrl, refNo, repairId) {
    const repNo = String(repairId).padStart(5, '0');
    Swal.fire({
        title: 'Proof of Payment #' + repNo,
        html: `
            <div style="text-align: center; margin-top: 6px;">
                ${refNo ? `<div style="margin-bottom: 12px; font-size: 13px; color: #475569;"><strong>GCash Ref / Trans No:</strong> <span style="font-family: monospace; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 3px 8px; border-radius: 4px; font-weight: 700;">${refNo}</span></div>` : ''}
                <div style="max-height: 480px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: #0f172a; padding: 8px; display: flex; align-items: center; justify-content: center;">
                    <img src="${imageUrl}" alt="Payment Proof" style="max-width: 100%; max-height: 460px; object-fit: contain; border-radius: 4px;">
                </div>
                <div style="margin-top: 12px;">
                    <a href="${imageUrl}" target="_blank" style="font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-external-link-alt"></i> Open Full Image in New Tab
                    </a>
                </div>
            </div>
        `,
        width: 540,
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-check"></i> Verify Payment',
        cancelButtonText: 'Close'
    }).then((result) => {
        if (result.isConfirmed) {
            verifyPayment(repairId, 'verify');
        }
    });
}

async function verifyPayment(repairId, action) {
    const actionText = action === 'verify' ? 'verify' : 'reject';
    const confirmMsg = action === 'verify' 
        ? 'Are you sure you want to verify this payment? The repair status will be changed to Returned.'
        : 'Are you sure you want to reject this payment? The customer will need to select a new payment method.';
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
        
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Response:', text);
            throw new Error('Server returned invalid response');
        }
        
        if (result.success) {
            await Swal.fire({icon:'success',title:'Success',text:result.message,confirmButtonColor:'#272343',timer:2500,timerProgressBar:true});
            location.reload();
        } else {
            Swal.fire({icon:'error',title:'Error',text:result.message,confirmButtonColor:'#272343'});
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({icon:'error',title:'Error',text:error.message,confirmButtonColor:'#272343'});
    }
}

function openReturnModal(repairId, customerName, appliance) {
    document.getElementById('return_repair_id').value = repairId;
    document.getElementById('return_repair_label').textContent = '#' + repairId;
    document.getElementById('return_customer_label').textContent = customerName;
    document.getElementById('return_appliance_label').textContent = appliance;
    document.getElementById('return_received_by').value = customerName;
    document.getElementById('return_item_condition').value = 'Good';
    document.getElementById('return_warranty_period').value = 30;
    document.getElementById('return_remarks').value = '';
    document.getElementById('returnErrorMsg').style.display = 'none';
    document.getElementById('returnSubmitBtn').disabled = false;
    document.getElementById('returnSubmitBtn').innerHTML = '<i class="fas fa-check"></i> Confirm Return';
    document.getElementById('returnModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.getElementById('returnForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const receivedBy = document.getElementById('return_received_by').value.trim();
    if (!receivedBy) {
        const errEl = document.getElementById('returnErrorMsg');
        errEl.textContent = 'Please enter who received the item.';
        errEl.style.display = 'block';
        return;
    }
    document.getElementById('returnErrorMsg').style.display = 'none';
    const submitBtn = document.getElementById('returnSubmitBtn');
    const origHTML = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    try {
        const formData = new FormData(this);
        const response = await fetch('process-return.php', { method: 'POST', body: formData });
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); } catch(e) { throw new Error('Server returned invalid response'); }

        if (result.success) {
            closeReturnModal();
            await Swal.fire({ icon: 'success', title: 'Returned!', text: result.message, confirmButtonColor: '#059669', timer: 2500, timerProgressBar: true });
            location.reload();
        } else {
            const errEl = document.getElementById('returnErrorMsg');
            errEl.textContent = result.message || 'Failed to record return.';
            errEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = origHTML;
        }
    } catch (error) {
        console.error('Error:', error);
        const errEl = document.getElementById('returnErrorMsg');
        errEl.textContent = error.message || 'An error occurred. Please try again.';
        errEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = origHTML;
    }
});

document.getElementById('returnModal').addEventListener('click', function(e) {
    if (e.target === this) closeReturnModal();
});

function deleteRepair(repairId, applianceType) {
    Swal.fire({
        title: 'Delete Repair #' + repairId + '?',
        html: 'Are you sure you want to permanently delete this repair record?<br><strong>' + applianceType + '</strong><br><br><small class="text-muted">This will also delete all related schedules, return records, and logs.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'repairs.php';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= generateCSRFToken() ?>';
            form.appendChild(csrfInput);
            
            const repairInput = document.createElement('input');
            repairInput.type = 'hidden';
            repairInput.name = 'repair_id';
            repairInput.value = repairId;
            form.appendChild(repairInput);
            
            const deleteInput = document.createElement('input');
            deleteInput.type = 'hidden';
            deleteInput.name = 'delete_repair';
            deleteInput.value = '1';
            form.appendChild(deleteInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

<?php if ($repairDetails): ?>
    document.getElementById('viewRepairModal').classList.add('show');
<?php endif; ?>

<?php if ($editRepair): ?>
    document.getElementById('editRepairModal').classList.add('show');
<?php endif; ?>

<?php if ($assignRepair): ?>
    openAssignModal(
        <?= $assignRepair['repair_id'] ?>, 
        '<?= htmlspecialchars(addslashes($assignRepair['customer_name'])) ?>', 
        '<?= htmlspecialchars(addslashes($assignRepair['appliance_type'] . ($assignRepair['brand'] ? ' (' . $assignRepair['brand'] . ')' : ''))) ?>', 
        '<?= htmlspecialchars(addslashes($assignRepair['problem_description'])) ?>', 
        '<?= $assignRepair['estimated_cost'] ?? '' ?>', 
        <?= (int)($assignRepair['technician_id'] ?? 0) ?>,
        '<?= $assignRepair['total_cost'] ?? '' ?>'
    );
<?php endif; ?>

function openImageModal(src) {
    const modal = document.createElement('div');
    modal.className = 'image-lightbox';
    modal.innerHTML = `
        <div class="image-lightbox-content">
            <span class="image-lightbox-close">&times;</span>
            <img src="${src}" alt="Appliance Photo">
        </div>
    `;
    document.body.appendChild(modal);
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal || e.target.classList.contains('image-lightbox-close')) {
            modal.remove();
        }
    });
    
    document.addEventListener('keydown', function closeOnEscape(e) {
        if (e.key === 'Escape') {
            modal.remove();
            document.removeEventListener('keydown', closeOnEscape);
        }
    });
}
const openAdminPhoto = openImageModal;

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
            await Swal.fire({icon:'success',title:'Success',text:result.message,confirmButtonColor:'#272343',timer:2000,timerProgressBar:true});
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

<style>

.appliance-image-container {
    text-align: center;
}

.appliance-image {
    max-width: 100%;
    max-height: 300px;
    border-radius: var(--radius);
    cursor: pointer;
    transition: transform var(--transition), box-shadow var(--transition);
    border: 1px solid var(--border);
}

.appliance-image:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.image-hint {
    display: block;
    margin-top: 0.5rem;
    color: var(--text-muted);
    font-size: 0.813rem;
}

.image-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

.image-lightbox-content {
    position: relative;
    max-width: 90%;
    max-height: 90%;
}

.image-lightbox-content img {
    max-width: 100%;
    max-height: 90vh;
    border-radius: var(--radius);
    box-shadow: 0 4px 30px rgba(0, 0, 0, 0.5);
}

.image-lightbox-close {
    position: absolute;
    top: -40px;
    right: 0;
    font-size: 2rem;
    color: white;
    cursor: pointer;
    transition: color var(--transition);
}

.image-lightbox-close:hover {
    color: var(--danger);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
