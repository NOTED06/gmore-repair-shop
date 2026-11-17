<?php

require_once '../config/config.php';
requireRole('Admin');

$repairId = (int)($_GET['id'] ?? 0);
if ($repairId > 0) {
    redirect('repairs.php?assign_id=' . $repairId);
} else {
    redirect('repairs.php');
}

if ($repair['repair_status'] === 'Returned') {
    redirect('repairs.php', 'This repair has been returned. No further changes allowed.', 'warning');
}

$stmt = $db->query("
    SELECT t.*, 
           (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status IN ('Assigned', 'In Progress')) as active_repairs
    FROM technicians t
    JOIN users u ON t.user_id = u.user_id
    WHERE u.status = 'Active'
    ORDER BY t.availability DESC, active_repairs ASC
");
$technicians = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
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
        
        if (!$technicianId) {
            $errors[] = 'Please select a technician.';
        }
        
        
        if (!empty($scheduledDate)) {
            $now = new DateTime();
            $today = $now->format('Y-m-d');
            $currentTime = $now->format('H:i');
            
            if ($scheduledDate < $today) {
                $errors[] = 'Scheduled date cannot be in the past.';
            } elseif ($scheduledDate === $today && !empty($scheduledTime) && $scheduledTime < $currentTime) {
                $errors[] = 'Start time cannot be in the past for today.';
            }
            
            if (!empty($scheduledTime) && !empty($endTime) && $endTime <= $scheduledTime) {
                $errors[] = 'End time must be after the start time.';
            }
            
            
            if (!empty($scheduledTime)) {
                if ($scheduledTime < '08:00' || $scheduledTime > '19:00') {
                    $errors[] = 'Start time must be within business hours (8:00 AM to 7:00 PM).';
                }
            }
            if (!empty($endTime)) {
                if ($endTime < '08:00' || $endTime > '19:00') {
                    $errors[] = 'End time must be within business hours (8:00 AM to 7:00 PM).';
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                $oldStatus = $repair['repair_status'];
                
                
                $stmtDiag = $db->prepare("SELECT diagnosis FROM repairs WHERE repair_id = ?");
                $stmtDiag->execute([$repairId]);
                $existingDiag = $stmtDiag->fetchColumn();
                $newDiagnosis = $notes !== '' ? (($existingDiag ? $existingDiag . "\n" : '') . $notes) : $existingDiag;

                
                $stmt = $db->prepare("
                    UPDATE repairs 
                    SET technician_id = ?, 
                        repair_status = 'Assigned', 
                        estimated_cost = ?,
                        total_cost = COALESCE(?, total_cost),
                        diagnosis = ?
                    WHERE repair_id = ?
                ");
                $stmt->execute([$technicianId, $estimatedCost, $totalCost, $newDiagnosis, $repairId]);
                
                
                logRepairAction($repairId, $_SESSION['user_id'], 'Technician Assigned', $oldStatus, 'Assigned', $notes);
                
                
                $stmt = $db->prepare("SELECT user_id, full_name FROM technicians WHERE technician_id = ?");
                $stmt->execute([$technicianId]);
                $tech = $stmt->fetch();
                
                
                if (!empty($scheduledDate)) {
                    $stmt = $db->prepare("
                        INSERT INTO schedules (repair_id, technician_id, scheduled_date, scheduled_time, end_time, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $repairId,
                        $technicianId,
                        $scheduledDate,
                        $scheduledTime ?: null,
                        $endTime ?: null,
                        $notes ?: null
                    ]);
                }

                
                $schedMsg = !empty($scheduledDate) ? " Scheduled: " . date('M d, Y', strtotime($scheduledDate)) . ($scheduledTime ? ' at ' . date('h:i A', strtotime($scheduledTime)) : '') . '.' : '';
                createNotification(
                    $tech['user_id'],
                    'New Repair Assigned',
                    "You have been assigned to repair #{$repairId}: {$repair['appliance_type']}.{$schedMsg}",
                    'Info',
                    $repairId
                );
                
                
                $stmt = $db->prepare("SELECT user_id FROM customers WHERE customer_id = ?");
                $stmt->execute([$repair['customer_id']]);
                $customer = $stmt->fetch();
                
                
                $customerMsg = "A technician ({$tech['full_name']}) has been assigned to your repair request #{$repairId}.";
                createNotification(
                    $customer['user_id'],
                    'Technician Assigned',
                    $customerMsg,
                    'Success',
                    $repairId
                );

                $db->commit();
                
                
                if (!empty($repair['contact_no'])) {
                    try {
                        require_once '../includes/SmsQueue.php';
                        $smsQueue = new SmsQueue($db);
                        $smsMessage = getSetting('site_name', SITE_NAME) . ": " . $customerMsg;
                        $smsQueue->add($repair['contact_no'], $smsMessage);
                    } catch (Throwable $se) {
                        error_log('SMS send error: ' . $se->getMessage());
                    }
                }
                
                redirect('repair-view.php?id=' . $repairId, 'Technician assigned successfully!', 'success');
                
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                
                $errors[] = 'Failed to assign technician: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="page-header">
    <h1><i class="fas fa-user-plus"></i> Assign Technician</h1>
    <a href="repair-view.php?id=<?= $repairId ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Repair
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
    </div>
    <div class="card-body">
        <div class="detail-grid-2">
            <div class="detail-row">
                <label>Customer:</label>
                <span><?= sanitize($repair['customer_name']) ?></span>
            </div>
            <div class="detail-row">
                <label>Appliance:</label>
                <span><?= sanitize($repair['appliance_type']) ?> <?= $repair['brand'] ? "({$repair['brand']})" : '' ?></span>
            </div>
            <div class="detail-row">
                <label>Problem:</label>
                <span><?= sanitize($repair['problem_description']) ?></span>
            </div>
            <div class="detail-row">
                <label>Current Status:</label>
                <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>"><?= $repair['repair_status'] ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-user-cog"></i> Select Technician</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            
            <div class="form-group">
                <label>Available Technicians:</label>
                <div class="technician-grid">
                    <?php foreach ($technicians as $tech): ?>
                        <label class="technician-card <?= $tech['availability'] !== 'Available' ? 'unavailable' : '' ?>">
                            <input type="radio" name="technician_id" value="<?= $tech['technician_id'] ?>"
                                   <?= $tech['availability'] !== 'Available' ? 'disabled' : '' ?>
                                   <?= ($repair['technician_id'] == $tech['technician_id']) ? 'checked' : '' ?>>
                            <div class="tech-info">
                                <h4><?= sanitize($tech['full_name']) ?></h4>
                                <p class="specialization"><?= sanitize($tech['specialization'] ?: 'General') ?></p>
                                <p class="contact"><i class="fas fa-phone"></i> <?= sanitize($tech['contact_no']) ?></p>
                                <div class="tech-stats">
                                    <span class="badge <?= getStatusBadgeClass($tech['availability']) ?>">
                                        <?= $tech['availability'] ?>
                                    </span>
                                    <span class="badge badge-info">
                                        <?= $tech['active_repairs'] ?> active repairs
                                    </span>
                                    <span class="badge badge-secondary">
                                        <?= $tech['experience_years'] ?> yrs exp
                                    </span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label for="total_cost">Total Cost / Quotation (₱):</label>
                    <input type="number" id="total_cost" name="total_cost" class="form-control" 
                           step="0.01" min="0" placeholder="e.g. 1500.00" value="<?= !empty($repair['total_cost']) ? (float)$repair['total_cost'] : '' ?>">
                    <small class="text-muted" style="font-size: 11px;">Quoted repair cost</small>
                </div>
                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label for="estimated_cost">Downpayment (₱):</label>
                        <button type="button" onclick="calcAssignDp50()" style="background:#EEF2FF; color:#4F46E5; border:1px solid #C7D2FE; border-radius:4px; padding:1px 6px; font-size:10px; font-weight:600; cursor:pointer;" title="Calculate 50% deposit">
                            <i class="fas fa-calculator"></i> Auto 50%
                        </button>
                    </div>
                    <input type="number" id="estimated_cost" name="estimated_cost" class="form-control" 
                           step="0.01" min="0" placeholder="e.g. 750.00" value="<?= !empty($repair['estimated_cost']) ? (float)$repair['estimated_cost'] : '' ?>">
                    <small class="text-muted" style="font-size: 11px;">Initial deposit (auto 50%)</small>
                </div>
            </div>
            
            <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label for="scheduled_date"><i class="fas fa-calendar-alt"></i> Schedule Date <span class="required">*</span></label>
                    <input type="date" id="scheduled_date" name="scheduled_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label for="scheduled_time"><i class="fas fa-clock"></i> Start Time (8 AM - 7 PM)</label>
                    <?php 
                    $defaultStart = '08:00';
                    $nowTime = date('H:i');
                    if ($nowTime >= '08:00' && $nowTime <= '19:00') {
                        $defaultStart = $nowTime;
                    }
                    ?>
                    <input type="time" id="scheduled_time" name="scheduled_time" class="form-control"
                           min="08:00" max="19:00" value="<?= $defaultStart ?>">
                </div>
                <div class="form-group">
                    <label for="end_time"><i class="fas fa-clock"></i> End Time (8 AM - 7 PM)</label>
                    <?php 
                    $defaultEnd = date('H:i', strtotime('+1 hour', strtotime($defaultStart)));
                    if ($defaultEnd > '19:00') {
                        $defaultEnd = '19:00';
                    }
                    ?>
                    <input type="time" id="end_time" name="end_time" class="form-control"
                           min="08:00" max="19:00" value="<?= $defaultEnd ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Notes (optional):</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" 
                          placeholder="Add any notes for the technician..."></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Assign Technician
                </button>
                <a href="repairs.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('scheduled_date');
    const startTimeInput = document.getElementById('scheduled_time');
    const endTimeInput = document.getElementById('end_time');

    function updateTimeMin() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const todayStr = `${year}-${month}-${day}`;
        
        
        if (dateInput.value === todayStr) {
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            startTimeInput.min = `${hours}:${minutes}`;
        } else {
            startTimeInput.removeAttribute('min');
        }
        
        
        if (startTimeInput.value) {
            endTimeInput.min = startTimeInput.value;
        }
    }

    dateInput.addEventListener('change', updateTimeMin);
    startTimeInput.addEventListener('change', updateTimeMin);
    
    
    updateTimeMin();

    
    document.querySelector('form').addEventListener('submit', (e) => {
        if (startTimeInput.value && endTimeInput.value && endTimeInput.value <= startTimeInput.value) {
            e.preventDefault();
            alert('End time must be after the start time.');
        }
    });

    
    document.getElementById('total_cost')?.addEventListener('input', function() {
        const dpInput = document.getElementById('estimated_cost');
        const total = parseFloat(this.value);
        if (!isNaN(total) && total > 0) {
            dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
        } else if (this.value === '' || total === 0) {
            dpInput.value = '';
        }
    });

    window.calcAssignDp50 = function() {
        const totalInput = document.getElementById('total_cost');
        const dpInput = document.getElementById('estimated_cost');
        const total = parseFloat(totalInput.value);
        if (!isNaN(total) && total > 0) {
            dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
        }
    };
});
</script>

<?php require_once '../includes/footer.php'; ?>
