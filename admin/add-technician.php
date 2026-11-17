<?php

require_once '../config/config.php';
requireRole('Admin');

redirect('technicians.php', 'Please use the Add Technician button.', 'info');
exit;
