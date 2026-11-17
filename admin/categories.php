<?php

require_once '../config/config.php';
$pageTitle = 'Manage Categories - ' . SITE_NAME;
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_category']) || isset($_POST['edit_category']))) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('categories.php', 'Invalid security token. Please try again.', 'danger');
    }
    
    $isEdit = isset($_POST['edit_category']);
    $categoryId = $isEdit ? (int)$_POST['category_id'] : null;
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'] ?? 'Active';
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Category name is required.';
    }
    
    
    $imagePath = $isEdit ? $_POST['current_image'] : null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = $_FILES['image']['type'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Invalid image type. Only JPG, PNG, GIF, and WEBP are allowed.';
        } elseif ($_FILES['image']['size'] > MAX_FILE_SIZE) {
            $errors[] = 'Image size too large. Max 5MB allowed.';
        } else {
            $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('cat_') . '.' . $extension;
            $uploadDir = UPLOAD_PATH . 'categories/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                
                if ($isEdit && $imagePath && file_exists(UPLOAD_PATH . $imagePath)) {
                    @unlink(UPLOAD_PATH . $imagePath);
                }
                $imagePath = 'categories/' . $fileName;
            } else {
                $errors[] = 'Failed to upload image.';
            }
        }
    }
    
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $db->prepare("UPDATE product_categories SET category_name = ?, description = ?, image = ?, status = ? WHERE category_id = ?");
                $stmt->execute([$name, $description, $imagePath, $status, $categoryId]);
                $msg = 'Category updated successfully!';
            } else {
                $stmt = $db->prepare("INSERT INTO product_categories (category_name, description, image, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $description, $imagePath, $status]);
                $msg = 'Category added successfully!';
            }
            redirect('categories.php', $msg, 'success');
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}

if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        redirect('categories.php', 'Cannot delete category that has products. Please reassign products first.', 'danger');
    }
    
    
    $stmt = $db->prepare("SELECT image FROM product_categories WHERE category_id = ?");
    $stmt->execute([$id]);
    $image = $stmt->fetchColumn();
    if ($image && file_exists(UPLOAD_PATH . $image)) {
        @unlink(UPLOAD_PATH . $image);
    }
    
    $stmt = $db->prepare("DELETE FROM product_categories WHERE category_id = ?");
    $stmt->execute([$id]);
    redirect('categories.php', 'Category deleted successfully.', 'success');
}

$stmt = $db->query("SELECT * FROM product_categories ORDER BY category_name ASC");
$categories = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-tags"></i> Service Categories</h1>
    <button type="button" class="btn btn-primary" onclick="openAddModal()">
        <i class="fas fa-plus-circle"></i> Add Category
    </button>
</div>

<?php if (isset($_SESSION['errors'])): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($_SESSION['errors'] as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['errors']); ?>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <i class="fas fa-tags fa-3x"></i>
                <p>No categories defined yet.</p>
                <button type="button" class="btn btn-primary" onclick="openAddModal()">Create First Category</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <?php if ($cat['image']): ?>
                                        <img src="<?= SITE_URL ?>/uploads/<?= $cat['image'] ?>" alt="<?= sanitize($cat['category_name']) ?>" class="cat-thumb">
                                    <?php else: ?>
                                        <div class="cat-thumb-placeholder"><i class="fas fa-image"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= sanitize($cat['category_name']) ?></strong></td>
                                <td><?= sanitize($cat['description']) ?></td>
                                <td>
                                    <span class="badge <?= $cat['status'] === 'Active' ? 'badge-success' : 'badge-secondary' ?>">
                                        <?= $cat['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-warning" onclick='openEditModal(<?= json_encode($cat) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $cat['category_id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
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

<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Category</h2>
            <button type="button" class="close-modal" onclick="closeModal('categoryModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="categoryForm">
            <?= csrfField() ?>
            <input type="hidden" name="category_id" id="category_id">
            <input type="hidden" name="current_image" id="current_image">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="name">Category Name <span class="required">*</span></label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="image">Category Photo</label>
                    <div class="image-upload-preview" id="imagePreview">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Click to upload photo</span>
                    </div>
                    <input type="file" name="image" id="image" class="form-control" accept="image/*" style="display: none;">
                    <div id="selectedFileName" class="text-muted small mt-1"></div>
                </div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">Cancel</button>
                <button type="submit" name="add_category" id="submitBtn" class="btn btn-primary">Save Category</button>
            </div>
        </form>
    </div>
</div>

<style>
.cat-thumb {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid #ddd;
}
.cat-thumb-placeholder {
    width: 50px;
    height: 50px;
    background: #f1f1f1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    color: #ccc;
}
.image-upload-preview {
    border: 2px dashed #ddd;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: #f9f9f9;
}
.image-upload-preview:hover {
    border-color: var(--primary);
    background: #f0f7ff;
}
.image-upload-preview i {
    font-size: 2rem;
    color: #aaa;
    display: block;
    margin-bottom: 10px;
}
.image-upload-preview.has-image {
    padding: 0;
    overflow: hidden;
    height: 150px;
}
.image-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
</style>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
    document.getElementById('current_image').value = '';
    document.getElementById('submitBtn').name = 'add_category';
    document.getElementById('imagePreview').innerHTML = '<i class="fas fa-cloud-upload-alt"></i><span>Click to upload photo</span>';
    document.getElementById('imagePreview').classList.remove('has-image');
    openModal('categoryModal');
}

function openEditModal(cat) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Category';
    document.getElementById('category_id').value = cat.category_id;
    document.getElementById('name').value = cat.category_name;
    document.getElementById('description').value = cat.description;
    document.getElementById('status').value = cat.status;
    document.getElementById('current_image').value = cat.image;
    document.getElementById('submitBtn').name = 'edit_category';
    
    if (cat.image) {
        document.getElementById('imagePreview').innerHTML = '<img src="<?= SITE_URL ?>/uploads/' + cat.image + '">';
        document.getElementById('imagePreview').classList.add('has-image');
    } else {
        document.getElementById('imagePreview').innerHTML = '<i class="fas fa-cloud-upload-alt"></i><span>Click to upload photo</span>';
        document.getElementById('imagePreview').classList.remove('has-image');
    }
    
    openModal('categoryModal');
}

document.getElementById('imagePreview').addEventListener('click', function() {
    document.getElementById('image').click();
});

document.getElementById('image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('selectedFileName').textContent = file.name;
        const reader = new FileReader();
        reader.onload = function(event) {
            document.getElementById('imagePreview').innerHTML = '<img src="' + event.target.result + '">';
            document.getElementById('imagePreview').classList.add('has-image');
        }
        reader.readAsDataURL(file);
    }
});

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "Cannot delete category if products are assigned to it.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'categories.php?delete_id=' + id;
        }
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
