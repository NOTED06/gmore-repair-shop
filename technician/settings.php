<?php

require_once '../config/config.php';
$pageTitle = 'Profile Settings - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();
$errors = [];
$success = false;

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$profile = null;
if ($userRole === 'Customer') {
    $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
} elseif ($userRole === 'Technician') {
    $stmt = $db->prepare("SELECT * FROM technicians WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $action = $_POST['action'] ?? '';
        
        
        if ($action === 'update_profile') {
            $email = sanitize($_POST['email'] ?? '');
            $fullName = sanitize($_POST['full_name'] ?? '');
            $contactNo = sanitize($_POST['contact_no'] ?? '');
            
            
            if (!validateEmail($email)) {
                $errors[] = 'Please enter a valid email address.';
            }
            
            if (empty($fullName)) {
                $errors[] = 'Full name is required.';
            }
            
            if (empty($contactNo)) {
                $errors[] = 'Contact number is required.';
            }
            
            
            if (empty($errors)) {
                $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    $errors[] = 'Email already in use by another account.';
                }
            }
            
            
            if (empty($errors)) {
                try {
                    $db->beginTransaction();
                    
                    
                    $stmt = $db->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                    $stmt->execute([$email, $userId]);
                    
                    
                    if ($userRole === 'Customer') {
                        $address = sanitize($_POST['address'] ?? '');
                        $stmt = $db->prepare("UPDATE customers SET full_name = ?, contact_no = ?, address = ? WHERE user_id = ?");
                        $stmt->execute([$fullName, $contactNo, $address, $userId]);
                    } elseif ($userRole === 'Technician') {
                        $specialization = sanitize($_POST['specialization'] ?? '');
                        $experienceYears = (int)($_POST['experience_years'] ?? 0);
                        $stmt = $db->prepare("UPDATE technicians SET full_name = ?, contact_no = ?, specialization = ?, experience_years = ? WHERE user_id = ?");
                        $stmt->execute([$fullName, $contactNo, $specialization, $experienceYears, $userId]);
                    }
                    
                    $db->commit();
                    $_SESSION['full_name'] = $fullName;
                    
                    redirect($_SERVER['PHP_SELF'], 'Profile updated successfully!', 'success');
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            }
        }
        
        
        elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            
            if (!verifyPassword($currentPassword, $user['password'])) {
                $errors[] = 'Current password is incorrect.';
            }
            
            if (strlen($newPassword) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            }
            
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New passwords do not match.';
            }
            
            
            if (empty($errors)) {
                try {
                    $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $stmt->execute([hashPassword($newPassword), $userId]);
                    
                    redirect($_SERVER['PHP_SELF'], 'Password changed successfully!', 'success');
                    
                } catch (Exception $e) {
                    $errors[] = 'Failed to change password. Please try again.';
                }
            }
        }
    }
}

$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($userRole === 'Customer') {
    $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
} elseif ($userRole === 'Technician') {
    $stmt = $db->prepare("SELECT * FROM technicians WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
}
?>

<div class="settings-premium-layout">
    
    
    <div class="profile-banner">
        <div class="banner-bg"></div>
        <div class="banner-content">
            <div class="avatar-container">
                <div class="avatar-glow"></div>
                <div class="profile-avatar">
                    <?= strtoupper(substr($profile['full_name'] ?? $user['username'], 0, 2)) ?>
                </div>
            </div>
            
            <div class="profile-intro">
                <h2 class="profile-title"><?= sanitize($profile['full_name'] ?? $user['username']) ?></h2>
                <div class="profile-badges">
                    <span class="badge-role"><i class="fas fa-wrench"></i> <?= $user['role'] ?></span>
                    <?php if ($userRole === 'Technician' && !empty($profile['specialization'])): ?>
                        <span class="badge-spec"><i class="fas fa-bolt"></i> <?= sanitize($profile['specialization']) ?> Expert</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="profile-quick-stats">
                <div class="quick-stat">
                    <span class="stat-val"><?= sanitize($user['username']) ?></span>
                    <span class="stat-lbl">Username</span>
                </div>
                <div class="stat-divider"></div>
                <div class="quick-stat">
                    <span class="stat-val"><?= formatDate($user['created_at']) ?></span>
                    <span class="stat-lbl">Member Since</span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="premium-alert error-alert">
        <i class="fas fa-exclamation-triangle"></i>
        <div class="alert-content">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= sanitize($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="settings-grid">
        
        <div class="premium-card form-card">
            <div class="card-header-accent"></div>
            <div class="premium-card-body">
                <div class="premium-card-title">
                    <div class="title-icon"><i class="fas fa-user-edit"></i></div>
                    <div>
                        <h3>Personal Information</h3>
                        <p>Keep your contact details up to date</p>
                    </div>
                </div>

                <form method="POST" class="premium-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group-modern">
                        <label>Username</label>
                        <input type="text" class="input-modern disabled" value="<?= sanitize($user['username']) ?>" disabled>
                        <i class="fas fa-lock field-icon lock-icon"></i>
                    </div>
                    
                    <div class="form-group-modern">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="email" class="input-modern" value="<?= sanitize($user['email']) ?>" required>
                        <i class="fas fa-envelope field-icon"></i>
                    </div>
                    
                    <?php if ($profile): ?>
                    <div class="form-row-modern">
                        <div class="form-group-modern half">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" class="input-modern" value="<?= sanitize($profile['full_name']) ?>" required>
                            <i class="fas fa-user field-icon"></i>
                        </div>
                        <div class="form-group-modern half">
                            <label>Contact Number <span class="req">*</span></label>
                            <input type="tel" name="contact_no" class="input-modern" value="<?= sanitize($profile['contact_no']) ?>" required>
                            <i class="fas fa-phone-alt field-icon"></i>
                        </div>
                    </div>
                    
                    <?php if ($userRole === 'Customer'): ?>
                    <div class="form-group-modern">
                        <label>Address</label>
                        <textarea name="address" class="input-modern textarea" rows="3"><?= sanitize($profile['address'] ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($userRole === 'Technician'): ?>
                    <div class="form-row-modern">
                        <div class="form-group-modern half">
                            <label>Specialization</label>
                            <input type="text" name="specialization" class="input-modern" value="<?= sanitize($profile['specialization'] ?? '') ?>">
                            <i class="fas fa-star field-icon"></i>
                        </div>
                        <div class="form-group-modern half">
                            <label>Experience (Years)</label>
                            <input type="number" name="experience_years" class="input-modern" min="0" max="50" value="<?= (int)($profile['experience_years'] ?? 0) ?>">
                            <i class="fas fa-calendar-check field-icon"></i>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn-premium-cyan">
                            <span>Save Profile</span> <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        
        <div class="premium-card form-card">
            <div class="card-header-accent warning"></div>
            <div class="premium-card-body">
                <div class="premium-card-title">
                    <div class="title-icon warning"><i class="fas fa-shield-alt"></i></div>
                    <div>
                        <h3>Security Settings</h3>
                        <p>Manage your password and account access</p>
                    </div>
                </div>

                <form method="POST" class="premium-form" id="passwordForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group-modern">
                        <label>Current Password <span class="req">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="current_password" name="current_password" class="input-modern" required>
                            <i class="fas fa-key field-icon"></i>
                            <button type="button" class="btn-toggle-pass" onclick="togglePassword('current_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group-modern">
                        <label>New Password <span class="req">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="new_password" name="new_password" class="input-modern" required>
                            <i class="fas fa-lock field-icon"></i>
                            <button type="button" class="btn-toggle-pass" onclick="togglePassword('new_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="helper-text">Must be at least 6 characters long</span>
                    </div>
                    
                    <div class="form-group-modern">
                        <label>Confirm New Password <span class="req">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="input-modern" required>
                            <i class="fas fa-check-circle field-icon"></i>
                            <button type="button" class="btn-toggle-pass" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-actions-modern">
                        <button type="submit" class="btn-premium-warning">
                            <i class="fas fa-sync-alt"></i> <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = input.parentElement.querySelector('.btn-toggle-pass i');
    
    if (input.type === 'password') {
        input.type = 'text';
        button.classList.remove('fa-eye');
        button.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        button.classList.remove('fa-eye-slash');
        button.classList.add('fa-eye');
    }
}

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New passwords do not match!');
        return false;
    }
    
    if (newPassword.length < 6) {
        e.preventDefault();
        alert('New password must be at least 6 characters long!');
        return false;
    }
});
</script>

<style>

.settings-premium-layout {
    max-width: 1200px;
    margin: 0 auto;
    padding-bottom: 3rem;
}

.profile-banner {
    position: relative;
    background: var(--primary);
    border-radius: 24px;
    overflow: hidden;
    margin-bottom: 2.5rem;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
}

.banner-bg {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    z-index: 1;
}

.banner-bg::before {
    content: '';
    position: absolute;
    top: -50%; left: -20%;
    width: 60%; height: 200%;
    background: radial-gradient(circle, var(--gold-dark) 0%, transparent 70%);
    transform: rotate(30deg);
    opacity: 0.15;
}

.banner-content {
    position: relative;
    z-index: 2;
    padding: 3rem;
    display: flex;
    align-items: center;
    gap: 3rem;
}

.avatar-container {
    position: relative;
}

.avatar-glow {
    position: absolute;
    top: -5px; left: -5px; right: -5px; bottom: -5px;
    background: var(--gold);
    border-radius: 50%;
    filter: blur(12px);
    opacity: 0.6;
    animation: pulseGlow 3s infinite alternate;
}

@keyframes pulseGlow {
    0% { opacity: 0.4; transform: scale(0.95); }
    100% { opacity: 0.8; transform: scale(1.05); }
}

.profile-avatar {
    position: relative;
    width: 130px; height: 130px;
    background: var(--bg-white);
    border: 4px solid var(--primary-dark);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    font-weight: 800;
    color: var(--primary);
    font-family: 'Syne', sans-serif;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.1);
}

.profile-intro {
    flex: 1;
}

.profile-title {
    color: #fff;
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 0.75rem;
    letter-spacing: -0.5px;
}

.profile-badges {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.profile-badges span {
    padding: 6px 14px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.badge-role {
    background: var(--gold-light);
    color: var(--gold-dark);
    border: 1px solid var(--gold);
}

.badge-spec {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.profile-quick-stats {
    display: flex;
    align-items: center;
    gap: 2rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 1.5rem 2.5rem;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
}

.quick-stat {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stat-val {
    color: #fff;
    font-size: 1.1rem;
    font-weight: 700;
}

.stat-lbl {
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-divider {
    width: 1px;
    height: 40px;
    background: rgba(255, 255, 255, 0.2);
}

.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2.5rem;
    align-items: start;
}

@media (max-width: 992px) {
    .settings-grid { grid-template-columns: 1fr; }
    .banner-content { flex-direction: column; text-align: center; }
    .profile-quick-stats { width: 100%; justify-content: center; }
}

.premium-card {
    background: var(--bg-white);
    border-radius: 20px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    transition: transform 0.3s ease;
}

.premium-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
}

.card-header-accent {
    height: 6px;
    background: var(--primary);
}

.card-header-accent.warning {
    background: var(--status-pending);
}

.premium-card-body {
    padding: 2.5rem;
}

.premium-card-title {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}

.title-icon {
    width: 54px; height: 54px;
    background: var(--bg-hover);
    color: var(--primary);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.title-icon.warning {
    background: #FFF8E1;
    color: var(--status-pending);
}

.premium-card-title h3 {
    margin: 0 0 4px;
    font-size: 1.35rem;
    color: var(--text-heading);
    font-weight: 700;
}

.premium-card-title p {
    margin: 0;
    color: var(--text-muted);
    font-size: 14px;
}

.premium-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.form-row-modern {
    display: flex;
    gap: 1.5rem;
}

.form-group-modern {
    position: relative;
    flex: 1;
}

.form-group-modern label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-body);
    margin-bottom: 8px;
    letter-spacing: 0.3px;
}

.form-group-modern .req {
    color: #EF4444;
}

.input-modern {
    width: 100%;
    background: var(--bg-hover);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px 14px 44px;
    font-size: 14px;
    color: var(--text-heading);
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.input-modern.textarea {
    padding-left: 16px;
    resize: vertical;
}

.input-modern:focus {
    outline: none;
    background: var(--bg-white);
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1);
}

.form-group-modern:has(.input-modern:focus) .field-icon {
    color: var(--primary);
}

.input-modern.disabled {
    background: var(--border);
    color: var(--text-muted);
    cursor: not-allowed;
}

.field-icon {
    position: absolute;
    left: 16px;
    top: 40px;
    color: var(--text-muted);
    font-size: 15px;
    transition: color 0.3s ease;
}

.lock-icon {
    opacity: 0.5;
}

.helper-text {
    display: block;
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 6px;
}

.password-wrapper {
    position: relative;
}

.password-wrapper .input-modern {
    padding-right: 44px;
}

.password-wrapper .field-icon {
    top: 15px;
}

.btn-toggle-pass {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 5px;
    transition: color 0.2s;
}

.btn-toggle-pass:hover {
    color: var(--text-heading);
}

.form-actions-modern {
    margin-top: 1rem;
    display: flex;
    justify-content: flex-end;
}

.btn-premium-cyan, .btn-premium-warning {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    border-radius: 12px;
    border: none;
    color: #fff;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.btn-premium-cyan {
    background: var(--primary);
    box-shadow: 0 4px 10px rgba(30, 58, 138, 0.2);
}

.btn-premium-cyan:hover {
    transform: translateY(-3px);
    background: var(--primary-dark);
    box-shadow: 0 8px 15px rgba(30, 58, 138, 0.3);
}

.btn-premium-warning {
    background: var(--status-pending);
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2);
}

.btn-premium-warning:hover {
    transform: translateY(-3px);
    background: #D97706;
    box-shadow: 0 8px 15px rgba(245, 158, 11, 0.3);
}

.premium-alert {
    background: #FEF2F2;
    border-left: 4px solid #EF4444;
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    gap: 16px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1);
}

.premium-alert i {
    color: #EF4444;
    font-size: 1.25rem;
    margin-top: 2px;
}

.alert-content strong {
    color: #991B1B;
    display: block;
    margin-bottom: 8px;
}

.alert-content ul {
    margin: 0; padding-left: 1.5rem;
    color: #B91C1C;
    font-size: 14px;
}
</style>

<?php require_once '../includes/footer.php'; ?>