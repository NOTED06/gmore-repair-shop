<?php

require_once __DIR__ . '/config/config.php';

$userRole = getUserRole();

$loginUrl = ($userRole === 'Admin' || $userRole === 'Technician')
    ? SITE_URL . '/admin/login.php'
    : SITE_URL . '/login.php';

$backLabel = $userRole ? 'Go to My Dashboard' : 'Go to Login';
$backUrl   = match ($userRole) {
    'Admin'      => SITE_URL . '/admin/dashboard.php',
    'Technician' => SITE_URL . '/technician/dashboard.php',
    'Customer'   => SITE_URL . '/customer/dashboard.php',
    default      => $loginUrl,
};

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied — <?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></title>
    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fonts/fonts.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:   #4f46e5;
            --danger:    #dc2626;
            --bg:        #f1f5f9;
            --card:      #ffffff;
            --text:      #0f172a;
            --muted:     #64748b;
            --border:    #e2e8f0;
            --radius:    16px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 56px 48px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.08);
            animation: pop-in .45s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes pop-in {
            from { opacity: 0; transform: scale(.88) translateY(20px); }
            to   { opacity: 1; transform: scale(1)  translateY(0); }
        }

        .icon-wrap {
            width: 96px; height: 96px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 28px;
            animation: pulse-ring 2s ease-in-out infinite;
        }
        @keyframes pulse-ring {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,.25); }
            50%      { box-shadow: 0 0 0 16px rgba(220,38,38,0); }
        }
        .icon-wrap i { font-size: 2.75rem; color: var(--danger); }

        .code {
            font-size: 11px; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: var(--danger);
            background: #fee2e2; display: inline-block;
            padding: 4px 12px; border-radius: 20px; margin-bottom: 14px;
        }

        h1 {
            font-size: 1.85rem; font-weight: 800;
            color: var(--text); margin-bottom: 12px;
            line-height: 1.25;
        }
        p {
            color: var(--muted); font-size: .95rem; line-height: 1.65;
            margin-bottom: 36px;
        }

        .btn-group { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; border-radius: 10px;
            font-family: inherit; font-size: .9rem; font-weight: 600;
            cursor: pointer; border: none; text-decoration: none;
            transition: all .2s ease;
        }
        .btn-primary {
            background: var(--primary); color: #fff;
        }
        .btn-primary:hover { background: #4338ca; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,70,229,.3); }

        .btn-outline {
            background: transparent; color: var(--muted);
            border: 1.5px solid var(--border);
        }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-2px); }

        .divider { height: 1px; background: var(--border); margin: 32px 0; }

        .site-name {
            font-size: .8rem; color: var(--muted);
        }

        @media (max-width: 480px) {
            .card { padding: 40px 24px; }
            h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="icon-wrap">
        <i class="fas fa-shield-alt"></i>
    </div>

    <div class="code">403 — Access Denied</div>

    <h1>You don't have permission to view this page.</h1>
    <p>
        Your account does not have the required access level for this section.
        Please contact an administrator if you believe this is a mistake.
    </p>

    <div class="btn-group">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> <?= htmlspecialchars($backLabel) ?>
        </a>
        <a href="<?= htmlspecialchars(SITE_URL . '/logout.php') ?>" class="btn btn-outline">
            <i class="fas fa-sign-out-alt"></i> Log Out
        </a>
    </div>

    <div class="divider"></div>
    <div class="site-name"><?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></div>
</div>

</body>
</html>
