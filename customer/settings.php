<?php

require_once '../config/config.php';
requireRole('Customer');

$db     = getDB();
$errors = [];

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$profile = null;
if ($userRole === 'Customer') {
    $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $email         = sanitize(trim($_POST['email'] ?? ''));
            $firstName     = sanitize(trim($_POST['first_name'] ?? ''));
            $middleInitial = sanitize(trim($_POST['middle_initial'] ?? ''));
            $lastName      = sanitize(trim($_POST['last_name'] ?? ''));
            $contactNo     = sanitize(trim($_POST['contact_no'] ?? ''));

            
            if (empty($firstName) && empty($lastName) && !empty($_POST['full_name'])) {
                $fullName = sanitize(trim($_POST['full_name']));
            } else {
                if ($middleInitial !== '') {
                    $cleanMI = strtoupper(preg_replace('/[^a-zA-Z]/', '', $middleInitial));
                    $formattedMI = strlen($cleanMI) === 1 ? ($cleanMI . '.') : $middleInitial;
                    $fullName = trim($firstName . ' ' . $formattedMI . ' ' . $lastName);
                } else {
                    $fullName = trim($firstName . ' ' . $lastName);
                }
            }

            if (!validateEmail($email))  $errors[] = 'Please enter a valid email address.';
            if (empty($firstName))       $errors[] = 'First name is required.';
            if (empty($lastName))        $errors[] = 'Last name is required.';
            if (!empty($firstName) && !empty($lastName) && strcasecmp($firstName, $lastName) === 0) {
                $errors[] = 'First name and last name cannot be the same.';
            }
            if (empty($contactNo))       $errors[] = 'Contact number is required.';

            if (empty($errors)) {
                $email = strtolower(trim($email));
                $stmt = $db->prepare("SELECT user_id FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND user_id != ?");
                $stmt->execute([$email, $email, $userId]);
                if ($stmt->fetch()) $errors[] = 'Email already in use by another account.';

                $stmt = $db->prepare("SELECT customer_id FROM customers WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) AND user_id != ?");
                $stmt->execute([$fullName, $userId]);
                if ($stmt->fetch()) $errors[] = 'An account with this name already exists.';
            }

            if (empty($errors)) {
                try {
                    $db->beginTransaction();
                    $db->prepare("UPDATE users SET email = ?, username = ? WHERE user_id = ?")->execute([$email, $email, $userId]);
                    if ($userRole === 'Customer') {
                        $address = sanitize($_POST['address'] ?? '');
                        $db->prepare("UPDATE customers SET full_name = ?, contact_no = ?, address = ? WHERE user_id = ?")->execute([$fullName, $contactNo, $address, $userId]);
                    }
                    $db->commit();
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['username'] = $email;
                    redirect($_SERVER['PHP_SELF'], 'Profile updated successfully!', 'success');
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            }
        }

        elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!verifyPassword($currentPassword, $user['password']))  $errors[] = 'Current password is incorrect.';
            if (strlen($newPassword) < 6)                              $errors[] = 'New password must be at least 6 characters.';
            if ($newPassword !== $confirmPassword)                     $errors[] = 'New passwords do not match.';

            if (empty($errors)) {
                try {
                    $db->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([hashPassword($newPassword), $userId]);
                    redirect($_SERVER['PHP_SELF'], 'Password changed successfully!', 'success');
                } catch (Exception $e) {
                    $errors[] = 'Failed to change password.';
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
}

$parsedFirst = '';
$parsedMI    = '';
$parsedLast  = '';
if (!empty($profile['full_name'])) {
    $parts = preg_split('/\s+/', trim($profile['full_name']));
    if (count($parts) === 1) {
        $parsedFirst = $parts[0];
    } elseif (count($parts) === 2) {
        $parsedFirst = $parts[0];
        $parsedLast = $parts[1];
    } else {
        if (preg_match('/^[a-zA-Z]\.?$/', $parts[1])) {
            $parsedFirst = $parts[0];
            $parsedMI = rtrim($parts[1], '.');
            $parsedLast = implode(' ', array_slice($parts, 2));
        } else {
            $parsedFirst = $parts[0];
            $parsedLast = implode(' ', array_slice($parts, 1));
        }
    }
}
$valFirst = $_POST['first_name'] ?? $parsedFirst;
$valMI    = $_POST['middle_initial'] ?? $parsedMI;
$valLast  = $_POST['last_name'] ?? $parsedLast;

$pageTitle    = 'Profile';
$activePage   = 'settings';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($profile['full_name'] ?? $user['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><i data-lucide="user-circle"></i> My Profile</h1>
            <p class="page-sub">Manage your profile information and password.</p>
        </div>
    </div>

    
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-bottom:20px">
        <i data-lucide="alert-circle"></i>
        <ul><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start">

        
        <div class="card" style="text-align:center;padding:28px 20px">
            <div style="width:72px;height:72px;border-radius:50%;background:rgba(0,229,200,0.12);border:2px solid rgba(0,229,200,0.3);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:22px;color:var(--accent);margin:0 auto 14px"><?= $userInitials ?></div>
            <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:16px;color:var(--text-primary);margin-bottom:4px"><?= sanitize($userName) ?></div>
            <div style="font-size:12px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.07em;margin-bottom:16px"><?= sanitize($user['role']) ?></div>

            <div style="display:flex;flex-direction:column;gap:8px;text-align:left">
                <?php
                $infoItems = [
                    'mail'         => [sanitize($user['email']), 'Email'],
                    'calendar'     => [formatDate($user['created_at'] ?? '', 'M d, Y'), 'Joined'],
                ];
                if (!empty($user['last_login'])) {
                    $infoItems['clock'] = [formatDate($user['last_login'], 'M d, Y'), 'Last Login'];
                }
                foreach ($infoItems as $icon => [$val, $label]):
                ?>
                <div style="display:flex;align-items:center;gap:8px;background:var(--bg-base);border:1px solid var(--border);border-radius:8px;padding:9px 12px">
                    <i data-lucide="<?= $icon ?>" style="width:14px;height:14px;color:var(--text-tertiary);flex-shrink:0"></i>
                    <div>
                        <div style="font-size:11px;color:var(--text-tertiary)"><?= $label ?></div>
                        <div style="font-size:12px;color:var(--text-primary);font-weight:500;word-break:break-all"><?= $val ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        
        <div style="display:flex;flex-direction:column;gap:16px">

            
            <div class="card">
                <div style="padding:0 0 14px;border-bottom:1px solid var(--border);margin-bottom:18px;display:flex;align-items:center;gap:8px">
                    <i data-lucide="user-circle" style="width:16px;height:16px;color:var(--accent)"></i>
                    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Profile Information</span>
                </div>
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <?php if ($profile): ?>
                    
                    <div class="form-grid-3" style="margin-bottom:14px">
                        <div class="field-group">
                            <label class="field-label" for="first_name">First Name <span style="color:var(--rose)">*</span></label>
                            <input type="text" id="first_name" name="first_name" class="input" placeholder="First name" value="<?= sanitize($valFirst) ?>" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="middle_initial">Middle Initial <span style="font-size:11px;font-weight:400;color:var(--text-tertiary);">(Optional)</span></label>
                            <input type="text" id="middle_initial" name="middle_initial" class="input" placeholder="e.g. M." maxlength="5" value="<?= sanitize($valMI) ?>">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="last_name">Last Name <span style="color:var(--rose)">*</span></label>
                            <input type="text" id="last_name" name="last_name" class="input" placeholder="Last name" value="<?= sanitize($valLast) ?>" required>
                        </div>
                    </div>

                    <div class="form-grid-2" style="margin-bottom:14px">
                        <div class="field-group">
                            <label class="field-label" for="email">Email Address <span style="color:var(--rose)">*</span></label>
                            <input type="email" id="email" name="email" class="input" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="contact_no">Contact Number <span style="color:var(--rose)">*</span></label>
                            <input type="tel" id="contact_no" name="contact_no" class="input" placeholder="Phone number" value="<?= sanitize($_POST['contact_no'] ?? $profile['contact_no'] ?? '') ?>" required>
                        </div>
                    </div>

                    <?php if ($userRole === 'Customer'): ?>
                    <div class="field-group" style="margin-bottom:18px">
                        <label class="field-label" for="address">Address <span style="font-size:11px;font-weight:400;color:var(--text-tertiary);">(Optional)</span></label>
                        <textarea id="address" name="address" class="input" rows="2" placeholder="Enter your complete address"><?= sanitize($_POST['address'] ?? $profile['address'] ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div style="margin-bottom:14px">
                        <div class="field-group">
                            <label class="field-label" for="email">Email Address <span style="color:var(--rose)">*</span></label>
                            <input type="email" id="email" name="email" class="input" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn-primary"><i data-lucide="save"></i> Save Changes</button>
                </form>
            </div>

            
            <div class="card">
                <div style="padding:0 0 14px;border-bottom:1px solid var(--border);margin-bottom:18px;display:flex;align-items:center;gap:8px">
                    <i data-lucide="lock" style="width:16px;height:16px;color:var(--amber)"></i>
                    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-primary)">Change Password</span>
                </div>
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div style="display:flex;flex-direction:column;gap:14px;max-width:480px">
                        <div class="field-group">
                            <label class="field-label" for="current_password">Current Password<span style="color:var(--rose)"> *</span></label>
                            <input type="password" id="current_password" name="current_password" class="input" placeholder="Enter current password" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_password">New Password<span style="color:var(--rose)"> *</span></label>
                            <input type="password" id="new_password" name="new_password" class="input" placeholder="At least 6 characters" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="confirm_password">Confirm New Password<span style="color:var(--rose)"> *</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" class="input" placeholder="Repeat new password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="margin-top:18px"><i data-lucide="key-round"></i> Update Password</button>
                </form>
            </div>

            
            <div class="card" style="border-color:rgba(244,63,94,0.2)">
                <div style="padding:0 0 14px;border-bottom:1px solid rgba(244,63,94,0.15);margin-bottom:16px;display:flex;align-items:center;gap:8px">
                    <i data-lucide="shield-alert" style="width:16px;height:16px;color:var(--rose)"></i>
                    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--rose)">Danger Zone</span>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:14px;font-weight:600;color:var(--text-primary)">Sign out of your account</div>
                        <div style="font-size:12px;color:var(--text-tertiary);margin-top:2px">You will be returned to the login page.</div>
                    </div>
                    <a href="<?= SITE_URL ?>/logout.php" class="btn-sm-rose"><i data-lucide="log-out"></i> Log Out</a>
                </div>
            </div>
        </div>
    </div>

</main>
</div>
<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
</body>
</html>
