<?php

require_once '../config/config.php';
$pageTitle = 'My Repairs - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();

$stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$techId = $stmt->fetchColumn();

$status = $_GET['status'] ?? '';

$where = ["r.technician_id = ?"];
$params = [$techId];

if ($status) {
    $where[] = "r.repair_status = ?";
    $params[] = $status;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT r.*, c.full_name as customer_name, c.contact_no, c.address
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    $whereClause
    ORDER BY 
        CASE r.repair_status 
            WHEN 'In Progress' THEN 1 
            WHEN 'Assigned' THEN 2 
            ELSE 3 
        END,
        r.date_requested DESC
");
$stmt->execute($params);
$repairs = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT repair_status, COUNT(*) as count 
    FROM repairs 
    WHERE technician_id = ?
    GROUP BY repair_status
");
$stmt->execute([$techId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalRepairs = array_sum($statusCounts);
$assignedCount = $statusCounts['Assigned'] ?? 0;
$inProgressCount = $statusCounts['In Progress'] ?? 0;
$completedCount = $statusCounts['Completed'] ?? 0;
?>

<style>

.tech-repairs-page {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}
.tech-stat-card {
    background: #fff; border-radius: 20px; padding: 24px;
    display: flex; align-items: center; gap: 20px;
    box-shadow: var(--card-shadow); border: 1px solid var(--border-color);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.tech-stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); }
.tech-stat-icon {
    width: 56px; height: 56px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0; background: #EEF2FF; color: #4F46E5;
}
.tech-stat-content h3 {
    font-size: 32px; font-weight: 800; color: var(--text-dark);
    margin: 0 0 4px; line-height: 1; font-family: 'Syne', sans-serif;
}
.tech-stat-content p { margin: 0; color: var(--text-muted); font-size: 14px; font-weight: 500; }

.modern-tabs {
    display: flex; gap: 12px; margin-bottom: 32px; overflow-x: auto; padding-bottom: 8px;
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

.repairs-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;
}
.modern-repair-card {
    background: #fff; border-radius: 8px; border: 1px solid var(--border-color);
    box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04); overflow: hidden; display: flex; flex-direction: column;
    transition: transform 0.2s ease;
}
.modern-repair-card:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(15, 23, 42, 0.08); }

.repair-card-header {
    padding: 6px 8px; background: #f8fafc; border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
}
.repair-number {
    display: flex; align-items: center; gap: 4px; font-weight: 700; color: var(--text-dark); font-size: 12px;
}
.repair-number i { color: var(--accent-indigo); font-size: 10px; }
.repair-status .badge { font-size: 9px; padding: 2px 6px; border-radius: 8px; }

.repair-card-body { padding: 8px; flex: 1; display: flex; flex-direction: column; }
.repair-appliance h3 {
    margin: 0 0 2px; font-size: 13px; font-weight: 700; color: var(--text-dark); font-family: 'Syne', sans-serif;
}
.appliance-model { margin: 0 0 6px; color: var(--text-muted); font-size: 11px; display: flex; align-items: center; gap: 4px; }
.model-badge { background: #f1f5f9; padding: 1px 4px; border-radius: 4px; font-size: 9px; color: #475569; font-weight: 600; }

.repair-problem h4, .repair-customer h4 {
    margin: 0 0 2px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 700;
}
.repair-problem p { 
    margin: 0 0 8px; color: #334155; font-size: 11px; line-height: 1.3;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}

.customer-details { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
.detail-row { display: flex; align-items: center; gap: 4px; color: #475569; font-size: 11px; }
.detail-row i { color: #cbd5e1; width: 10px; text-align: center; }

.repair-meta { display: flex; gap: 6px; padding-top: 6px; border-top: 1px solid var(--border-color); margin-top: auto; flex-wrap: wrap; }
.meta-item { display: flex; align-items: center; gap: 2px; font-size: 10px; color: var(--text-muted); font-weight: 500; }

.repair-card-footer {
    padding: 10px 12px; border-top: 1px solid var(--border-color); background: #f8fafc;
    display: flex; flex-direction: column; gap: 8px;
}
.repair-meta-group {
    display: flex; align-items: center; justify-content: flex-start;
}
.repair-meta-item {
    display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-muted);
}
.repair-actions-row {
    display: flex; gap: 6px; justify-content: flex-end;
}
.tech-btn-sm { padding: 4px 8px; font-size: 11px; }

.empty-state-modern {
    text-align: center; padding: 64px 24px; background: #fff; border-radius: 24px; border: 1px dashed #cbd5e1;
}
.empty-icon { width: 80px; height: 80px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 32px; color: #94a3b8; }
.empty-state-modern h3 { margin: 0 0 8px; font-size: 24px; font-weight: 700; color: var(--text-dark); }
.empty-state-modern p { margin: 0; color: var(--text-muted); font-size: 15px; }

.tech-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px;
    font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s;
}
.tech-btn-light { background: #fff; color: var(--text-dark); border: 1px solid var(--border-color); box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.tech-btn-light:hover { background: #f8fafc; border-color: #cbd5e1; }
.tech-btn-primary { background: var(--accent-indigo); color: #fff; }
.tech-btn-primary:hover { background: #4338CA; }
</style>

<div class="tech-repairs-page">
    <div class="page-header" style="margin-bottom: 32px;">
        <h1 style="font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);"><i class="fas fa-tools" style="color: var(--accent-indigo); margin-right: 12px;"></i> My Repairs</h1>
    </div>

    
    <div class="stats-grid">
        <div class="tech-stat-card">
            <div class="tech-stat-icon"><i class="fas fa-clipboard-list"></i></div>
            <div class="tech-stat-content">
                <h3><?= $totalRepairs ?></h3>
                <p>Total Assigned</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon" style="background: #FFFBEB; color: #D97706;"><i class="fas fa-clock"></i></div>
            <div class="tech-stat-content">
                <h3><?= $assignedCount ?></h3>
                <p>New Assignments</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon" style="background: #F0F9FF; color: #0284C7;"><i class="fas fa-wrench"></i></div>
            <div class="tech-stat-content">
                <h3><?= $inProgressCount ?></h3>
                <p>In Progress</p>
            </div>
        </div>
        <div class="tech-stat-card">
            <div class="tech-stat-icon" style="background: #ECFDF5; color: #059669;"><i class="fas fa-check-circle"></i></div>
            <div class="tech-stat-content">
                <h3><?= $completedCount ?></h3>
                <p>Completed</p>
            </div>
        </div>
    </div>

    
    <div class="modern-tabs">
        <a href="repairs.php" class="modern-tab <?= !$status ? 'active' : '' ?>">
            <i class="fas fa-th-large"></i> All Repairs
            <span class="tab-badge"><?= $totalRepairs ?></span>
        </a>
        <a href="repairs.php?status=Assigned" class="modern-tab <?= $status === 'Assigned' ? 'active' : '' ?>">
            <i class="fas fa-clock"></i> Assigned
            <span class="tab-badge"><?= $assignedCount ?></span>
        </a>
        <a href="repairs.php?status=In Progress" class="modern-tab <?= $status === 'In Progress' ? 'active' : '' ?>">
            <i class="fas fa-spinner"></i> In Progress
            <span class="tab-badge"><?= $inProgressCount ?></span>
        </a>
        <a href="repairs.php?status=Completed" class="modern-tab <?= $status === 'Completed' ? 'active' : '' ?>">
            <i class="fas fa-check-double"></i> Completed
            <span class="tab-badge"><?= $completedCount ?></span>
        </a>
    </div>

    
    <?php if (empty($repairs)): ?>
        <div class="empty-state-modern">
            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
            <h3>No Repairs Found</h3>
            <p>You don't have any repairs<?= $status ? " with status: <strong>$status</strong>" : '' ?>.</p>
        </div>
    <?php else: ?>
        <div class="repairs-grid">
            <?php foreach ($repairs as $repair): ?>
                <?php 
                
                $statusClass = 'status-' . strtolower(str_replace(' ', '-', $repair['repair_status']));
                ?>
                <div class="repair-card <?= $statusClass ?>">
                    <div class="repair-card-top">
                        <div class="repair-id-badge">
                            <i class="fas fa-hashtag"></i>
                            <span><?= $repair['repair_id'] ?></span>
                        </div>
                        <div class="repair-status">
                            <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>"><?= $repair['repair_status'] ?></span>
                        </div>
                    </div>

                    <div class="repair-device-row">
                        <div class="repair-device-icon">
                            <i class="fas <?= getApplianceIcon($repair['appliance_type']) ?>"></i>
                        </div>
                        <div class="repair-device-info">
                            <h3 class="repair-device-name"><?= sanitize($repair['appliance_type']) ?></h3>
                            <div class="repair-device-meta">
                                <span><?= sanitize($repair['brand']) ?></span>
                                <?php if (!empty($repair['model'])): ?>
                                    <span class="model-badge"><?= sanitize($repair['model']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="repair-problem-section">
                        <span class="repair-problem-label">Reported Problem</span>
                        <p class="repair-problem-text"><?= sanitize($repair['problem_description']) ?></p>
                    </div>

                    <div class="repair-customer-preview" style="font-size: 13px; color: var(--text-muted);">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <i class="fas fa-user" style="width: 14px; color: #94a3b8;"></i>
                            <span style="color: var(--text-dark); font-weight: 600;"><?= sanitize($repair['customer_name']) ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-map-marker-alt" style="width: 14px; color: #94a3b8;"></i>
                            <span><?= sanitize($repair['address'] ?? 'No address provided') ?></span>
                        </div>
                    </div>

                    <div class="repair-card-footer">
                        <div class="repair-meta-group">
                            <div class="repair-meta-item">
                                <i class="far fa-calendar-alt"></i>
                                <span>Requested: <?= date('M d, Y', strtotime($repair['date_requested'])) ?></span>
                            </div>
                        </div>
                        
                        <div class="repair-actions-row">
                            <button type="button" class="btn-sm-ghost" onclick="openDetailsModal(<?= $repair['repair_id'] ?>)">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <?php if (!in_array($repair['repair_status'], ['Completed', 'Returned', 'Cancelled'])): ?>
                                <button type="button" class="btn-status-<?= strtolower(str_replace(' ', '-', $repair['repair_status'])) ?>" onclick="openStatusModal(<?= $repair['repair_id'] ?>, '<?= $repair['repair_status'] ?>', '<?= sanitize($repair['appliance_type']) ?>', '<?= $repair['total_cost'] ?? '' ?>', '<?= $repair['estimated_cost'] ?? '' ?>', '<?= htmlspecialchars(addslashes($repair['diagnosis'] ?? '')) ?>')">
                                    <i class="fas fa-sync-alt"></i> Update
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

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
</style>

<div id="detailsModal" class="professional-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(8px); z-index: 10000; justify-content: center; align-items: center; overflow-y: auto; padding: 2rem;">
    <div class="modal-container" style="max-width: 800px; width: 100%; margin: auto; background: white; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);">
        
        
        <div class="modal-header-pro" style="background: linear-gradient(135deg, #0f172a 0%, #06b6d4 100%); padding: 2rem; border-radius: 20px 20px 0 0; position: relative; overflow: hidden;">
            <div style="position: absolute; top: 0; right: 0; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; transform: translate(50%, -50%);"></div>
            <div style="position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="background: rgba(255,255,255,0.25); padding: 1rem; border-radius: 16px; backdrop-filter: blur(10px);">
                            <i class="fas fa-info-circle" style="font-size: 1.75rem; color: white;"></i>
                        </div>
                        <h2 style="margin: 0; color: white; font-size: 1.75rem; font-weight: 700; letter-spacing: -0.5px;">Repair Details</h2>
                    </div>
                </div>
                <button type="button" onclick="closeDetailsModal()" style="background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.3); color: white; font-size: 1.5rem; width: 48px; height: 48px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; font-weight: 300;">&times;</button>
            </div>
        </div>

        
        <div id="detailsModalContent" style="padding: 2rem; max-height: 70vh; overflow-y: auto;">
            <div style="text-align: center; padding: 3rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 3rem; color: #667eea;"></i>
                <p style="margin-top: 1rem; color: #64748b;">Loading repair details...</p>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes modalSlideUp {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.professional-modal.active {
    display: flex !important;
}
</style>

<script>
function openDetailsModal(repairId) {
    const modal = document.getElementById('detailsModal');
    const content = document.getElementById('detailsModalContent');
    
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    
    fetch(`repair-detail.php?id=${repairId}&ajax=1`)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = `
                <div style="text-align: center; padding: 3rem;">
                    <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: #ef4444;"></i>
                    <p style="margin-top: 1rem; color: #64748b;">Failed to load repair details. Please try again.</p>
                    <button onclick="closeDetailsModal()" style="margin-top: 1rem; padding: 0.75rem 1.5rem; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer;">Close</button>
                </div>
            `;
        });
}

function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    modal.classList.remove('active');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDetailsModal();
    }
});

function openStatusModal(repairId, currentStatus, appliance, totalCost, estimatedCost, diagnosis) {
    console.log('Opening modal for repair:', repairId);
    
    const modal = document.getElementById('statusModal');
    if (!modal) {
        console.error('Modal not found!');
        return;
    }
    
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

    const numTotal = parseFloat(totalCost || 0);
    const numDp = parseFloat(estimatedCost || 0);

    totalCostInput.value = numTotal > 0 ? numTotal.toFixed(2) : '';
    estCostInput.value = numDp > 0 ? numDp.toFixed(2) : '';
    if (diagnosisInput) diagnosisInput.value = diagnosis || '';

    
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

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing modal handlers');
    
    
    const newStatusSelect = document.getElementById('new_status');
    if (newStatusSelect) {
        newStatusSelect.addEventListener('change', function() {
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
    }

    
    const totalCostInput = document.getElementById('total_cost');
    const estCostInput = document.getElementById('estimated_cost');
    if (totalCostInput && estCostInput) {
        totalCostInput.addEventListener('input', function() {
            const val = parseFloat(this.value);
            if (!isNaN(val) && val > 0) {
                estCostInput.value = (Math.round(val * 0.5 * 100) / 100).toFixed(2);
            } else if (this.value === '' || val === 0) {
                estCostInput.value = '';
            }
        });
    }

    const modal = document.getElementById('statusModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
    }

    const statusForm = document.getElementById('statusUpdateForm');
    if (statusForm) {
        statusForm.addEventListener('submit', function(e) {
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
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(text => {
                console.log('Response:', text);
                try {
                    const data = JSON.parse(text);
                    return data;
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response');
                }
            })
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
    }
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
