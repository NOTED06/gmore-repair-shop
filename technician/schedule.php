<?php

require_once '../config/config.php';
$pageTitle = 'My Schedule - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Technician');

$db = getDB();

define('BUSINESS_OPEN', 8);  
define('BUSINESS_CLOSE', 19); 

$stmt = $db->prepare("SELECT technician_id, full_name FROM technicians WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$technician = $stmt->fetch();
$techId = $technician['technician_id'];

$filterDate = $_GET['date'] ?? date('Y-m-d');
$viewMode = $_GET['view'] ?? 'day'; 

$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($filterDate)));
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($filterDate)));

if ($viewMode === 'week') {
    $stmt = $db->prepare("
        SELECT s.*, r.appliance_type, r.repair_status, r.problem_description, r.brand,
               c.full_name as customer_name, c.contact_no as customer_contact, c.address as customer_address
        FROM schedules s
        JOIN repairs r ON s.repair_id = r.repair_id
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE s.technician_id = ? AND s.scheduled_date BETWEEN ? AND ?
        ORDER BY s.scheduled_date ASC, s.scheduled_time ASC
    ");
    $stmt->execute([$techId, $weekStart, $weekEnd]);
} else {
    $stmt = $db->prepare("
        SELECT s.*, r.appliance_type, r.repair_status, r.problem_description, r.brand,
               c.full_name as customer_name, c.contact_no as customer_contact, c.address as customer_address
        FROM schedules s
        JOIN repairs r ON s.repair_id = r.repair_id
        JOIN customers c ON r.customer_id = c.customer_id
        WHERE s.technician_id = ? AND s.scheduled_date = ?
        ORDER BY s.scheduled_time ASC
    ");
    $stmt->execute([$techId, $filterDate]);
}
$schedules = $stmt->fetchAll();

$schedulesByDate = [];
foreach ($schedules as $schedule) {
    $date = $schedule['scheduled_date'];
    if (!isset($schedulesByDate[$date])) {
        $schedulesByDate[$date] = [];
    }
    $schedulesByDate[$date][] = $schedule;
}

$stmt = $db->prepare("
    SELECT s.scheduled_date, COUNT(*) as count
    FROM schedules s
    WHERE s.technician_id = ? AND s.scheduled_date >= CURDATE() AND s.schedule_status != 'Cancelled'
    GROUP BY s.scheduled_date
    ORDER BY s.scheduled_date ASC
    LIMIT 7
");
$stmt->execute([$techId]);
$upcomingDates = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

function getDayAvailability($techId, $date, $db) {
    $stmt = $db->prepare("
        SELECT scheduled_time, end_time 
        FROM schedules 
        WHERE technician_id = ? AND scheduled_date = ? AND schedule_status != 'Cancelled'
        ORDER BY scheduled_time ASC
    ");
    $stmt->execute([$techId, $date]);
    $busySlots = $stmt->fetchAll();
    
    $totalHours = BUSINESS_CLOSE - BUSINESS_OPEN;
    $busyHours = 0;
    
    foreach ($busySlots as $slot) {
        if ($slot['scheduled_time'] && $slot['end_time']) {
            $start = strtotime($slot['scheduled_time']);
            $end = strtotime($slot['end_time']);
            $busyHours += ($end - $start) / 3600;
        }
    }
    
    return [
        'total' => $totalHours,
        'busy' => $busyHours,
        'available' => $totalHours - $busyHours,
        'percentage' => round(($busyHours / $totalHours) * 100)
    ];
}

$dayStats = getDayAvailability($techId, $filterDate, $db);
?>

<style>

.tech-schedule-page {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
}

.tech-schedule-page .page-header { margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; }
.tech-schedule-page .page-header h1 { font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark); margin: 0; }
.tech-schedule-page .page-header h1 i { color: var(--accent-indigo); margin-right: 12px; }
.tech-btn-outline {
    background: #fff; color: var(--text-dark); border: 1px solid var(--border-color);
    padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none;
    transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: inline-flex; align-items: center; gap: 8px;
}
.tech-btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

.schedule-nav-card {
    background: #fff; border-radius: 20px; padding: 16px 24px;
    box-shadow: var(--card-shadow); border: 1px solid var(--border-color); margin-bottom: 32px;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
}
.view-toggles { display: flex; gap: 8px; background: #f1f5f9; padding: 4px; border-radius: 12px; }
.view-toggles a {
    padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 14px; color: var(--text-muted);
    text-decoration: none; transition: all 0.2s; display: flex; align-items: center; gap: 8px;
}
.view-toggles a.active { background: #fff; color: var(--accent-indigo); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }

.date-stepper { display: flex; align-items: center; gap: 16px; }
.date-stepper-btn {
    width: 36px; height: 36px; border-radius: 10px; background: #fff; border: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: center; color: var(--text-muted); text-decoration: none; transition: all 0.2s;
}
.date-stepper-btn:hover { background: #f8fafc; color: var(--accent-indigo); border-color: #cbd5e1; }
.current-date-display { font-weight: 700; color: var(--text-dark); font-size: 16px; min-width: 180px; text-align: center; }

.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; margin-bottom: 32px; }
.stat-mini-card {
    background: #fff; border-radius: 20px; padding: 20px; display: flex; align-items: center; gap: 16px;
    box-shadow: var(--card-shadow); border: 1px solid var(--border-color);
}
.stat-mini-icon {
    width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}
.stat-mini-card h4 { margin: 0 0 4px; font-size: 24px; font-weight: 800; color: var(--text-dark); font-family: 'Syne', sans-serif; }
.stat-mini-card p { margin: 0; font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }

.tech-section-card {
    background: #fff; border-radius: 24px; box-shadow: var(--card-shadow); border: 1px solid var(--border-color); margin-bottom: 32px; overflow: hidden;
}
.tech-section-header {
    padding: 24px 32px; border-bottom: 1px solid var(--border-color); background: #f8fafc;
    display: flex; justify-content: space-between; align-items: center;
}
.tech-section-header h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--text-dark); font-family: 'Syne', sans-serif; display: flex; align-items: center; gap: 12px; }
.tech-section-header h2 i { color: var(--accent-indigo); }
.header-badge { background: #fff; border: 1px solid var(--border-color); padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; color: var(--text-muted); }

.timeline-container { padding: 32px; }
.timeline-item { display: flex; gap: 24px; position: relative; margin-bottom: 32px; }
.timeline-item:last-child { margin-bottom: 0; }
.timeline-time { width: 100px; text-align: right; flex-shrink: 0; padding-top: 4px; }
.time-start { font-weight: 700; font-size: 15px; color: var(--text-dark); }
.time-end { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
.timeline-connector { position: relative; display: flex; flex-direction: column; align-items: center; }
.connector-dot { width: 16px; height: 16px; border-radius: 50%; background: var(--accent-indigo); border: 4px solid #fff; box-shadow: 0 0 0 1px var(--border-color); z-index: 2; margin-top: 4px; }
.connector-line { position: absolute; top: 20px; bottom: -32px; width: 2px; background: var(--border-color); z-index: 1; }
.timeline-item:last-child .connector-line { display: none; }

.timeline-content { flex: 1; background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
.timeline-item:hover .timeline-content { border-color: var(--accent-indigo); box-shadow: 0 8px 24px rgba(79, 70, 229, 0.08); }
.timeline-item.completed { opacity: 0.7; }
.timeline-item.completed .connector-dot { background: #10B981; }

.schedule-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.schedule-title h4 { margin: 0; font-size: 18px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }
.repair-id { color: var(--accent-indigo); font-weight: 800; }
.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

.schedule-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.detail-item { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; color: var(--text-muted); }
.detail-item i { color: #94a3b8; margin-top: 3px; }
.schedule-actions { display: flex; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 16px; }

.week-grid-container { padding: 24px; }
.week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 16px; }
.week-day-col { border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; min-height: 400px; background: #f8fafc; }
.week-day-col.is-today { border-color: var(--accent-indigo); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1); background: #fff; }
.day-header { padding: 16px; text-align: center; border-bottom: 1px solid var(--border-color); background: #fff; }
.week-day-col.is-today .day-header { background: var(--accent-indigo); color: #fff; border-bottom: none; }
.day-name { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 4px; }
.week-day-col.is-today .day-name { color: rgba(255,255,255,0.8); }
.day-date { font-size: 24px; font-weight: 800; font-family: 'Syne', sans-serif; }
.day-content { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.week-schedule-item { background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; text-decoration: none; transition: all 0.2s; border-left: 3px solid var(--accent-indigo); }
.week-schedule-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.time-mini { font-size: 12px; font-weight: 700; color: var(--accent-indigo); margin-bottom: 4px; }
.info-mini { font-size: 13px; color: var(--text-dark); font-weight: 600; line-height: 1.4; }

.upcoming-dates { display: flex; gap: 16px; padding: 24px 32px; overflow-x: auto; }
.upcoming-card {
    background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 16px;
    min-width: 100px; text-align: center; text-decoration: none; transition: all 0.2s; display: flex; flex-direction: column; align-items: center;
}
.upcoming-card:hover { border-color: var(--accent-indigo); box-shadow: 0 8px 24px rgba(79, 70, 229, 0.08); transform: translateY(-2px); }
.upcoming-card.active { background: #EEF2FF; border-color: var(--accent-indigo); }
.up-month { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
.up-date { font-size: 28px; font-weight: 800; color: var(--text-dark); font-family: 'Syne', sans-serif; line-height: 1.2; margin: 4px 0; }
.up-count { background: #F1F5F9; color: #475569; font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 12px; }
.upcoming-card.active .up-count { background: var(--accent-indigo); color: #fff; }

.tech-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px;
    font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.tech-btn-primary { background: var(--accent-indigo); color: #fff; }
.tech-btn-primary:hover { background: #4338CA; }
.tech-btn-light { background: #fff; color: var(--text-dark); border: 1px solid var(--border-color); }
.tech-btn-light:hover { background: #f8fafc; border-color: #cbd5e1; }

.empty-state-modern { text-align: center; padding: 64px 24px; }
.empty-icon { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; }
.empty-state-modern h3 { font-size: 20px; font-weight: 700; color: var(--text-dark); margin: 0 0 8px; }
.empty-state-modern p { color: var(--text-muted); margin: 0; }
</style>

<div class="tech-schedule-page">
    <div class="page-header">
        <h1><i class="fas fa-calendar-alt"></i> My Schedule</h1>
        <a href="dashboard.php" class="tech-btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    
    <div class="schedule-nav-card">
        <div class="view-toggles">
            <a href="?view=day&date=<?= $filterDate ?>" class="<?= $viewMode === 'day' ? 'active' : '' ?>"><i class="fas fa-calendar-day"></i> Day View</a>
            <a href="?view=week&date=<?= $filterDate ?>" class="<?= $viewMode === 'week' ? 'active' : '' ?>"><i class="fas fa-calendar-week"></i> Week View</a>
        </div>
        
        <div class="date-stepper">
            <?php if ($viewMode === 'week'): ?>
                <a href="?view=week&date=<?= date('Y-m-d', strtotime('-7 days', strtotime($filterDate))) ?>" class="date-stepper-btn"><i class="fas fa-chevron-left"></i></a>
                <div class="current-date-display"><?= date('M d', strtotime($weekStart)) ?> — <?= date('M d, Y', strtotime($weekEnd)) ?></div>
                <a href="?view=week&date=<?= date('Y-m-d', strtotime('+7 days', strtotime($filterDate))) ?>" class="date-stepper-btn"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <a href="?view=day&date=<?= date('Y-m-d', strtotime('-1 day', strtotime($filterDate))) ?>" class="date-stepper-btn"><i class="fas fa-chevron-left"></i></a>
                <div class="current-date-display"><?= date('l, F j, Y', strtotime($filterDate)) ?></div>
                <a href="?view=day&date=<?= date('Y-m-d', strtotime('+1 day', strtotime($filterDate))) ?>" class="date-stepper-btn"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
            <a href="?view=<?= $viewMode ?>&date=<?= date('Y-m-d') ?>" class="tech-btn tech-btn-outline" style="margin-left: 8px;"><i class="fas fa-crosshairs"></i> Today</a>
        </div>
    </div>

    <?php if ($viewMode === 'day'): ?>
    
    <div class="stats-row">
        <div class="stat-mini-card">
            <div class="stat-mini-icon" style="background: #EEF2FF; color: #4F46E5;"><i class="fas fa-tasks"></i></div>
            <div>
                <h4><?= count($schedules) ?></h4>
                <p>Jobs Today</p>
            </div>
        </div>
        <div class="stat-mini-card">
            <div class="stat-mini-icon" style="background: #FFFBEB; color: #D97706;"><i class="fas fa-clock"></i></div>
            <div>
                <h4><?= number_format($dayStats['busy'], 1) ?>h</h4>
                <p>Scheduled</p>
            </div>
        </div>
        <div class="stat-mini-card">
            <div class="stat-mini-icon" style="background: #ECFDF5; color: #059669;"><i class="fas fa-check-circle"></i></div>
            <div>
                <h4><?= number_format($dayStats['available'], 1) ?>h</h4>
                <p>Available</p>
            </div>
        </div>
        <div class="stat-mini-card">
            <div class="stat-mini-icon" style="background: #F0F9FF; color: #0284C7;"><i class="fas fa-percentage"></i></div>
            <div>
                <h4><?= $dayStats['percentage'] ?>%</h4>
                <p>Utilization</p>
            </div>
        </div>
    </div>

    
    <div class="tech-section-card">
        <div class="tech-section-header">
            <h2><i class="fas fa-stream"></i> Schedule Timeline</h2>
            <div class="header-badge"><?= date('g A', strtotime(BUSINESS_OPEN . ':00')) ?> — <?= date('g A', strtotime(BUSINESS_CLOSE . ':00')) ?></div>
        </div>
        <div class="timeline-container">
            <?php if (empty($schedules)): ?>
                <div class="empty-state-modern">
                    <i class="fas fa-calendar-check empty-icon"></i>
                    <h3>No Schedules</h3>
                    <p>You have no scheduled repairs for this day.</p>
                </div>
            <?php else: ?>
                <?php foreach ($schedules as $schedule): ?>
                    <div class="timeline-item <?= $schedule['schedule_status'] === 'Completed' ? 'completed' : '' ?>">
                        <div class="timeline-time">
                            <?php if ($schedule['scheduled_time']): ?>
                                <div class="time-start"><?= date('g:i A', strtotime($schedule['scheduled_time'])) ?></div>
                                <?php if ($schedule['end_time']): ?>
                                    <div class="time-end">to <?= date('g:i A', strtotime($schedule['end_time'])) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="time-start">TBD</div>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-connector">
                            <div class="connector-dot"></div>
                            <div class="connector-line"></div>
                        </div>
                        <div class="timeline-content">
                            <div class="schedule-title">
                                <h4><span class="repair-id">#<?= $schedule['repair_id'] ?></span> <?= sanitize($schedule['appliance_type']) ?></h4>
                                <span class="badge <?= getStatusBadgeClass($schedule['schedule_status']) ?>"><?= $schedule['schedule_status'] ?></span>
                            </div>
                            <div class="schedule-details">
                                <div class="detail-item"><i class="fas fa-user"></i> <span><?= sanitize($schedule['customer_name']) ?></span></div>
                                <div class="detail-item"><i class="fas fa-phone"></i> <span><?= sanitize($schedule['customer_contact']) ?></span></div>
                                <?php if ($schedule['customer_address']): ?>
                                    <div class="detail-item" style="grid-column: 1 / -1;"><i class="fas fa-map-marker-alt"></i> <span><?= sanitize($schedule['customer_address']) ?></span></div>
                                <?php endif; ?>
                                <div class="detail-item" style="grid-column: 1 / -1;"><i class="fas fa-exclamation-circle"></i> <span style="font-style: italic;"><?= sanitize(substr($schedule['problem_description'], 0, 100)) ?>...</span></div>
                            </div>
                            <div class="schedule-actions">
                                <a href="repair-detail.php?id=<?= $schedule['repair_id'] ?>" class="tech-btn tech-btn-light"><i class="fas fa-eye"></i> View Details</a>
                                <?php if (!in_array($schedule['schedule_status'], ['Completed', 'Cancelled'])): ?>
                                    <a href="repairs.php?id=<?= $schedule['repair_id'] ?>" class="tech-btn tech-btn-primary"><i class="fas fa-wrench"></i> Start Work</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    
    <div class="tech-section-card">
        <div class="tech-section-header">
            <h2><i class="fas fa-calendar-week"></i> Week Overview</h2>
        </div>
        <div class="week-grid-container">
            <div class="week-grid">
                <?php 
                $currentDate = strtotime($weekStart);
                for ($i = 0; $i < 7; $i++): 
                    $dayDate = date('Y-m-d', $currentDate);
                    $isToday = $dayDate === date('Y-m-d');
                    $daySchedules = $schedulesByDate[$dayDate] ?? [];
                ?>
                    <div class="week-day-col <?= $isToday ? 'is-today' : '' ?>">
                        <div class="day-header">
                            <div class="day-name"><?= date('D', $currentDate) ?></div>
                            <div class="day-date"><?= date('j', $currentDate) ?></div>
                        </div>
                        <div class="day-content">
                            <?php if (empty($daySchedules)): ?>
                                <div style="text-align: center; padding-top: 20px; color: #cbd5e1;"><i class="fas fa-coffee" style="font-size: 24px;"></i></div>
                            <?php else: ?>
                                <?php foreach ($daySchedules as $schedule): ?>
                                    <a href="repair-detail.php?id=<?= $schedule['repair_id'] ?>" class="week-schedule-item">
                                        <div class="time-mini"><?= $schedule['scheduled_time'] ? date('g:i A', strtotime($schedule['scheduled_time'])) : 'TBD' ?></div>
                                        <div class="info-mini">#<?= $schedule['repair_id'] ?> <?= sanitize($schedule['appliance_type']) ?></div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    $currentDate = strtotime('+1 day', $currentDate);
                endfor; 
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <?php if (!empty($upcomingDates)): ?>
    <div class="tech-section-card">
        <div class="tech-section-header">
            <h2><i class="fas fa-calendar-alt"></i> Upcoming Days with Schedules</h2>
        </div>
        <div class="upcoming-dates">
            <?php foreach ($upcomingDates as $date => $count): ?>
                <a href="?view=day&date=<?= $date ?>" class="upcoming-card <?= $date === $filterDate ? 'active' : '' ?>">
                    <span class="up-month"><?= date('M', strtotime($date)) ?></span>
                    <span class="up-date"><?= date('j', strtotime($date)) ?></span>
                    <span class="up-count"><?= $count ?> Job<?= $count > 1 ? 's' : '' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    
    <style>
    #techCalendar .fc { font-family: 'Inter', sans-serif; }
    #techCalendar .fc-toolbar-title { font-size: 1.25rem !important; font-weight: 800; font-family: 'Syne', sans-serif; color: var(--text-dark); }
    #techCalendar .fc-button { background: #fff !important; color: var(--text-dark) !important; border-color: var(--border-color) !important; font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 600; border-radius: 8px !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05); text-transform: capitalize; }
    #techCalendar .fc-button:hover { background: #f8fafc !important; border-color: #cbd5e1 !important; }
    #techCalendar .fc-button-active { background: var(--accent-indigo) !important; color: #fff !important; border-color: var(--accent-indigo) !important; }
    #techCalendar .fc-event { border-radius: 6px; padding: 4px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; }
    #techCalendar .fc-col-header-cell { background: #f8fafc; font-weight: 700; font-size: 13px; text-transform: uppercase; color: var(--text-muted); padding: 12px 0; border-bottom: 1px solid var(--border-color); }
    #techCalendar .fc-daygrid-day-number { font-weight: 600; color: var(--text-dark); }
    </style>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <h2><i class="fas fa-calendar"></i> Calendar View</h2>
    </div>
    <div class="card-body">
        <div id="techCalendar" style="min-height: 450px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('techCalendar');
    if (!calendarEl) return;

    var siteUrl = document.querySelector('meta[name="site-url"]').getAttribute('content');

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: '<?= $filterDate ?>',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
        },
        buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
        slotMinTime: '<?= sprintf('%02d:00:00', BUSINESS_OPEN) ?>',
        slotMaxTime: '<?= sprintf('%02d:00:00', BUSINESS_CLOSE) ?>',
        allDaySlot: false,
        nowIndicator: true,
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
            fetch(siteUrl + '/api/calendar-events.php?start=' + info.startStr.substring(0,10) + '&end=' + info.endStr.substring(0,10))
                .then(function(r) { return r.json(); })
                .then(successCallback)
                .catch(failureCallback);
        },
        eventClick: function(info) {
            var e = info.event.extendedProps;
            Swal.fire({
                title: '#' + e.repair_id + ' - ' + e.appliance_type,
                html: '<div style="text-align:left;font-size:0.9rem;">' +
                    '<p><strong><i class="fas fa-user"></i> Customer:</strong> ' + e.customer_name + '</p>' +
                    '<p><strong><i class="fas fa-phone"></i> Contact:</strong> ' + e.customer_contact + '</p>' +

                    '<p><strong><i class="fas fa-info-circle"></i> Status:</strong> ' + e.status + '</p>' +
                    (e.problem ? '<p><strong><i class="fas fa-exclamation-circle"></i> Problem:</strong> ' + e.problem + '</p>' : '') +
                    '</div>',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-eye"></i> View Repair',
                cancelButtonText: 'Close',
                confirmButtonColor: '#272343'
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = 'repair-detail.php?id=' + e.repair_id;
                }
            });
        },
        dateClick: function(info) {
            window.location.href = '?view=day&date=' + info.dateStr;
        }
    });
    calendar.render();
});
</script>

<?php require_once '../includes/footer.php'; ?>
