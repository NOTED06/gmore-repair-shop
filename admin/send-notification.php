<?php

require_once '../config/config.php';
$pageTitle = 'Send Notification - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();
$errors = [];
$success = false;

$repairId = (int)($_GET['repair_id'] ?? $_POST['repair_id'] ?? 0);
$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

if ($repairId > 0) {
    $stmt = $db->prepare("
        SELECT r.*, c.full_name as customer_name, t.full_name as technician_name
        FROM repairs r
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        LEFT JOIN technicians t ON r.technician_id = t.technician_id
        WHERE r.repair_id = ?
    ");
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch();
}

if ($userId > 0) {
    $stmt = $db->prepare("SELECT user_id, username, email, role FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $title = sanitize($_POST['title'] ?? '');
        $message = sanitize($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'Info';
        $recipients = $_POST['recipients'] ?? [];
        
        
        if (empty($title)) {
            $errors[] = 'Title is required.';
        }
        
        if (empty($message)) {
            $errors[] = 'Message is required.';
        }
        
        if (empty($recipients)) {
            $errors[] = 'Please select at least one recipient.';
        }
        
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO notifications (user_id, repair_id, title, message, type) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $count = 0;
                foreach ($recipients as $recipientId) {
                    $stmt->execute([
                        (int)$recipientId,
                        $repairId > 0 ? $repairId : null,
                        $title,
                        $message,
                        $type
                    ]);
                    $count++;
                }
                
                redirect('repairs.php', "Notification sent to $count recipient(s)!", 'success');
                
            } catch (Exception $e) {
                $errors[] = 'Failed to send notification. Please try again.';
            }
        }
    }
}

$recipients = [];

if ($repairId > 0 && isset($repair)) {
    $stmt = $db->prepare("SELECT user_id FROM customers WHERE customer_id = ?");
    $stmt->execute([$repair['customer_id']]);
    $customerUserId = $stmt->fetchColumn();
    
    if ($customerUserId) {
        $stmt = $db->prepare("SELECT user_id, username, email FROM users WHERE user_id = ?");
        $stmt->execute([$customerUserId]);
        $customer = $stmt->fetch();
        if ($customer) {
            $recipients[] = [
                'user_id' => $customer['user_id'],
                'name' => $repair['customer_name'],
                'role' => 'Customer',
                'email' => $customer['email']
            ];
        }
    }
    
    if ($repair['technician_id']) {
        $stmt = $db->prepare("SELECT user_id FROM technicians WHERE technician_id = ?");
        $stmt->execute([$repair['technician_id']]);
        $techUserId = $stmt->fetchColumn();
        
        if ($techUserId) {
            $stmt = $db->prepare("SELECT user_id, username, email FROM users WHERE user_id = ?");
            $stmt->execute([$techUserId]);
            $tech = $stmt->fetch();
            if ($tech) {
                $recipients[] = [
                    'user_id' => $tech['user_id'],
                    'name' => $repair['technician_name'],
                    'role' => 'Technician',
                    'email' => $tech['email']
                ];
            }
        }
    }
}

if ($userId > 0 && isset($user)) {
    $userName = $user['username'];
    if ($user['role'] === 'Customer') {
        $stmt = $db->prepare("SELECT full_name FROM customers WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        $userName = $profile['full_name'] ?? $userName;
    } elseif ($user['role'] === 'Technician') {
        $stmt = $db->prepare("SELECT full_name FROM technicians WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        $userName = $profile['full_name'] ?? $userName;
    }
    
    $recipients[] = [
        'user_id' => $user['user_id'],
        'name' => $userName,
        'role' => $user['role'],
        'email' => $user['email']
    ];
}

if (empty($recipients)) {
    $stmt = $db->query("
        SELECT u.user_id, u.username, u.email, u.role,
               COALESCE(c.full_name, t.full_name, u.username) as full_name
        FROM users u
        LEFT JOIN customers c ON u.user_id = c.user_id
        LEFT JOIN technicians t ON u.user_id = t.user_id
        WHERE u.role IN ('Customer', 'Technician')
        ORDER BY u.role, full_name
    ");
    
    while ($row = $stmt->fetch()) {
        $recipients[] = [
            'user_id' => $row['user_id'],
            'name' => $row['full_name'],
            'role' => $row['role'],
            'email' => $row['email']
        ];
    }
}
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-paper-plane"></i> Send Notification</h1>
        <p class="text-muted">Send a notification to users</p>
    </div>
    <a href="<?= $repairId > 0 ? 'repair-view.php?id=' . $repairId : 'repairs.php' ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    <div>
        <strong>Please fix the following errors:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($repairId > 0 && isset($repair)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <div>
        <strong>Repair: #<?= $repairId ?> - <?= sanitize($repair['appliance_type']) ?></strong>
        <p style="margin: 0.5rem 0 0 0;">Status: <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>"><?= $repair['repair_status'] ?></span></p>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-envelope"></i> Notification Details</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form">
            <?= csrfField() ?>
            <input type="hidden" name="repair_id" value="<?= $repairId ?>">
            
            <div class="form-group">
                <label for="title" class="required">Title</label>
                <input type="text" id="title" name="title" class="form-control" 
                       value="<?= sanitize($_POST['title'] ?? '') ?>" 
                       placeholder="e.g., Repair Status Update" required>
            </div>
            
            <div class="form-group">
                <label for="message" class="required">Message</label>
                <textarea id="message" name="message" class="form-control" rows="5" 
                          placeholder="Enter your message here..." required><?= sanitize($_POST['message'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="type">Notification Type</label>
                <select id="type" name="type" class="form-control">
                    <option value="Info" <?= ($_POST['type'] ?? '') === 'Info' ? 'selected' : '' ?>>Info</option>
                    <option value="Success" <?= ($_POST['type'] ?? '') === 'Success' ? 'selected' : '' ?>>Success</option>
                    <option value="Warning" <?= ($_POST['type'] ?? '') === 'Warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="Alert" <?= ($_POST['type'] ?? '') === 'Alert' ? 'selected' : '' ?>>Alert</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="required">Recipients</label>
                <div class="recipients-list">
                    <?php if (!empty($recipients)): ?>
                        <?php foreach ($recipients as $recipient): ?>
                            <label class="recipient-item">
                                <input type="checkbox" name="recipients[]" value="<?= $recipient['user_id'] ?>" 
                                       <?= ($userId > 0 && $recipient['user_id'] == $userId) || ($repairId > 0) ? 'checked' : '' ?>>
                                <div class="recipient-info">
                                    <div class="recipient-name">
                                        <i class="fas fa-user"></i>
                                        <?= sanitize($recipient['name']) ?>
                                        <span class="badge badge-secondary"><?= $recipient['role'] ?></span>
                                    </div>
                                    <div class="recipient-email"><?= sanitize($recipient['email']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No recipients available.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Send Notification
                </button>
                <a href="<?= $repairId > 0 ? 'repair-view.php?id=' . $repairId : 'repairs.php' ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.required::after {
    content: ' *';
    color: var(--danger);
}

.recipients-list {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    max-height: 400px;
    overflow-y: auto;
}

.recipient-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    margin-bottom: 0.5rem;
}

.recipient-item:hover {
    background: var(--background-secondary);
}

.recipient-item input[type="checkbox"] {
    margin-top: 0.25rem;
    cursor: pointer;
}

.recipient-info {
    flex: 1;
}

.recipient-name {
    font-weight: 500;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.recipient-name i {
    color: var(--accent);
}

.recipient-email {
    font-size: 0.875rem;
    color: var(--text-muted);
}
</style>

<?php require_once '../includes/footer.php'; ?>
