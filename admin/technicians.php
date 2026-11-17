<?php

require_once '../config/config.php';
$pageTitle = 'Manage Technicians - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

$technicianDetails = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $stmt = $db->prepare("
        SELECT t.*, u.username, u.email, u.status as user_status, u.created_at as registered_at, u.last_login,
               (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id) as total_repairs,
               (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed') as completed_repairs,
               (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status IN ('Pending', 'Assigned', 'In Progress')) as active_repairs,
               (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed' AND MONTH(r.date_completed) = MONTH(CURDATE()) AND YEAR(r.date_completed) = YEAR(CURDATE())) as completed_this_month,
               (SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, r.date_started, r.date_completed)), 1) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed' AND r.date_started IS NOT NULL AND r.date_completed IS NOT NULL) as avg_repair_hours,
               (SELECT ROUND(AVG(r.total_cost), 2) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed' AND r.total_cost IS NOT NULL) as avg_revenue,
               (SELECT SUM(r.total_cost) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed' AND r.total_cost IS NOT NULL) as total_revenue,
               (SELECT COUNT(*) FROM schedules s WHERE s.technician_id = t.technician_id AND s.scheduled_date = CURDATE() AND s.schedule_status != 'Cancelled') as today_schedules
        FROM technicians t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.technician_id = ?
    ");
    $stmt->execute([$viewId]);
    $technicianDetails = $stmt->fetch();
}

$editTechnician = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmt = $db->prepare("
        SELECT t.*, u.username, u.email
        FROM technicians t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.technician_id = ?
    ");
    $stmt->execute([$editId]);
    $editTechnician = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_technician'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('technicians.php', 'Invalid security token. Please try again.', 'danger');
    }
    $techId = (int)$_POST['technician_id'];
    $fullName = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $contact = trim($_POST['contact_no']);
    $specialization = trim($_POST['specialization']);
    $experience = (int)$_POST['experience_years'];
    $availability = $_POST['availability'];
    
    $errors = [];
    
    
    if (strlen($fullName) < 2) {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }
    if (strlen($contact) < 10) {
        $errors[] = 'Valid contact number is required.';
    }
    
    
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT u.user_id FROM users u JOIN technicians t ON u.user_id = t.user_id WHERE u.email = ? AND t.technician_id != ?");
        $stmt->execute([$email, $techId]);
        if ($stmt->fetch()) {
            $errors[] = 'Email already exists for another user.';
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            
            $stmt = $db->prepare("SELECT user_id FROM technicians WHERE technician_id = ?");
            $stmt->execute([$techId]);
            $userId = $stmt->fetchColumn();
            
            
            $stmt = $db->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $stmt->execute([$email, $userId]);
            
            
            $stmt = $db->prepare("UPDATE technicians SET full_name = ?, contact_no = ?, specialization = ?, experience_years = ?, availability = ? WHERE technician_id = ?");
            $stmt->execute([$fullName, $contact, $specialization, $experience, $availability, $techId]);
            
            $db->commit();
            redirect('technicians.php', 'Technician updated successfully!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to update technician: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        redirect('technicians.php?edit_id=' . $techId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_technician'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('technicians.php', 'Invalid security token. Please try again.', 'danger');
    }
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    $fullName = trim($_POST['full_name']);
    $contact = trim($_POST['contact_no']);
    $specialization = trim($_POST['specialization']);
    $experience = (int)$_POST['experience_years'];
    
    $errors = [];
    
    
    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($fullName) < 2) {
        $errors[] = 'Full name is required.';
    }
    if (strlen($contact) < 10) {
        $errors[] = 'Valid contact number is required.';
    }
    
    
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Username or email already exists.';
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'Technician', 'Active')");
            $stmt->execute([$username, $email, $hashedPassword]);
            $userId = $db->lastInsertId();
            
            
            $stmt = $db->prepare("INSERT INTO technicians (user_id, full_name, contact_no, specialization, experience_years, availability) VALUES (?, ?, ?, ?, ?, 'Available')");
            $stmt->execute([$userId, $fullName, $contact, $specialization, $experience]);
            
            $db->commit();
            redirect('technicians.php', 'Technician added successfully!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to create technician: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}

$stmt = $db->query("
    SELECT t.*, u.username, u.email, u.status as user_status,
           (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status IN ('Assigned', 'In Progress')) as active_repairs,
           (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id AND r.repair_status = 'Completed') as completed_repairs
    FROM technicians t
    JOIN users u ON t.user_id = u.user_id
    ORDER BY t.full_name ASC
");
$technicians = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_availability'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('technicians.php', 'Invalid security token. Please try again.', 'danger');
    }
    $techId = (int)$_POST['technician_id'];
    $newStatus = $_POST['new_status'];
    
    $stmt = $db->prepare("UPDATE technicians SET availability = ? WHERE technician_id = ?");
    $stmt->execute([$newStatus, $techId]);
    
    redirect('technicians.php', 'Availability updated.', 'success');
}
?>

<style>

.customer-view-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 24px;
}
.customer-profile-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    border: 1px solid #e2e8f0;
}
.customer-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    font-size: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-weight: 700;
}
.customer-profile-card h3 {
    margin: 0 0 4px;
    font-size: 18px;
    color: #1e293b;
}
.customer-username {
    color: #64748b;
    font-size: 14px;
    margin-bottom: 12px;
}
.customer-stats {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.customer-stats .stat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    text-align: left;
}
.customer-stats .stat-item i {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.customer-stats .stat-item div {
    display: flex;
    flex-direction: column;
}
.customer-stats .stat-item strong {
    font-size: 16px;
    color: #1e293b;
}
.customer-stats .stat-item span {
    font-size: 12px;
    color: #64748b;
}

.customer-details-sections {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.detail-section h4 {
    margin: 0 0 16px;
    color: #334155;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f1f5f9;
}
.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.detail-item label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.detail-item value {
    font-size: 14px;
    color: #1e293b;
}
.detail-item value a {
    color: var(--primary);
    text-decoration: none;
}
.detail-item value a:hover {
    text-decoration: underline;
}
@media (max-width: 768px) {
    .customer-view-grid {
        grid-template-columns: 1fr;
    }
    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-header">
    <h1><i class="fas fa-user-cog"></i> Manage Technicians</h1>
    <button type="button" class="btn btn-primary" onclick="openModal('addTechnicianModal')">
        <i class="fas fa-user-plus"></i> Add Technician
    </button>
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

<div class="card">
    <div class="card-body">
        <?php if (empty($technicians)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash fa-3x"></i>
                <p>No technicians found.</p>
                <button type="button" class="btn btn-primary" onclick="openModal('addTechnicianModal')">Add First Technician</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Specialization</th>
                            <th>Experience</th>
                            <th>Status</th>
                            <th>Active</th>
                            <th>Completed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($technicians as $tech): ?>
                            <tr>
                                <td>#<?= $tech['technician_id'] ?></td>
                                <td>
                                    <strong><?= sanitize($tech['full_name']) ?></strong>
                                    <br><small class="text-muted">@<?= sanitize($tech['username']) ?></small>
                                </td>
                                <td>
                                    <?= sanitize($tech['contact_no']) ?>
                                    <br><small class="text-muted"><?= sanitize($tech['email']) ?></small>
                                </td>
                                <td><?= sanitize($tech['specialization'] ?: 'General') ?></td>
                                <td><?= $tech['experience_years'] ?> years</td>
                                <td>
                                    <form method="POST" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="toggle_availability" value="1">
                                        <input type="hidden" name="technician_id" value="<?= $tech['technician_id'] ?>">
                                        <select name="new_status" class="form-control form-control-sm status-select" onchange="this.form.submit()">
                                            <option value="Available" <?= $tech['availability'] === 'Available' ? 'selected' : '' ?>>Available</option>
                                            <option value="Busy" <?= $tech['availability'] === 'Busy' ? 'selected' : '' ?>>Busy</option>
                                            <option value="On Leave" <?= $tech['availability'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <span class="badge badge-primary"><?= $tech['active_repairs'] ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-success"><?= $tech['completed_repairs'] ?></span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-info" onclick="viewTechnician(<?= $tech['technician_id'] ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="editTechnician(<?= $tech['technician_id'] ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
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

<div id="addTechnicianModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Add New Technician</h2>
            <button type="button" class="close-modal" onclick="closeModal('addTechnicianModal')">&times;</button>
        </div>
        <form method="POST" id="addTechnicianForm">
            <?= csrfField() ?>
            <div class="modal-body">
                <div class="form-section">
                    <h3><i class="fas fa-user-circle"></i> Account Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">
                                <i class="fas fa-user"></i> Username <span class="required">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control" required minlength="3">
                            <small class="form-text">Minimum 3 characters</small>
                        </div>

                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i> Email <span class="required">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">
                                <i class="fas fa-lock"></i> Password <span class="required">*</span>
                            </label>
                            <div class="password-input-group">
                                <input type="password" id="password" name="password" class="form-control" required minlength="6">
                                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="form-text">Minimum 6 characters</small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">
                                <i class="fas fa-lock"></i> Confirm Password <span class="required">*</span>
                            </label>
                            <div class="password-input-group">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">
                                <i class="fas fa-user"></i> Full Name <span class="required">*</span>
                            </label>
                            <input type="text" id="full_name" name="full_name" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="contact_no">
                                <i class="fas fa-phone"></i> Contact Number <span class="required">*</span>
                            </label>
                            <input type="tel" id="contact_no" name="contact_no" class="form-control" required pattern="[0-9]{10,15}">
                            <small class="form-text">10-15 digits</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="specialization">
                                <i class="fas fa-tools"></i> Specialization
                            </label>
                            <input type="text" id="specialization" name="specialization" class="form-control" placeholder="e.g., Electronics, Hardware, Software">
                        </div>

                        <div class="form-group">
                            <label for="experience_years">
                                <i class="fas fa-calendar-alt"></i> Experience (Years) <span class="required">*</span>
                            </label>
                            <input type="number" id="experience_years" name="experience_years" class="form-control" required min="0" max="50" value="0">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addTechnicianModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="add_technician" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Add Technician
                </button>
            </div>
        </form>
    </div>
</div>

<div id="viewTechnicianModal" class="modal <?= $technicianDetails ? 'show' : '' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-cog"></i> Technician Details</h2>
            <button type="button" class="close-modal" onclick="closeTechnicianModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($technicianDetails): ?>
                <div class="customer-view-grid">
                    
                    <div class="customer-profile-card">
                        <div class="customer-avatar-large">
                            <?= strtoupper(substr($technicianDetails['full_name'], 0, 2)) ?>
                        </div>
                        <h3><?= sanitize($technicianDetails['full_name']) ?></h3>
                        <p class="customer-username">@<?= sanitize($technicianDetails['username']) ?></p>
                        <span class="badge <?= $technicianDetails['availability'] === 'Available' ? 'badge-success' : ($technicianDetails['availability'] === 'Busy' ? 'badge-warning' : 'badge-secondary') ?>">
                            <?= $technicianDetails['availability'] ?>
                        </span>
                        
                        <div class="customer-stats">
                            <div class="stat-item">
                                <i class="fas fa-tools"></i>
                                <div>
                                    <strong><?= $technicianDetails['total_repairs'] ?></strong>
                                    <span>Total Repairs</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <strong><?= $technicianDetails['completed_repairs'] ?></strong>
                                    <span>Completed</span>
                                </div>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-spinner"></i>
                                <div>
                                    <strong><?= $technicianDetails['active_repairs'] ?></strong>
                                    <span>Active</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    
                    <div class="customer-details-sections">
                        <div class="detail-section">
                            <h4><i class="fas fa-id-card"></i> Account Information</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Technician ID</label>
                                    <value>#<?= $technicianDetails['technician_id'] ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Username</label>
                                    <value><?= sanitize($technicianDetails['username']) ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Email Address</label>
                                    <value><?= sanitize($technicianDetails['email']) ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Registered Date</label>
                                    <value><?= formatDateTime($technicianDetails['registered_at']) ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Last Login</label>
                                    <value><?= $technicianDetails['last_login'] ? formatDateTime($technicianDetails['last_login']) : 'Never' ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Account Status</label>
                                    <value>
                                        <span class="badge <?= getStatusBadgeClass($technicianDetails['user_status']) ?>">
                                            <?= $technicianDetails['user_status'] ?>
                                        </span>
                                    </value>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4><i class="fas fa-address-card"></i> Contact Information</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Full Name</label>
                                    <value><?= sanitize($technicianDetails['full_name']) ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Contact Number</label>
                                    <value>
                                        <a href="tel:<?= sanitize($technicianDetails['contact_no']) ?>">
                                            <i class="fas fa-phone"></i> <?= sanitize($technicianDetails['contact_no']) ?>
                                        </a>
                                    </value>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4><i class="fas fa-briefcase"></i> Professional Information</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Specialization</label>
                                    <value><?= sanitize($technicianDetails['specialization'] ?: 'General') ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Experience</label>
                                    <value><?= $technicianDetails['experience_years'] ?> years</value>
                                </div>
                                <div class="detail-item">
                                    <label>Availability Status</label>
                                    <value>
                                        <span class="badge <?= $technicianDetails['availability'] === 'Available' ? 'badge-success' : ($technicianDetails['availability'] === 'Busy' ? 'badge-warning' : 'badge-secondary') ?>">
                                            <?= $technicianDetails['availability'] ?>
                                        </span>
                                    </value>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4><i class="fas fa-chart-bar"></i> Performance Monitoring</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Total Repairs</label>
                                    <value><?= $technicianDetails['total_repairs'] ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Active Repairs</label>
                                    <value><?= $technicianDetails['active_repairs'] ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Completed Repairs</label>
                                    <value><?= $technicianDetails['completed_repairs'] ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Completed This Month</label>
                                    <value><span class="badge badge-info"><?= $technicianDetails['completed_this_month'] ?? 0 ?></span></value>
                                </div>
                                <div class="detail-item">
                                    <label>Completion Rate</label>
                                    <value>
                                        <?php 
                                        $rate = $technicianDetails['total_repairs'] > 0 
                                            ? round(($technicianDetails['completed_repairs'] / $technicianDetails['total_repairs']) * 100) 
                                            : 0;
                                        $rateColor = $rate >= 80 ? '#059669' : ($rate >= 50 ? '#d97706' : '#dc2626');
                                        ?>
                                        <span style="color: <?= $rateColor ?>; font-weight: 700;"><?= $rate ?>%</span>
                                        <div style="background: #e5e7eb; border-radius: 4px; height: 8px; margin-top: 4px; overflow: hidden;">
                                            <div style="background: <?= $rateColor ?>; height: 100%; width: <?= $rate ?>%; border-radius: 4px; transition: width 0.5s;"></div>
                                        </div>
                                    </value>
                                </div>
                                <div class="detail-item">
                                    <label>Avg. Repair Time</label>
                                    <value>
                                        <?php 
                                        $avgHours = $technicianDetails['avg_repair_hours'] ?? 0;
                                        if ($avgHours > 24) {
                                            echo round($avgHours / 24, 1) . ' days';
                                        } else {
                                            echo ($avgHours ?: 'N/A') . ($avgHours ? ' hours' : '');
                                        }
                                        ?>
                                    </value>
                                </div>
                                <div class="detail-item">
                                    <label>Avg. Revenue/Repair</label>
                                    <value><?= $technicianDetails['avg_revenue'] ? '₱' . number_format($technicianDetails['avg_revenue'], 2) : 'N/A' ?></value>
                                </div>
                                <div class="detail-item">
                                    <label>Total Revenue Generated</label>
                                    <value><strong><?= $technicianDetails['total_revenue'] ? '₱' . number_format($technicianDetails['total_revenue'], 2) : 'N/A' ?></strong></value>
                                </div>
                                <div class="detail-item">
                                    <label>Today's Schedules</label>
                                    <value><span class="badge badge-primary"><?= $technicianDetails['today_schedules'] ?? 0 ?></span></value>
                                </div>
                                <div class="detail-item">
                                    <label>Workload Status</label>
                                    <value>
                                        <?php
                                        $active = $technicianDetails['active_repairs'];
                                        if ($active >= 5) {
                                            echo '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Overloaded (' . $active . ')</span>';
                                        } elseif ($active >= 3) {
                                            echo '<span class="badge badge-warning"><i class="fas fa-exclamation-circle"></i> Heavy (' . $active . ')</span>';
                                        } elseif ($active >= 1) {
                                            echo '<span class="badge badge-info"><i class="fas fa-tasks"></i> Normal (' . $active . ')</span>';
                                        } else {
                                            echo '<span class="badge badge-success"><i class="fas fa-check"></i> Available</span>';
                                        }
                                        ?>
                                    </value>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTechnicianModal()">
                <i class="fas fa-times"></i> Close
            </button>
            <button type="button" class="btn btn-warning" onclick="editTechnician(<?= $technicianDetails['technician_id'] ?>)">
                <i class="fas fa-edit"></i> Edit Technician
            </button>
        </div>
    </div>
</div>

<div id="editTechnicianModal" class="modal <?= $editTechnician ? 'show' : '' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-edit"></i> Edit Technician</h2>
            <button type="button" class="close-modal" onclick="closeEditTechnicianModal()">&times;</button>
        </div>
        <form method="POST" id="editTechnicianForm">
            <?= csrfField() ?>
            <input type="hidden" name="technician_id" value="<?= $editTechnician['technician_id'] ?? '' ?>">
            <div class="modal-body">
                <?php if ($editTechnician): ?>
                <div class="form-section">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_full_name">
                                <i class="fas fa-user"></i> Full Name <span class="required">*</span>
                            </label>
                            <input type="text" id="edit_full_name" name="full_name" class="form-control" required value="<?= sanitize($editTechnician['full_name']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_email">
                                <i class="fas fa-envelope"></i> Email <span class="required">*</span>
                            </label>
                            <input type="email" id="edit_email" name="email" class="form-control" required value="<?= sanitize($editTechnician['email']) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_contact_no">
                                <i class="fas fa-phone"></i> Contact Number <span class="required">*</span>
                            </label>
                            <input type="tel" id="edit_contact_no" name="contact_no" class="form-control" required pattern="[0-9]{10,15}" value="<?= sanitize($editTechnician['contact_no']) ?>">
                            <small class="form-text">10-15 digits</small>
                        </div>

                        <div class="form-group">
                            <label for="edit_availability">
                                <i class="fas fa-signal"></i> Availability Status <span class="required">*</span>
                            </label>
                            <select id="edit_availability" name="availability" class="form-control" required>
                                <option value="Available" <?= $editTechnician['availability'] === 'Available' ? 'selected' : '' ?>>Available</option>
                                <option value="Busy" <?= $editTechnician['availability'] === 'Busy' ? 'selected' : '' ?>>Busy</option>
                                <option value="On Leave" <?= $editTechnician['availability'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-briefcase"></i> Professional Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_specialization">
                                <i class="fas fa-tools"></i> Specialization
                            </label>
                            <input type="text" id="edit_specialization" name="specialization" class="form-control" placeholder="e.g., Electronics, Hardware, Software" value="<?= sanitize($editTechnician['specialization']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_experience_years">
                                <i class="fas fa-calendar-alt"></i> Experience (Years) <span class="required">*</span>
                            </label>
                            <input type="number" id="edit_experience_years" name="experience_years" class="form-control" required min="0" max="50" value="<?= $editTechnician['experience_years'] ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditTechnicianModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="edit_technician" class="btn btn-warning">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewTechnician(technicianId) {
    window.location.href = 'technicians.php?view_id=' + technicianId;
}

function editTechnician(technicianId) {
    window.location.href = 'technicians.php?edit_id=' + technicianId;
}

function closeTechnicianModal() {
    const url = new URL(window.location);
    url.searchParams.delete('view_id');
    window.location.href = url.toString();
}

function closeEditTechnicianModal() {
    const url = new URL(window.location);
    url.searchParams.delete('edit_id');
    window.location.href = url.toString();
}

<?php if ($technicianDetails): ?>
    document.getElementById('viewTechnicianModal').classList.add('show');
<?php endif; ?>

<?php if ($editTechnician): ?>
    document.getElementById('editTechnicianModal').classList.add('show');
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
