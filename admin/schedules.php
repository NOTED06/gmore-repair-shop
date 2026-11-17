<?php

require_once '../config/config.php';
$pageTitle = 'Manage Schedules - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

define('BUSINESS_OPEN', 8);  
define('BUSINESS_CLOSE', 19); 

$filterDate = $_GET['date'] ?? date('Y-m-d');
$techFilter = $_GET['technician'] ?? '';

$where = ["s.scheduled_date = ?"];
$params = [$filterDate];

if ($techFilter) {
    $where[] = "s.technician_id = ?";
    $params[] = $techFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT s.*, r.appliance_type, r.repair_status, r.problem_description,
           c.full_name as customer_name, c.contact_no as customer_contact,
           t.full_name as technician_name
    FROM schedules s
    JOIN repairs r ON s.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN technicians t ON s.technician_id = t.technician_id
    $whereClause
    ORDER BY s.scheduled_time ASC
");
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$technicians = $db->query("SELECT technician_id, full_name, availability FROM technicians ORDER BY full_name")->fetchAll();

$stmt = $db->prepare("
    SELECT s.technician_id, s.scheduled_time, s.end_time, t.full_name as technician_name
    FROM schedules s
    JOIN technicians t ON s.technician_id = t.technician_id
    WHERE s.scheduled_date = ? AND s.schedule_status != 'Cancelled'
    ORDER BY s.technician_id, s.scheduled_time
");
$stmt->execute([$filterDate]);
$allSchedules = $stmt->fetchAll();

function getTechnicianAvailability($techId, $allSchedules, $filterDate) {
    $busySlots = [];
    foreach ($allSchedules as $sch) {
        if ($sch['technician_id'] == $techId && $sch['scheduled_time'] && $sch['end_time']) {
            $busySlots[] = [
                'start' => strtotime($sch['scheduled_time']),
                'end' => strtotime($sch['end_time'])
            ];
        }
    }
    
    
    usort($busySlots, function($a, $b) {
        return $a['start'] - $b['start'];
    });
    
    
    $availableSlots = [];
    $currentTime = strtotime(sprintf('%02d:00:00', BUSINESS_OPEN));
    $closeTime = strtotime(sprintf('%02d:00:00', BUSINESS_CLOSE));
    
    foreach ($busySlots as $slot) {
        if ($slot['start'] > $currentTime) {
            $availableSlots[] = [
                'start' => date('H:i', $currentTime),
                'end' => date('H:i', $slot['start'])
            ];
        }
        $currentTime = max($currentTime, $slot['end']);
    }
    
    
    if ($currentTime < $closeTime) {
        $availableSlots[] = [
            'start' => date('H:i', $currentTime),
            'end' => date('H:i', $closeTime)
        ];
    }
    
    return [
        'busy' => $busySlots,
        'available' => $availableSlots,
        'fullyBooked' => empty($availableSlots)
    ];
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editSchedule = null;
$showEditModal = false;
$errors = [];

if ($editId) {
    $stmt = $db->prepare("
        SELECT s.*, r.appliance_type, c.full_name as customer_name, t.full_name as technician_name
        FROM schedules s
        JOIN repairs r ON s.repair_id = r.repair_id
        JOIN customers c ON r.customer_id = c.customer_id
        JOIN technicians t ON s.technician_id = t.technician_id
        WHERE s.schedule_id = ?
    ");
    $stmt->execute([$editId]);
    $editSchedule = $stmt->fetch();
    $showEditModal = $editSchedule ? true : false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $scheduleId = (int)$_POST['schedule_id'];
        $scheduledDate = $_POST['scheduled_date'];
        $scheduledTime = $_POST['scheduled_time'] ?: null;
        $endTime = $_POST['end_time'] ?: null;
        $estimatedCompletion = $_POST['estimated_completion'] ?: null;
        $scheduleStatus = trim($_POST['schedule_status'] ?? 'Scheduled');
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$scheduleId || !$scheduledDate || !$scheduledTime || !$endTime) {
            $errors[] = 'Please fill in all required fields (Date, Start Time, End Time).';
        }
        
        
        if ($scheduledTime && $endTime && strtotime($endTime) <= strtotime($scheduledTime)) {
            $errors[] = 'End time must be after start time.';
        }
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE schedules 
                    SET scheduled_date = ?, scheduled_time = ?, end_time = ?, estimated_completion = ?, 
                        schedule_status = ?, notes = ?, updated_at = NOW()
                    WHERE schedule_id = ?
                ");
                $stmt->execute([$scheduledDate, $scheduledTime, $endTime, $estimatedCompletion, $scheduleStatus, $notes, $scheduleId]);
            } catch (Exception $e) {
                $errors[] = 'Failed to update schedule: ' . $e->getMessage();
            }
            
            if (empty($errors)) {
                
                try {
                    $stmt = $db->prepare("
                        SELECT s.repair_id, c.user_id, r.appliance_type, t.user_id as tech_user_id
                        FROM schedules s
                        JOIN repairs r ON s.repair_id = r.repair_id
                        JOIN customers c ON r.customer_id = c.customer_id
                        JOIN technicians t ON s.technician_id = t.technician_id
                        WHERE s.schedule_id = ?
                    ");
                    $stmt->execute([$scheduleId]);
                    $scheduleInfo = $stmt->fetch();
                    $repairId = $scheduleInfo['repair_id'] ?? 0;
                    
                    if ($scheduleInfo) {
                        $tStr = $scheduledTime ? ' at ' . date('g:i A', strtotime($scheduledTime)) : '';
                        $eStr = $endTime ? ' to ' . date('g:i A', strtotime($endTime)) : '';
                        $cStr = $estimatedCompletion ? ' | Est: ' . date('F j, Y', strtotime($estimatedCompletion)) : '';
                        $msg  = "Your {$scheduleInfo['appliance_type']} repair schedule updated to " .
                                date('F j, Y', strtotime($scheduledDate)) . $tStr . $eStr . $cStr . ". Status: $scheduleStatus.";
                        createNotification($scheduleInfo['user_id'], 'Schedule Updated', $msg, 'schedule_update', $repairId);
                        if ($scheduleInfo['tech_user_id']) {
                            createNotification($scheduleInfo['tech_user_id'], 'Schedule Updated', $msg, 'schedule_update', $repairId);
                        }
                    }
                } catch (Exception $notifEx) {
                    error_log('Schedule notification failed: ' . $notifEx->getMessage());
                }
                
                logRepairAction($repairId ?? 0, $_SESSION['user_id'], 'Schedule Updated', null, null, "Updated schedule to $scheduledDate");
                redirect('schedules.php?date=' . $scheduledDate, 'Schedule updated successfully!', 'success');
            }
        }
        $showEditModal = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission (CSRF). Please refresh and try again.';
    } else {
        $scheduleId = (int)$_POST['schedule_id'];
        
        if (!$scheduleId) {
            $errors[] = 'Invalid schedule ID.';
        } else {
            try {
                
                $stmt = $db->prepare("SELECT repair_id, scheduled_date FROM schedules WHERE schedule_id = ?");
                $stmt->execute([$scheduleId]);
                $scheduleData = $stmt->fetch();
                
                if (!$scheduleData) {
                    redirect('schedules.php', 'Schedule not found.', 'warning');
                }
                
                $stmt = $db->prepare("DELETE FROM schedules WHERE schedule_id = ?");
                $stmt->execute([$scheduleId]);
                
                logRepairAction($scheduleData['repair_id'], $_SESSION['user_id'], 'Schedule Deleted', null, null, 'Deleted schedule');
                
                redirect('schedules.php?date=' . $scheduleData['scheduled_date'], 'Schedule deleted successfully!', 'success');
            } catch (Exception $e) {
                $errors[] = 'Failed to delete schedule: ' . $e->getMessage();
            }
        }
    }
}

if (!isset($errors)) $errors = [];
$repairId = (int)($_GET['repair_id'] ?? 0);
$showModal = isset($_GET['action']) && $_GET['action'] === 'add';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $scheduleType = $_POST['schedule_type'] ?? 'existing';
        $scheduledDate = $_POST['scheduled_date'] ?? '';
        $scheduledTime = $_POST['scheduled_time'] ?: null;
        $endTime = $_POST['end_time'] ?: null;
        $estimatedCompletion = $_POST['estimated_completion'] ?: null;
        $notes = sanitize($_POST['notes'] ?? '');

        if (!$scheduledDate || !$scheduledTime || !$endTime) {
            $errors[] = 'Please fill in Scheduled Date, Start Time, and End Time.';
        }

        if ($scheduledDate && $scheduledDate < date('Y-m-d')) {
            $errors[] = 'Scheduled date cannot be in the past.';
        }

        
        if ($scheduledTime && $endTime && strtotime($endTime) <= strtotime($scheduledTime)) {
            $endTime = date('H:i:s', strtotime($scheduledTime . ' +1 hour'));
        }

        $repairId = 0;
        $technicianId = 0;
        $customerId = 0;
        $walkinCustomerType = $_POST['walkin_customer_type'] ?? 'new';

        if ($scheduleType === 'walkin') {
            if ($walkinCustomerType === 'existing') {
                $customerId = (int)($_POST['existing_customer_id'] ?? 0);
                if (!$customerId) {
                    $errors[] = 'Please select an existing customer.';
                }
            } else {
                $customerName = trim($_POST['walkin_name'] ?? '');
                $contactNo = trim($_POST['walkin_contact'] ?? '');
                $address = trim($_POST['walkin_address'] ?? '');

                if (empty($customerName)) {
                    $errors[] = 'Please enter the walk-in customer name.';
                }
                if (empty($contactNo)) {
                    $errors[] = 'Please enter the customer contact number.';
                }
            }

            $applianceType = trim($_POST['walkin_appliance_type'] ?? '');
            $brand = trim($_POST['walkin_brand'] ?? '');
            $problemDescription = trim($_POST['walkin_problem'] ?? '');
            if (empty($problemDescription)) {
                $problemDescription = 'Walk-in repair service';
            }
            $technicianId = (int)($_POST['walkin_technician_id'] ?? 0);

            if (empty($applianceType)) {
                $errors[] = 'Please enter the appliance type.';
            }
            if (!$technicianId) {
                $errors[] = 'Please select a technician for this walk-in repair.';
            }

        } else {
            
            $repairId = (int)($_POST['repair_id'] ?? 0);
            $technicianId = (int)(!empty($_POST['technician_id']) ? $_POST['technician_id'] : ($_POST['existing_technician_id'] ?? 0));

            if (!$repairId) {
                $errors[] = 'Please select a repair.';
            }
            if (!$technicianId) {
                $errors[] = 'Please select an assigned technician.';
            }
        }

        
        if (empty($errors) && $scheduledTime && $endTime && $technicianId) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM schedules 
                WHERE technician_id = ? AND scheduled_date = ? AND schedule_status != 'Cancelled'
                AND ((scheduled_time < ? AND end_time > ?) OR (scheduled_time < ? AND end_time > ?) OR (scheduled_time >= ? AND end_time <= ?))
            ");
            $stmt->execute([$technicianId, $scheduledDate, $endTime, $scheduledTime, $endTime, $scheduledTime, $scheduledTime, $endTime]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'This technician already has a schedule during this time slot.';
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                if ($scheduleType === 'walkin') {
                    
                    if ($walkinCustomerType !== 'existing' || !$customerId) {
                        
                        $checkC = $db->prepare("SELECT customer_id FROM customers WHERE contact_no = ? LIMIT 1");
                        $checkC->execute([$contactNo]);
                        $foundCustId = $checkC->fetchColumn();

                        if ($foundCustId) {
                            $customerId = (int)$foundCustId;
                        } else {
                            $username = 'walkin_' . time() . '_' . rand(100, 999);
                            $email = $username . '@repairshop.local';
                            $randPass = hashPassword(bin2hex(random_bytes(8)));

                            $uStmt = $db->prepare("
                                INSERT INTO users (username, email, password, role, status)
                                VALUES (?, ?, ?, 'Customer', 'Active')
                            ");
                            $uStmt->execute([$username, $email, $randPass]);
                            $newUserId = $db->lastInsertId();

                            $cStmt = $db->prepare("
                                INSERT INTO customers (user_id, full_name, contact_no, address)
                                VALUES (?, ?, ?, ?)
                            ");
                            $cStmt->execute([$newUserId, $customerName, $contactNo, $address ?: null]);
                            $customerId = $db->lastInsertId();
                        }
                    }

                    
                    $rStmt = $db->prepare("
                        INSERT INTO repairs (
                            customer_id, technician_id, appliance_type, brand, 
                            problem_description, repair_status, priority, date_requested
                        ) VALUES (?, ?, ?, ?, ?, 'Assigned', 'Normal', CURRENT_TIMESTAMP)
                    ");
                    $rStmt->execute([
                        $customerId, $technicianId, $applianceType, $brand ?: null, $problemDescription
                    ]);
                    $repairId = $db->lastInsertId();

                    logRepairAction($repairId, $_SESSION['user_id'], 'Walk-in Repair Created', null, 'Assigned', 'Walk-in customer repair scheduled');
                } else {
                    
                    $uStmt = $db->prepare("
                        UPDATE repairs 
                        SET technician_id = ?, repair_status = CASE WHEN repair_status = 'Pending' THEN 'Assigned' ELSE repair_status END
                        WHERE repair_id = ?
                    ");
                    $uStmt->execute([$technicianId, $repairId]);
                    logRepairAction($repairId, $_SESSION['user_id'], 'Schedule Created', null, null, "Scheduled for $scheduledDate");
                }

                
                $stmt = $db->prepare("
                    INSERT INTO schedules (repair_id, technician_id, scheduled_date, scheduled_time, end_time, estimated_completion, notes, schedule_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Scheduled')
                ");
                $stmt->execute([$repairId, $technicianId, $scheduledDate, $scheduledTime, $endTime, $estimatedCompletion, $notes]);

                $db->commit();

                
                try {
                    $stmt = $db->prepare("
                        SELECT c.user_id, c.full_name, c.contact_no, r.appliance_type, t.full_name as technician_name
                        FROM repairs r
                        JOIN customers c ON r.customer_id = c.customer_id
                        JOIN technicians t ON t.technician_id = ?
                        WHERE r.repair_id = ?
                    ");
                    $stmt->execute([$technicianId, $repairId]);
                    $repairInfo = $stmt->fetch();

                    if ($repairInfo) {
                        $scheduleTimeStr = $scheduledTime ? ' at ' . date('g:i A', strtotime($scheduledTime)) : '';
                        $endTimeStr = $endTime ? ' to ' . date('g:i A', strtotime($endTime)) : '';
                        $msg = "Your {$repairInfo['appliance_type']} repair has been scheduled for " . 
                               date('F j, Y', strtotime($scheduledDate)) . $scheduleTimeStr . $endTimeStr .
                               ". Technician: {$repairInfo['technician_name']}";

                        if (!empty($repairInfo['user_id'])) {
                            createNotification((int)$repairInfo['user_id'], 'Repair Scheduled', $msg, 'schedule_update', $repairId);
                        }

                        if (!empty($repairInfo['contact_no'])) {
                            require_once '../includes/SmsQueue.php';
                            $smsQueue = new SmsQueue($db);
                            $smsQueue->add($repairInfo['contact_no'], getSetting('site_name', SITE_NAME) . ": " . $msg);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Schedule notification error: ' . $e->getMessage());
                }

                $succMsg = ($scheduleType === 'walkin') ? 'Walk-in schedule created successfully!' : 'Schedule created successfully!';
                redirect('schedules.php?date=' . $scheduledDate, $succMsg, 'success');
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = 'Failed to create schedule: ' . $e->getMessage();
            }
        }
        $showModal = true;
    }
}

$assignedRepairs = $db->query("
    SELECT r.repair_id, r.appliance_type, r.brand, r.technician_id, r.repair_status,
           c.full_name as customer_name, t.full_name as technician_name
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    WHERE r.repair_status IN ('Pending', 'Assigned', 'In Progress')
    ORDER BY r.repair_id DESC
")->fetchAll();

$existingCustomers = $db->query("
    SELECT customer_id, full_name, contact_no 
    FROM customers 
    ORDER BY full_name ASC
")->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Manage Schedules</h1>
    <button class="btn btn-primary" onclick="openModal('scheduleModal')">
        <i class="fas fa-plus"></i> Create Schedule
    </button>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" class="schedule-filter-form">
            <div class="filter-section">
                <label class="filter-label">
                    <i class="fas fa-calendar"></i> Date
                </label>
                <div class="date-navigation">
                    <button type="button" class="btn-nav" onclick="navigateDate(-1)" title="Previous Day">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <input type="date" name="date" class="form-control date-input" value="<?= $filterDate ?>" onchange="this.form.submit()">
                    <button type="button" class="btn-nav" onclick="navigateDate(1)" title="Next Day">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button type="button" class="btn-today" onclick="goToToday()">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                </div>
            </div>
            
            <div class="filter-section">
                <label class="filter-label">
                    <i class="fas fa-user-cog"></i> Technician
                </label>
                <select name="technician" class="form-control select-technician" onchange="this.form.submit()">
                    <option value="">All Technicians</option>
                    <?php foreach ($technicians as $tech): ?>
                        <option value="<?= $tech['technician_id'] ?>" <?= $techFilter == $tech['technician_id'] ? 'selected' : '' ?>>
                            <?= sanitize($tech['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<style>
.schedule-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    align-items: flex-end;
}
.filter-section {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-secondary);
}
.date-navigation {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.btn-nav, .btn-today {
    padding: 0.45rem 0.75rem;
    border: 1px solid var(--border-light);
    background: var(--background);
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-primary);
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.btn-nav:hover, .btn-today:hover {
    background: var(--background-secondary);
    color: var(--primary);
}
.date-input {
    width: auto;
    padding: 0.45rem 0.75rem;
}
.select-technician {
    width: auto;
    min-width: 220px;
}
</style>

<script>
function navigateDate(days) {
    const dateInput = document.querySelector('input[name="date"]');
    const currentDate = new Date(dateInput.value);
    currentDate.setDate(currentDate.getDate() + days);
    dateInput.value = currentDate.toISOString().split('T')[0];
    dateInput.form.submit();
}

function goToToday() {
    const dateInput = document.querySelector('input[name="date"]');
    dateInput.value = new Date().toISOString().split('T')[0];
    dateInput.form.submit();
}
</script>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-user-clock"></i> Technician Availability - <?= formatDate($filterDate, 'M j, Y') ?></h2>
        <span class="badge badge-secondary"><i class="fas fa-building"></i> Business Hours: <?= date('g A', strtotime(BUSINESS_OPEN . ':00')) ?> - <?= date('g A', strtotime(BUSINESS_CLOSE . ':00')) ?></span>
    </div>
    <div class="card-body">
        <div class="technician-availability-grid">
            <?php foreach ($technicians as $tech): 
                $availability = getTechnicianAvailability($tech['technician_id'], $allSchedules, $filterDate);
            ?>
            <div class="tech-availability-card <?= $availability['fullyBooked'] ? 'fully-booked' : '' ?>">
                <div class="tech-info">
                    <div class="tech-avatar">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="tech-details">
                        <h4><?= sanitize($tech['full_name']) ?></h4>
                        <span class="tech-status <?= $availability['fullyBooked'] ? 'status-busy' : 'status-available' ?>">
                            <?= $availability['fullyBooked'] ? '🔴 Fully Booked' : '🟢 Available' ?>
                        </span>
                    </div>
                </div>
                
                <div class="time-slots">
                    <div class="time-bar">
                        <?php 
                        
                        $totalHours = BUSINESS_CLOSE - BUSINESS_OPEN;
                        foreach ($availability['busy'] as $busy): 
                            $startHour = (date('H', $busy['start']) + date('i', $busy['start']) / 60) - BUSINESS_OPEN;
                            $endHour = (date('H', $busy['end']) + date('i', $busy['end']) / 60) - BUSINESS_OPEN;
                            $leftPercent = ($startHour / $totalHours) * 100;
                            $widthPercent = (($endHour - $startHour) / $totalHours) * 100;
                        ?>
                            <div class="time-block busy" style="left: <?= $leftPercent ?>%; width: <?= $widthPercent ?>%;" 
                                 title="Busy: <?= date('g:i A', $busy['start']) ?> - <?= date('g:i A', $busy['end']) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="time-labels">
                        <?php for ($h = BUSINESS_OPEN; $h <= BUSINESS_CLOSE; $h += 2): ?>
                            <span><?= date('gA', strtotime($h . ':00')) ?></span>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <?php if (!empty($availability['available'])): ?>
                <div class="available-slots">
                    <strong><i class="fas fa-check-circle"></i> Available:</strong>
                    <?php foreach ($availability['available'] as $slot): ?>
                        <span class="slot-badge"><?= date('g:i A', strtotime($slot['start'])) ?> - <?= date('g:i A', strtotime($slot['end'])) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
.technician-availability-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 1.25rem;
}

.tech-availability-card {
    background: var(--background);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    transition: all var(--transition);
}

.tech-availability-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.tech-availability-card.fully-booked {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-color: #fca5a5;
}

.tech-info {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.tech-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
}

.tech-details h4 {
    margin: 0 0 0.25rem 0;
    font-size: 1rem;
    color: var(--text-primary);
}

.tech-status {
    font-size: 0.813rem;
    font-weight: 600;
}

.tech-status.status-available {
    color: #059669;
}

.tech-status.status-busy {
    color: #dc2626;
}

.time-slots {
    margin-bottom: 0.75rem;
}

.time-bar {
    position: relative;
    height: 24px;
    background: linear-gradient(90deg, #d1fae5 0%, #a7f3d0 100%);
    border-radius: 4px;
    overflow: hidden;
}

.time-block.busy {
    position: absolute;
    top: 0;
    height: 100%;
    background: linear-gradient(90deg, #f87171 0%, #ef4444 100%);
    border-radius: 2px;
}

.time-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 0.25rem;
    font-size: 0.688rem;
    color: var(--text-muted);
}

.available-slots {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.813rem;
    color: var(--text-secondary);
}

.available-slots strong {
    color: #059669;
}

.slot-badge {
    background: #d1fae5;
    color: #065f46;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.time-separator {
    font-size: 0.625rem;
    color: var(--text-muted);
    display: block;
    margin: 0.125rem 0;
}

.schedule-time-badge .time-display {
    display: flex;
    flex-direction: column;
    align-items: center;
}
</style>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar"></i> Calendar View</h2>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span class="badge badge-info" style="font-size: 0.75rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#3b82f6;margin-right:4px;"></span> Scheduled</span>
            <span class="badge badge-success" style="font-size: 0.75rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#10b981;margin-right:4px;"></span> Confirmed</span>
            <span class="badge badge-warning" style="font-size: 0.75rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#f59e0b;margin-right:4px;"></span> In Progress</span>
            <span class="badge badge-secondary" style="font-size: 0.75rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#6b7280;margin-right:4px;"></span> Completed</span>
        </div>
    </div>
    <div class="card-body">
        <div id="scheduleCalendar" style="min-height: 500px;"></div>
    </div>
</div>

<style>

#scheduleCalendar .fc {
    font-family: 'Poppins', sans-serif;
}
#scheduleCalendar .fc-toolbar-title {
    font-size: 1.25rem !important;
    font-weight: 600;
    color: var(--text-primary);
}
#scheduleCalendar .fc-button {
    background: var(--primary) !important;
    border-color: var(--primary) !important;
    font-family: 'Poppins', sans-serif;
    font-size: 0.813rem;
    padding: 0.4rem 0.75rem;
    border-radius: var(--radius-md) !important;
}
#scheduleCalendar .fc-button:hover {
    opacity: 0.9;
}
#scheduleCalendar .fc-button-active {
    background: var(--accent) !important;
    border-color: var(--accent) !important;
    color: var(--primary) !important;
    font-weight: 600;
}
#scheduleCalendar .fc-event {
    border-radius: 4px;
    padding: 2px 4px;
    font-size: 0.75rem;
    cursor: pointer;
}
#scheduleCalendar .fc-daygrid-day.fc-day-today {
    background: rgba(186, 232, 232, 0.2) !important;
}
#scheduleCalendar .fc-timegrid-slot {
    height: 3em;
}
#scheduleCalendar .fc-col-header-cell {
    background: var(--surface);
    font-weight: 600;
    font-size: 0.813rem;
    padding: 8px 0;
}

.cal-popup-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4); z-index: 9999;
    display: flex; align-items: center; justify-content: center;
}
.cal-popup {
    background: white; border-radius: var(--radius-lg);
    padding: 0; width: 420px; max-width: 95vw;
    box-shadow: var(--shadow-xl); overflow: hidden;
}
.cal-popup-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white; padding: 1rem 1.25rem;
    display: flex; justify-content: space-between; align-items: center;
}
.cal-popup-header h3 { margin: 0; font-size: 1rem; }
.cal-popup-close { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }
.cal-popup-body { padding: 1.25rem; }
.cal-popup-body .detail-item {
    display: flex; align-items: flex-start; gap: 0.75rem;
    padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0;
}
.cal-popup-body .detail-item:last-child { border-bottom: none; }
.cal-popup-body .detail-item i { width: 18px; color: #667eea; margin-top: 2px; }
.cal-popup-body .detail-item .label { font-weight: 600; min-width: 90px; font-size: 0.813rem; color: var(--text-secondary); }
.cal-popup-body .detail-item .value { font-size: 0.875rem; color: var(--text-primary); }
.cal-popup-footer {
    padding: 0.75rem 1.25rem; background: var(--surface);
    display: flex; gap: 0.5rem; justify-content: flex-end;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('scheduleCalendar');
    if (!calendarEl) return;

    var siteUrl = document.querySelector('meta[name="site-url"]').getAttribute('content');
    var techFilter = '<?= $techFilter ?>';

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: '<?= $filterDate ?>',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'List'
        },
        slotMinTime: '<?= sprintf('%02d:00:00', BUSINESS_OPEN) ?>',
        slotMaxTime: '<?= sprintf('%02d:00:00', BUSINESS_CLOSE) ?>',
        allDaySlot: false,
        nowIndicator: true,
        navLinks: true,
        editable: false,
        selectable: false,
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
            var url = siteUrl + '/api/calendar-events.php?start=' + info.startStr.substring(0,10) + '&end=' + info.endStr.substring(0,10);
            if (techFilter) url += '&technician_id=' + techFilter;
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(successCallback)
                .catch(failureCallback);
        },
        eventClick: function(info) {
            var e = info.event.extendedProps;
            showCalendarPopup(info.event, e);
        },
        eventDidMount: function(info) {
            info.el.title = info.event.title + ' | ' + info.event.extendedProps.customer_name + ' | ' + info.event.extendedProps.status;
        },
        dateClick: function(info) {
            
            window.location.href = '?date=' + info.dateStr + (techFilter ? '&technician=' + techFilter : '');
        }
    });
    calendar.render();

    window.showCalendarPopup = function(event, e) {
        var existing = document.querySelector('.cal-popup-overlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.className = 'cal-popup-overlay';
        overlay.onclick = function(ev) { if (ev.target === overlay) overlay.remove(); };

        var startTime = event.start ? event.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'TBD';
        var endTime = event.end ? event.end.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '';
        var timeStr = endTime ? (startTime + ' - ' + endTime) : startTime;

        overlay.innerHTML = '<div class="cal-popup">' +
            '<div class="cal-popup-header"><h3><i class="fas fa-calendar-check"></i> Schedule Details</h3><button class="cal-popup-close" onclick="this.closest(\'.cal-popup-overlay\').remove()">&times;</button></div>' +
            '<div class="cal-popup-body">' +
            '<div class="detail-item"><i class="fas fa-hashtag"></i><span class="label">Repair</span><span class="value">#' + e.repair_id + ' - ' + e.appliance_type + '</span></div>' +
            '<div class="detail-item"><i class="fas fa-clock"></i><span class="label">Time</span><span class="value">' + timeStr + '</span></div>' +
            '<div class="detail-item"><i class="fas fa-user"></i><span class="label">Customer</span><span class="value">' + e.customer_name + ' (' + e.customer_contact + ')</span></div>' +
            '<div class="detail-item"><i class="fas fa-user-cog"></i><span class="label">Technician</span><span class="value">' + e.technician_name + '</span></div>' +

            '<div class="detail-item"><i class="fas fa-info-circle"></i><span class="label">Status</span><span class="value">' + e.status + '</span></div>' +
            (e.problem ? '<div class="detail-item"><i class="fas fa-exclamation-circle"></i><span class="label">Problem</span><span class="value">' + e.problem + '</span></div>' : '') +
            (e.notes ? '<div class="detail-item"><i class="fas fa-sticky-note"></i><span class="label">Notes</span><span class="value">' + e.notes + '</span></div>' : '') +
            '</div>' +
            '<div class="cal-popup-footer">' +
            '<a href="repair-view.php?id=' + e.repair_id + '" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> View Repair</a>' +
            '<a href="schedules.php?edit_id=' + e.schedule_id + '&date=' + event.startStr.substring(0,10) + '" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> Edit</a>' +
            '</div></div>';

        document.body.appendChild(overlay);
    };
});
</script>

<div id="scheduleModal" class="modal <?= $showModal ? 'show' : '' ?>">
    <div class="modal-content" style="max-width: 660px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="background: linear-gradient(135deg, #4F46E5, #6366F1); color: #fff;">
            <h2 style="color: #fff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-calendar-plus"></i> Create Schedule
            </h2>
            <button class="modal-close" onclick="closeModal('scheduleModal')" style="color: #fff; font-size: 1.5rem; opacity: 0.85;">&times;</button>
        </div>
        <form method="POST" id="createScheduleForm">
            <?= csrfField() ?>
            <input type="hidden" name="create_schedule" value="1">
            <input type="hidden" name="schedule_type" id="scheduleTypeInput" value="existing">
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin: 16px 20px 0;">
                    <ul style="margin: 0; padding-left: 18px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= sanitize($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="modal-body" style="padding: 18px 20px;">
                
                <div style="display: flex; background: #f1f5f9; padding: 4px; border-radius: 12px; margin-bottom: 18px; border: 1px solid #e2e8f0; gap: 6px;">
                    <button type="button" class="btn" id="btnTypeExisting" onclick="setScheduleType('existing')" 
                            style="flex: 1; padding: 9px 14px; border: none; border-radius: 9px; font-weight: 700; font-size: 13.5px; cursor: pointer; transition: all 0.2s; background: #4F46E5; color: #ffffff; box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);">
                        <i class="fas fa-clipboard-list" style="margin-right: 6px;"></i> Select Repair
                    </button>
                    <button type="button" class="btn" id="btnTypeWalkin" onclick="setScheduleType('walkin')" 
                            style="flex: 1; padding: 9px 14px; border: none; border-radius: 9px; font-weight: 700; font-size: 13.5px; cursor: pointer; transition: all 0.2s; background: transparent; color: #64748b;">
                        <i class="fas fa-walking" style="margin-right: 6px;"></i> Walk-in Customer
                    </button>
                </div>

                
                <div id="sectionExistingRepair">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label style="font-weight: 700; font-size: 13px; color: #1e293b;">Select Repair: <span class="required" style="color: #dc2626;">*</span></label>
                        <select name="repair_id" id="selectRepairDropdown" class="form-control" onchange="updateTechnician(this)" style="font-size: 13.5px;" required>
                            <option value="">-- Select Repair --</option>
                            <?php foreach ($assignedRepairs as $rep): ?>
                                <option value="<?= $rep['repair_id'] ?>" 
                                        data-tech="<?= $rep['technician_id'] ?>" 
                                        data-techname="<?= htmlspecialchars($rep['technician_name'] ?? '') ?>"
                                        data-status="<?= $rep['repair_status'] ?>"
                                        <?= ($repairId == $rep['repair_id']) ? 'selected' : '' ?>>
                                    #<?= $rep['repair_id'] ?> - <?= sanitize($rep['appliance_type']) ?><?= !empty($rep['brand']) ? ' (' . sanitize($rep['brand']) . ')' : '' ?> &mdash; <?= sanitize($rep['customer_name']) ?> [<?= $rep['repair_status'] ?>]<?= !empty($rep['technician_name']) ? ' • ' . sanitize($rep['technician_name']) : ' • (No Tech Assigned)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Shows active repair requests waiting for scheduling.</small>
                    </div>

                    <input type="hidden" name="technician_id" id="technician_id" value="">

                    
                    <div id="existingTechNotice" style="display: none; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #3730a3;">
                        <i class="fas fa-user-check" style="margin-right: 6px;"></i> Assigned Technician: <strong id="assignedTechNameSpan">—</strong>
                    </div>

                    
                    <div class="form-group" id="existingTechSelectGroup" style="display: none; margin-bottom: 16px;">
                        <label style="font-weight: 700; font-size: 13px; color: #1e293b;">Assign Technician: <span class="required" style="color: #dc2626;">*</span></label>
                        <select name="existing_technician_id" id="existingTechSelect" class="form-control">
                            <option value="">-- Select Available Technician --</option>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= $t['technician_id'] ?>">
                                    <?= sanitize($t['full_name']) ?> (<?= $t['availability'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                
                <div id="sectionWalkin" style="display: none;">
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px;">
                        <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.04em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-user" style="color: #4F46E5;"></i> Customer Information
                        </div>
                        
                        <div style="display: flex; gap: 18px; margin-bottom: 12px; font-size: 13px;">
                            <label style="cursor: pointer; display: flex; align-items: center; gap: 6px; font-weight: 600; color: #1e293b;">
                                <input type="radio" name="walkin_customer_type" value="new" checked onchange="toggleWalkinCustomerType(this.value)"> + New Walk-in Customer
                            </label>
                            <label style="cursor: pointer; display: flex; align-items: center; gap: 6px; font-weight: 600; color: #1e293b;">
                                <input type="radio" name="walkin_customer_type" value="existing" onchange="toggleWalkinCustomerType(this.value)"> Existing Customer
                            </label>
                        </div>

                        
                        <div id="walkinNewCustomerFields">
                            <div class="form-row">
                                <div class="form-group" style="flex: 1; margin-bottom: 10px;">
                                    <label style="font-size: 12px; font-weight: 600;">Full Name: <span class="required" style="color: #dc2626;">*</span></label>
                                    <input type="text" name="walkin_name" id="walkinNameInput" class="form-control" placeholder="e.g. Juan dela Cruz">
                                </div>
                                <div class="form-group" style="flex: 1; margin-bottom: 10px;">
                                    <label style="font-size: 12px; font-weight: 600;">Contact Number: <span class="required" style="color: #dc2626;">*</span></label>
                                    <input type="text" name="walkin_contact" id="walkinContactInput" class="form-control" placeholder="e.g. 09123456789">
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 12px; font-weight: 600;">Address: <small style="color: #94a3b8; font-weight: normal;">(Optional)</small></label>
                                <input type="text" name="walkin_address" class="form-control" placeholder="Street / Barangay / City">
                            </div>
                        </div>

                        
                        <div id="walkinExistingCustomerField" style="display: none;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 12px; font-weight: 600;">Select Existing Customer: <span class="required" style="color: #dc2626;">*</span></label>
                                <select name="existing_customer_id" id="existingCustomerSelect" class="form-control">
                                    <option value="">-- Choose Customer --</option>
                                    <?php foreach ($existingCustomers as $ec): ?>
                                        <option value="<?= $ec['customer_id'] ?>">
                                            <?= sanitize($ec['full_name']) ?> (<?= sanitize($ec['contact_no']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px;">
                        <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.04em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-tools" style="color: #4F46E5;"></i> Appliance & Problem Details
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group" style="flex: 1; margin-bottom: 10px;">
                                <label style="font-size: 12px; font-weight: 600;">Appliance Type: <span class="required" style="color: #dc2626;">*</span></label>
                                <input type="text" name="walkin_appliance_type" id="walkinApplianceInput" class="form-control" placeholder="e.g. Air Conditioner, Refrigerator" list="walkinApplianceList">
                                <datalist id="walkinApplianceList">
                                    <option value="Air Conditioner">
                                    <option value="Refrigerator">
                                    <option value="Washing Machine">
                                    <option value="Television">
                                    <option value="Microwave Oven">
                                    <option value="Electric Fan">
                                    <option value="Computer / Laptop">
                                </datalist>
                            </div>
                            <div class="form-group" style="flex: 1; margin-bottom: 10px;">
                                <label style="font-size: 12px; font-weight: 600;">Brand / Model: <small style="color: #94a3b8; font-weight: normal;">(Optional)</small></label>
                                <input type="text" name="walkin_brand" class="form-control" placeholder="e.g. Samsung RT20">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 12px; font-weight: 600;">Problem Description: <span class="required" style="color: #dc2626;">*</span></label>
                            <textarea name="walkin_problem" id="walkinProblemInput" class="form-control" rows="2" placeholder="Describe the issue reported by the customer..."></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 12px; font-weight: 600;">Assign Technician: <span class="required" style="color: #dc2626;">*</span></label>
                            <select name="walkin_technician_id" id="walkinTechSelect" class="form-control">
                                <option value="">-- Select Available Technician --</option>
                                <?php foreach ($technicians as $t): ?>
                                    <option value="<?= $t['technician_id'] ?>">
                                        <?= sanitize($t['full_name']) ?> (<?= $t['availability'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.04em; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-clock" style="color: #4F46E5;"></i> Schedule Details
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex: 1; margin-bottom: 12px;">
                            <label style="font-size: 12px; font-weight: 600;">Scheduled Date: <span class="required" style="color: #dc2626;">*</span></label>
                            <input type="date" name="scheduled_date" class="form-control" required 
                                   value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group" style="flex: 1; margin-bottom: 12px;">
                            <label style="font-size: 12px; font-weight: 600;">Start Time: <span class="required" style="color: #dc2626;">*</span></label>
                            <select name="scheduled_time" class="form-control" required>
                                <option value="">-- Select Time --</option>
                                <?php 
                                $currH = (int)date('H');
                                $autoStart = sprintf('%02d:00:00', max(BUSINESS_OPEN, min(BUSINESS_CLOSE - 1, $currH)));
                                ?>
                                <?php for ($h = BUSINESS_OPEN; $h < BUSINESS_CLOSE; $h++): ?>
                                    <?php $t0 = sprintf('%02d:00:00', $h); $t30 = sprintf('%02d:30:00', $h); ?>
                                    <option value="<?= $t0 ?>" <?= ($t0 === $autoStart) ? 'selected' : '' ?>><?= date('g:i A', strtotime($t0)) ?></option>
                                    <option value="<?= $t30 ?>"><?= date('g:i A', strtotime($t30)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1; margin-bottom: 12px;">
                            <label style="font-size: 12px; font-weight: 600;">End Time: <span class="required" style="color: #dc2626;">*</span></label>
                            <select name="end_time" class="form-control" required>
                                <option value="">-- Select Time --</option>
                                <?php 
                                $autoEnd = sprintf('%02d:00:00', max(BUSINESS_OPEN + 1, min(BUSINESS_CLOSE, $currH + 1)));
                                ?>
                                <?php for ($h = BUSINESS_OPEN; $h <= BUSINESS_CLOSE; $h++): ?>
                                    <?php if ($h < BUSINESS_CLOSE): ?>
                                    <option value="<?= sprintf('%02d:30:00', $h) ?>"><?= date('g:i A', strtotime(sprintf('%02d:30', $h))) ?></option>
                                    <?php endif; ?>
                                    <?php $te = sprintf('%02d:00:00', $h); ?>
                                    <option value="<?= $te ?>" <?= ($te === $autoEnd) ? 'selected' : '' ?>><?= date('g:i A', strtotime($te)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label style="font-size: 12px; font-weight: 600;">Estimated Completion Date: <small style="color: #94a3b8; font-weight: normal;">(Optional)</small></label>
                        <input type="date" name="estimated_completion" class="form-control">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Notes: <small style="color: #94a3b8; font-weight: normal;">(Optional)</small></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any special instructions for the technician..."></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" style="padding: 14px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitSchedule" style="background: #4F46E5; border-color: #4F46E5;">
                    <i class="fas fa-check" style="margin-right: 4px;"></i> Create Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($editSchedule): ?>
<div id="editScheduleModal" class="modal <?= $showEditModal ? 'show' : '' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Update Schedule</h2>
            <button class="modal-close" onclick="closeModal('editScheduleModal')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="update_schedule" value="1">
            <input type="hidden" name="schedule_id" value="<?= $editSchedule['schedule_id'] ?>">
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= sanitize($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="modal-body">
                <div class="schedule-info-box">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-hashtag"></i> Repair ID:</span>
                        <span class="info-value">#<?= $editSchedule['repair_id'] ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-wrench"></i> Appliance:</span>
                        <span class="info-value"><?= sanitize($editSchedule['appliance_type']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user"></i> Customer:</span>
                        <span class="info-value"><?= sanitize($editSchedule['customer_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-user-cog"></i> Technician:</span>
                        <span class="info-value"><?= sanitize($editSchedule['technician_name']) ?></span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Scheduled Date:</label>
                        <input type="date" name="scheduled_date" class="form-control" required 
                               value="<?= $editSchedule['scheduled_date'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Start Time: <span class="required">*</span></label>
                        <select name="scheduled_time" class="form-control" required>
                            <option value="">-- Select Time --</option>
                            <?php for ($h = BUSINESS_OPEN; $h < BUSINESS_CLOSE; $h++): ?>
                                <option value="<?= sprintf('%02d:00:00', $h) ?>" <?= $editSchedule['scheduled_time'] == sprintf('%02d:00:00', $h) ? 'selected' : '' ?>><?= date('g:i A', strtotime(sprintf('%02d:00', $h))) ?></option>
                                <option value="<?= sprintf('%02d:30:00', $h) ?>" <?= $editSchedule['scheduled_time'] == sprintf('%02d:30:00', $h) ? 'selected' : '' ?>><?= date('g:i A', strtotime(sprintf('%02d:30', $h))) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>End Time: <span class="required">*</span></label>
                        <select name="end_time" class="form-control" required>
                            <option value="">-- Select Time --</option>
                            <?php for ($h = BUSINESS_OPEN; $h <= BUSINESS_CLOSE; $h++): ?>
                                <?php if ($h < BUSINESS_CLOSE): ?>
                                <option value="<?= sprintf('%02d:30:00', $h) ?>" <?= ($editSchedule['end_time'] ?? '') == sprintf('%02d:30:00', $h) ? 'selected' : '' ?>><?= date('g:i A', strtotime(sprintf('%02d:30', $h))) ?></option>
                                <?php endif; ?>
                                <option value="<?= sprintf('%02d:00:00', $h) ?>" <?= ($editSchedule['end_time'] ?? '') == sprintf('%02d:00:00', $h) ? 'selected' : '' ?>><?= date('g:i A', strtotime(sprintf('%02d:00', $h))) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Estimated Completion Date:</label>
                    <input type="date" name="estimated_completion" class="form-control"
                           value="<?= $editSchedule['estimated_completion'] ?>">
                </div>
                
                <div class="form-group">
                    <label class="required">Status:</label>
                    <select name="schedule_status" class="form-control" required>
                        <option value="Scheduled"  <?= $editSchedule['schedule_status'] == 'Scheduled'  ? 'selected' : '' ?>>Scheduled</option>
                        <option value="Pending"    <?= $editSchedule['schedule_status'] == 'Pending'    ? 'selected' : '' ?>>Pending</option>
                        <option value="Confirmed"  <?= $editSchedule['schedule_status'] == 'Confirmed'  ? 'selected' : '' ?>>Confirmed</option>
                        <option value="In Progress" <?= $editSchedule['schedule_status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed"  <?= $editSchedule['schedule_status'] == 'Completed'  ? 'selected' : '' ?>>Completed</option>
                        <option value="Rescheduled" <?= $editSchedule['schedule_status'] == 'Rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                        <option value="Cancelled"  <?= $editSchedule['schedule_status'] == 'Cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Notes:</label>
                    <textarea name="notes" class="form-control" rows="3"><?= sanitize($editSchedule['notes'] ?? '') ?></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="confirmDeleteSchedule(<?= $editSchedule['schedule_id'] ?>)">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<form id="deleteForm" method="POST" style="display: none;">
    <?= csrfField() ?>
    <input type="hidden" name="delete_schedule" value="1">
    <input type="hidden" name="schedule_id" id="deleteScheduleId">
</form>

<script>
function setScheduleType(type) {
    document.getElementById('scheduleTypeInput').value = type;
    const btnExisting = document.getElementById('btnTypeExisting');
    const btnWalkin = document.getElementById('btnTypeWalkin');
    const secExisting = document.getElementById('sectionExistingRepair');
    const secWalkin = document.getElementById('sectionWalkin');
    const btnSubmit = document.getElementById('btnSubmitSchedule');
    const repairSelect = document.getElementById('selectRepairDropdown');

    if (type === 'walkin') {
        btnWalkin.style.background = '#4F46E5';
        btnWalkin.style.color = '#ffffff';
        btnWalkin.style.boxShadow = '0 2px 8px rgba(79, 70, 229, 0.25)';
        btnExisting.style.background = 'transparent';
        btnExisting.style.color = '#64748b';
        btnExisting.style.boxShadow = 'none';

        secWalkin.style.display = 'block';
        secExisting.style.display = 'none';
        btnSubmit.innerHTML = '<i class="fas fa-check" style="margin-right: 4px;"></i> Create Walk-in Schedule';

        if (repairSelect) repairSelect.removeAttribute('required');
        document.getElementById('walkinApplianceInput').setAttribute('required', 'required');
        document.getElementById('walkinProblemInput').setAttribute('required', 'required');
        document.getElementById('walkinTechSelect').setAttribute('required', 'required');
        
        
        const custType = document.querySelector('input[name="walkin_customer_type"]:checked')?.value || 'new';
        toggleWalkinCustomerType(custType);
    } else {
        btnExisting.style.background = '#4F46E5';
        btnExisting.style.color = '#ffffff';
        btnExisting.style.boxShadow = '0 2px 8px rgba(79, 70, 229, 0.25)';
        btnWalkin.style.background = 'transparent';
        btnWalkin.style.color = '#64748b';
        btnWalkin.style.boxShadow = 'none';

        secExisting.style.display = 'block';
        secWalkin.style.display = 'none';
        btnSubmit.innerHTML = '<i class="fas fa-check" style="margin-right: 4px;"></i> Create Schedule';

        if (repairSelect) repairSelect.setAttribute('required', 'required');
        document.getElementById('walkinApplianceInput').removeAttribute('required');
        document.getElementById('walkinProblemInput').removeAttribute('required');
        document.getElementById('walkinTechSelect').removeAttribute('required');
        document.getElementById('walkinNameInput').removeAttribute('required');
        document.getElementById('walkinContactInput').removeAttribute('required');
        document.getElementById('existingCustomerSelect').removeAttribute('required');
    }
}

function toggleWalkinCustomerType(val) {
    const newFields = document.getElementById('walkinNewCustomerFields');
    const existFields = document.getElementById('walkinExistingCustomerField');
    const nameInp = document.getElementById('walkinNameInput');
    const contactInp = document.getElementById('walkinContactInput');
    const existSelect = document.getElementById('existingCustomerSelect');
    const schedType = document.getElementById('scheduleTypeInput').value;

    if (val === 'existing') {
        newFields.style.display = 'none';
        existFields.style.display = 'block';
        nameInp.removeAttribute('required');
        contactInp.removeAttribute('required');
        if (schedType === 'walkin') {
            existSelect.setAttribute('required', 'required');
        }
    } else {
        newFields.style.display = 'block';
        existFields.style.display = 'none';
        existSelect.removeAttribute('required');
        if (schedType === 'walkin') {
            nameInp.setAttribute('required', 'required');
            contactInp.setAttribute('required', 'required');
        }
    }
}

function updateTechnician(select) {
    const option = select.options[select.selectedIndex];
    const techId = option.dataset.tech || '';
    const techName = option.dataset.techname || '';
    document.getElementById('technician_id').value = techId;

    const noticeBox = document.getElementById('existingTechNotice');
    const selectBox = document.getElementById('existingTechSelectGroup');
    const nameSpan = document.getElementById('assignedTechNameSpan');
    const techSelect = document.getElementById('existingTechSelect');

    if (!select.value) {
        noticeBox.style.display = 'none';
        selectBox.style.display = 'none';
        techSelect.removeAttribute('required');
        return;
    }

    if (techId && techName) {
        nameSpan.textContent = techName;
        noticeBox.style.display = 'block';
        selectBox.style.display = 'none';
        techSelect.removeAttribute('required');
    } else {
        noticeBox.style.display = 'none';
        selectBox.style.display = 'block';
        techSelect.setAttribute('required', 'required');
    }
}

function confirmDeleteSchedule(scheduleId) {
    
    closeModal('editScheduleModal');
    
    Swal.fire({
        title: 'Are you sure?',
        text: 'Delete this schedule? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#272343',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('deleteScheduleId').value = scheduleId;
            document.getElementById('deleteForm').submit();
        } else {
            
            openModal('editScheduleModal');
        }
    });
}

function closeEditModal() {
    closeModal('editScheduleModal');
    
    const url = new URL(window.location);
    url.searchParams.delete('edit_id');
    window.history.replaceState({}, '', url);
}

document.addEventListener('DOMContentLoaded', function() {
    const select = document.querySelector('select[name="repair_id"]');
    if (select && select.value) updateTechnician(select);
    
    
    <?php if ($showEditModal): ?>
    openModal('editScheduleModal');
    <?php endif; ?>
});
</script>

<?php require_once '../includes/footer.php'; ?>
