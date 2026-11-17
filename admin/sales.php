<?php

require_once '../config/config.php';
$pageTitle = 'Sales Management - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_sale'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('sales.php', 'Invalid request.', 'danger');
    }

    $productId = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $paymentMethod = $_POST['payment_method'] ?? 'Cash';
    $notes = trim($_POST['notes'] ?? '');

    
    $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        redirect('sales.php', 'Product not found.', 'danger');
    }

    if ($product['quantity'] < $quantity) {
        redirect('sales.php', 'Insufficient stock. Only ' . $product['quantity'] . ' available.', 'warning');
    }

    $unitPrice = $product['price'];
    $totalPrice = $unitPrice * $quantity;

    try {
        $db->beginTransaction();

        
        $stmt = $db->prepare("
            INSERT INTO sales (product_id, quantity, unit_price, total_price, payment_method, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$productId, $quantity, $unitPrice, $totalPrice, $paymentMethod, $notes]);

        
        $newQty = $product['quantity'] - $quantity;
        $newStatus = $newQty > 0 ? 'Available' : 'Sold Out';
        $stmt = $db->prepare("UPDATE products SET quantity = ?, status = ? WHERE product_id = ?");
        $stmt->execute([$newQty, $newStatus, $productId]);

        $db->commit();
        redirect('sales.php', 'Sale recorded successfully! Total: ' . formatCurrency($totalPrice), 'success');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('sales.php', 'Failed to record sale: ' . $e->getMessage(), 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_sale'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('sales.php', 'Invalid request.', 'danger');
    }
    $saleId       = (int)$_POST['sale_id'];
    $newQty       = max(1, (int)$_POST['quantity']);
    $payMethod    = $_POST['payment_method'] ?? 'Cash';
    $notes        = trim($_POST['notes'] ?? '');

    $stmt = $db->prepare("SELECT s.*, p.quantity AS stock_qty FROM sales s JOIN products p ON s.product_id = p.product_id WHERE s.sale_id = ?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale) { redirect('sales.php', 'Sale not found.', 'danger'); }

    $oldQty    = (int)$sale['quantity'];
    $qtyDiff   = $newQty - $oldQty;          
    $newStock  = (int)$sale['stock_qty'] - $qtyDiff;

    if ($newStock < 0) {
        redirect('sales.php', 'Insufficient stock. Only ' . ($sale['stock_qty'] + $oldQty) . ' available.', 'warning');
    }

    $newTotal  = $sale['unit_price'] * $newQty;
    $newStatus = $newStock > 0 ? 'Available' : 'Sold Out';

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE sales SET quantity=?, total_price=?, payment_method=?, notes=? WHERE sale_id=?")->execute([$newQty, $newTotal, $payMethod, $notes, $saleId]);
        $db->prepare("UPDATE products SET quantity=?, status=? WHERE product_id=?")->execute([$newStock, $newStatus, $sale['product_id']]);
        $db->commit();
        redirect('sales.php', 'Sale updated successfully!', 'success');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('sales.php', 'Failed to update sale: ' . $e->getMessage(), 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('sales.php', 'Invalid request.', 'danger');
    }
    $saleId = (int)$_POST['sale_id'];
    $stmt = $db->prepare("SELECT * FROM sales WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale) { redirect('sales.php', 'Sale not found.', 'danger'); }

    try {
        $db->beginTransaction();
        
        $db->prepare("UPDATE products SET quantity = quantity + ?, status = 'Available' WHERE product_id = ?")->execute([$sale['quantity'], $sale['product_id']]);
        $db->prepare("DELETE FROM sales WHERE sale_id = ?")->execute([$saleId]);
        $db->commit();
        redirect('sales.php', 'Sale deleted and ' . $sale['quantity'] . ' unit(s) restored to stock.', 'success');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('sales.php', 'Failed to delete sale: ' . $e->getMessage(), 'danger');
    }
}

$period = $_GET['period'] ?? 'today';
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

switch ($period) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d');
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-d');
        break;
    case 'custom':
        
        break;
    default:
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
}

$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(quantity), 0) as total_units,
        COALESCE(SUM(total_price), 0) as total_revenue
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$salesStats = $stmt->fetch();

$todayRevenue = $db->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE DATE(sale_date) = CURDATE()")->fetchColumn();

$monthRevenue = $db->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())")->fetchColumn();

$stmt = $db->prepare("
    SELECT s.*, p.product_name, pc.category_name
    FROM sales s
    JOIN products p ON s.product_id = p.product_id
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$stmt->execute([$startDate, $endDate]);
$sales = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT p.product_name, SUM(s.quantity) as units_sold, SUM(s.total_price) as revenue
    FROM sales s
    JOIN products p ON s.product_id = p.product_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.product_id, p.product_name
    ORDER BY revenue DESC
    LIMIT 10
");
$stmt->execute([$startDate, $endDate]);
$topProducts = $stmt->fetchAll();

$dailySales = $db->query("
    SELECT DATE(sale_date) as sale_day, SUM(total_price) as daily_total
    FROM sales
    WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())
    GROUP BY DATE(sale_date)
    ORDER BY sale_day ASC
")->fetchAll();

$stmt = $db->prepare("
    SELECT payment_method, COUNT(*) as count, SUM(total_price) as total
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total DESC
");
$stmt->execute([$startDate, $endDate]);
$paymentBreakdown = $stmt->fetchAll();

$availableProducts = $db->query("SELECT product_id, product_name, price, quantity FROM products WHERE status = 'Available' AND quantity > 0 ORDER BY product_name")->fetchAll();

$stmt = $db->prepare("
    SELECT r.repair_id, c.full_name AS customer_name,
           CONCAT(r.appliance_type, IF(r.brand IS NOT NULL, CONCAT(' - ', r.brand), '')) AS item_desc,
           r.total_cost, r.payment_method, r.date_completed
    FROM repairs r
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.repair_status = 'Completed'
      AND r.total_cost > 0
      AND DATE(r.date_completed) BETWEEN ? AND ?
    ORDER BY r.date_completed DESC
");
$stmt->execute([$startDate, $endDate]);
$repairRevenue = $stmt->fetchAll();

$repairRevenueTotal = array_sum(array_column($repairRevenue, 'total_cost'));
$totalCombinedRevenue = $salesStats['total_revenue'] + $repairRevenueTotal;
?>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> Sales Management</h1>
    <p>Track and monitor appliance sales</p>
</div>

<div class="stats-grid">
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <h3><?= formatCurrency($todayRevenue) ?></h3>
            <p>Today's Sales</p>
        </div>
    </div>
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
        <div class="stat-content">
            <h3><?= formatCurrency($monthRevenue) ?></h3>
            <p>This Month</p>
        </div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <h3><?= $salesStats['total_transactions'] ?></h3>
            <p>Product Transactions (Period)</p>
        </div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-icon"><i class="fas fa-tools"></i></div>
        <div class="stat-content">
            <h3><?= formatCurrency($totalCombinedRevenue) ?></h3>
            <p>Total Revenue (Period)</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;">
            
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                <a href="sales.php?period=today" class="btn btn-sm <?= $period === 'today' ? 'btn-primary' : 'btn-secondary' ?>">Today</a>
                <a href="sales.php?period=week" class="btn btn-sm <?= $period === 'week' ? 'btn-primary' : 'btn-secondary' ?>">This Week</a>
                <a href="sales.php?period=month" class="btn btn-sm <?= $period === 'month' ? 'btn-primary' : 'btn-secondary' ?>">This Month</a>
                <form method="GET" style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="hidden" name="period" value="custom">
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $startDate ?>" style="width:auto;">
                    <span>to</span>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $endDate ?>" style="width:auto;">
                    <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <button onclick="document.getElementById('recordSaleModal').classList.add('active')" class="btn btn-success">
                <i class="fas fa-plus"></i> Record Sale
            </button>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;margin-top:1.5rem;">
    
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list"></i> Sales Transactions</h2>
            <span class="badge badge-primary"><?= count($sales) ?> records</span>
        </div>
        <div class="card-body">
            <?php if (empty($sales)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt fa-3x"></i>
                    <h3>No sales for this period</h3>
                    <p>Record your first sale to see data here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td><?= $sale['sale_id'] ?></td>
                                <td>
                                    <strong><?= sanitize($sale['product_name']) ?></strong>
                                    <br><small class="text-muted"><?= sanitize($sale['category_name'] ?? '') ?></small>
                                </td>
                                <td><?= $sale['quantity'] ?></td>
                                <td><strong><?= formatCurrency($sale['total_price']) ?></strong></td>
                                <td><span class="badge badge-info"><?= $sale['payment_method'] ?></span></td>
                                <td><?= formatDateTime($sale['sale_date']) ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-info" title="Edit"
                                            onclick="openEditSale(<?= $sale['sale_id'] ?>, '<?= addslashes($sale['product_name']) ?>', <?= $sale['quantity'] ?>, '<?= $sale['payment_method'] ?>', '<?= addslashes($sale['notes'] ?? '') ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="sale_id" value="<?= $sale['sale_id'] ?>">
                                            <button type="submit" name="delete_sale" class="btn btn-sm btn-danger" title="Delete"
                                                data-confirm="Delete this sale record? <?= $sale['quantity'] ?> unit(s) of '<?= sanitize($sale['product_name']) ?>' will be restored to stock.">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;background:var(--background-secondary);">
                                <td colspan="3">Total</td>
                                <td><?= formatCurrency($salesStats['total_revenue']) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    
    <div>
        
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2><i class="fas fa-trophy"></i> Top Products</h2>
            </div>
            <div class="card-body">
                <?php if (empty($topProducts)): ?>
                    <p class="text-muted">No sales data yet.</p>
                <?php else: ?>
                    <?php foreach ($topProducts as $i => $tp): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 0;<?= $i < count($topProducts) - 1 ? 'border-bottom:1px solid var(--border-light);' : '' ?>">
                        <div>
                            <span style="color:var(--text-muted);font-size:0.875rem;font-weight:600;">#<?= $i + 1 ?></span>
                            <strong style="margin-left:0.5rem;"><?= sanitize($tp['product_name']) ?></strong>
                            <br><small class="text-muted"><?= $tp['units_sold'] ?> units sold</small>
                        </div>
                        <strong class="text-success"><?= formatCurrency($tp['revenue']) ?></strong>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-wallet"></i> Payment Methods</h2>
            </div>
            <div class="card-body">
                <?php if (empty($paymentBreakdown)): ?>
                    <p class="text-muted">No data yet.</p>
                <?php else: ?>
                    <?php foreach ($paymentBreakdown as $pm): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border-light);">
                        <span><i class="fas fa-credit-card" style="color:var(--accent);margin-right:0.5rem;"></i><?= $pm['payment_method'] ?></span>
                        <div style="text-align:right;">
                            <strong><?= formatCurrency($pm['total']) ?></strong>
                            <br><small class="text-muted"><?= $pm['count'] ?> transactions</small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h2><i class="fas fa-tools"></i> Repair Revenue</h2>
        <span class="badge badge-primary"><?= count($repairRevenue) ?> records</span>
    </div>
    <div class="card-body">
        <?php if (empty($repairRevenue)): ?>
            <div class="empty-state">
                <i class="fas fa-tools fa-3x"></i>
                <h3>No completed repairs in this period</h3>
                <p>Completed repairs with a total cost will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Repair #</th>
                            <th>Customer</th>
                            <th>Item</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Date Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repairRevenue as $rr): ?>
                        <tr>
                            <td class="text-muted">#<?= $rr['repair_id'] ?></td>
                            <td><?= sanitize($rr['customer_name']) ?></td>
                            <td><?= sanitize($rr['item_desc']) ?></td>
                            <td><strong><?= formatCurrency($rr['total_cost']) ?></strong></td>
                            <td><span class="badge badge-info"><?= $rr['payment_method'] ?? 'N/A' ?></span></td>
                            <td><?= formatDateTime($rr['date_completed']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;background:var(--background-secondary);">
                            <td colspan="3">Repair Revenue Total</td>
                            <td><?= formatCurrency($repairRevenueTotal) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="editSaleModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Sale</h2>
            <button class="close-modal" onclick="document.getElementById('editSaleModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" id="edit_sale_id" name="sale_id">
                <div class="form-group">
                    <label>Product</label>
                    <input type="text" id="edit_sale_product_name" class="form-control" readonly style="background:var(--background-secondary);">
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="edit_sale_quantity">Quantity *</label>
                        <input type="number" id="edit_sale_quantity" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_sale_payment">Payment Method</label>
                        <select id="edit_sale_payment" name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_sale_notes">Notes</label>
                    <textarea id="edit_sale_notes" name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editSaleModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="edit_sale" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="recordSaleModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-cash-register"></i> Record New Sale</h2>
            <button class="close-modal" onclick="document.getElementById('recordSaleModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="sale_product">Product *</label>
                    <select id="sale_product" name="product_id" class="form-control" required onchange="updateSalePrice()">
                        <option value="">Select Product</option>
                        <?php foreach ($availableProducts as $ap): ?>
                        <option value="<?= $ap['product_id'] ?>" data-price="<?= $ap['price'] ?>" data-qty="<?= $ap['quantity'] ?>">
                            <?= sanitize($ap['product_name']) ?> - <?= formatCurrency($ap['price']) ?> (<?= $ap['quantity'] ?> in stock)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="sale_quantity">Quantity *</label>
                        <input type="number" id="sale_quantity" name="quantity" class="form-control" min="1" value="1" required onchange="updateSaleTotal()">
                    </div>
                    <div class="form-group">
                        <label for="sale_payment">Payment Method</label>
                        <select id="sale_payment" name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div id="saleTotalDisplay" style="background:var(--background-secondary);border-radius:var(--radius);padding:1rem;text-align:center;margin-bottom:1rem;display:none;">
                    <small class="text-muted">Total Amount</small>
                    <h2 id="saleTotalAmount" style="margin:0;color:var(--success);">₱0.00</h2>
                </div>
                <div class="form-group">
                    <label for="sale_notes">Notes</label>
                    <textarea id="sale_notes" name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('recordSaleModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="record_sale" class="btn btn-success"><i class="fas fa-check"></i> Record Sale</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateSalePrice() {
    updateSaleTotal();
}

function openEditSale(saleId, productName, quantity, paymentMethod, notes) {
    document.getElementById('edit_sale_id').value       = saleId;
    document.getElementById('edit_sale_product_name').value = productName;
    document.getElementById('edit_sale_quantity').value = quantity;
    document.getElementById('edit_sale_notes').value    = notes;
    const paySelect = document.getElementById('edit_sale_payment');
    for (let i = 0; i < paySelect.options.length; i++) {
        if (paySelect.options[i].value === paymentMethod) {
            paySelect.selectedIndex = i;
            break;
        }
    }
    document.getElementById('editSaleModal').classList.add('active');
}

function updateSaleTotal() {
    const select = document.getElementById('sale_product');
    const qty = parseInt(document.getElementById('sale_quantity').value) || 0;
    const option = select.options[select.selectedIndex];
    const display = document.getElementById('saleTotalDisplay');
    const totalEl = document.getElementById('saleTotalAmount');
    
    if (option && option.dataset.price) {
        const price = parseFloat(option.dataset.price);
        const maxQty = parseInt(option.dataset.qty);
        const qtyInput = document.getElementById('sale_quantity');
        
        if (qty > maxQty) qtyInput.value = maxQty;
        
        const total = price * Math.min(qty, maxQty);
        totalEl.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
        display.style.display = 'block';
    } else {
        display.style.display = 'none';
    }
}

document.getElementById('sale_quantity')?.addEventListener('input', updateSaleTotal);
</script>

<?php require_once '../includes/footer.php'; ?>
