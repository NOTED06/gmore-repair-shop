<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

if (isLoggedIn()) {
    $role = getUserRole();
    switch ($role) {
        case 'Admin':
            redirect(SITE_URL . '/admin/dashboard.php');
        case 'Technician':
            redirect(SITE_URL . '/technician/dashboard.php');
        case 'Customer':
            redirect(SITE_URL . '/customer/dashboard.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, username, email, password, role, status FROM users WHERE (username = ? OR email = ?) AND role IN ('Admin', 'Technician')");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password'])) {
            if ($user['status'] !== 'Active') {
                $error = 'Your account is ' . strtolower($user['status']) . '. Please contact the administrator.';
            } else {
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $updateStmt->execute([$user['user_id']]);
                
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                
                if ($user['role'] === 'Technician') {
                    $profileStmt = $db->prepare("SELECT full_name FROM technicians WHERE user_id = ?");
                    $profileStmt->execute([$user['user_id']]);
                    $profile = $profileStmt->fetch();
                    $_SESSION['full_name'] = $profile['full_name'] ?? $user['username'];
                } else {
                    $_SESSION['full_name'] = $user['username'];
                }
                
                if ($user['role'] === 'Admin') {
                    redirect(SITE_URL . '/admin/dashboard.php');
                } else {
                    redirect(SITE_URL . '/technician/dashboard.php');
                }
            }
        } else {
            $error = 'Invalid credentials or unauthorized access.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/vendor/fonts/fonts.css">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/auth.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1730 0%, #272343 50%, #2d2755 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .auth-container {
            flex-direction: row;
            align-items: center;
            justify-content: center;
            width: 100%;
        }
        .auth-panel-right {
            max-width: 460px;
            width: 100%;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 3rem;
            margin: 2rem;
            background: #fff;
            flex: none;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="login-logo">
                    <i class="fas fa-shield-halved"></i>
                </div>
                <div class="auth-form-header">
                    <h2>Staff Login</h2>
                    <p>Access the management dashboard</p>
                </div>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" autocomplete="off">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input 
                                type="text" 
                                id="username" 
                                name="username" 
                                class="form-control" 
                                placeholder="Enter your username or email"
                                value="<?php echo htmlspecialchars($username ?? ''); ?>"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper has-toggle">
                            <i class="fas fa-lock input-icon"></i>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control" 
                                placeholder="Enter your password"
                                required
                            >
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>

                <a href="<?php echo SITE_URL; ?>/login.php" class="back-to-home">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pwField = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (pwField.type === 'password') {
                pwField.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwField.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
