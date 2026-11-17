<?php

require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Inventory Management - ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
requireRole('Admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('inventory.php', 'Invalid request.', 'danger');
    }
    
    $productName = trim($_POST['product_name']);
    $description = trim($_POST['description']);
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $price = (float)$_POST['price'];
    $quantity = (int)$_POST['quantity'];
    
    $errors = [];
    if (strlen($productName) < 2) $errors[] = 'Product name is required.';
    if ($price < 0) $errors[] = 'Price must be a positive number.';
    if ($quantity < 0) $errors[] = 'Quantity must be a positive number.';
    
    if (empty($errors)) {
        $status = $quantity > 0 ? 'Available' : 'Sold Out';
        
        
        $imagePath = null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = UPLOAD_PATH . 'products/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            $filename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $destPath)) {
                $imagePath = 'uploads/products/' . $filename;
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO products (product_name, description, category_id, price, quantity, status, image)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$productName, $description, $categoryId, $price, $quantity, $status, $imagePath]);
        redirect('inventory.php', 'Product added successfully!', 'success');
    } else {
        $_SESSION['errors'] = $errors;
        redirect('inventory.php?action=add');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('inventory.php', 'Invalid request.', 'danger');
    }
    
    $productId = (int)$_POST['product_id'];
    $productName = trim($_POST['product_name']);
    $description = trim($_POST['description']);
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $price = (float)$_POST['price'];
    $quantity = (int)$_POST['quantity'];
    
    $errors = [];
    if (strlen($productName) < 2) $errors[] = 'Product name is required.';
    if ($price < 0) $errors[] = 'Price must be a positive number.';
    if ($quantity < 0) $errors[] = 'Quantity must be a positive number.';
    
    if (empty($errors)) {
        $status = $quantity > 0 ? 'Available' : 'Sold Out';
        
        
        $imagePath = null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = UPLOAD_PATH . 'products/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            $filename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $destPath)) {
                $imagePath = 'uploads/products/' . $filename;
            }
        }
        
        if ($imagePath) {
            $stmt = $db->prepare("
                UPDATE products SET product_name = ?, description = ?, category_id = ?, 
                price = ?, quantity = ?, status = ?, image = ? WHERE product_id = ?
            ");
            $stmt->execute([$productName, $description, $categoryId, $price, $quantity, $status, $imagePath, $productId]);
        } else {
            $stmt = $db->prepare("
                UPDATE products SET product_name = ?, description = ?, category_id = ?, 
                price = ?, quantity = ?, status = ? WHERE product_id = ?
            ");
            $stmt->execute([$productName, $description, $categoryId, $price, $quantity, $status, $productId]);
        }
        
        redirect('inventory.php', 'Product updated successfully!', 'success');
    } else {
        $_SESSION['errors'] = $errors;
        redirect('inventory.php?edit_id=' . $productId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_sale'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('inventory.php', 'Invalid request.', 'danger');
    }
    
    $productId = (int)$_POST['product_id'];
    $quantityToMinus = (int)$_POST['quantity_to_minus'];
    $paymentMethod = $_POST['payment_method'] ?? 'Cash';
    
    $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        redirect('inventory.php', 'Product not found.', 'danger');
    }
    
    if ($product['quantity'] < $quantityToMinus) {
        redirect('inventory.php', 'Insufficient stock to complete this sale.', 'warning');
    }
    
    try {
        $db->beginTransaction();
        
        
        $newQuantity = $product['quantity'] - $quantityToMinus;
        $status = $newQuantity > 0 ? 'Available' : 'Sold Out';
        $stmt = $db->prepare("UPDATE products SET quantity = ?, status = ? WHERE product_id = ?");
        $stmt->execute([$newQuantity, $status, $productId]);
        
        
        $unitPrice = $product['price'];
        $totalPrice = $unitPrice * $quantityToMinus;
        $stmt = $db->prepare("
            INSERT INTO sales (product_id, quantity, unit_price, total_price, payment_method, sale_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$productId, $quantityToMinus, $unitPrice, $totalPrice, $paymentMethod]);
        
        $db->commit();
        redirect('inventory.php', 'Sale recorded and inventory updated successfully!', 'success');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('inventory.php', 'Failed to record sale: ' . $e->getMessage(), 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('inventory.php', 'Invalid request.', 'danger');
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        redirect('inventory.php', 'Invalid product ID.', 'danger');
    }

    $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        redirect('inventory.php', 'Product not found.', 'danger');
    }

    try {
        $db->beginTransaction();

        
        $stmtSales = $db->prepare("DELETE FROM sales WHERE product_id = ?");
        $stmtSales->execute([$productId]);

        
        $stmtDel = $db->prepare("DELETE FROM products WHERE product_id = ?");
        $stmtDel->execute([$productId]);

        $db->commit();

        
        if (!empty($product['image'])) {
            $imageFullPath = __DIR__ . '/../' . ltrim($product['image'], '/\\');
            if (file_exists($imageFullPath) && is_file($imageFullPath)) {
                @unlink($imageFullPath);
            }
        }

        redirect('inventory.php', 'Product "' . $product['product_name'] . '" deleted successfully!', 'success');
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirect('inventory.php', 'Failed to delete product: ' . $e->getMessage(), 'danger');
    }
}

$categories = $db->query("SELECT * FROM product_categories ORDER BY category_name")->fetchAll();

$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

$where = [];
$params = [];

if ($statusFilter) {
    $where[] = "p.status = ?";
    $params[] = $statusFilter;
}
if ($categoryFilter) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT p.*, pc.category_name, 
           COALESCE((SELECT SUM(s.quantity) FROM sales s WHERE s.product_id = p.product_id), 0) as total_sold
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id
    $whereClause
    ORDER BY p.updated_at DESC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$availableCount = $db->query("SELECT COUNT(*) FROM products WHERE status = 'Available'")->fetchColumn();
$soldOutCount = $db->query("SELECT COUNT(*) FROM products WHERE status = 'Sold Out'")->fetchColumn();
$totalValue = $db->query("SELECT COALESCE(SUM(price * quantity), 0) FROM products")->fetchColumn();

$editProduct = null;
if (isset($_GET['edit_id'])) {
    $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([(int)$_GET['edit_id']]);
    $editProduct = $stmt->fetch();
}
?>

<div class="page-header" style="margin-bottom:0.75rem;">
    <h1 style="font-size:1.1rem;"><i class="fas fa-boxes"></i> Inventory Management</h1>
    <p style="font-size:0.75rem;margin:0;">Manage appliances and products for sale</p>
</div>

<?php if (isset($_SESSION['errors'])): ?>
    <div class="alert alert-danger">
        <ul style="margin:0;padding-left:1.5rem;">
            <?php foreach ($_SESSION['errors'] as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['errors']); ?>
<?php endif; ?>

<div class="inv-stats-strip">
    <div class="inv-stat"><i class="fas fa-boxes text-primary"></i><span class="inv-stat-val"><?= $totalProducts ?></span><span class="inv-stat-lbl">Products</span></div>
    <div class="inv-stat"><i class="fas fa-check-circle text-success"></i><span class="inv-stat-val"><?= $availableCount ?></span><span class="inv-stat-lbl">Available</span></div>
    <div class="inv-stat"><i class="fas fa-exclamation-triangle text-warning"></i><span class="inv-stat-val"><?= $soldOutCount ?></span><span class="inv-stat-lbl">Sold Out</span></div>
    <div class="inv-stat"><i class="fas fa-peso-sign text-info"></i><span class="inv-stat-val"><?= formatCurrency($totalValue) ?></span><span class="inv-stat-lbl">Value</span></div>
</div>

<div class="inv-toolbar">
    <form method="GET" class="inv-toolbar-form">
        <select name="status" class="inv-select">
            <option value="">All Status</option>
            <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
            <option value="Sold Out" <?= $statusFilter === 'Sold Out' ? 'selected' : '' ?>>Sold Out</option>
        </select>
        <select name="category" class="inv-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['category_id'] ?>" <?= $categoryFilter == $cat['category_id'] ? 'selected' : '' ?>><?= sanitize($cat['category_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="inv-btn inv-btn-primary"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($statusFilter || $categoryFilter): ?>
        <a href="inventory.php" class="inv-btn inv-btn-ghost"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
    <button onclick="document.getElementById('addProductModal').classList.add('active')" class="inv-btn inv-btn-success">
        <i class="fas fa-plus"></i> Add Product
    </button>
</div>

<div class="card inv-card">
    <div class="inv-card-header">
        <span><i class="fas fa-list"></i> Products <span class="inv-count-badge"><?= count($products) ?></span></span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($products)): ?>
            <div class="empty-state" style="padding:2rem;">
                <i class="fas fa-box-open fa-2x"></i>
                <h3 style="font-size:0.9rem;">No products found</h3>
                <p style="font-size:0.75rem;">Start by adding your first product.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Qty</th>
                            <th>Sold</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td class="text-muted">#<?= $product['product_id'] ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:0.5rem;">
                                    <?php if ($product['image']): ?>
                                    <img src="<?= SITE_URL ?>/<?= $product['image'] ?>" alt="" style="width:32px;height:32px;border-radius:4px;object-fit:cover;">
                                    <?php else: ?>
                                    <div style="width:32px;height:32px;border-radius:4px;background:var(--background-secondary);display:flex;align-items:center;justify-content:center;">
                                        <i class="fas fa-box" style="color:var(--text-muted);font-size:0.7rem;"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong style="font-size:0.78rem;"><?= sanitize($product['product_name']) ?></strong>
                                        <?php if ($product['description']): ?>
                                        <br><small class="text-muted" style="font-size:0.65rem;"><?= sanitize(substr($product['description'], 0, 40)) ?><?= strlen($product['description']) > 40 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= sanitize($product['category_name'] ?? 'Uncategorized') ?></td>
                            <td><strong><?= formatCurrency($product['price']) ?></strong></td>
                            <td>
                                <span class="<?= $product['quantity'] <= 5 && $product['quantity'] > 0 ? 'text-warning' : ($product['quantity'] == 0 ? 'text-danger' : '') ?>">
                                    <?= $product['quantity'] ?>
                                </span>
                            </td>
                            <td><?= $product['total_sold'] ?></td>
                            <td>
                                <span class="badge <?= $product['status'] === 'Available' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $product['status'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-warning" title="Quick Sale (Minus)"
                                            onclick="openQuickSaleModal(<?= $product['product_id'] ?>, '<?= addslashes(sanitize($product['product_name'])) ?>', <?= $product['quantity'] ?>, <?= $product['price'] ?>)">
                                        <i class="fas fa-minus-circle"></i>
                                    </button>
                                    <a href="inventory.php?edit_id=<?= $product['product_id'] ?>" class="btn btn-sm btn-info" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" data-confirm="Delete '<?= sanitize($product['product_name']) ?>'? This will also remove any sales records for this product. This cannot be undone." data-confirm-title="Delete Product" data-confirm-btn="Yes, delete it!" data-confirm-icon="warning">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="delete_product" value="1">
                                        <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
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

<div class="modal" id="addProductModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Add New Product</h2>
            <button class="close-modal" onclick="document.getElementById('addProductModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="product_name">Product Name *</label>
                    <input type="text" id="product_name" name="product_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= sanitize($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="price">Price (₱) *</label>
                        <input type="number" id="price" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="quantity">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" class="form-control" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="product_image">Product Image</label>
                        <input type="file" id="product_image" name="product_image" class="form-control" accept="image/*">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addProductModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="add_product" class="btn btn-success"><i class="fas fa-save"></i> Add Product</button>
            </div>
        </form>
    </div>
</div>

<?php if ($editProduct): ?>
<div class="modal active" id="editProductModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Product</h2>
            <a href="inventory.php" class="close-modal">&times;</a>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="product_id" value="<?= $editProduct['product_id'] ?>">
                <div class="form-group">
                    <label for="edit_product_name">Product Name *</label>
                    <input type="text" id="edit_product_name" name="product_name" class="form-control" value="<?= sanitize($editProduct['product_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control" rows="3"><?= sanitize($editProduct['description']) ?></textarea>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="edit_category_id">Category</label>
                        <select id="edit_category_id" name="category_id" class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= $editProduct['category_id'] == $cat['category_id'] ? 'selected' : '' ?>><?= sanitize($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_price">Price (₱) *</label>
                        <input type="number" id="edit_price" name="price" class="form-control" step="0.01" min="0" value="<?= $editProduct['price'] ?>" required>
                    </div>
                </div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="edit_quantity">Quantity *</label>
                        <input type="number" id="edit_quantity" name="quantity" class="form-control" min="0" value="<?= $editProduct['quantity'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_product_image">Product Image</label>
                        <input type="file" id="edit_product_image" name="product_image" class="form-control" accept="image/*">
                        <?php if ($editProduct['image']): ?>
                        <small class="text-muted">Current: <?= basename($editProduct['image']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="inventory.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="edit_product" class="btn btn-primary"><i class="fas fa-save"></i> Update Product</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal" id="quickSaleModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-minus-circle"></i> Record Shop Sale</h2>
            <button class="close-modal" onclick="document.getElementById('quickSaleModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="product_id" id="qs_product_id">
                
                <div style="background:var(--background-secondary);padding:1rem;border-radius:8px;margin-bottom:1.5rem;border-left:4px solid var(--warning);">
                    <p style="margin:0;font-size:0.9rem;font-weight:600;" id="qs_product_display"></p>
                    <small class="text-muted">Is a customer purchasing this product? This will automatically deduct stock and record a sale.</small>
                </div>

                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label for="qs_quantity">Quantity to Sell *</label>
                        <input type="number" id="qs_quantity" name="quantity_to_minus" class="form-control" value="1" min="1" required>
                        <small class="text-muted">Available: <span id="qs_available_qty">0</span></small>
                    </div>
                    <div class="form-group">
                        <label for="qs_payment_method">Payment Method</label>
                        <select id="qs_payment_method" name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                        </select>
                    </div>
                </div>

                <div class="total-sale-box" style="margin-top:1rem;padding:1rem;background:var(--primary-light);border-radius:8px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:600;font-size:0.85rem;">Total Amount:</span>
                    <span id="qs_total_display" style="font-size:1.1rem;font-weight:700;color:var(--primary);">₱0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('quickSaleModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="record_sale" class="btn btn-warning"><i class="fas fa-check"></i> Confirm Sale</button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuickSaleModal(id, name, qty, price) {
    const modal = document.getElementById('quickSaleModal');
    document.getElementById('qs_product_id').value = id;
    document.getElementById('qs_product_display').textContent = name;
    document.getElementById('qs_available_qty').textContent = qty;
    document.getElementById('qs_quantity').max = qty;
    document.getElementById('qs_quantity').value = 1;
    
    const updateTotal = () => {
        const q = parseInt(document.getElementById('qs_quantity').value) || 0;
        const total = q * price;
        document.getElementById('qs_total_display').textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(total);
    };
    
    document.getElementById('qs_quantity').oninput = updateTotal;
    updateTotal();
    
    modal.classList.add('active');
}
</script>

<style>

.inv-stats-strip {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.inv-stat {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--background);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    flex: 1;
    min-width: 140px;
}
.inv-stat i { font-size: 1.1rem; }
.inv-stat-val { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
.inv-stat-lbl { font-size: 0.75rem; color: var(--text-muted); }

.inv-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.inv-toolbar-form {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.inv-select {
    padding: 0.3rem 1.6rem 0.3rem 0.5rem;
    font-family: inherit;
    font-size: 0.75rem;
    color: var(--text-primary);
    background: var(--background);
    border: 1px solid var(--border);
    border-radius: 5px;
    outline: none;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' fill='%235c5a72' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.4rem center;
    min-width: 110px;
    transition: border-color 0.2s;
}
.inv-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-light);
}
.inv-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.65rem;
    font-family: inherit;
    font-size: 0.72rem;
    font-weight: 500;
    border: 1px solid transparent;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    white-space: nowrap;
}
.inv-btn i { font-size: 0.62rem; }
.inv-btn-primary { background: var(--primary); color: #fff; }
.inv-btn-primary:hover { opacity: 0.85; }
.inv-btn-success { background: var(--success, #28a745); color: #fff; }
.inv-btn-success:hover { opacity: 0.85; }
.inv-btn-ghost {
    background: transparent;
    color: var(--text-muted);
    border-color: var(--border);
}
.inv-btn-ghost:hover { background: var(--background-secondary); color: var(--text-primary); }

.inv-card { margin-bottom: 1rem; }
.inv-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 0.85rem;
    border-bottom: 1px solid var(--border-light);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-primary);
}
.inv-card-header i { color: var(--accent); margin-right: 0.3rem; font-size: 0.7rem; }
.inv-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 0.3rem;
    font-size: 0.6rem;
    font-weight: 700;
    color: #fff;
    background: var(--primary);
    border-radius: 10px;
    margin-left: 0.3rem;
}

@media (max-width: 768px) {
    .inv-stats-strip { gap: 0.35rem; }
    .inv-stat { min-width: 0; flex: 1 1 45%; }
    .inv-toolbar { flex-direction: column; align-items: stretch; }
    .inv-toolbar-form { flex-direction: column; }
    .inv-select { width: 100%; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
