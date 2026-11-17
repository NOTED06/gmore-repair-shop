<?php

require_once 'config/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    $role = getUserRole();
    switch ($role) {
        case 'Admin':
            header('Location: admin/dashboard.php');
            break;
        case 'Technician':
            header('Location: technician/dashboard.php');
            break;
        case 'Customer':
            header('Location: customer/dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
} else {
    header('Location: login.php');
}
exit;
