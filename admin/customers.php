<?php

require_once '../config/config.php';
$pageTitle = 'Manage Customers - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

$customerDetails = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $stmt = $db->prepare("
        SELECT c.*, u.username, u.email, u.status as user_status, u.created_at as registered_at, u.last_login,
               (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id) as total_repairs,
               (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id AND r.repair_status = 'Completed') as completed_repairs,
               (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id AND r.repair_status IN ('Pending', 'Assigned', 'In Progress')) as active_repairs,
               (SELECT SUM(total_cost) FROM repairs r WHERE r.customer_id = c.customer_id AND r.repair_status = 'Completed') as total_spent
        FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.customer_id = ?
    ");
    $stmt->execute([$viewId]);
    $customerDetails = $stmt->fetch();
}

$search = $_GET['search'] ?? '';

$where = '';
$params = [];
if ($search) {
    $where = "WHERE c.full_name LIKE ? OR c.contact_no LIKE ? OR u.email LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$stmt = $db->prepare("
    SELECT c.*, u.username, u.email, u.status as user_status, u.last_login,
           (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id) as total_repairs,
           (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id AND r.repair_status IN ('Pending', 'Assigned', 'In Progress')) as active_repairs
    FROM customers c
    JOIN users u ON c.user_id = u.user_id
    $where
    ORDER BY c.created_at DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<style>

.admin-page-wrapper {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
}

.page-header-modern {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 32px;
}
.page-header-modern h1 {
    margin: 0; font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);
    font-size: 28px; display: flex; align-items: center; gap: 12px;
}

.modern-search-card {
    background: #fff; border-radius: 16px; padding: 20px;
    box-shadow: var(--card-shadow); border: 1px solid var(--border-color);
    margin-bottom: 32px; display: flex; align-items: center; gap: 16px;
}
.modern-search-wrapper {
    flex: 1; position: relative; display: flex; align-items: center;
}
.modern-search-wrapper i.fa-search {
    position: absolute; left: 20px; color: #94a3b8; font-size: 16px;
}
.modern-search-input {
    width: 100%; padding: 14px 20px 14px 52px; border: 1px solid #cbd5e1;
    border-radius: 12px; font-size: 15px; transition: all 0.2s; background: #f8fafc;
}
.modern-search-input:focus {
    background: #fff; border-color: var(--accent-indigo); outline: none;
    box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
}
.modern-search-btn {
    padding: 14px 28px; background: var(--text-dark); color: #fff;
    border: none; border-radius: 12px; font-weight: 600; font-size: 15px;
    cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;
}

.modern-admin-card {
    background: #fff; border-radius: 20px; box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 32px;
}
.modern-table { width: 100%; border-collapse: collapse; }
.modern-table th {
    background: #f8fafc; text-align: left; padding: 16px 24px;
    font-size: 12px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
}
.modern-table td {
    padding: 20px 24px; border-bottom: 1px solid var(--border-color); vertical-align: middle;
}
.modern-table tr:hover td { background: #f8fafc; }

.table-id { font-weight: 700; color: var(--text-dark); font-size: 15px; }
.table-main-text { font-weight: 600; color: var(--text-dark); margin-bottom: 4px; display: block; }
.table-meta-text { font-size: 13px; color: var(--text-muted); }

.action-btn-group { display: flex; gap: 6px; flex-wrap: nowrap; justify-content: flex-end; }
.admin-action-btn {
    width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
    border: none; cursor: pointer; transition: all 0.2s; color: #fff; font-size: 14px;
}
.btn-view { background: #e0f2fe; color: #0284c7; }
.btn-view:hover { background: #bae6fd; }
.btn-repairs { background: #ede9fe; color: #6d28d9; }
.btn-repairs:hover { background: #ddd6fe; }

.admin-badge {
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block;
}
.bg-active { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
.bg-inactive { background: #F3F4F6; color: #4B5563; border: 1px solid #E5E7EB; }
</style>

<div class="admin-page-wrapper">
    <div class="page-header-modern">
        <h1><i class="fas fa-users" style="color: var(--accent-indigo);"></i> Manage Customers</h1>
    </div>

    
    <form method="GET" id="searchForm">
        <div class="modern-search-card">
            <div class="modern-search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="modern-search-input" 
                       placeholder="Search by customer name, phone number, or email..."
                       value="<?= sanitize($search) ?>" autocomplete="off">
                <?php if ($search): ?>
                    <button type="button" class="admin-action-btn" onclick="clearSearch()" style="background: #fee2e2; color: #dc2626; position: absolute; right: 12px; width: 28px; height: 28px;" title="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                <?php endif; ?>
            </div>
            <button type="submit" class="modern-search-btn">Search</button>
        </div>
    </form>

    <script>
    function clearSearch() {
        document.querySelector('input[name="search"]').value = '';
        document.getElementById('searchForm').submit();
    }
    </script>

    <div class="modern-admin-card">
        <?php if (empty($customers)): ?>
            <div style="text-align: center; padding: 64px 24px;">
                <div style="width: 80px; height: 80px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 32px; color: #94a3b8;">
                    <i class="fas fa-user-slash"></i>
                </div>
                <h3 style="margin: 0 0 8px; font-size: 20px; font-weight: 700; color: var(--text-dark);">No Customers Found</h3>
                <p style="margin: 0; color: var(--text-muted);">There are no customers matching your criteria.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th style="width: 250px;">Address</th>
                            <th>Status</th>
                            <th>Repairs</th>
                            <th>Last Login</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><span class="table-id">#<?= $customer['customer_id'] ?></span></td>
                                <td>
                                    <span class="table-main-text"><?= sanitize($customer['full_name']) ?></span>
                                    <span class="table-meta-text">@<?= sanitize($customer['username']) ?></span>
                                </td>
                                <td>
                                    <span class="table-main-text" style="font-weight: 500;"><i class="fas fa-phone" style="font-size: 11px; color: #94a3b8;"></i> <?= sanitize($customer['contact_no']) ?></span>
                                    <span class="table-meta-text"><i class="fas fa-envelope" style="font-size: 11px; color: #94a3b8;"></i> <?= sanitize($customer['email']) ?></span>
                                </td>
                                <td>
                                    <span class="table-meta-text truncate" style="display: block; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= sanitize($customer['address']) ?>">
                                        <?= sanitize($customer['address'] ?: 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="admin-badge <?= $customer['user_status'] === 'Active' ? 'bg-active' : 'bg-inactive' ?>">
                                        <?= $customer['user_status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="background: #EFF6FF; color: #1E3A8A; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-right: 4px;"><?= $customer['active_repairs'] ?> active</span>
                                    <span style="background: #F1F5F9; color: #475569; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"><?= $customer['total_repairs'] ?> total</span>
                                </td>
                                <td><span class="table-meta-text"><?= $customer['last_login'] ? formatDateTime($customer['last_login']) : 'Never' ?></span></td>
                                <td>
                                    <div class="action-btn-group">
                                        <button type="button" class="admin-action-btn btn-view" onclick="viewCustomer(<?= $customer['customer_id'] ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="repairs.php?customer=<?= $customer['customer_id'] ?>" class="admin-action-btn btn-repairs" title="View Repairs">
                                            <i class="fas fa-wrench"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div> 

<style>

.compact-premium-modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px);
    z-index: 1050; align-items: center; justify-content: center; padding: 1rem;
}
.compact-premium-modal.show { display: flex !important; }

.cpm-content {
    background: #fff; border-radius: 16px; width: 100%; max-width: 600px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden;
    animation: cpmSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes cpmSlideUp { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

.cpm-header {
    background: #4F46E5; color: white; padding: 20px 24px;
    display: flex; justify-content: space-between; align-items: center;
}
.cpm-header h2 { margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.cpm-close { background: none; border: none; color: rgba(255,255,255,0.7); font-size: 24px; cursor: pointer; padding: 0; line-height: 1; transition: color 0.2s; }
.cpm-close:hover { color: white; }

.cpm-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
.cpm-section { background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; padding: 16px; margin-bottom: 16px; }
.cpm-section h4 { margin: 0 0 12px 0; color: #0f172a; font-size: 14px; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px; }

.cpm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cpm-item { display: flex; flex-direction: column; gap: 4px; }
.cpm-item.full { grid-column: 1 / -1; }
.cpm-item label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; }
.cpm-item value { font-size: 14px; color: #0f172a; font-weight: 500; }

.cpm-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: flex-end; gap: 12px; }
.cpm-btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; border: none; }
.cpm-btn-secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
.cpm-btn-secondary:hover { background: #f1f5f9; color: #0f172a; }
.cpm-btn-primary { background: #4F46E5; color: white; border: 1px solid #4F46E5; }
.cpm-btn-primary:hover { background: #4338ca; }
</style>

<div id="viewCustomerModal" class="compact-premium-modal <?= $customerDetails ? 'show' : '' ?>">
    <div class="cpm-content">
        <div class="cpm-header">
            <h2><i class="fas fa-user-circle"></i> Customer Details</h2>
            <button type="button" class="cpm-close" onclick="closeCustomerModal()">&times;</button>
        </div>
        <div class="cpm-body">
            <?php if ($customerDetails): ?>
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px;">
                    <div style="width: 64px; height: 64px; border-radius: 16px; background: #4F46E5; color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700;">
                        <?= strtoupper(substr($customerDetails['full_name'], 0, 2)) ?>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 20px; color: var(--text-dark);"><?= sanitize($customerDetails['full_name']) ?></h3>
                        <p style="margin: 4px 0 0; color: var(--text-muted);">@<?= sanitize($customerDetails['username']) ?> &nbsp;•&nbsp; <span style="color: <?= $customerDetails['user_status'] === 'Active' ? '#059669' : '#dc2626' ?>; font-weight: 600;"><?= $customerDetails['user_status'] ?></span></p>
                    </div>
                </div>

                <div class="cpm-section">
                    <h4><i class="fas fa-id-card" style="color: #4F46E5;"></i> Account Information</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label>Customer ID</label><value>#<?= $customerDetails['customer_id'] ?></value></div>
                        <div class="cpm-item"><label>Email Address</label><value><?= sanitize($customerDetails['email']) ?></value></div>
                        <div class="cpm-item"><label>Contact Number</label><value><?= sanitize($customerDetails['contact_no']) ?></value></div>
                        <div class="cpm-item"><label>Registered</label><value><?= date('M d, Y', strtotime($customerDetails['registered_at'])) ?></value></div>
                        <div class="cpm-item full"><label>Address</label><value><?= sanitize($customerDetails['address'] ?: 'Not provided') ?></value></div>
                    </div>
                </div>

                <div class="cpm-section">
                    <h4><i class="fas fa-chart-bar" style="color: #4F46E5;"></i> Repair Statistics</h4>
                    <div class="cpm-grid">
                        <div class="cpm-item"><label>Total Repairs</label><value><?= $customerDetails['total_repairs'] ?></value></div>
                        <div class="cpm-item"><label>Active Repairs</label><value><?= $customerDetails['active_repairs'] ?></value></div>
                        <div class="cpm-item"><label>Completed Repairs</label><value><?= $customerDetails['completed_repairs'] ?></value></div>
                        <div class="cpm-item"><label>Total Spent</label><value><?= formatCurrency($customerDetails['total_spent'] ?? 0) ?></value></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="cpm-footer">
            <button type="button" class="cpm-btn cpm-btn-secondary" onclick="closeCustomerModal()"><i class="fas fa-times"></i> Close</button>
            <a href="repairs.php?customer=<?= $customerDetails['customer_id'] ?? '' ?>" class="cpm-btn cpm-btn-primary"><i class="fas fa-wrench"></i> View Repairs</a>
        </div>
    </div>
</div>

<script>
function viewCustomer(customerId) {
    window.location.href = 'customers.php?view_id=' + customerId + (window.location.search.includes('search=') ? '&search=' + encodeURIComponent(new URLSearchParams(window.location.search).get('search')) : '');
}

function closeCustomerModal() {
    const url = new URL(window.location);
    url.searchParams.delete('view_id');
    window.location.href = url.toString();
}

<?php if ($customerDetails): ?>
    document.getElementById('viewCustomerModal').classList.add('show');
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
