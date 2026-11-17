<?php

require_once '../config/config.php';
requireRole('Admin');
$pageTitle = 'Admin Dashboard - ' . getSetting('site_name', SITE_NAME);
require_once '../includes/header.php';

$db = getDB();

$stats = [];

$stmt = $db->query("
    SELECT repair_status, COUNT(*) as count 
    FROM repairs 
    GROUP BY repair_status
");
$repairStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stats['pending'] = $repairStats['Pending'] ?? 0;
$stats['in_progress'] = ($repairStats['Assigned'] ?? 0) + ($repairStats['In Progress'] ?? 0);
$stats['completed'] = $repairStats['Completed'] ?? 0;
$stats['total_repairs'] = array_sum($repairStats);

$stmt = $db->query("SELECT COUNT(*) FROM customers");
$stats['total_customers'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM technicians");
$stats['total_technicians'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM technicians WHERE availability = 'Available'");
$stats['available_technicians'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM schedules WHERE scheduled_date = CURDATE()");
$stats['today_schedules'] = $stmt->fetchColumn();

$stmt = $db->query("
    SELECT r.*, c.full_name as customer_name, t.full_name as technician_name
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN technicians t ON r.technician_id = t.technician_id
    ORDER BY r.date_requested DESC
    LIMIT 5
");
$recentRepairs = $stmt->fetchAll();

$stmt = $db->query("
    SELECT COALESCE(SUM(total_cost), 0) as revenue 
    FROM repairs 
    WHERE repair_status = 'Completed' 
    AND MONTH(date_completed) = MONTH(CURDATE())
    AND YEAR(date_completed) = YEAR(CURDATE())
");
$stats['monthly_revenue'] = $stmt->fetchColumn();

$stats['total_products'] = $db->query("SELECT COUNT(*) FROM products")->fetchColumn() ?: 0;
$stats['sold_out_products'] = $db->query("SELECT COUNT(*) FROM products WHERE status = 'Sold Out'")->fetchColumn() ?: 0;

$stats['today_sales'] = $db->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE DATE(sale_date) = CURDATE()")->fetchColumn() ?: 0;
$stats['month_sales'] = $db->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())")->fetchColumn() ?: 0;

$stats['warranty_alerts'] = $db->query("SELECT COUNT(*) FROM warranty_tracking WHERE warranty_status IN ('Grace Period', 'Expired')")->fetchColumn() ?: 0;
?>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> <?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?></h1>
    <p>Admin Portal — Welcome back, <?= sanitize($_SESSION['username']) ?>!</p>
</div>

<div class="stats-grid">
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-wrench"></i></div>
        <div class="stat-content">
            <h3><?= $stats['total_repairs'] ?></h3>
            <p>Total Repairs</p>
        </div>
    </div>
    
    <div class="stat-card stat-warning">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-content">
            <h3><?= $stats['pending'] ?></h3>
            <p>Pending Requests</p>
        </div>
    </div>
    
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="fas fa-cog fa-spin"></i></div>
        <div class="stat-content">
            <h3><?= $stats['in_progress'] ?></h3>
            <p>In Progress</p>
        </div>
    </div>
    
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-content">
            <h3><?= $stats['completed'] ?></h3>
            <p>Completed</p>
        </div>
    </div>
    
    <div class="stat-card stat-secondary">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-content">
            <h3><?= $stats['total_customers'] ?></h3>
            <p>Customers</p>
        </div>
    </div>
    
    <div class="stat-card stat-dark">
        <div class="stat-icon"><i class="fas fa-user-cog"></i></div>
        <div class="stat-content">
            <h3><?= $stats['available_technicians'] ?>/<?= $stats['total_technicians'] ?></h3>
            <p>Available Technicians</p>
        </div>
    </div>
    
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-content">
            <h3><?= $stats['today_schedules'] ?></h3>
            <p>Today's Schedules</p>
        </div>
    </div>
    
    <div class="stat-card stat-revenue">
        <div class="revenue-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="revenue-content">
            <p class="revenue-label">Monthly Revenue</p>
            <h3 class="revenue-amount"><?= formatCurrency($stats['monthly_revenue']) ?></h3>
            <p class="revenue-period"><?= date('F Y') ?></p>
        </div>
    </div>
</div>

<div class="stats-grid" style="margin-top:0;">
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-cash-register"></i></div>
        <div class="stat-content">
            <h3><?= formatCurrency($stats['today_sales']) ?></h3>
            <p>Today's Sales</p>
        </div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
        <div class="stat-content">
            <h3><?= formatCurrency($stats['month_sales']) ?></h3>
            <p>Monthly Sales</p>
        </div>
    </div>
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-boxes"></i></div>
        <div class="stat-content">
            <h3><?= $stats['total_products'] ?></h3>
            <p>Products in Inventory</p>
        </div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
        <div class="stat-content">
            <h3><?= $stats['warranty_alerts'] ?></h3>
            <p>Warranty Alerts</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-history"></i> Recent Repair Requests</h2>
        <a href="repairs.php" class="btn btn-sm btn-primary">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentRepairs)): ?>
            <p class="text-muted">No repair requests yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Appliance</th>
                        <th>Status</th>
                        <th>Technician</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRepairs as $repair): ?>
                        <tr>
                            <td>#<?= $repair['repair_id'] ?></td>
                            <td><?= sanitize($repair['customer_name']) ?></td>
                            <td><?= sanitize($repair['appliance_type']) ?></td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($repair['repair_status']) ?>">
                                    <?= $repair['repair_status'] ?>
                                </span>
                            </td>
                            <td><?= $repair['technician_name'] ? sanitize($repair['technician_name']) : '<em>Unassigned</em>' ?></td>
                            <td><?= formatDateTime($repair['date_requested']) ?></td>
                            <td>
                                <a href="repair-view.php?id=<?= $repair['repair_id'] ?>" class="btn btn-sm btn-info" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($repair['repair_status'] === 'Pending'): ?>
                                    <a href="assign-technician.php?id=<?= $repair['repair_id'] ?>" class="btn btn-sm btn-primary" title="Assign">
                                        <i class="fas fa-user-plus"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
