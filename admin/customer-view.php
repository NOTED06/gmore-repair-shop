<?php

require_once '../config/config.php';
requireRole('Admin');

$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customerId > 0) {
    redirect('customers.php?view_id=' . $customerId, '', 'info');
} else {
    redirect('customers.php', 'Invalid customer ID.', 'error');
}
exit;
