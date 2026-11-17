<?php

require_once '../config/config.php';
require_once '../includes/functions.php';
requireRole('Admin');

$scheduleId = (int)($_GET['id'] ?? 0);

if (!$scheduleId) {
    redirect('schedules.php', 'Invalid schedule ID.', 'error');
}

$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, r.repair_id, r.appliance_type, c.full_name as customer_name
    FROM schedules s
    JOIN repairs r ON s.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE s.schedule_id = ?
");
$stmt->execute([$scheduleId]);
$schedule = $stmt->fetch();

if (!$schedule) {
    redirect('schedules.php', 'Schedule not found.', 'error');
}

redirect('schedules.php?date=' . $schedule['scheduled_date'] . '&edit_id=' . $scheduleId);
