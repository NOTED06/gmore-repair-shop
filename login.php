<?php

require_once 'config/config.php';
require_once 'includes/functions.php';

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
$openModal = false;
$registerErrors = [];
$registerSuccess = false;
$openRegisterModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['customer_login'])) {
        $openModal = true;
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    
    if (!validateEmail($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, username, email, password, role, status FROM users WHERE LOWER(email) = ? AND role = 'Customer'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password'])) {
            if ($user['status'] !== 'Active') {
                $error = 'Your account is ' . strtolower($user['status']) . '. Please contact admin.';
            } else {
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $updateStmt->execute([$user['user_id']]);
                
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                $stmt = $db->prepare("SELECT full_name FROM customers WHERE user_id = ?");
                $stmt->execute([$user['user_id']]);
                $profile = $stmt->fetch();
                $_SESSION['full_name'] = $profile['full_name'] ?? $user['username'];
                
                redirect(SITE_URL . '/customer/dashboard.php');
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
    } elseif (isset($_POST['customer_register'])) {
        $openRegisterModal = true;
        
        
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $registerErrors[] = 'Invalid form submission.';
        } else {
            
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $firstName = sanitize(trim($_POST['first_name'] ?? ''));
            $middleInitial = sanitize(trim($_POST['middle_initial'] ?? ''));
            $lastName = sanitize(trim($_POST['last_name'] ?? ''));
            $contactNo = sanitize($_POST['contact_no'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            
            
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
            
            
            if (!validateEmail($email)) {
                $registerErrors[] = 'Please enter a valid email address.';
            }
            
            if (empty($firstName)) {
                $registerErrors[] = 'First name is required.';
            }

            if (empty($lastName)) {
                $registerErrors[] = 'Last name is required.';
            }

            if (!empty($firstName) && !empty($lastName) && strcasecmp($firstName, $lastName) === 0) {
                $registerErrors[] = 'First name and last name cannot be the same.';
            }

            if (empty($contactNo)) {
                $registerErrors[] = 'Contact number is required.';
            }

            if (strlen($password) < 6) {
                $registerErrors[] = 'Password must be at least 6 characters.';
            }
            
            if ($password !== $confirmPassword) {
                $registerErrors[] = 'Passwords do not match.';
            }
            
            
            if (empty($registerErrors)) {
                $db = getDB();
                $stmt = $db->prepare("SELECT user_id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ?");
                $stmt->execute([$email, $email]);
                
                if ($stmt->fetch()) {
                    $registerErrors[] = 'An account with this email already exists.';
                }

                $stmt = $db->prepare("SELECT customer_id FROM customers WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?))");
                $stmt->execute([$fullName]);
                if ($stmt->fetch()) {
                    $registerErrors[] = 'An account with this name already exists.';
                }
            }
            
            
            if (empty($registerErrors)) {
                try {
                    $db->beginTransaction();
                    
                    
                    $stmt = $db->prepare("
                        INSERT INTO users (username, email, password, role, status) 
                        VALUES (?, ?, ?, 'Customer', 'Active')
                    ");
                    
                    $stmt->execute([$email, $email, hashPassword($password)]);
                    $userId = $db->lastInsertId();
                    
                    
                    $stmt = $db->prepare("
                        INSERT INTO customers (user_id, full_name, contact_no, address) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$userId, $fullName, $contactNo, $address]);
                    
                    $db->commit();
                    $registerSuccess = true;
                    
                    
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $email;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'Customer';
                    $_SESSION['full_name'] = $fullName;
                    
                    redirect(SITE_URL . '/customer/dashboard.php');
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $registerErrors[] = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

if (isset($_GET['login'])) {
    $openModal = true;
}

if (isset($_GET['register'])) {
    $openRegisterModal = true;
}

$db = getDB();
$totalRepairs = $db->query("SELECT COUNT(*) FROM repairs WHERE repair_status = 'Completed'")->fetchColumn() ?: 0;
$totalCustomers = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn() ?: 0;
$totalTechnicians = $db->query("SELECT COUNT(*) FROM technicians")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Professional Appliance Repair Services - Fast, Reliable & Affordable. Track your repairs in real-time.">
    <title><?= getSetting('site_name', SITE_NAME) ?> - Professional Appliance Repair Services</title>
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fonts/fonts.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/landing.css">
    <style>
        
        .register-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(39, 35, 67, 0.55);
            backdrop-filter: blur(6px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }
        .register-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .register-modal {
            background: #fff;
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 530px;
            padding: 1.5rem;
            box-shadow: var(--shadow-xl);
            position: relative;
            transform: translateY(20px) scale(0.96);
            transition: var(--transition);
            max-height: 95vh;
            overflow-y: auto;
        }
        .register-modal-overlay.active .register-modal {
            transform: translateY(0) scale(1);
        }
        
        .register-modal .login-modal-header {
            margin-bottom: 0.85rem;
        }
        .register-modal .login-modal-logo {
            width: 44px;
            height: 44px;
            font-size: 1.25rem;
            margin-bottom: 0.4rem;
        }
        .register-modal .login-modal-header h2 {
            font-size: 1.3rem;
        }
        .register-modal .login-modal-header p {
            font-size: 0.85rem;
        }
        
        
        .register-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        
        .register-name-row {
            display: grid;
            grid-template-columns: 1.15fr 1fr 1.15fr;
            gap: 0.65rem;
            margin-bottom: 0.75rem;
            align-items: flex-end;
        }

        .register-name-row .register-form-group {
            margin-bottom: 0;
        }

        .register-name-row .register-form-group label {
            font-size: 0.8rem;
            line-height: 1.25;
            min-height: 2.15rem;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            margin-bottom: 0.25rem;
        }

        .register-name-row .register-form-group label .optional {
            font-size: 0.72rem;
            font-weight: 400;
            color: var(--text-muted);
        }

        .register-input-wrapper.no-icon input {
            padding: 0.6rem 0.65rem;
            text-align: left;
        }
        
        .register-form-group {
            margin-bottom: 0.75rem;
        }
        
        
        .register-form-group.full-width {
            grid-column: span 2;
        }
        
        .register-form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .register-form-group label .optional {
            font-weight: 400;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        
        .register-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .register-input-wrapper > i:first-child {
            position: absolute;
            left: 0.85rem;
            color: var(--text-muted);
            font-size: 0.85rem;
            pointer-events: none;
            z-index: 1;
        }
        
        .register-input-wrapper input {
            width: 100%;
            padding: 0.6rem 0.75rem 0.6rem 2.35rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--text-primary);
            background: var(--background-alt);
            transition: var(--transition);
            outline: none;
        }
        
        .register-input-wrapper input:focus {
            border-color: var(--info);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .register-input-wrapper input::placeholder {
            color: var(--text-muted);
            font-size: 0.82rem;
        }
        
        .register-pw-toggle {
            position: absolute;
            right: 0.6rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.4rem;
            font-size: 0.85rem;
            transition: var(--transition);
        }
        
        .register-pw-toggle:hover {
            color: var(--primary);
        }
        
        .register-submit-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
            margin-top: 0.25rem;
        }
        
        .register-submit-btn:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(39, 35, 67, 0.25);
        }
        
        @media (max-width: 768px) {
            .register-form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .register-name-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .register-name-row .register-form-group {
                margin-bottom: 0.75rem;
            }
            .register-name-row .register-form-group label {
                min-height: auto;
                flex-direction: row;
                gap: 0.35rem;
                align-items: baseline;
            }
            .register-form-group.full-width {
                grid-column: span 1;
            }
            .register-modal {
                margin: 1rem;
                padding: 1.25rem;
            }
        }
    </style>
</head>
<body>

    
    <nav class="landing-nav" id="landingNav">
        <div class="nav-container">
            <a href="#" class="nav-brand">
                <div class="nav-brand-icon"><i class="fas fa-tools"></i></div>
                <span><?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></span>
            </a>
            <ul class="nav-links" id="navLinks">
                <li><a href="#home">Home</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#how-it-works">How It Works</a></li>
                <li><a href="#why-us">Why Us</a></li>
                <li><a href="#testimonials">Testimonials</a></li>
            </ul>
            <div class="nav-actions">
                <button class="btn-nav-login" id="openLoginBtn" onclick="openLoginModal()">
                    <i class="fas fa-sign-in-alt"></i> Log In
                </button>
                <a href="#" class="btn-nav-signup" onclick="openRegisterModal(); return false;">
                    <i class="fas fa-user-plus"></i> Sign Up
                </a>
                <button class="nav-mobile-toggle" id="mobileNavToggle" aria-label="Toggle menu">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
    </nav>

    
    <section class="hero-section" id="home">
        <div class="hero-bg-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>
        <div class="hero-container">
            <div class="hero-content">
                <div class="hero-badge"><i class="fas fa-shield-alt"></i> Trusted by <?= number_format($totalCustomers) ?>+ Customers</div>
                <h1>Expert Appliance <br><span class="hero-highlight">Repair Services</span></h1>
                <p class="hero-description">
                    From diagnosis to delivery â€” we handle your appliance repairs with care and precision. 
                    Track every step online with real-time updates.
                </p>
                <div class="hero-buttons">
                    <a href="#" class="btn-hero-primary" onclick="openRegisterModal(); return false;">
                        <i class="fas fa-rocket"></i> Get Started Free
                    </a>
                    <a href="#how-it-works" class="btn-hero-secondary">
                        <i class="fas fa-play-circle"></i> How It Works
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <strong><?= number_format($totalRepairs) ?>+</strong>
                        <span>Repairs Done</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <strong><?= number_format($totalTechnicians) ?>+</strong>
                        <span>Expert Technicians</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <strong>98%</strong>
                        <span>Satisfaction Rate</span>
                    </div>
                </div>
            </div>
            <div class="hero-visual">
                <div class="hero-card-stack">
                    <div class="hero-float-card card-repair">
                        <div class="float-card-icon"><i class="fas fa-wrench"></i></div>
                        <div class="float-card-info">
                            <strong>Repair In Progress</strong>
                            <span>Samsung Refrigerator</span>
                            <div class="float-card-progress"><div class="progress-fill" style="width:75%"></div></div>
                        </div>
                    </div>
                    <div class="hero-float-card card-status">
                        <div class="float-card-icon icon-success"><i class="fas fa-check-circle"></i></div>
                        <div class="float-card-info">
                            <strong>Repair Completed!</strong>
                            <span>Ready for pickup</span>
                        </div>
                    </div>
                    <div class="hero-float-card card-notification">
                        <div class="float-card-icon icon-notify"><i class="fas fa-bell"></i></div>
                        <div class="float-card-info">
                            <strong>Status Updated</strong>
                            <span>Your AC unit is now being repaired</span>
                        </div>
                    </div>
                    <div class="hero-illustration-circle">
                        <i class="fas fa-cogs"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    
    <section class="services-section" id="services">
        <div class="section-container">
            <div class="section-header">
                <span class="section-badge">Our Services</span>
                <h2>What We <span class="text-accent">Repair</span></h2>
                <p>We service a wide range of home and commercial appliances with expert care</p>
            </div>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-snowflake"></i></div>
                    <h3>Air Conditioners</h3>
                    <p>Cooling issues, compressor repair, gas refilling, thermostat fixes and complete maintenance.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-temperature-low"></i></div>
                    <h3>Refrigerators</h3>
                    <p>Temperature problems, compressor issues, ice maker repairs and door seal replacements.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-tshirt"></i></div>
                    <h3>Washing Machines</h3>
                    <p>Drum repair, motor replacement, drainage issues and electronic control board fixes.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-tv"></i></div>
                    <h3>Television & Displays</h3>
                    <p>Screen repairs, backlight issues, motherboard fixes and HDMI port replacements.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-fire"></i></div>
                    <h3>Microwave & Ovens</h3>
                    <p>Heating element repair, control panel fixes, turntable motor and magnetron replacements.</p>
                </div>
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-blender"></i></div>
                    <h3>Small Appliances</h3>
                    <p>Rice cookers, blenders, coffee makers, electric fans and more household appliances.</p>
                </div>
            </div>
        </div>
    </section>

    
    <section class="how-section" id="how-it-works">
        <div class="section-container">
            <div class="section-header">
                <span class="section-badge">Simple Process</span>
                <h2>How It <span class="text-accent">Works</span></h2>
                <p>Get your appliance repaired in just 4 simple steps</p>
            </div>
            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <div class="step-icon"><i class="fas fa-user-plus"></i></div>
                    <h3>Create Account</h3>
                    <p>Sign up for free in less than a minute. Just fill in your basic details.</p>
                </div>
                <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-icon"><i class="fas fa-clipboard-list"></i></div>
                    <h3>Submit Request</h3>
                    <p>Describe your appliance issue and upload a photo. We'll take it from there.</p>
                </div>
                <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-icon"><i class="fas fa-tools"></i></div>
                    <h3>Expert Repair</h3>
                    <p>A certified technician is assigned and begins diagnosing and fixing your appliance.</p>
                </div>
                <div class="step-connector"><i class="fas fa-chevron-right"></i></div>
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-icon"><i class="fas fa-hand-holding-heart"></i></div>
                    <h3>Pickup & Warranty</h3>
                    <p>Get notified when it's ready. Pick up your repaired appliance with warranty coverage.</p>
                </div>
            </div>
        </div>
    </section>

    
    <section class="why-section" id="why-us">
        <div class="section-container">
            <div class="why-grid">
                <div class="why-content">
                    <span class="section-badge">Why Choose Us</span>
                    <h2>We Make Appliance Repair <span class="text-accent">Easy & Transparent</span></h2>
                    <p>No more guesswork. Track your repair status, chat with support, and pay securely â€” all from your dashboard.</p>
                    <div class="why-features">
                        <div class="why-feature">
                            <div class="why-feature-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div>
                                <h4>Real-Time Tracking</h4>
                                <p>Follow every step of your repair from submission to completion with live status updates.</p>
                            </div>
                        </div>
                        <div class="why-feature">
                            <div class="why-feature-icon"><i class="fas fa-comments"></i></div>
                            <div>
                                <h4>Direct Chat Support</h4>
                                <p>Message our support team directly from your dashboard. Get instant help anytime.</p>
                            </div>
                        </div>
                        <div class="why-feature">
                            <div class="why-feature-icon"><i class="fas fa-shield-alt"></i></div>
                            <div>
                                <h4>Warranty Protection</h4>
                                <p>Every repair comes with a 30-day warranty plus a 30-day grace period for peace of mind.</p>
                            </div>
                        </div>
                        <div class="why-feature">
                            <div class="why-feature-icon"><i class="fas fa-credit-card"></i></div>
                            <div>
                                <h4>Flexible Payments</h4>
                                <p>Pay with Cash, GCash, Bank Transfer, Credit Card, or other methods â€” your choice.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="why-visual">
                    <div class="why-stats-card">
                        <div class="why-stat-item">
                            <div class="why-stat-circle">
                                <svg viewBox="0 0 36 36">
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e8e8ed" stroke-width="3"/>
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#10b981" stroke-width="3" stroke-dasharray="98, 100"/>
                                </svg>
                                <span>98%</span>
                            </div>
                            <p>Customer Satisfaction</p>
                        </div>
                        <div class="why-stat-item">
                            <div class="why-stat-circle">
                                <svg viewBox="0 0 36 36">
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e8e8ed" stroke-width="3"/>
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#667eea" stroke-width="3" stroke-dasharray="95, 100"/>
                                </svg>
                                <span>95%</span>
                            </div>
                            <p>On-Time Completion</p>
                        </div>
                        <div class="why-stat-item">
                            <div class="why-stat-circle">
                                <svg viewBox="0 0 36 36">
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e8e8ed" stroke-width="3"/>
                                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#f59e0b" stroke-width="3" stroke-dasharray="100, 100"/>
                                </svg>
                                <span>100%</span>
                            </div>
                            <p>Warranty Coverage</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    
    <section class="testimonials-section" id="testimonials">
        <div class="section-container">
            <div class="section-header">
                <span class="section-badge">Testimonials</span>
                <h2>What Our Customers <span class="text-accent">Say</span></h2>
                <p>Real feedback from real customers who trust our services</p>
            </div>
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="testimonial-stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p>"The real-time tracking is amazing! I could see exactly when my refrigerator was being repaired. Super convenient and professional service."</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">MR</div>
                        <div>
                            <strong>Maria Reyes</strong>
                            <span>Loyal Customer</span>
                        </div>
                    </div>
                </div>
                <div class="testimonial-card featured">
                    <div class="testimonial-stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p>"Best repair shop I've used! The online system made everything so easy. I submitted my request, got updates, and picked it up â€” all hassle-free."</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">JC</div>
                        <div>
                            <strong>Juan Cruz</strong>
                            <span>Repeat Customer</span>
                        </div>
                    </div>
                </div>
                <div class="testimonial-card">
                    <div class="testimonial-stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i>
                    </div>
                    <p>"The warranty and support chat features are really helpful. My washing machine got fixed quickly and they even followed up after!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">AS</div>
                        <div>
                            <strong>Ana Santos</strong>
                            <span>Verified Customer</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    
    <section class="cta-section">
        <div class="section-container">
            <div class="cta-content">
                <h2>Ready to Get Your Appliance Fixed?</h2>
                <p>Join hundreds of satisfied customers. Create your free account today and submit your first repair request in minutes.</p>
                <div class="cta-buttons">
                    <a href="#" class="btn-cta-primary" onclick="openRegisterModal(); return false;">
                        <i class="fas fa-user-plus"></i> Create Free Account
                    </a>
                    <button class="btn-cta-secondary" onclick="openLoginModal()">
                        <i class="fas fa-sign-in-alt"></i> I Already Have an Account
                    </button>
                </div>
            </div>
        </div>
    </section>

    
    <footer class="landing-footer">
        <div class="section-container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <div class="footer-logo">
                        <div class="nav-brand-icon"><i class="fas fa-tools"></i></div>
                        <span><?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></span>
                    </div>
                    <p>Professional appliance repair services with real-time tracking, warranty coverage, and expert technicians you can trust.</p>
                    <div class="footer-social">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="footer-links-group">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="#services">Services</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                        <li><a href="#why-us">Why Choose Us</a></li>
                    </ul>
                </div>
                <div class="footer-links-group">
                    <h4>Services</h4>
                    <ul>
                        <li><a href="#services">Air Conditioner Repair</a></li>
                        <li><a href="#services">Refrigerator Repair</a></li>
                        <li><a href="#services">Washing Machine Repair</a></li>
                        <li><a href="#services">TV & Display Repair</a></li>
                    </ul>
                </div>
                <div class="footer-links-group">
                    <h4>Account</h4>
                    <ul>
                        <li><a href="#" onclick="openLoginModal(); return false;">Customer Login</a></li>
                        <li><a href="#" onclick="openRegisterModal(); return false;">Create Account</a></li>
                        <li><a href="admin/login.php">Staff Portal</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> <?= getSetting('site_name', SITE_NAME) ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    
    <div class="login-modal-overlay" id="loginModal">
        <div class="login-modal">
            <button class="login-modal-close" onclick="closeLoginModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
            <div class="login-modal-header">
                <div class="login-modal-logo">
                    <i class="fas fa-tools"></i>
                </div>
                <h2>Welcome Back</h2>
                <p>Sign in to your customer account</p>
            </div>
            
            <?php if ($error): ?>
            <div class="login-alert login-alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= sanitize($error) ?></span>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="login-modal-form" id="loginForm">
                <input type="hidden" name="customer_login" value="1">
                <div class="login-form-group">
                    <label for="email">Email Address</label>
                    <div class="login-input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="email" id="email" name="email"
                               placeholder="Enter your email address"
                               value="<?= sanitize($_POST['email'] ?? '') ?>" autocomplete="email" required>
                    </div>
                </div>
                <div class="login-form-group">
                    <label for="password">Password</label>
                    <div class="login-input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Enter your password" required>
                        <button type="button" class="login-pw-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="pwToggleIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="login-submit-btn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
            <div class="login-modal-footer">
                <p>Don't have an account? <a href="#" onclick="closeLoginModal(); openRegisterModal(); return false;">Sign up for free</a></p>
            </div>
        </div>
    </div>

    
    <div class="register-modal-overlay" id="registerModal">
        <div class="register-modal">
            <button class="login-modal-close" onclick="closeRegisterModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
            <div class="login-modal-header">
                <div class="login-modal-logo">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h2>Create Account</h2>
                <p>Register as a new customer</p>
            </div>
            
            <?php if (!empty($registerErrors)): ?>
            <div class="login-alert login-alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following:</strong>
                    <ul style="margin: 0.25rem 0 0 1rem; padding: 0;">
                        <?php foreach ($registerErrors as $regErr): ?>
                            <li><?= sanitize($regErr) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="login-modal-form" id="registerForm">
                <input type="hidden" name="customer_register" value="1">
                <?= csrfField() ?>
                
                <div class="register-form-group full-width">
                        <label for="reg_email">Email Address</label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="reg_email" name="email" 
                                   placeholder="your@email.com"
                                   value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                        </div>
                </div>
                
                <div class="register-name-row">
                    <div class="register-form-group">
                        <label for="reg_first_name">
                            <span>First Name</span>
                        </label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="reg_first_name" name="first_name" 
                                   placeholder="First name"
                                   value="<?= sanitize($_POST['first_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="register-form-group mi-group">
                        <label for="reg_middle_initial">
                            <span>Middle Initial</span>
                            <span class="optional">(Optional)</span>
                        </label>
                        <div class="register-input-wrapper no-icon">
                            <input type="text" id="reg_middle_initial" name="middle_initial" 
                                   placeholder="e.g. M." maxlength="5"
                                   value="<?= sanitize($_POST['middle_initial'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="register-form-group">
                        <label for="reg_last_name">
                            <span>Last Name</span>
                        </label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="reg_last_name" name="last_name" 
                                   placeholder="Last name"
                                   value="<?= sanitize($_POST['last_name'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="register-form-row">
                    <div class="register-form-group">
                        <label for="reg_contact_no">Contact Number</label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="tel" id="reg_contact_no" name="contact_no" 
                                   placeholder="Phone number"
                                   value="<?= sanitize($_POST['contact_no'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="register-form-group">
                        <label for="reg_address">Address <span class="optional">(Optional)</span></label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-map-marker-alt"></i>
                            <input type="text" id="reg_address" name="address" 
                                   placeholder="Your address"
                                   value="<?= sanitize($_POST['address'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="register-form-row">
                    <div class="register-form-group">
                        <label for="reg_password">Password</label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="reg_password" name="password" 
                                   placeholder="Min. 6 characters" required>
                            <button type="button" class="register-pw-toggle" onclick="toggleRegisterPassword('reg_password')">
                                <i class="fas fa-eye" id="regPwToggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="register-form-group">
                        <label for="reg_confirm_password">Confirm Password</label>
                        <div class="register-input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="reg_confirm_password" name="confirm_password" 
                                   placeholder="Repeat password" required>
                            <button type="button" class="register-pw-toggle" onclick="toggleRegisterPassword('reg_confirm_password')">
                                <i class="fas fa-eye" id="regConfirmPwToggleIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="register-submit-btn">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>
            <div class="login-modal-footer">
                <p>Already have an account? <a href="#" onclick="closeRegisterModal(); openLoginModal(); return false;">Sign in</a></p>
            </div>
        </div>
    </div>

    
    <script>
    (function() {
        
        const nav = document.getElementById('landingNav');
        window.addEventListener('scroll', function() {
            nav.classList.toggle('scrolled', window.scrollY > 50);
        });

        
        const mobileToggle = document.getElementById('mobileNavToggle');
        const navLinks = document.getElementById('navLinks');
        mobileToggle.addEventListener('click', function() {
            navLinks.classList.toggle('open');
            mobileToggle.classList.toggle('active');
        });
        
        navLinks.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                navLinks.classList.remove('open');
                mobileToggle.classList.remove('active');
            });
        });

        
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    var offset = 80;
                    var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                }
            });
        });

        
        var observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        document.querySelectorAll('.service-card, .step-card, .testimonial-card, .why-feature, .hero-float-card').forEach(function(el) {
            observer.observe(el);
        });

        
        <?php if ($openModal): ?>
        document.addEventListener('DOMContentLoaded', function() { openLoginModal(); });
        <?php endif; ?>

        
        <?php if ($openRegisterModal): ?>
        document.addEventListener('DOMContentLoaded', function() { openRegisterModal(); });
        <?php endif; ?>
    })();

    
    function openLoginModal() {
        document.getElementById('loginModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var emailField = document.getElementById('email');
            if (emailField) emailField.focus();
        }, 300);
    }
    function closeLoginModal() {
        document.getElementById('loginModal').classList.remove('active');
        document.body.style.overflow = '';
    }
    
    document.getElementById('loginModal').addEventListener('click', function(e) {
        if (e.target === this) closeLoginModal();
    });

    
    function openRegisterModal() {
        document.getElementById('registerModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var emailField = document.getElementById('reg_email');
            if (emailField) emailField.focus();
        }, 300);
    }
    function closeRegisterModal() {
        document.getElementById('registerModal').classList.remove('active');
        document.body.style.overflow = '';
    }
    
    document.getElementById('registerModal').addEventListener('click', function(e) {
        if (e.target === this) closeRegisterModal();
    });

    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLoginModal();
            closeRegisterModal();
        }
    });

    
    function togglePassword() {
        var pw = document.getElementById('password');
        var icon = document.getElementById('pwToggleIcon');
        if (pw.type === 'password') {
            pw.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pw.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    
    function toggleRegisterPassword(id) {
        var pw = document.getElementById(id);
        var btn = pw.nextElementSibling;
        var icon = btn.querySelector('i');
        if (pw.type === 'password') {
            pw.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pw.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
    </script>
</body>
</html>
