<?php

require_once '../config/config.php';
require_once '../includes/functions.php';
requireRole('Admin');

$repairId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($repairId > 0) {
    redirect('repairs.php?view_id=' . $repairId, '', '');
} else {
    redirect('repairs.php', 'Invalid repair ID.', 'error');
}
