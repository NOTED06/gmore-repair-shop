<?php

require_once '../config/config.php';
requireRole('Admin');

$technicianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($technicianId > 0) {
    redirect('technicians.php?edit_id=' . $technicianId, '', 'info');
} else {
    redirect('technicians.php', 'Invalid technician ID.', 'error');
}
exit;
