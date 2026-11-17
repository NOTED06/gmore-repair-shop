<?php

require_once 'config/config.php';

$role = $_SESSION['role'] ?? '';

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

if ($role === 'Admin' || $role === 'Technician') {
    header('Location: admin/login.php');
} else {
    header('Location: login.php');
}
exit;

