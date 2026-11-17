<?php

require_once '../config/config.php';
$pageTitle = 'Repair Details - ' . SITE_NAME;

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

if (!$isAjax) {
    require_once '../includes/header.php';
}
requireRole('Technician');

$db = getDB();
$repairId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$techId = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT r.*, c.full_name as customer_name, c.contact_no, c.address, u.email
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN users u ON c.user_id = u.user_id
    WHERE r.repair_id = ? AND r.technician_id = ?
");
$stmt->execute([$repairId, $techId]);
$repair = $stmt->fetch();

if (!$repair) {
    redirect('repairs.php', 'Repair not found or not assigned to you.', 'danger');
}

$stmt = $db->prepare("SELECT * FROM schedules WHERE repair_id = ? ORDER BY scheduled_date DESC LIMIT 1");
$stmt->execute([$repairId]);
$schedule = $stmt->fetch();
?>

<style>

.tech-repair-detail-page {
    --border-color: var(--border);
    --text-dark: var(--text-heading);
    --text-muted: var(--text-body);
    font-family: 'Inter', system-ui, sans-serif;
    padding: 20px 0;
}

.tech-repair-detail-page .page-header { 
    margin-bottom: 32px; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}

.tech-repair-detail-page .page-header h1 { 
    font-weight: 800; 
    color: var(--primary-dark); 
    margin: 0; 
}

.tech-repair-detail-page .page-header h1 i { 
    color: var(--gold); 
    margin-right: 12px; 
}

.tech-btn-outline {
    background: var(--bg-white); 
    color: var(--primary); 
    border: 2px solid var(--primary);
    padding: 8px 16px; 
    border-radius: 8px; 
    font-size: 14px; 
    font-weight: 600; 
    text-decoration: none;
    transition: all 0.2s; 
    display: inline-flex; 
    align-items: center; 
    gap: 8px;
}

.tech-btn-outline:hover { 
    background: var(--primary); 
    color: var(--bg-white); 
}

.detail-grid-modern { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); 
    gap: 24px; 
}

.detail-card-modern { 
    background: var(--bg-white); 
    border-radius: 16px; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); 
    border: 1px solid var(--border-color); 
    overflow: hidden; 
}

.detail-card-modern.full-width {
    grid-column: 1 / -1;
}

.detail-card-header { 
    padding: 16px 24px; 
    border-bottom: 1px solid var(--border-color); 
    background: var(--bg-hover); 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}

.detail-card-header h2 { 
    margin: 0; 
    font-size: 16px; 
    font-weight: 700; 
    color: var(--primary-dark); 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-card-header h2 i { 
    color: var(--gold-dark); 
    font-size: 18px;
}

.detail-card-body { 
    padding: 24px; 
}

.modern-detail-row { 
    display: flex; 
    flex-direction: column; 
    gap: 4px; 
    margin-bottom: 16px; 
}

.modern-detail-row:last-child { 
    margin-bottom: 0; 
}

.modern-detail-row label { 
    font-size: 12px; 
    font-weight: 600; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
    color: var(--text-muted); 
    display: flex; 
    align-items: center; 
    gap: 6px; 
}

.modern-detail-row label i {
    color: var(--gold);
}

.modern-detail-row span, .modern-detail-row p { 
    margin: 0; 
    font-size: 15px; 
    color: var(--text-dark); 
    font-weight: 500; 
    line-height: 1.5; 
}

.modern-detail-row a { 
    color: var(--primary); 
    text-decoration: none; 
    font-weight: 600; 
}

.modern-detail-row a:hover { 
    text-decoration: underline; 
}

.timeline-divider { 
    height: 1px; 
    background: var(--border-color); 
    margin: 20px 0; 
    border: none; 
}

.cost-highlight { 
    font-size: 22px !important; 
    font-weight: 800 !important; 
    color: var(--primary) !important; 
}

.action-card { 
    background: var(--gold-light); 
    border-color: var(--gold); 
}

.action-card .detail-card-header { 
    background: transparent; 
    border-bottom: none; 
    padding-bottom: 0; 
}

.tech-btn-primary { 
    background: var(--primary); 
    color: var(--bg-white); 
    border: none; 
    padding: 12px 24px; 
    border-radius: 8px; 
    font-size: 15px; 
    font-weight: 600; 
    cursor: pointer; 
    transition: all 0.2s; 
    display: inline-flex; 
    align-items: center; 
    gap: 8px; 
    width: 100%;
    justify-content: center;
}

.tech-btn-primary:hover { 
    background: var(--primary-dark); 
    transform: translateY(-2px); 
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2); 
}

.completed-card { 
    background: #ECFDF5; 
    border-color: #A7F3D0; 
}

.returned-card { 
    background: #F0F9FF; 
    border-color: #BAE6FD; 
}

.alert-modern { 
    padding: 16px; 
    border-radius: 12px; 
    font-weight: 500; 
    font-size: 14px; 
    display: flex; 
    gap: 12px; 
    align-items: flex-start; 
}

.alert-modern i { 
    margin-top: 3px; 
    font-size: 18px; 
}

.alert-success-modern { 
    background: rgba(255,255,255,0.6); 
    color: #065F46; 
}

.alert-info-modern { 
    background: rgba(255,255,255,0.6); 
    color: #075985; 
}

.problem-block {
    background: var(--bg-hover);
    padding: 16px;
    border-radius: 8px;
    border-left: 4px solid var(--gold);
    margin-top: 8px;
}
</style>

<?php if (!$isAjax): ?>
    <div class="tech-repair-detail-page">
        <div class="page-header">
            <h1><i class="fas fa-wrench"></i> Repair #<?= $repairId ?></h1>
            <a href="repairs.php" class="tech-btn-outline"><i class="fas fa-arrow-left"></i> Back to Repairs</a>
        </div>
<?php else: ?>
    <div class="tech-repair-detail-page" style="padding-top: 10px;">
<?php endif; ?>

    <div class="detail-grid-modern">
        
        <div class="detail-card-modern full-width">
            <div class="detail-card-header">
                <h2><i class="fas fa-tools"></i> Repair Information</h2>
                <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>" style="font-size: 13px; padding: 6px 12px;">
                    <?= $repair['repair_status'] ?>
                </span>
            </div>
            
            <div class="detail-card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px;">
                
                <div>
                    <div class="modern-detail-row">
                        <label>Appliance Type</label>
                        <span><i class="fas fa-tv" style="color:var(--text-muted); margin-right:6px;"></i> <?= sanitize($repair['appliance_type']) ?></span>
                    </div>
                    <?php if ($repair['brand']): ?>
                    <div class="modern-detail-row">
                        <label>Brand & Model</label>
                        <span><?= sanitize($repair['brand']) ?> <span style="color:var(--text-muted)">|</span> <?= sanitize($repair['model']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($repair['serial_number']): ?>
                    <div class="modern-detail-row">
                        <label>Serial Number</label>
                        <span style="font-family: monospace; background: var(--bg-hover); padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color);"><?= sanitize($repair['serial_number']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                
                <div>
                    <div class="modern-detail-row">
                        <label><i class="fas fa-exclamation-triangle"></i> Problem Description</label>
                        <div class="problem-block">
                            <p style="font-size: 14px; margin: 0;"><?= nl2br(sanitize($repair['problem_description'])) ?></p>
                        </div>
                    </div>
                    <?php if ($repair['diagnosis']): ?>
                    <div class="modern-detail-row" style="margin-top: 16px;">
                        <label><i class="fas fa-stethoscope"></i> Diagnosis</label>
                        <p style="font-size: 14px; margin-top: 4px;"><?= nl2br(sanitize($repair['diagnosis'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($repair['appliance_image'])): ?>
            <div class="detail-card-body" style="border-top: 1px solid var(--border-color); background: #fdfdfd;">
                <div class="modern-detail-row">
                    <label><i class="fas fa-camera"></i> Appliance Photo</label>
                    <div class="appliance-image-container" style="text-align: left; margin-top: 8px;">
                        <img src="<?= SITE_URL . '/' . $repair['appliance_image'] ?>" 
                             alt="Appliance Photo" 
                             class="appliance-image"
                             style="max-height: 150px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); cursor: pointer;"
                             onclick="openImageModal(this.src)">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        
        <div class="detail-card-modern">
            <div class="detail-card-header">
                <h2><i class="fas fa-user-circle"></i> Customer Contact</h2>
            </div>
            <div class="detail-card-body">
                <div class="modern-detail-row">
                    <label>Full Name</label>
                    <span style="font-weight: 700; color: var(--primary);"><?= sanitize($repair['customer_name']) ?></span>
                </div>
                <div class="modern-detail-row">
                    <label><i class="fas fa-phone-alt"></i> Phone Number</label>
                    <span><a href="tel:<?= $repair['contact_no'] ?>"><?= sanitize($repair['contact_no']) ?></a></span>
                </div>
                <div class="modern-detail-row">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <span><a href="mailto:<?= $repair['email'] ?>"><?= sanitize($repair['email']) ?></a></span>
                </div>
                <div class="modern-detail-row">
                    <label><i class="fas fa-map-marker-alt"></i> Service Address</label>
                    <span style="font-size: 14px; line-height: 1.4;"><?= sanitize($repair['address'] ?: 'No address provided') ?></span>
                </div>
            </div>
        </div>
        
        
        <div class="detail-card-modern">
            <div class="detail-card-header">
                <h2><i class="fas fa-receipt"></i> Timeline & Cost</h2>
            </div>
            <div class="detail-card-body">
                <div style="display: flex; gap: 16px;">
                    <div class="modern-detail-row" style="flex: 1;">
                        <label>Requested On</label>
                        <span style="font-size: 13px;"><?= formatDateTime($repair['date_requested']) ?></span>
                    </div>
                    <?php if ($repair['date_started']): ?>
                    <div class="modern-detail-row" style="flex: 1;">
                        <label>Started On</label>
                        <span style="font-size: 13px; color: var(--primary);"><?= formatDateTime($repair['date_started']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <hr class="timeline-divider">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                    <div class="modern-detail-row" style="margin: 0;">
                        <label>Est. Cost</label>
                        <span style="font-size: 14px; color: var(--text-muted);"><?= formatCurrency($repair['estimated_cost']) ?></span>
                    </div>
                    <div class="modern-detail-row" style="margin: 0; text-align: right;">
                        <label style="justify-content: flex-end; color: var(--primary);">Total Due</label>
                        <span class="cost-highlight"><?= formatCurrency($repair['total_cost']) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        
        <?php if ($schedule): ?>
        <div class="detail-card-modern full-width">
            <div class="detail-card-header">
                <h2><i class="fas fa-calendar-alt"></i> Scheduled Visit</h2>
                <span class="badge <?= getStatusBadgeClass($schedule['schedule_status']) ?>"><?= $schedule['schedule_status'] ?></span>
            </div>
            <div class="detail-card-body" style="display: flex; gap: 32px; flex-wrap: wrap; background: #FAFAFA;">
                <div class="modern-detail-row" style="margin: 0;">
                    <label>Visit Date</label>
                    <span style="font-size: 16px; font-weight: 700; color: var(--primary-dark);">
                        <i class="far fa-calendar-alt" style="color: var(--gold); margin-right: 6px;"></i> 
                        <?= formatDate($schedule['scheduled_date']) ?>
                    </span>
                </div>
                <?php if ($schedule['scheduled_time']): ?>
                <div class="modern-detail-row" style="margin: 0;">
                    <label>Expected Time</label>
                    <span style="font-size: 16px; font-weight: 700;">
                        <i class="far fa-clock" style="color: var(--text-muted); margin-right: 6px;"></i> 
                        <?= date('h:i A', strtotime($schedule['scheduled_time'])) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    
    <?php if (in_array($repair['repair_status'], ['Assigned', 'In Progress', 'On Hold'])): ?>
    <div class="detail-card-modern action-card" style="margin-top: 24px;">
        <div class="detail-card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h3 style="margin: 0 0 4px 0; color: var(--primary-dark); font-size: 16px;"><i class="fas fa-exclamation-circle" style="color: var(--gold-dark);"></i> Status Update Required</h3>
                <p style="margin: 0; color: var(--text-body); font-size: 13px;">Keep the customer informed by updating the repair progress.</p>
            </div>
            <?php $statusSlug = strtolower(str_replace(' ', '-', $repair['repair_status'])); ?>
            <button type="button" class="btn-status-<?= $statusSlug ?>" style="width: auto; padding: 12px 24px; border-radius: 8px; font-weight: 600;" onclick="<?php if ($isAjax): ?>if (typeof closeDetailsModal === 'function') closeDetailsModal(); setTimeout(function() { <?php endif; ?>openStatusModal(<?= $repair['repair_id'] ?>, '<?= $repair['repair_status'] ?>', '<?= addslashes(sanitize($repair['appliance_type'])) ?>', '<?= $repair['total_cost'] ?? '' ?>', '<?= $repair['estimated_cost'] ?? '' ?>', '<?= htmlspecialchars(addslashes($repair['diagnosis'] ?? '')) ?>')<?php if ($isAjax): ?>; }, 100);<?php endif; ?>;">
                <i class="fas fa-sync-alt"></i> Update Repair Status
            </button>
        </div>
    </div>
    <?php elseif ($repair['repair_status'] === 'Completed'): ?>
    <div class="detail-card-modern completed-card" style="margin-top: 24px;">
        <div class="detail-card-body" style="padding: 16px;">
            <div class="alert-modern alert-success-modern" style="margin: 0;">
                <i class="fas fa-check-circle"></i> 
                <div>
                    <strong>Repair Completed</strong><br>
                    <small>Waiting for customer to pick up the item. No further updates needed.</small>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($repair['repair_status'] === 'Returned'): ?>
    <div class="detail-card-modern returned-card" style="margin-top: 24px;">
        <div class="detail-card-body" style="padding: 16px;">
            <div class="alert-modern alert-info-modern" style="margin: 0;">
                <i class="fas fa-undo"></i> 
                <div>
                    <strong>Item Returned</strong><br>
                    <small>Repair job is complete. Thank you!</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isAjax): ?>

<div id="statusModal" class="professional-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center; padding: 12px;">
    <div class="modal-container" style="max-width: 540px; width: 100%; max-height: calc(100vh - 24px); display: flex; flex-direction: column; margin: auto; background: white; border-radius: 16px; box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.3); animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden;">
        
        
        <div class="modal-header-pro" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 12px 18px; border-radius: 16px 16px 0 0; position: relative; overflow: hidden; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="background: rgba(255,255,255,0.25); padding: 6px 10px; border-radius: 10px; backdrop-filter: blur(8px);">
                    <i class="fas fa-sync-alt" style="font-size: 14px; color: white;"></i>
                </div>
                <div>
                    <h2 style="margin: 0; color: white; font-size: 16px; font-weight: 700;">Update Repair Status</h2>
                    <p style="margin: 0; color: rgba(255,255,255,0.85); font-size: 11.5px;">Repair #<?= $repairId ?> &bull; <?= sanitize($repair['appliance_type']) ?></p>
                </div>
            </div>
            <button type="button" onclick="closeStatusModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 18px; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;">&times;</button>
        </div>

        
        <form method="POST" action="process-status-update.php" id="statusUpdateForm" style="padding: 14px 18px; overflow-y: auto; flex: 1; margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="repair_id" value="<?= $repairId ?>">
            
            
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                <span style="color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase;">Current Status:</span>
                <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>" style="font-size: 11.5px; padding: 3px 10px;"><?= $repair['repair_status'] ?></span>
            </div>

            
            <div style="margin-bottom: 10px;">
                <label for="new_status" style="display: block; color: #1e293b; font-weight: 600; margin-bottom: 4px; font-size: 12.5px;">
                    New Status <span style="color: #ef4444;">*</span>
                </label>
                <select name="new_status" id="new_status" required style="width: 100%; padding: 7px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13.5px; color: #1e293b; background: white; height: 38px; cursor: pointer;">
                    <option value="">Select New Status</option>
                    <option value="In Progress">🔄 In Progress</option>
                    <option value="Completed">✅ Completed</option>
                    <option value="On Hold">⏸️ On Hold</option>
                    <option value="Cancelled">❌ Cancelled</option>
                </select>
            </div>

            
            <div id="cost_group" style="display: <?= in_array($repair['repair_status'], ['Assigned', 'In Progress']) ? 'block' : 'none' ?>; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; margin-bottom: 10px;">
                <div style="font-size: 11px; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fas fa-file-invoice-dollar" style="color: #2563eb;"></i> Repair Quotation & Downpayment</span>
                    <span style="font-size: 10px; font-weight: 500; color: #64748b; text-transform: none;">Before or during In Progress</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label for="total_cost" style="display: block; color: #1e293b; font-weight: 600; margin-bottom: 3px; font-size: 11.5px;">
                            Total Cost (₱)
                        </label>
                        <input type="number" name="total_cost" id="total_cost" step="0.01" min="0" placeholder="e.g. 1500.00" value="<?= !empty($repair['total_cost']) ? (float)$repair['total_cost'] : '' ?>" style="width: 100%; padding: 6px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: white;">
                        <small style="color: #64748b; font-size: 10px; display: block; margin-top: 2px;">Full repair quotation</small>
                    </div>
                    <div>
                        <label for="estimated_cost" style="display: block; color: #1e293b; font-weight: 600; margin-bottom: 3px; font-size: 11.5px;">
                            Downpayment (₱)
                        </label>
                        <input type="number" name="estimated_cost" id="estimated_cost" step="0.01" min="0" placeholder="e.g. 750.00" value="<?= !empty($repair['estimated_cost']) ? (float)$repair['estimated_cost'] : '' ?>" style="width: 100%; padding: 6px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #1e293b; background: white;">
                        <small style="color: #64748b; font-size: 10px; display: block; margin-top: 2px;">Initial deposit (auto 50%)</small>
                    </div>
                </div>
            </div>

            
            <div id="diag_notes_row" style="display: grid; grid-template-columns: <?= in_array($repair['repair_status'], ['Assigned', 'In Progress']) ? '1fr 1fr' : '1fr' ?>; gap: 10px; margin-bottom: 0;">
                
                <div id="diagnosis_group" style="display: <?= in_array($repair['repair_status'], ['Assigned', 'In Progress']) ? 'block' : 'none' ?>; margin-bottom: 0;">
                    <label for="diagnosis" style="display: flex; align-items: center; gap: 5px; color: #1e293b; font-weight: 600; margin-bottom: 3px; font-size: 12px;">
                        <i class="fas fa-stethoscope" style="color: #2563eb;"></i> Diagnosis & Findings
                    </label>
                    <textarea name="diagnosis" id="diagnosis" rows="2" placeholder="Enter findings, root cause, parts..." style="width: 100%; height: 56px; min-height: 50px; padding: 6px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; color: #1e293b; background: white; resize: vertical; font-family: inherit;"><?= htmlspecialchars($repair['diagnosis'] ?? '') ?></textarea>
                </div>

                
                <div id="notes_group" style="margin-bottom: 0;">
                    <label for="status_notes" style="display: flex; align-items: center; gap: 5px; color: #1e293b; font-weight: 600; margin-bottom: 3px; font-size: 12px;">
                        <i class="fas fa-comment-alt" style="color: #64748b;"></i> Notes / Comments
                    </label>
                    <textarea name="status_notes" id="status_notes" rows="2" placeholder="Add notes about update..." style="width: 100%; height: 56px; min-height: 50px; padding: 6px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; color: #1e293b; background: white; resize: vertical; font-family: inherit;"></textarea>
                </div>
            </div>

            
            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px; margin-top: 12px; border-top: 1px solid #f1f5f9;">
                <button type="button" onclick="closeStatusModal()" style="padding: 7px 16px; background: white; border: 1.5px solid #e2e8f0; color: #64748b; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s ease;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" style="padding: 7px 18px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                    <i class="fas fa-check"></i> Update Status
                </button>
            </div>
        </form>
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

.modal-header-pro button:hover {
    background: rgba(255,255,255,0.3) !important;
    transform: rotate(90deg);
}
</style>

<script>
function openStatusModal() {
    document.getElementById('statusModal').classList.add('active');
    document.getElementById('statusModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('active');
    document.getElementById('statusModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

document.getElementById('new_status').addEventListener('change', function() {
    const costGroup = document.getElementById('cost_group');
    const diagnosisGroup = document.getElementById('diagnosis_group');
    const diagNotesRow = document.getElementById('diag_notes_row');
    const totalCostInput = document.getElementById('total_cost');
    
    if (this.value === 'In Progress') {
        if (costGroup) costGroup.style.display = 'block';
        if (diagnosisGroup) diagnosisGroup.style.display = 'block';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr 1fr';
    } else {
        if (costGroup) costGroup.style.display = 'none';
        if (diagnosisGroup) diagnosisGroup.style.display = 'none';
        if (diagNotesRow) diagNotesRow.style.gridTemplateColumns = '1fr';
    }
});

document.getElementById('total_cost')?.addEventListener('input', function() {
    const dpInput = document.getElementById('estimated_cost');
    const total = parseFloat(this.value);
    if (!isNaN(total) && total > 0 && dpInput) {
        dpInput.value = (Math.round((total * 0.5) * 100) / 100).toFixed(2);
    } else if ((this.value === '' || total === 0) && dpInput) {
        dpInput.value = '';
    }
});

document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStatusModal();
    }
});

document.getElementById('statusUpdateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalHTML = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('process-status-update.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                closeStatusModal();
                window.location.reload();
            } else {
                alert(data.message || 'Failed to update status');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        } catch (e) {
            console.error('Parse error:', e, 'Response:', text);
            alert('An error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHTML;
    });
});
</script>
<?php endif; ?>

<script>

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
</script>

<style>

.appliance-image-container {
    text-align: center;
    margin-top: 0.5rem;
}

.appliance-image {
    max-width: 100%;
    max-height: 250px;
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

<?php 
if (!$isAjax) {
    require_once '../includes/footer.php';
}
?>
