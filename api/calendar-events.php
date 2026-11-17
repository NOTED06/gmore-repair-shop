<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$role = $_SESSION['role'] ?? '';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start)) $start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $end)) $end = date('Y-m-t');

$params = [$start, $end];
$techFilter = '';

if ($role === 'Technician') {
    $stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $techId = $stmt->fetchColumn();
    $techFilter = 'AND s.technician_id = ?';
    $params[] = $techId;
} elseif ($role === 'Admin' && !empty($_GET['technician_id'])) {
    $techFilter = 'AND s.technician_id = ?';
    $params[] = (int)$_GET['technician_id'];
}

$stmt = $db->prepare("
    SELECT s.schedule_id, s.repair_id, s.scheduled_date, s.scheduled_time, s.end_time,
           s.schedule_status, s.notes, s.estimated_completion,
           r.appliance_type, r.repair_status, r.problem_description,
           c.full_name as customer_name, c.contact_no as customer_contact,
           t.full_name as technician_name
    FROM schedules s
    JOIN repairs r ON s.repair_id = r.repair_id
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN technicians t ON s.technician_id = t.technician_id
    WHERE s.scheduled_date BETWEEN ? AND ? $techFilter
    ORDER BY s.scheduled_date, s.scheduled_time
");
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$events = [];
$statusColors = [
    'Scheduled'   => ['#3b82f6', '#1e40af'],
    'Confirmed'   => ['#10b981', '#047857'],
    'In Progress' => ['#f59e0b', '#b45309'],
    'Completed'   => ['#6b7280', '#374151'],
    'Rescheduled' => ['#8b5cf6', '#5b21b6'],
    'Cancelled'   => ['#ef4444', '#991b1b'],
    'Pending'     => ['#f97316', '#c2410c'],
];

foreach ($schedules as $s) {
    $colors = $statusColors[$s['schedule_status']] ?? ['#3b82f6', '#1e40af'];

    $startDT = $s['scheduled_date'];
    $endDT = $s['scheduled_date'];
    if ($s['scheduled_time']) {
        $startDT .= 'T' . $s['scheduled_time'];
    }
    if ($s['end_time']) {
        $endDT .= 'T' . $s['end_time'];
    } elseif ($s['scheduled_time']) {
        $endDT .= 'T' . date('H:i:s', strtotime($s['scheduled_time'] . ' +1 hour'));
    }

    $events[] = [
        'id'              => $s['schedule_id'],
        'title'           => '#' . $s['repair_id'] . ' - ' . $s['appliance_type'],
        'start'           => $startDT,
        'end'             => $endDT,
        'backgroundColor' => $colors[0],
        'borderColor'     => $colors[1],
        'textColor'       => '#ffffff',
        'extendedProps'   => [
            'schedule_id'      => $s['schedule_id'],
            'repair_id'        => $s['repair_id'],
            'customer_name'    => $s['customer_name'],
            'customer_contact' => $s['customer_contact'],
            'technician_name'  => $s['technician_name'],
            'appliance_type'   => $s['appliance_type'],
            'problem'          => substr($s['problem_description'], 0, 150),
            'status'           => $s['schedule_status'],
            'repair_status'    => $s['repair_status'],

            'notes'            => $s['notes'],
        ],
    ];
}

echo json_encode($events);
