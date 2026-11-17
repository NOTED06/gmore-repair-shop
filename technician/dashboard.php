<?php

require_once '../config/config.php';
$pageTitle = 'Technician Dashboard - ' . getSetting('site_name', SITE_NAME);
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();

$stmt = $db->prepare("SELECT * FROM technicians WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$technician = $stmt->fetch();

if (!$technician) {
    redirect(SITE_URL . '/logout.php', 'Technician profile not found.', 'danger');
}

$techId = $technician['technician_id'];

$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN repair_status = 'Assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN repair_status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN repair_status = 'Completed' AND MONTH(date_completed) = MONTH(CURDATE()) THEN 1 ELSE 0 END) as completed_this_month
    FROM repairs
    WHERE technician_id = ?
");
$stmt->execute([$techId]);
$stats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT s.*, r.appliance_type, r.problem_description, r.repair_status, r.diagnosis, r.total_cost, r.estimated_cost,
           c.full_name as customer_name, c.contact_no, c.address
    FROM schedules s
    JOIN repairs r ON s.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE s.technician_id = ? AND s.scheduled_date = CURDATE()
    ORDER BY s.scheduled_time ASC
");
$stmt->execute([$techId]);
$todaySchedule = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT r.*, c.full_name as customer_name, c.contact_no
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.technician_id = ? AND r.repair_status IN ('Assigned', 'In Progress')
    ORDER BY r.date_requested ASC
");
$stmt->execute([$techId]);
$activeRepairs = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> <?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></h1>
    <p>Staff Portal — Welcome, <?= sanitize($technician['full_name']) ?>!</p>
</div>

<style>

.tech-dashboard {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    --accent-emerald: #10B981;
    font-family: 'Inter', system-ui, sans-serif;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}
.tech-stat-card {
    background: #fff;
    border-radius: 20px;
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.tech-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}
.tech-stat-icon {
    width: 64px; height: 64px;
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}
.icon-indigo { background: #EEF2FF; color: #4F46E5; }
.icon-amber { background: #FFFBEB; color: #D97706; }
.icon-emerald { background: #ECFDF5; color: #059669; }
.icon-sky { background: #F0F9FF; color: #0284C7; }

.tech-stat-content h3 {
    font-size: 32px; font-weight: 800; color: var(--text-dark);
    margin: 0 0 4px; line-height: 1;
    font-family: 'Syne', sans-serif;
}
.tech-stat-content p {
    margin: 0; color: var(--text-muted); font-size: 14px; font-weight: 500;
}

.tech-section-card {
    background: #fff;
    border-radius: 24px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    margin-bottom: 32px;
    overflow: hidden;
}
.tech-section-header {
    padding: 24px 32px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.tech-section-title {
    display: flex; align-items: center; gap: 12px;
}
.tech-section-title i {
    color: var(--accent-indigo); font-size: 20px;
}
.tech-section-title h2 {
    margin: 0; font-size: 20px; font-weight: 700; color: var(--text-dark);
    font-family: 'Syne', sans-serif;
}
.tech-btn-outline {
    background: #fff; color: var(--text-dark);
    border: 1px solid var(--border-color);
    padding: 8px 16px; border-radius: 8px;
    font-size: 14px; font-weight: 600; text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
.tech-btn-outline:hover {
    background: #f1f5f9; border-color: #cbd5e1;
}

.tech-schedule-list {
    padding: 24px 32px;
}
.tech-schedule-item {
    display: flex; gap: 24px; align-items: center;
    padding: 20px;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    margin-bottom: 16px;
    transition: all 0.2s;
    background: #fff;
}
.tech-schedule-item:last-child { margin-bottom: 0; }
.tech-schedule-item:hover {
    border-color: var(--accent-indigo);
    box-shadow: 0 8px 24px rgba(79, 70, 229, 0.08);
}
.tech-time-badge {
    background: var(--accent-indigo); color: #fff;
    padding: 12px 16px; border-radius: 12px;
    font-weight: 700; font-size: 15px; text-align: center;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
    min-width: 100px;
}
.tech-schedule-info { flex: 1; }
.tech-schedule-info h4 {
    margin: 0 0 8px; font-size: 18px; font-weight: 700; color: var(--text-dark);
}
.tech-schedule-meta {
    display: flex; gap: 20px; flex-wrap: wrap;
}
.tech-schedule-meta p {
    margin: 0; color: var(--text-muted); font-size: 14px;
    display: flex; align-items: center; gap: 6px;
}
.tech-schedule-meta i { color: #94a3b8; }
.tech-schedule-actions {
    display: flex; flex-direction: column; align-items: flex-end; gap: 12px;
}

.tech-table { width: 100%; border-collapse: collapse; }
.tech-table th {
    text-align: left; padding: 16px 24px;
    font-size: 13px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
    background: #f8fafc;
}
.tech-table td {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.tech-table tr:last-child td { border-bottom: none; }
.tech-table tr:hover td { background: #f8fafc; }

.tech-table .customer-info { display: flex; flex-direction: column; gap: 4px; }
.tech-table .customer-name { font-weight: 600; color: var(--text-dark); }
.tech-table .customer-contact { font-size: 13px; color: var(--text-muted); }

.tech-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px;
    font-size: 14px; font-weight: 600; border: none; cursor: pointer;
    transition: all 0.2s; text-decoration: none;
}
.tech-btn-primary { background: var(--accent-indigo); color: #fff; }
.tech-btn-primary:hover { background: #4338CA; }
.tech-btn-light { background: #F1F5F9; color: var(--text-dark); }
.tech-btn-light:hover { background: #E2E8F0; }
</style>

<div class="tech-dashboard">
    
    <div class="stats-grid">
        <div class="tech-stat-card">
            <div class="tech-stat-icon icon-indigo"><i class="fas fa-clipboard-list"></i></div>
            <div class="tech-stat-content">
                <h3><?= $stats['assigned'] ?></h3>
                <p>Assigned to Me</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon icon-amber"><i class="fas fa-cog fa-spin"></i></div>
            <div class="tech-stat-content">
                <h3><?= $stats['in_progress'] ?></h3>
                <p>In Progress</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon icon-emerald"><i class="fas fa-check-circle"></i></div>
            <div class="tech-stat-content">
                <h3><?= $stats['completed_this_month'] ?></h3>
                <p>Completed This Month</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon icon-sky"><i class="fas fa-calendar-day"></i></div>
            <div class="tech-stat-content">
                <h3><?= count($todaySchedule) ?></h3>
                <p>Today's Schedule</p>
            </div>
        </div>
    </div>

    
    <div class="tech-section-card">
        <div class="tech-section-header">
            <div class="tech-section-title">
                <i class="fas fa-calendar-day"></i>
                <h2>Today's Schedule <span style="font-weight: 400; font-size: 15px; color: var(--text-muted); margin-left: 8px;">— <?= date('l, F d, Y') ?></span></h2>
            </div>
            <a href="schedule.php" class="tech-btn-outline"><i class="fas fa-calendar-alt"></i> Full Schedule</a>
        </div>
        <div class="tech-schedule-list">
            <?php if (empty($todaySchedule)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <i class="fas fa-calendar-check" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px;"></i>
                    <p style="color: var(--text-muted); font-size: 16px;">No scheduled repairs for today.</p>
                </div>
            <?php else: ?>
                <?php foreach ($todaySchedule as $schedule): ?>
                    <div class="tech-schedule-item">
                        <div class="tech-time-badge">
                            <?= $schedule['scheduled_time'] ? date('h:i A', strtotime($schedule['scheduled_time'])) : 'TBD' ?>
                        </div>
                        <div class="tech-schedule-info">
                            <h4>Repair #<?= $schedule['repair_id'] ?> — <?= sanitize($schedule['appliance_type']) ?></h4>
                            <div class="tech-schedule-meta">
                                <p><i class="fas fa-user"></i> <?= sanitize($schedule['customer_name']) ?></p>
                                <p><i class="fas fa-phone"></i> <?= sanitize($schedule['contact_no']) ?></p>
                                <p><i class="fas fa-map-marker-alt"></i> <?= sanitize($schedule['address'] ?: 'N/A') ?></p>
                            </div>
                        </div>
                        <div class="tech-schedule-actions">
                            <span class="badge <?= getStatusBadgeClass($schedule['repair_status']) ?>" style="font-size: 13px; padding: 6px 12px; border-radius: 20px;">
                                <?= $schedule['repair_status'] ?>
                            </span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" class="tech-btn tech-btn-light" onclick="openDetailsModal(<?= $schedule['repair_id'] ?>)"><i class="fas fa-eye"></i> View</button>
                                <?php if (in_array($schedule['repair_status'], ['Assigned', 'In Progress', 'On Hold'])): ?>
                                    <button type="button" class="tech-btn tech-btn-primary" onclick="openStatusModal(<?= $schedule['repair_id'] ?>, '<?= $schedule['repair_status'] ?>', '<?= sanitize($schedule['appliance_type']) ?>', '<?= $schedule['total_cost'] ?? '' ?>', '<?= $schedule['estimated_cost'] ?? '' ?>', '<?= htmlspecialchars(addslashes($schedule['diagnosis'] ?? '')) ?>')">
                                        <i class="fas fa-sync-alt"></i> Update
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="tech-section-card">
        <div class="tech-section-header">
            <div class="tech-section-title">
                <i class="fas fa-wrench"></i>
                <h2>My Active Repairs</h2>
            </div>
            <a href="repairs.php" class="tech-btn-outline">View All</a>
        </div>
        <div>
            <?php if (empty($activeRepairs)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <i class="fas fa-inbox" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px;"></i>
                    <p style="color: var(--text-muted); font-size: 16px;">No active repairs assigned to you.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="tech-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Appliance</th>
                                <th>Problem</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeRepairs as $repair): ?>
                                <tr>
                                    <td><strong style="color: var(--text-dark);">#<?= $repair['repair_id'] ?></strong></td>
                                    <td>
                                        <div class="customer-info">
                                            <span class="customer-name"><?= sanitize($repair['customer_name']) ?></span>
                                            <span class="customer-contact"><?= sanitize($repair['contact_no']) ?></span>
                                        </div>
                                    </td>
                                    <td><span style="font-weight: 500; color: var(--text-dark);"><?= sanitize($repair['appliance_type']) ?></span></td>
                                    <td>
                                        <span style="color: var(--text-muted);"><?= sanitize(substr($repair['problem_description'], 0, 40)) ?>...</span>
                                    </td>
                                    <td>
                                        <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>" style="font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                                            <?= $repair['repair_status'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 8px;">
                                            <button type="button" class="tech-btn tech-btn-light" onclick="openDetailsModal(<?= $repair['repair_id'] ?>)" title="View Details"><i class="fas fa-eye"></i></button>
                                            <button type="button" class="tech-btn tech-btn-primary" onclick="openStatusModal(<?= $repair['repair_id'] ?>, '<?= $repair['repair_status'] ?>', '<?= sanitize($repair['appliance_type']) ?>', '<?= $repair['total_cost'] ?? '' ?>', '<?= $repair['estimated_cost'] ?? '' ?>', '<?= htmlspecialchars(addslashes($repair['diagnosis'] ?? '')) ?>')">
                                                Update
                                            </button>
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

<div id="detailsModal" class="professional-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(8px); z-index: 10000; justify-content: center; align-items: center; overflow-y: auto; padding: 1.5rem;">
    <div class="modal-container" style="max-width: 800px; width: 100%; margin: auto; background: white; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden;">
        
        
        <div class="modal-header-pro" style="background: linear-gradient(135deg, #0f172a 0%, #06b6d4 100%); padding: 1.25rem 1.75rem; border-radius: 20px 20px 0 0; position: relative; overflow: hidden; display: flex; justify-content: space-between; align-items: center;">
            <div style="position: absolute; top: 0; right: 0; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; transform: translate(50%, -50%);"></div>
            <div style="position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="background: rgba(255,255,255,0.25); padding: 0.6rem 0.8rem; border-radius: 12px; backdrop-filter: blur(10px);">
                        <i class="fas fa-info-circle" style="font-size: 1.3rem; color: white;"></i>
                    </div>
                    <h2 style="margin: 0; color: white; font-size: 1.35rem; font-weight: 700; letter-spacing: -0.5px;">Repair Details</h2>
                </div>
                <button type="button" onclick="closeDetailsModal()" style="background: rgba(255,255,255,0.2); border: 1.5px solid rgba(255,255,255,0.3); color: white; font-size: 1.5rem; width: 38px; height: 38px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;">&times;</button>
            </div>
        </div>

        
        <div id="detailsModalContent" style="padding: 1.5rem; max-height: calc(85vh - 70px); overflow-y: auto;">
            <div style="text-align: center; padding: 3rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2.5rem; color: #667eea;"></i>
                <p style="margin-top: 1rem; color: #64748b; font-size: 14px;">Loading repair details...</p>
            </div>
        </div>
    </div>
</div>

<div id="statusModal" class="status-modal">
    <div class="status-modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-sync-alt" style="color: var(--primary);"></i> Update Repair Status</h2>
            <button type="button" class="modal-close" onclick="closeStatusModal()">&times;</button>
        </div>
        <form method="POST" id="statusUpdateForm">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="repair_id" id="repair_id">
            
            <div class="modal-body">
                <div class="status-update-info">
                    <div class="info-item">
                        <strong>Repair ID:</strong>
                        <span id="modal_repair_id" style="font-weight: 700; color: var(--primary-dark);"></span>
                    </div>
                    <div class="info-item">
                        <strong>Appliance:</strong>
                        <span id="modal_appliance"></span>
                    </div>
                    <div class="info-item">
                        <span id="modal_current_status" class="modal-status-badge status-color-default"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_status">New Status <span class="required">*</span></label>
                    <select name="new_status" id="new_status" class="form-control" required>
                        <option value="">Select Status</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="On Hold">On Hold</option>
                    </select>
                </div>

                <div class="form-group" id="cost_group" style="display: none; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; margin-bottom: 10px;">
                    <div style="font-size: 11px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fas fa-file-invoice-dollar" style="color: #2563eb;"></i> Repair Quotation & Downpayment</span>
                        <span style="font-size: 10px; font-weight: 500; color: #64748b; text-transform: none;">Before or during In Progress</span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label for="total_cost" style="font-weight: 600; font-size: 11.5px; margin-bottom: 3px; display: block;">Total Cost (₱) <span id="total_cost_required" class="required" style="display:none;">*</span></label>
                            <input type="number" name="total_cost" id="total_cost" class="form-control" step="0.01" min="0" placeholder="e.g. 1500.00" style="padding: 6px 10px; font-size: 13px;">
                            <small style="color: #64748B; font-size: 10px; display: block; margin-top: 2px;">Full repair quotation</small>
                        </div>
                        <div>
                            <label for="estimated_cost" style="font-weight: 600; font-size: 11.5px; margin-bottom: 3px; display: block;">Downpayment (₱)</label>
                            <input type="number" name="estimated_cost" id="estimated_cost" class="form-control" step="0.01" min="0" placeholder="e.g. 750.00" style="padding: 6px 10px; font-size: 13px;">
                            <small style="color: #64748B; font-size: 10px; display: block; margin-top: 2px;">Initial deposit (auto 50%)</small>
                        </div>
                    </div>
                </div>

                <div id="diag_notes_row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 0;">
                    
                    <div class="form-group" id="diagnosis_group" style="display: none; margin-bottom: 0;">
                        <label for="diagnosis" style="font-weight: 600; font-size: 12px; display: flex; align-items: center; gap: 5px; margin-bottom: 3px;">
                            <i class="fas fa-stethoscope" style="color: #2563eb;"></i> Diagnosis & Findings
                        </label>
                        <textarea name="diagnosis" id="diagnosis" class="form-control" rows="2" style="height: 56px; min-height: 50px; resize: vertical; padding: 6px 10px; font-size: 12.5px;" placeholder="Enter findings, root cause, parts..."></textarea>
                    </div>

                    
                    <div class="form-group" id="notes_group" style="margin-bottom: 0;">
                        <label for="status_notes" style="font-weight: 600; font-size: 12px; margin-bottom: 3px; display: block;">
                            <i class="fas fa-comment-alt" style="color: #64748b;"></i> Notes / Remarks
                        </label>
                        <textarea name="status_notes" id="status_notes" class="form-control" rows="2" style="height: 56px; min-height: 50px; resize: vertical; padding: 6px 10px; font-size: 12.5px;" placeholder="Add notes about update..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="tech-btn tech-btn-light" onclick="closeStatusModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" id="modal_submit_btn" class="tech-btn modal-status-btn status-color-default">
                    <i class="fas fa-check"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<style>

.status-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(4px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 12px;
}

.status-modal.active { display: flex !important; }

.status-modal-content {
    background: #fff;
    border-radius: 16px;
    max-width: 540px;
    width: 100%;
    max-height: calc(100vh - 24px);
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.3);
    animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}

@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.status-modal .modal-header {
    padding: 12px 18px;
    border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
    background: #f8fafc;
    flex-shrink: 0;
}

.status-modal .modal-header h2 {
    margin: 0; font-size: 16px; color: var(--text-dark);
    display: flex; align-items: center; gap: 8px; font-weight: 700;
    font-family: 'Syne', sans-serif;
}

.status-modal .modal-close {
    background: none; border: none; font-size: 22px; color: var(--text-muted);
    cursor: pointer; transition: color 0.2s; padding: 0; line-height: 1;
}

.status-modal .modal-close:hover { color: #ef4444; }

.status-modal .modal-body {
    padding: 14px 18px;
    overflow-y: auto;
    flex: 1;
}

.status-update-info {
    background: #f8fafc; border: 1px solid var(--border-color);
    border-radius: 10px; padding: 8px 12px; margin-bottom: 10px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; flex-wrap: wrap;
}

.status-update-info .info-item {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 0; border: none; font-size: 12.5px;
}

.status-update-info strong { color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
.status-update-info span:not(.modal-status-badge) { color: var(--text-dark); font-weight: 600; font-size: 12.5px; }

.form-group { margin-bottom: 10px; }
.form-group label { display: block; color: var(--text-dark); font-weight: 600; margin-bottom: 4px; font-size: 12.5px; }
.form-group .required { color: #ef4444; }

.form-control {
    width: 100%; padding: 7px 12px; border: 1px solid var(--border-color);
    border-radius: 8px; font-size: 13.5px; transition: all 0.2s;
    font-family: inherit; background: #fff;
}
.form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); }
textarea.form-control { resize: vertical; min-height: 50px; }

.status-modal .modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 10px 18px; background: #f8fafc; border-top: 1px solid var(--border-color);
    flex-shrink: 0;
}
.status-modal .modal-footer .tech-btn {
    padding: 7px 16px; font-size: 13px; border-radius: 8px;
}

.modal-status-badge {
    padding: 4px 12px !important;
    border-radius: 20px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    display: inline-block !important;
    color: #ffffff !important;
}
.modal-status-btn {
    color: #ffffff !important;
    border: none !important;
}
.status-color-pending { background-color: #F59E0B !important; }
.status-color-assigned { background-color: #1E3A8A !important; }
.status-color-in-progress { background-color: #06B6D4 !important; }
.status-color-completed { background-color: #059669 !important; }
.status-color-cancelled { background-color: #EF4444 !important; }
.status-color-on-hold { background-color: #F59E0B !important; }
.status-color-returned { background-color: #6B7280 !important; }
.status-color-default { background-color: #1E3A8A !important; }

.professional-modal.active {
    display: flex !important;
}
</style>

<script>
function openDetailsModal(repairId) {
    const modal = document.getElementById('detailsModal');
    const content = document.getElementById('detailsModalContent');
    if (!modal) return;
    
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    
    content.innerHTML = `
        <div style="text-align: center; padding: 3rem;">
            <i class="fas fa-spinner fa-spin" style="font-size: 2.5rem; color: #667eea;"></i>
            <p style="margin-top: 1rem; color: #64748b; font-size: 14px;">Loading repair details...</p>
        </div>
    `;
    
    
    fetch(`repair-detail.php?id=${repairId}&ajax=1`)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = `
                <div style="text-align: center; padding: 3rem;">
                    <i class="fas fa-exclamation-circle" style="font-size: 2.5rem; color: #ef4444;"></i>
                    <p style="margin-top: 1rem; color: #64748b; font-size: 14px;">Failed to load repair details. Please try again.</p>
                    <button onclick="closeDetailsModal()" style="margin-top: 1rem; padding: 0.5rem 1.25rem; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer;">Close</button>
                </div>
            `;
        });
}

function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeDetailsModal();
    }
});

function openStatusModal(repairId, currentStatus, appliance, currentTotalCost, currentEstCost, currentDiagnosis) {
    document.getElementById('repair_id').value = repairId;
    document.getElementById('modal_repair_id').textContent = '#' + repairId;
    document.getElementById('modal_appliance').textContent = appliance;
    
    
    const cleanStatus = (currentStatus || '').trim().toLowerCase().replace(/\s+/g, '-');
    const validStatuses = ['pending', 'assigned', 'in-progress', 'completed', 'cancelled', 'on-hold', 'returned'];
    const statusSlug = validStatuses.includes(cleanStatus) ? cleanStatus : 'default';
    
    const badgeEl = document.getElementById('modal_current_status');
    badgeEl.textContent = currentStatus || 'Unknown';
    badgeEl.className = 'modal-status-badge status-color-' + statusSlug;
    
    const btnEl = document.getElementById('modal_submit_btn');
    btnEl.className = 'tech-btn modal-status-btn status-color-' + statusSlug;

    
    document.getElementById('statusUpdateForm').reset();
    document.getElementById('repair_id').value = repairId;
    document.getElementById('status_notes').value = '';
    
    const totalCostInput = document.getElementById('total_cost');
    const estCostInput = document.getElementById('estimated_cost');
    const costGroup = document.getElementById('cost_group');
    const diagnosisGroup = document.getElementById('diagnosis_group');
    const diagnosisInput = document.getElementById('diagnosis');
    const newStatusSelect = document.getElementById('new_status');
    const reqStar = document.getElementById('total_cost_required');

    const numTotal = parseFloat(currentTotalCost || 0);
    const numDp = parseFloat(currentEstCost || 0);

    totalCostInput.value = numTotal > 0 ? numTotal.toFixed(2) : '';
    estCostInput.value = numDp > 0 ? numDp.toFixed(2) : '';
    if (diagnosisInput) diagnosisInput.value = currentDiagnosis || '';

    
    const diagNotesRow = document.getElementById('diag_notes_row');
    if (currentStatus === 'Assigned' || currentStatus === 'In Progress') {
        newStatusSelect.value = 'In Progress';
        costGroup.style.display = 'block';
        if (diagnosisGroup) diagnosisGroup.style.display = 'block';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr 1fr';
        if (reqStar) reqStar.style.display = 'none';
        totalCostInput.required = false;
    } else {
        newStatusSelect.value = '';
        costGroup.style.display = 'none';
        if (diagnosisGroup) diagnosisGroup.style.display = 'none';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr';
        totalCostInput.required = false;
    }
    
    
    const modal = document.getElementById('statusModal');
    modal.style.display = 'flex';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeStatusModal() {
    const modal = document.getElementById('statusModal');
    modal.style.display = 'none';
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
}

document.getElementById('new_status').addEventListener('change', function() {
    const costGroup = document.getElementById('cost_group');
    const diagnosisGroup = document.getElementById('diagnosis_group');
    const diagNotesRow = document.getElementById('diag_notes_row');
    const totalCostInput = document.getElementById('total_cost');
    const reqStar = document.getElementById('total_cost_required');
    if (this.value === 'In Progress') {
        if (costGroup) costGroup.style.display = 'block';
        if (diagnosisGroup) diagnosisGroup.style.display = 'block';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr 1fr';
        if (totalCostInput) totalCostInput.required = false;
        if (reqStar) reqStar.style.display = 'none';
    } else {
        if (costGroup) costGroup.style.display = 'none';
        if (diagnosisGroup) diagnosisGroup.style.display = 'none';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr';
        if (totalCostInput) totalCostInput.required = false;
        if (reqStar) reqStar.style.display = 'none';
    }
});

const dTotalCostInput = document.getElementById('total_cost');
const dEstCostInput = document.getElementById('estimated_cost');
if (dTotalCostInput && dEstCostInput) {
    dTotalCostInput.addEventListener('input', function() {
        const val = parseFloat(this.value);
        if (!isNaN(val) && val > 0) {
            dEstCostInput.value = (Math.round(val * 0.5 * 100) / 100).toFixed(2);
        } else if (this.value === '' || val === 0) {
            dEstCostInput.value = '';
        }
    });
}

document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStatusModal();
    }
});

document.getElementById('statusUpdateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('process-status-update.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeStatusModal();
            window.location.reload();
        } else {
            alert(data.message || 'Failed to update status. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

function openImageModal(src) {
    const modal = document.createElement('div');
    modal.className = 'image-lightbox';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(5px);';
    modal.innerHTML = `
        <div style="position:relative;max-width:90%;max-height:90%;">
            <span style="position:absolute;top:-35px;right:0;font-size:32px;color:white;cursor:pointer;line-height:1;" class="image-lightbox-close">&times;</span>
            <img src="${src}" alt="Appliance Photo" style="max-width:100%;max-height:80vh;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">
        </div>
    `;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal || e.target.classList.contains('image-lightbox-close')) {
            modal.remove();
        }
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
