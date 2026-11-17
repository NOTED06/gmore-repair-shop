<?php

require_once '../config/config.php';
$pageTitle = 'Reports & Analytics - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');
$preset    = $_GET['preset']     ?? '';

if ($preset === 'today') {
    $startDate = $endDate = date('Y-m-d');
} elseif ($preset === '7days') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate   = date('Y-m-d');
} elseif ($preset === 'month') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-d');
} elseif ($preset === 'year') {
    $startDate = date('Y-01-01');
    $endDate   = date('Y-m-d');
}

$stmt = $db->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN repair_status = 'Completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN repair_status = 'Pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN repair_status IN ('Assigned', 'In Progress') THEN 1 ELSE 0 END) as in_progress,
           SUM(CASE WHEN repair_status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
           SUM(CASE WHEN repair_status = 'Returned' THEN 1 ELSE 0 END) as returned_count
    FROM repairs
    WHERE DATE(date_requested) BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$repairStats = $stmt->fetch() ?: [];
$repairStats = array_map(fn($v) => $v ?? 0, $repairStats);

$stmt = $db->prepare("
    SELECT COALESCE(SUM(total_cost), 0) as total_revenue,
           COUNT(*) as paid_repairs,
           COALESCE(AVG(total_cost), 0) as avg_cost
    FROM repairs
    WHERE repair_status IN ('Completed', 'Returned')
      AND DATE(COALESCE(date_completed, date_requested)) BETWEEN ? AND ?
      AND total_cost > 0
");
$stmt->execute([$startDate, $endDate]);
$revenueStats = $stmt->fetch() ?: [];

$stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN payment_status = 'Verified' THEN 1 END) as verified_payments,
        COUNT(CASE WHEN payment_status = 'Pending' THEN 1 END) as pending_payments,
        COUNT(CASE WHEN payment_status = 'Rejected' THEN 1 END) as rejected_payments,
        COALESCE(SUM(CASE WHEN payment_status = 'Verified' THEN total_cost ELSE 0 END), 0) as verified_amount
    FROM repairs
    WHERE payment_method IS NOT NULL
      AND DATE(date_requested) BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$paymentStats = $stmt->fetch() ?: [];

$stmt = $db->prepare("
    SELECT t.full_name, 
           COUNT(r.repair_id) as total_repairs,
           SUM(CASE WHEN r.repair_status = 'Completed' THEN 1 ELSE 0 END) as completed,
           COALESCE(SUM(r.total_cost), 0) as revenue
    FROM technicians t
    LEFT JOIN repairs r ON t.technician_id = r.technician_id 
        AND DATE(r.date_requested) BETWEEN ? AND ?
    GROUP BY t.technician_id, t.full_name
    ORDER BY completed DESC, total_repairs DESC
    LIMIT 5
");
$stmt->execute([$startDate, $endDate]);
$topTechnicians = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT appliance_type, COUNT(*) as count
    FROM repairs
    WHERE DATE(date_requested) BETWEEN ? AND ?
    GROUP BY appliance_type
    ORDER BY count DESC
    LIMIT 8
");
$stmt->execute([$startDate, $endDate]);
$popularAppliances = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT payment_method, 
           COUNT(*) as count,
           COALESCE(SUM(total_cost), 0) as total_amount
    FROM repairs
    WHERE payment_method IS NOT NULL
      AND DATE(date_requested) BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY count DESC
");
$stmt->execute([$startDate, $endDate]);
$paymentMethods = $stmt->fetchAll();

$stmt = $db->query("
    SELECT 
        DATE_FORMAT(date_requested, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN repair_status IN ('Completed', 'Returned') THEN 1 ELSE 0 END) as completed,
        COALESCE(SUM(total_cost), 0) as revenue
    FROM repairs
    WHERE date_requested >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(date_requested, '%Y-%m')
    ORDER BY month ASC
");
$monthlyTrend = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_sales,
        COALESCE(SUM(total_price), 0) as total_sales_revenue,
        COALESCE(SUM(quantity), 0) as total_units_sold
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$salesReport = $stmt->fetch() ?: [];

$stmt = $db->prepare("
    SELECT p.product_name, SUM(s.quantity) as units_sold, SUM(s.total_price) as revenue
    FROM sales s
    JOIN products p ON s.product_id = p.product_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.product_id, p.product_name
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$startDate, $endDate]);
$topSellingProducts = $stmt->fetchAll();

$inventoryStats = $db->query("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'Sold Out' THEN 1 ELSE 0 END) as sold_out,
        COALESCE(SUM(price * quantity), 0) as total_value
    FROM products
")->fetch() ?: [];

$completionRate = $repairStats['total'] > 0 
    ? round(($repairStats['completed'] / $repairStats['total']) * 100, 1) 
    : 0;
?>

<style>

.reports-container {
    padding: 0.5rem 0 2rem 0;
    color: #1e293b;
    font-family: inherit;
}

.rp-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.rp-header-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.rp-header-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: linear-gradient(135deg, #272343 0%, #1e1b4b 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    box-shadow: 0 4px 12px rgba(39, 35, 67, 0.2);
}
.rp-header h1 {
    font-size: 1.45rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}
.rp-header p {
    font-size: 0.85rem;
    color: #64748b;
    margin: 0;
}

.rp-filter-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    padding: 1.15rem 1.4rem;
    margin-bottom: 1.75rem;
}
.rp-filter-form {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}
.rp-filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.rp-filter-field label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
}
.rp-filter-field input[type="date"] {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.55rem 0.85rem;
    font-size: 0.875rem;
    color: #0f172a;
    font-weight: 500;
    outline: none;
    transition: all 0.2s ease;
    min-width: 160px;
}
.rp-filter-field input[type="date"]:focus {
    border-color: #272343;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(39, 35, 67, 0.12);
}
.rp-filter-btn {
    background: #272343;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 0.58rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(39, 35, 67, 0.2);
}
.rp-filter-btn:hover {
    background: #1e1b4b;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(39, 35, 67, 0.25);
}
.rp-filter-separator {
    width: 1px;
    height: 38px;
    background: #e2e8f0;
    margin: 0 0.25rem;
}
.rp-quick-filters {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-left: auto;
}
.rp-quick-btn {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 0.4rem 0.9rem;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
}
.rp-quick-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.rp-quick-btn.active {
    background: #272343;
    border-color: #272343;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(39, 35, 67, 0.2);
}

.rp-section-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 1.75rem 0 1rem 0;
}
.rp-section-header h2 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.rp-section-header h2 i {
    color: #4f46e5;
    font-size: 1rem;
}
.rp-section-line {
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

.rp-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 1200px) {
    .rp-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .rp-kpi-grid { grid-template-columns: 1fr; }
}

.rp-kpi-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 1.15rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
}
.rp-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    border-color: #cbd5e1;
}
.rp-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.rp-kpi-card.blue .rp-kpi-icon   { background: #eff6ff; color: #2563eb; }
.rp-kpi-card.green .rp-kpi-icon  { background: #ecfdf5; color: #059669; }
.rp-kpi-card.amber .rp-kpi-icon  { background: #fffbeb; color: #d97706; }
.rp-kpi-card.purple .rp-kpi-icon { background: #faf5ff; color: #7c3aed; }
.rp-kpi-card.teal .rp-kpi-icon   { background: #f0fdfa; color: #0d9488; }
.rp-kpi-card.rose .rp-kpi-icon   { background: #fff1f2; color: #e11d48; }
.rp-kpi-card.slate .rp-kpi-icon  { background: #f8fafc; color: #475569; }
.rp-kpi-card.indigo .rp-kpi-icon { background: #eef2ff; color: #4f46e5; }

.rp-kpi-info {
    flex: 1;
    min-width: 0;
}
.rp-kpi-value {
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
    margin-bottom: 0.2rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.rp-kpi-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.rp-kpi-sub {
    font-size: 0.75rem;
    font-weight: 500;
    color: #059669;
    margin-top: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.rp-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 992px) {
    .rp-grid-2 { grid-template-columns: 1fr; }
}

.rp-panel {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.rp-panel-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.rp-panel-header h3 {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.rp-panel-header h3 i {
    color: #4f46e5;
}
.rp-panel-badge {
    font-size: 0.725rem;
    font-weight: 700;
    padding: 0.25rem 0.6rem;
    border-radius: 12px;
    background: #e2e8f0;
    color: #334155;
}
.rp-panel-body {
    padding: 1.25rem;
    flex: 1;
}

.rp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.rp-table th {
    background: #f8fafc;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.725rem;
    letter-spacing: 0.04em;
    padding: 0.65rem 0.85rem;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}
.rp-table td {
    padding: 0.75rem 0.85rem;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}
.rp-table tbody tr:last-child td {
    border-bottom: none;
}
.rp-table tbody tr:hover td {
    background: #f8fafc;
}

.rp-rank {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.rp-rank.gold   { background: #fef3c7; color: #b45309; }
.rp-rank.silver { background: #f1f5f9; color: #475569; }
.rp-rank.bronze { background: #ffedd5; color: #c2410c; }

.rp-progress-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
}
.rp-progress-bar {
    flex: 1;
    height: 8px;
    background: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
}
.rp-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4f46e5 0%, #6366f1 100%);
    border-radius: 999px;
}
.rp-progress-fill.green {
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}

.rp-pm-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}
.rp-pm-item:last-child {
    border-bottom: none;
}
.rp-pm-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.rp-pm-icon {
    font-size: 1.25rem;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #f8fafc;
}
.rp-pm-title {
    font-weight: 600;
    font-size: 0.875rem;
    color: #0f172a;
}
.rp-pm-subtitle {
    font-size: 0.75rem;
    color: #64748b;
}
.rp-pm-amount {
    font-weight: 700;
    font-size: 0.9rem;
    color: #059669;
}

.rp-summary-list {
    display: flex;
    flex-direction: column;
}
.rp-summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.7rem 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}
.rp-summary-item:last-child {
    border-bottom: none;
}
.rp-summary-label {
    color: #64748b;
    font-weight: 500;
}
.rp-summary-value {
    font-weight: 700;
    color: #0f172a;
}
.rp-summary-item.highlight {
    background: #f0fdf4;
    padding: 0.75rem 0.85rem;
    border-radius: 8px;
    border-bottom: none;
    margin-top: 0.5rem;
}
.rp-summary-item.highlight .rp-summary-label {
    color: #166534;
    font-weight: 600;
}
.rp-summary-item.highlight .rp-summary-value {
    color: #15803d;
    font-size: 1rem;
}

.rp-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}
.rp-empty i {
    font-size: 2.25rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
    display: block;
}
.rp-empty p {
    font-size: 0.85rem;
    margin: 0;
}
</style>

<div class="reports-container">

    
    <div class="rp-header">
        <div class="rp-header-title">
            <div class="rp-header-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <h1>Reports & Analytics</h1>
                <p>Track business performance, repair throughput, and technician productivity</p>
            </div>
        </div>
        <div style="font-size: 0.825rem; color: #64748b; background: #fff; padding: 0.45rem 0.85rem; border-radius: 8px; border: 1px solid #e2e8f0; font-weight: 500;">
            <i class="fas fa-calendar-alt" style="margin-right: 0.35rem; color: #4f46e5;"></i>
            <?= date('M d, Y', strtotime($startDate)) ?> &mdash; <?= date('M d, Y', strtotime($endDate)) ?>
        </div>
    </div>

    
    <div class="rp-filter-card">
        <form method="GET" class="rp-filter-form">
            <div class="rp-filter-field">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="rp-filter-field">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <button type="submit" class="rp-filter-btn">
                <i class="fas fa-filter"></i> Apply Filter
            </button>

            <div class="rp-filter-separator"></div>

            <div class="rp-quick-filters">
                <a href="?preset=today" class="rp-quick-btn <?= $preset === 'today' ? 'active' : '' ?>">Today</a>
                <a href="?preset=7days" class="rp-quick-btn <?= $preset === '7days' ? 'active' : '' ?>">Last 7 Days</a>
                <a href="?preset=month" class="rp-quick-btn <?= $preset === 'month' ? 'active' : '' ?>">This Month</a>
                <a href="?preset=year"  class="rp-quick-btn <?= $preset === 'year' ? 'active' : '' ?>">This Year</a>
            </div>
        </form>
    </div>

    
    <div class="rp-section-header">
        <h2><i class="fas fa-wrench"></i> Repair & Revenue Overview</h2>
        <div class="rp-section-line"></div>
    </div>

    <div class="rp-kpi-grid">
        <div class="rp-kpi-card blue">
            <div class="rp-kpi-icon"><i class="fas fa-wrench"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($repairStats['total'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Total Repairs</div>
                <div class="rp-kpi-sub" style="color: #64748b;">Requested in period</div>
            </div>
        </div>

        <div class="rp-kpi-card green">
            <div class="rp-kpi-icon"><i class="fas fa-check-circle"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($repairStats['completed'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Completed</div>
                <div class="rp-kpi-sub"><i class="fas fa-bolt"></i> <?= $completionRate ?>% completion rate</div>
            </div>
        </div>

        <div class="rp-kpi-card amber">
            <div class="rp-kpi-icon"><i class="fas fa-clock"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format($repairStats['pending'] + $repairStats['in_progress']) ?></div>
                <div class="rp-kpi-label">In Progress / Pending</div>
                <div class="rp-kpi-sub" style="color: #d97706;"><?= $repairStats['pending'] ?> pending queue</div>
            </div>
        </div>

        <div class="rp-kpi-card teal">
            <div class="rp-kpi-icon"><i class="fas fa-peso-sign"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= formatCurrency($revenueStats['total_revenue']) ?></div>
                <div class="rp-kpi-label">Repair Revenue</div>
                <div class="rp-kpi-sub" style="color: #0d9488;">Avg <?= formatCurrency($revenueStats['avg_cost']) ?> / repair</div>
            </div>
        </div>
    </div>

    
    <div class="rp-kpi-grid">
        <div class="rp-kpi-card green">
            <div class="rp-kpi-icon"><i class="fas fa-check-double"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($paymentStats['verified_payments'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Verified Payments</div>
                <div class="rp-kpi-sub"><?= formatCurrency($paymentStats['verified_amount'] ?? 0) ?> total</div>
            </div>
        </div>

        <div class="rp-kpi-card amber">
            <div class="rp-kpi-icon"><i class="fas fa-hourglass-half"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($paymentStats['pending_payments'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Pending Verification</div>
                <div class="rp-kpi-sub" style="color: #d97706;">Awaiting approval</div>
            </div>
        </div>

        <div class="rp-kpi-card purple">
            <div class="rp-kpi-icon"><i class="fas fa-undo"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format($repairStats['returned_count'] ?? 0) ?></div>
                <div class="rp-kpi-label">Items Returned</div>
                <div class="rp-kpi-sub" style="color: #7c3aed;">Completed handoffs</div>
            </div>
        </div>

        <div class="rp-kpi-card rose">
            <div class="rp-kpi-icon"><i class="fas fa-times-circle"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format($repairStats['cancelled'] ?? 0) ?></div>
                <div class="rp-kpi-label">Cancelled</div>
                <div class="rp-kpi-sub" style="color: #e11d48;"><?= number_format((int)($paymentStats['rejected_payments'] ?? 0)) ?> rejected pay</div>
            </div>
        </div>
    </div>

    
    <div class="rp-grid-2">
        
        <div class="rp-panel">
            <div class="rp-panel-header">
                <h3><i class="fas fa-trophy"></i> Top Technicians</h3>
                <span class="rp-panel-badge">Period Performance</span>
            </div>
            <div class="rp-panel-body" style="padding: 0;">
                <?php if (empty($topTechnicians)): ?>
                    <div class="rp-empty">
                        <i class="fas fa-user-slash"></i>
                        <p>No technician activity recorded for this period.</p>
                    </div>
                <?php else: ?>
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th style="width: 45px; text-align: center;">#</th>
                                <th>Technician</th>
                                <th style="text-align: center;">Assigned</th>
                                <th style="text-align: center;">Done</th>
                                <th style="text-align: right;">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topTechnicians as $idx => $tech): 
                                $rankClass = $idx === 0 ? 'gold' : ($idx === 1 ? 'silver' : ($idx === 2 ? 'bronze' : ''));
                            ?>
                            <tr>
                                <td style="text-align: center;">
                                    <span class="rp-rank <?= $rankClass ?>"><?= $idx + 1 ?></span>
                                </td>
                                <td>
                                    <strong><?= sanitize($tech['full_name']) ?></strong>
                                </td>
                                <td style="text-align: center; color: #64748b; font-weight: 600;">
                                    <?= $tech['total_repairs'] ?>
                                </td>
                                <td style="text-align: center;">
                                    <span style="background: #ecfdf5; color: #059669; padding: 2px 8px; border-radius: 12px; font-weight: 700; font-size: 0.775rem;">
                                        <?= $tech['completed'] ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #0f172a;">
                                    <?= formatCurrency($tech['revenue']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="rp-panel">
            <div class="rp-panel-header">
                <h3><i class="fas fa-credit-card"></i> Payment Methods</h3>
                <span class="rp-panel-badge"><?= count($paymentMethods) ?> Channels</span>
            </div>
            <div class="rp-panel-body">
                <?php if (empty($paymentMethods)): ?>
                    <div class="rp-empty">
                        <i class="fas fa-wallet"></i>
                        <p>No payment records in selected period.</p>
                    </div>
                <?php else: 
                    $pmIcons = [
                        'Cash'          => '💵',
                        'GCash'         => '📱',
                        'Bank Transfer' => '🏦',
                        'Credit Card'   => '💳',
                        'Full Payment'  => '💰',
                        'Down Payment'  => '📋',
                    ];
                    foreach ($paymentMethods as $m): 
                ?>
                    <div class="rp-pm-item">
                        <div class="rp-pm-left">
                            <div class="rp-pm-icon"><?= $pmIcons[$m['payment_method']] ?? '💳' ?></div>
                            <div>
                                <div class="rp-pm-title"><?= sanitize($m['payment_method']) ?></div>
                                <div class="rp-pm-subtitle"><?= $m['count'] ?> transaction<?= $m['count'] != 1 ? 's' : '' ?></div>
                            </div>
                        </div>
                        <div class="rp-pm-amount"><?= formatCurrency($m['total_amount']) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    
    <div class="rp-grid-2">
        
        <div class="rp-panel">
            <div class="rp-panel-header">
                <h3><i class="fas fa-tv"></i> Top Appliance Categories</h3>
                <span class="rp-panel-badge">Volume</span>
            </div>
            <div class="rp-panel-body" style="padding: 0.5rem 1.25rem;">
                <?php if (empty($popularAppliances)): ?>
                    <div class="rp-empty">
                        <i class="fas fa-box-open"></i>
                        <p>No repair data available for appliances.</p>
                    </div>
                <?php else: 
                    $maxVolume = max(array_column($popularAppliances, 'count'));
                    foreach ($popularAppliances as $i => $app): 
                        $pct = $maxVolume > 0 ? round(($app['count'] / $maxVolume) * 100) : 0;
                ?>
                    <div style="padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <span style="font-weight: 600; font-size: 0.85rem; color: #1e293b;">
                                <?= sanitize($app['appliance_type']) ?>
                            </span>
                            <span style="font-weight: 700; font-size: 0.8rem; color: #4f46e5; background: #eef2ff; padding: 1px 7px; border-radius: 10px;">
                                <?= $app['count'] ?> repair<?= $app['count'] != 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <div class="rp-progress-wrapper">
                            <div class="rp-progress-bar">
                                <div class="rp-progress-fill" style="width: <?= $pct ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        
        <div class="rp-panel">
            <div class="rp-panel-header">
                <h3><i class="fas fa-clipboard-check"></i> Performance Overview</h3>
                <span class="rp-panel-badge">Key Metrics</span>
            </div>
            <div class="rp-panel-body">
                <div class="rp-summary-list">
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Total Repair Requests</span>
                        <span class="rp-summary-value"><?= number_format((int)($repairStats['total'] ?? 0)) ?></span>
                    </div>
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Repairs Completed</span>
                        <span class="rp-summary-value" style="color: #059669;"><?= number_format((int)($repairStats['completed'] ?? 0)) ?></span>
                    </div>
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Completion Efficiency</span>
                        <span class="rp-summary-value"><?= $completionRate ?>%</span>
                    </div>
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Average Ticket Cost</span>
                        <span class="rp-summary-value"><?= formatCurrency($revenueStats['avg_cost']) ?></span>
                    </div>
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Items Safely Returned</span>
                        <span class="rp-summary-value"><?= number_format($repairStats['returned_count'] ?? 0) ?></span>
                    </div>
                    <div class="rp-summary-item">
                        <span class="rp-summary-label">Verified Cash Collected</span>
                        <span class="rp-summary-value" style="color: #059669;"><?= formatCurrency($paymentStats['verified_amount'] ?? 0) ?></span>
                    </div>
                    <div class="rp-summary-item highlight">
                        <span class="rp-summary-label">Total Completed Revenue</span>
                        <span class="rp-summary-value"><?= formatCurrency($revenueStats['total_revenue']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="rp-section-header">
        <h2><i class="fas fa-chart-area"></i> Monthly Repair Trend (Last 6 Months)</h2>
        <div class="rp-section-line"></div>
    </div>

    <div class="rp-panel" style="margin-bottom: 1.5rem;">
        <div class="rp-panel-body" style="padding: 0; overflow-x: auto;">
            <?php if (empty($monthlyTrend)): ?>
                <div class="rp-empty">
                    <i class="fas fa-chart-line"></i>
                    <p>No historical repair data in the last 6 months.</p>
                </div>
            <?php else: ?>
                <table class="rp-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th style="text-align: center;">Total Requests</th>
                            <th style="text-align: center;">Completed</th>
                            <th style="text-align: right;">Total Revenue</th>
                            <th style="width: 200px;">Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrend as $row): 
                            $mRate = $row['total'] > 0 ? round(($row['completed'] / $row['total']) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?= date('F Y', strtotime($row['month'] . '-01')) ?></strong>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <?= number_format($row['total']) ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="background: #ecfdf5; color: #059669; padding: 2px 8px; border-radius: 12px; font-weight: 700; font-size: 0.775rem;">
                                    <?= number_format($row['completed']) ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #0f172a;">
                                <?= formatCurrency($row['revenue']) ?>
                            </td>
                            <td>
                                <div class="rp-progress-wrapper">
                                    <div class="rp-progress-bar">
                                        <div class="rp-progress-fill green" style="width: <?= $mRate ?>%;"></div>
                                    </div>
                                    <span style="font-size: 0.75rem; font-weight: 700; color: #475569; min-width: 35px; text-align: right;">
                                        <?= $mRate ?>%
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="rp-section-header">
        <h2><i class="fas fa-cash-register"></i> Sales & Inventory Report</h2>
        <div class="rp-section-line"></div>
    </div>

    <div class="rp-kpi-grid">
        <div class="rp-kpi-card green">
            <div class="rp-kpi-icon"><i class="fas fa-cash-register"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= formatCurrency($salesReport['total_sales_revenue']) ?></div>
                <div class="rp-kpi-label">Sales Revenue</div>
                <div class="rp-kpi-sub"><?= number_format((int)($salesReport['total_units_sold'] ?? 0)) ?> units sold</div>
            </div>
        </div>

        <div class="rp-kpi-card teal">
            <div class="rp-kpi-icon"><i class="fas fa-receipt"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($salesReport['total_sales'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Transactions</div>
                <div class="rp-kpi-sub" style="color: #0d9488;">Completed sales</div>
            </div>
        </div>

        <div class="rp-kpi-card blue">
            <div class="rp-kpi-icon"><i class="fas fa-boxes"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= number_format((int)($inventoryStats['total_products'] ?? 0)) ?></div>
                <div class="rp-kpi-label">Total Products</div>
                <div class="rp-kpi-sub" style="color: #2563eb;"><?= $inventoryStats['available'] ?> avail &bull; <?= $inventoryStats['sold_out'] ?> sold out</div>
            </div>
        </div>

        <div class="rp-kpi-card amber">
            <div class="rp-kpi-icon"><i class="fas fa-warehouse"></i></div>
            <div class="rp-kpi-info">
                <div class="rp-kpi-value"><?= formatCurrency($inventoryStats['total_value']) ?></div>
                <div class="rp-kpi-label">Inventory Value</div>
                <div class="rp-kpi-sub" style="color: #d97706;">Stock retail valuation</div>
            </div>
        </div>
    </div>

    
    <div class="rp-panel">
        <div class="rp-panel-header">
            <h3><i class="fas fa-trophy"></i> Top Selling Products (Period)</h3>
            <span class="rp-panel-badge">POS Sales</span>
        </div>
        <div class="rp-panel-body" style="padding: 0;">
            <?php if (empty($topSellingProducts)): ?>
                <div class="rp-empty">
                    <i class="fas fa-box-open"></i>
                    <p>No product sales recorded in this period.</p>
                </div>
            <?php else: ?>
                <table class="rp-table">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align: center;">#</th>
                            <th>Product Name</th>
                            <th style="text-align: center;">Units Sold</th>
                            <th style="text-align: right;">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topSellingProducts as $i => $sp): 
                            $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                        ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="rp-rank <?= $rankClass ?>"><?= $i + 1 ?></span>
                            </td>
                            <td>
                                <strong><?= sanitize($sp['product_name']) ?></strong>
                            </td>
                            <td style="text-align: center; font-weight: 600; color: #475569;">
                                <?= number_format($sp['units_sold']) ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #059669;">
                                <?= formatCurrency($sp['revenue']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once '../includes/footer.php'; ?>
