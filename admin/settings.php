<?php

require_once '../config/config.php';
$pageTitle = 'Profile Settings - ' . SITE_NAME;
require_once '../includes/header.php';
requireLogin();

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

<div style="max-width:1100px;margin:0 auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-weight:800;font-size:24px;color:var(--text-primary);margin:0;display:flex;align-items:center;gap:10px">
                <div style="width:40px;height:40px;border-radius:10px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center">
                    <i data-lucide="settings"></i>
                </div>
                Account Settings
            </h1>
            <p style="color:var(--text-muted);margin:4px 0 0 50px;font-size:14px">Manage your profile information and security settings</p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-bottom:24px">
        <i data-lucide="alert-circle"></i>
        <div>
            <strong style="display:block;margin-bottom:4px">Please fix the following errors:</strong>
            <ul style="margin:0;padding-left:20px">
                <?php foreach ($errors as $error): ?>
                    <li><?= sanitize($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start">
        
        <div class="card" style="position:sticky;top:90px">
            <div style="text-align:center;padding-bottom:20px;border-bottom:1px solid var(--border)">
                <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1e1b4b);color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;margin:0 auto 16px;position:relative">
                    <?= strtoupper(substr($profile['full_name'] ?? $user['username'], 0, 2)) ?>
                    <div style="position:absolute;bottom:0;right:0;width:24px;height:24px;background:var(--emerald);border-radius:50%;border:3px solid #fff;display:flex;align-items:center;justify-content:center;color:#fff">
                        <i data-lucide="check" style="width:12px;height:12px"></i>
                    </div>
                </div>
                <h3 style="margin:0 0 4px;font-family:'Syne',sans-serif;font-size:18px"><?= sanitize($profile['full_name'] ?? $user['username']) ?></h3>
                <div style="display:inline-flex;align-items:center;gap:6px;background:var(--primary-light);color:var(--primary);padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600">
                    <i data-lucide="shield" style="width:14px;height:14px"></i>
                    <?= $user['role'] ?>
                </div>
            </div>
            
            <div style="padding-top:20px;display:flex;flex-direction:column;gap:16px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--background-secondary);color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="user" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;letter-spacing:0.5px">Username</div>
                        <div style="font-size:13px;font-weight:500;color:var(--text-primary)">@<?= sanitize($user['username']) ?></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--background-secondary);color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="mail" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;letter-spacing:0.5px">Email</div>
                        <div style="font-size:13px;font-weight:500;color:var(--text-primary)"><?= sanitize($user['email']) ?></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--background-secondary);color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="calendar" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;letter-spacing:0.5px">Joined</div>
                        <div style="font-size:13px;font-weight:500;color:var(--text-primary)"><?= formatDate($user['created_at'], 'M d, Y') ?></div>
                    </div>
                </div>
                <?php if ($user['last_login']): ?>
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--background-secondary);color:var(--text-muted);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="clock" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;letter-spacing:0.5px">Last Login</div>
                        <div style="font-size:13px;font-weight:500;color:var(--text-primary)"><?= formatDate($user['last_login'], 'M d, Y g:i A') ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        
        <div style="display:flex;flex-direction:column;gap:24px">
            
            <div class="card">
                <div style="padding:0 0 16px;border-bottom:1px solid var(--border);margin-bottom:20px;display:flex;align-items:center;gap:10px">
                    <div style="width:32px;height:32px;border-radius:8px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="user-circle" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <h2 style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin:0;color:var(--text-primary)">Profile Information</h2>
                        <span style="font-size:13px;color:var(--text-muted)">Update your personal details</span>
                    </div>
                </div>
                
                <form method="POST" novalidate id="profileForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-grid-2" style="margin-bottom:16px">
                        <div class="field-group">
                            <label class="field-label" for="username">Username</label>
                            <input type="text" id="username" class="input" value="<?= sanitize($user['username']) ?>" disabled style="opacity:0.6;cursor:not-allowed;background:#f8fafc">
                            <span class="field-hint">Username cannot be changed</span>
                        </div>
                        
                        <div class="field-group">
                            <label class="field-label" for="email">Email Address <span style="color:var(--rose)">*</span></label>
                            <input type="email" id="email" name="email" class="input" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                    </div>
                    
                    <?php if ($profile): ?>
                    <div class="form-grid-2" style="margin-bottom:16px">
                        <div class="field-group">
                            <label class="field-label" for="full_name">Full Name <span style="color:var(--rose)">*</span></label>
                            <input type="text" id="full_name" name="full_name" class="input" value="<?= sanitize($profile['full_name']) ?>" required>
                        </div>
                        
                        <div class="field-group">
                            <label class="field-label" for="contact_no">Contact Number <span style="color:var(--rose)">*</span></label>
                            <input type="tel" id="contact_no" name="contact_no" class="input" value="<?= sanitize($profile['contact_no']) ?>" required>
                        </div>
                    </div>
                    
                    <?php if ($userRole === 'Customer'): ?>
                    <div class="field-group" style="margin-bottom:16px">
                        <label class="field-label" for="address">Address</label>
                        <textarea id="address" name="address" class="input" rows="2" placeholder="Enter your complete address"><?= sanitize($profile['address'] ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($userRole === 'Technician'): ?>
                    <div class="form-grid-2" style="margin-bottom:16px">
                        <div class="field-group">
                            <label class="field-label" for="specialization">Specialization</label>
                            <input type="text" id="specialization" name="specialization" class="input" value="<?= sanitize($profile['specialization'] ?? '') ?>" placeholder="e.g., Electronics, Appliances">
                        </div>
                        
                        <div class="field-group">
                            <label class="field-label" for="experience_years">Years of Experience</label>
                            <input type="number" id="experience_years" name="experience_years" class="input" min="0" max="50" value="<?= (int)($profile['experience_years'] ?? 0) ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end">
                        <button type="submit" class="btn-primary">
                            <i data-lucide="save" style="width:16px;height:16px"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

            
            <div class="card">
                <div style="padding:0 0 16px;border-bottom:1px solid var(--border);margin-bottom:20px;display:flex;align-items:center;gap:10px">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(217,119,6,0.1);color:var(--amber);display:flex;align-items:center;justify-content:center">
                        <i data-lucide="lock" style="width:18px;height:18px"></i>
                    </div>
                    <div>
                        <h2 style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;margin:0;color:var(--text-primary)">Security Settings</h2>
                        <span style="font-size:13px;color:var(--text-muted)">Manage your password</span>
                    </div>
                </div>
                
                <form method="POST" novalidate id="passwordForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div style="display:flex;flex-direction:column;gap:16px;max-width:480px">
                        <div class="field-group">
                            <label class="field-label" for="current_password">Current Password <span style="color:var(--rose)">*</span></label>
                            <input type="password" id="current_password" name="current_password" class="input" required>
                        </div>
                        
                        <div class="field-group">
                            <label class="field-label" for="new_password">New Password <span style="color:var(--rose)">*</span></label>
                            <input type="password" id="new_password" name="new_password" class="input" placeholder="At least 6 characters" required>
                        </div>
                        
                        <div class="field-group">
                            <label class="field-label" for="confirm_password">Confirm New Password <span style="color:var(--rose)">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" class="input" required>
                        </div>
                    </div>
                    
                    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);display:flex">
                        <button type="submit" class="btn-primary" style="background:var(--amber);border-color:var(--amber);color:#fff">
                            <i data-lucide="key" style="width:16px;height:16px"></i>
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/vendor/lucide/lucide.min.js"></script>
<script>
lucide.createIcons();

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Mismatch',text:'New passwords do not match!',confirmButtonColor:'#272343'});
        return false;
    }
    
    if (newPassword.length < 6) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Too Short',text:'New password must be at least 6 characters long!',confirmButtonColor:'#272343'});
        return false;
    }
});
</script>

<style>
@media (max-width: 860px) {
    div[style*="grid-template-columns:300px 1fr"] {
        grid-template-columns: 1fr !important;
    }
    div[style*="position:sticky"] {
        position: static !important;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>
