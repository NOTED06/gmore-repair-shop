<?php

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();

$month = $_GET['month'] ?? date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$techStmt = $db->query("
    SELECT t.technician_id, t.full_name, t.specialization, t.availability,
           t.experience_years,
           (SELECT COUNT(*) FROM repairs r WHERE r.technician_id = t.technician_id
            AND r.repair_status IN ('Assigned','In Progress')) AS active_repairs
    FROM technicians t
    JOIN users u ON t.user_id = u.user_id
    WHERE u.status = 'Active'
    ORDER BY t.full_name
");
$technicians = $techStmt->fetchAll(PDO::FETCH_ASSOC);

$schedStmt = $db->prepare("
    SELECT s.technician_id, s.scheduled_date, s.scheduled_time, s.end_time,
           s.schedule_status, r.appliance_type
    FROM schedules s
    JOIN repairs r ON s.repair_id = r.repair_id
    WHERE s.scheduled_date BETWEEN ? AND ?
      AND s.schedule_status NOT IN ('Cancelled')
    ORDER BY s.scheduled_date, s.scheduled_time
");
$schedStmt->execute([$monthStart, $monthEnd]);
$schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

$schedByTech = [];
foreach ($schedules as $s) {
    $tid  = $s['technician_id'];
    $date = $s['scheduled_date'];
    $schedByTech[$tid][$date][] = [
        'time'   => $s['scheduled_time'],
        'end'    => $s['end_time'],
        'status' => $s['schedule_status'],
        'type'   => $s['appliance_type'],
    ];
}

$result = [];
foreach ($technicians as $t) {
    $tid = $t['technician_id'];
    $result[] = [
        'id'             => $tid,
        'name'           => $t['full_name'],
        'specialization' => $t['specialization'] ?: 'General',
        'availability'   => $t['availability'],
        'experience'     => (int)$t['experience_years'],
        'active_repairs' => (int)$t['active_repairs'],
        'schedules'      => $schedByTech[$tid] ?? [],
    ];
}

echo json_encode(['success' => true, 'technicians' => $result, 'month' => $month]);
