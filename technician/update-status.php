<?php

require_once '../config/config.php';
$pageTitle = 'Update Repair Status - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();
$repairId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$techId = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT r.*, c.full_name as customer_name, c.user_id as customer_user_id
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.repair_id = ? AND r.technician_id = ?
");
$stmt->execute([$repairId, $techId]);
$repair = $stmt->fetch();

if (!$repair) {
    redirect('repairs.php', 'Repair not found or not assigned to you.', 'danger');
}

if (in_array($repair['repair_status'], ['Completed', 'Returned'])) {
    redirect('repair-detail.php?id=' . $repairId, 'This repair is ' . $repair['repair_status'] . '. No further updates allowed.', 'warning');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $newStatus = $_POST['repair_status'] ?? '';
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $repairNotes = sanitize($_POST['repair_notes'] ?? '');
        $totalCost = !empty($_POST['total_cost']) ? (float)$_POST['total_cost'] : null;
        $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : null;
        
        $allowedStatuses = ['In Progress', 'Completed', 'On Hold'];
        if (!in_array($newStatus, $allowedStatuses)) {
            $errors[] = 'Invalid status selected.';
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                $oldStatus = $repair['repair_status'];
                $dateStarted = $repair['date_started'];
                $dateCompleted = null;
                
                
                if ($newStatus === 'In Progress' && !$dateStarted) {
                    $dateStarted = date('Y-m-d H:i:s');
                }
                
                
                if ($newStatus === 'Completed') {
                    $dateCompleted = date('Y-m-d H:i:s');
                }
                
                
                $stmt = $db->prepare("
                    UPDATE repairs 
                    SET repair_status = ?,
                        diagnosis = ?,
                        repair_notes = ?,
                        estimated_cost = COALESCE(?, estimated_cost),
                        total_cost = COALESCE(?, total_cost),
                        date_started = ?,
                        date_completed = ?
                    WHERE repair_id = ?
                ");
                $stmt->execute([$newStatus, $diagnosis, $repairNotes, $estimatedCost, $totalCost, $dateStarted, $dateCompleted, $repairId]);
                
                
                logRepairAction($repairId, $_SESSION['user_id'], 'Status Updated', $oldStatus, $newStatus, $repairNotes);
                
                
                if ($newStatus === 'In Progress') {
                    $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'In Progress' WHERE repair_id = ?");
                    $stmt->execute([$repairId]);
                } elseif ($newStatus === 'Completed') {
                    $stmt = $db->prepare("UPDATE schedules SET schedule_status = 'Completed', actual_completion = CURDATE() WHERE repair_id = ?");
                    $stmt->execute([$repairId]);
                }
                
                
                $statusMessages = [
                    'In Progress' => 'Your repair (#{repair_id}) is now being worked on by our technician. Item has been received and is under repair.',
                    'Completed' => 'Great news! Your repair (#{repair_id}) has been completed and is ready for pickup. Please visit our store to collect your item. Note: We do not offer home delivery.',
                    'On Hold' => 'Your repair (#{repair_id}) has been put on hold. We will contact you shortly.'
                ];
                
                $notifType = 'Info';
                if ($newStatus === 'Completed') {
                    $notifType = 'Success';
                    
                    
                    $completionDate = date('Y-m-d');
                    $warrantyEnd = date('Y-m-d', strtotime('+30 days'));
                    $graceEnd = date('Y-m-d', strtotime('+60 days'));
                    $wStmt = $db->prepare("
                        INSERT IGNORE INTO warranty_tracking (repair_id, completion_date, warranty_end_date, grace_period_end_date)
                        VALUES (?, ?, ?, ?)
                    ");
                    $wStmt->execute([$repairId, $completionDate, $warrantyEnd, $graceEnd]);
                }
                
                $message = str_replace('{repair_id}', $repairId, $statusMessages[$newStatus]);
                createNotification(
                    $repair['customer_user_id'],
                    "Repair Status: $newStatus",
                    $message,
                    $notifType,
                    $repairId
                );
                
                $db->commit();
                redirect('repair-detail.php?id=' . $repairId, 'Status updated successfully!', 'success');
                
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to update status. Please try again.';
            }
        }
    }
}
?>

<div class="page-header">
    <h1><i class="fas fa-edit"></i> Update Repair Status</h1>
    <a href="repair-detail.php?id=<?= $repairId ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-wrench"></i> Repair #<?= $repairId ?></h2>
        <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?> badge-lg">
            <?= $repair['repair_status'] ?>
        </span>
    </div>
    <div class="card-body">
        <div class="detail-grid-2">
            <div class="detail-row">
                <label>Appliance:</label>
                <span><?= sanitize($repair['appliance_type']) ?> <?= $repair['brand'] ? "({$repair['brand']})" : '' ?></span>
            </div>
            <div class="detail-row">
                <label>Customer:</label>
                <span><?= sanitize($repair['customer_name']) ?></span>
            </div>
            <div class="detail-row">
                <label>Problem:</label>
                <span><?= sanitize($repair['problem_description']) ?></span>
            </div>
            <div class="detail-row">
                <label>Downpayment (₱):</label>
                <span><?= formatCurrency($repair['estimated_cost']) ?></span>
            </div>

        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-clipboard-check"></i> Update Information</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            
            <div class="form-group">
                <label for="repair_status">New Status: <span class="required">*</span></label>
                <select id="repair_status" name="repair_status" class="form-control" required>
                    <?php if ($repair['repair_status'] === 'Assigned'): ?>
                        <option value="In Progress">🔧 Start Repair (In Progress)</option>
                        <option value="On Hold">⏸️ Put On Hold</option>
                    <?php elseif ($repair['repair_status'] === 'In Progress'): ?>
                        <option value="In Progress">🔧 Keep In Progress</option>
                        <option value="Completed">✅ Mark as Completed</option>
                        <option value="On Hold">⏸️ Put On Hold</option>
                    <?php elseif ($repair['repair_status'] === 'On Hold'): ?>
                        <option value="In Progress">🔧 Resume (In Progress)</option>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="diagnosis">Diagnosis:</label>
                <textarea id="diagnosis" name="diagnosis" class="form-control" rows="3" 
                          placeholder="What was the issue?"><?= sanitize($repair['diagnosis']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="repair_notes">Repair Notes:</label>
                <textarea id="repair_notes" name="repair_notes" class="form-control" rows="3" 
                          placeholder="What was done to fix it?"><?= sanitize($repair['repair_notes']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="estimated_cost">Downpayment (₱):</label>
                <input type="number" id="estimated_cost" name="estimated_cost" class="form-control" 
                       step="0.01" min="0" placeholder="0.00" value="<?= $repair['estimated_cost'] ?>">
                <small class="text-muted">Initial deposit amount</small>
            </div>
            
            <div class="form-group">
                <label for="total_cost">Total Cost (₱):</label>
                <input type="number" id="total_cost" name="total_cost" class="form-control" 
                       step="0.01" min="0" value="<?= $repair['total_cost'] ?? $repair['estimated_cost'] ?>">
                <small class="text-muted">Final cost (update when completing repair)</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Status
                </button>
                <a href="repairs.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
