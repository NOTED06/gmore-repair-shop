<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customerId = $stmt->fetchColumn();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $applianceType      = sanitize($_POST['appliance_type'] ?? '');
        $brand              = sanitize($_POST['brand'] ?? '');
        $model              = sanitize($_POST['model'] ?? '');
        $serialNumber       = sanitize($_POST['serial_number'] ?? '');
        $problemDescription = sanitize($_POST['problem_description'] ?? '');

        if (empty($applianceType))       $errors[] = 'Appliance type is required.';
        if (empty($problemDescription))  $errors[] = 'Problem description is required.';

        $imagePath = null;
        if (isset($_FILES['appliance_image']) && $_FILES['appliance_image']['error'] === UPLOAD_ERR_OK) {
            $file         = $_FILES['appliance_image'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize      = 5 * 1024 * 1024;

            if (!in_array($file['type'], $allowedTypes)) {
                $errors[] = 'Invalid image type. Please upload JPG, PNG, GIF, or WebP.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Image too large. Maximum size is 5MB.';
            } else {
                $uploadDir = dirname(__DIR__) . '/uploads/repairs/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename   = 'repair_' . time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $imagePath = 'uploads/repairs/' . $filename;
                } else {
                    $errors[] = 'Failed to upload image. Please try again.';
                }
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO repairs (
                        customer_id, appliance_type, brand, model, serial_number,
                        appliance_image, problem_description, repair_status, date_requested
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ");
                $stmt->execute([
                    $customerId, $applianceType,
                    $brand ?: null, $model ?: null, $serialNumber ?: null,
                    $imagePath, $problemDescription
                ]);
                $repairId = $db->lastInsertId();

                logRepairAction($repairId, $_SESSION['user_id'], 'Created', null, 'Pending', 'Repair request submitted by customer');

                $stmt = $db->prepare("SELECT user_id FROM users WHERE role = 'Admin'");
                $stmt->execute();
                while ($admin = $stmt->fetch()) {
                    createNotification(
                        $admin['user_id'],
                        'New Repair Request',
                        "New repair request #$repairId for $applianceType has been submitted."
                    );
                }

                
                $stmt = $db->prepare("SELECT contact_no FROM customers WHERE customer_id = ?");
                $stmt->execute([$customerId]);
                $contactNo = $stmt->fetchColumn();

                if (!empty($contactNo)) {
                    require_once '../includes/SmsQueue.php';
                    $smsQueue = new SmsQueue($db);
                    $siteName = getSetting('site_name', SITE_NAME);
                    $smsMsg = "$siteName: Your repair request #$repairId for $applianceType has been received. We will notify you once a technician is assigned.";
                    $smsQueue->add($contactNo, $smsMsg);
                }

                redirect('repairs.php', 'Your repair request has been submitted successfully! We will contact you soon.', 'success');
            } catch (PDOException $e) {
                $errors[] = 'An error occurred. Please try again.';
            }
        }
    }
}

$pageTitle    = 'New Repair Request';
$activePage   = 'repairs';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><i data-lucide="plus-circle"></i> Tell us what&rsquo;s broken.</h1>
            <p class="page-sub">Describe your device and we&rsquo;ll take it from here.</p>
        </div>
        <a href="repairs.php" class="btn-ghost"><i data-lucide="arrow-left"></i> Back to Repairs</a>
    </div>

    
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-bottom:20px">
        <i data-lucide="alert-circle"></i>
        <ul><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    
    <div class="form-card">
        <div class="card">
            
            <div style="padding:20px 24px 0">
                <div class="form-section-header">
                    <div class="form-section-title"><i data-lucide="search"></i> Device Information</div>
                    <span class="badge badge-teal">SMART SEARCH ENABLED</span>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" novalidate style="padding:0 24px 24px">
                <?php echo csrfField(); ?>

                
                <div class="field-group" style="margin-bottom:16px">
                    <label class="field-label" for="applianceType">Appliance Type <span style="color:var(--rose)">*</span></label>
                    <div class="input-wrap" style="position:relative">
                        <input type="text" id="applianceType" name="appliance_type" class="input"
                               placeholder="e.g. Refrigerator, TV, Washing Machine"
                               value="<?= sanitize($_POST['appliance_type'] ?? '') ?>"
                               autocomplete="off" required>
                        <div class="autocomplete-suggestions" id="applianceSuggestions"></div>
                    </div>
                </div>

                
                <div class="form-grid-3" style="margin-bottom:16px">
                    <div class="field-group">
                        <label class="field-label" for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" class="input"
                               placeholder="e.g. Samsung" value="<?= sanitize($_POST['brand'] ?? '') ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="model">Model</label>
                        <input type="text" id="model" name="model" class="input"
                               placeholder="e.g. RT38K5590S8" value="<?= sanitize($_POST['model'] ?? '') ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="serial_number">Serial No.</label>
                        <input type="text" id="serial_number" name="serial_number" class="input"
                               placeholder="Optional" value="<?= sanitize($_POST['serial_number'] ?? '') ?>">
                    </div>
                </div>

                
                <div class="field-group" style="margin-bottom:16px">
                    <label class="field-label" for="problemDescription">Problem Description <span style="color:var(--rose)">*</span></label>
                    <textarea id="problemDescription" name="problem_description" class="input"
                              rows="5" placeholder="Describe the issue with the device..."
                              required><?= sanitize($_POST['problem_description'] ?? '') ?></textarea>
                    <span class="field-hint">The more detail you provide, the faster we can diagnose.</span>
                </div>

                
                <div class="field-group" style="margin-bottom:24px">
                    <label class="field-label">Photo of Device / Problem (optional)</label>
                    <div class="upload-zone" id="uploadZoneEl">
                        <input type="file" id="applianceImage" name="appliance_image" accept="image/*">
                        <div class="upload-zone-icon"><i data-lucide="image-plus"></i></div>
                        <div class="upload-zone-text">Click or drag &amp; drop your image here</div>
                        <div class="upload-zone-hint">JPG, PNG, GIF, WebP up to 5MB</div>
                    </div>
                    <div class="upload-preview" id="uploadPreview" style="display:none">
                        <img id="previewImg" src="" alt="Preview">
                        <button type="button" class="upload-preview-remove" id="removePreview">
                            <i data-lucide="x"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="width:100%;justify-content:center">
                    <i data-lucide="send"></i> Submit Repair Request
                </button>
            </form>
        </div>
    </div>

</main>
</div>
<script src="<?= SITE_URL ?>/assets/js/main.js" defer></script>
<script>

const applianceInput = document.getElementById('applianceType');
const suggBox        = document.getElementById('applianceSuggestions');
let debouncer;

if (applianceInput && suggBox) {
    applianceInput.addEventListener('input', () => {
        clearTimeout(debouncer);
        const q = applianceInput.value.trim();
        if (q.length < 2) { suggBox.classList.remove('open'); return; }
        debouncer = setTimeout(() => {
            fetch(`<?= SITE_URL ?>/api/search-appliances.php?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    suggBox.innerHTML = '';
                    if (!data.length) {
                        suggBox.innerHTML = '<div class="suggestion-item suggestion-no-results">No matches found</div>';
                    } else {
                        data.forEach(item => {
                            const el = document.createElement('div');
                            el.className = 'suggestion-item';
                            
                            el.innerHTML = `
                                <div class="suggestion-main">${item.appliance_type}</div>
                                <div class="suggestion-desc">${item.brand} ${item.model} ${item.description ? ' - ' + item.description : ''}</div>
                            `;
                            el.addEventListener('mousedown', () => {
                                applianceInput.value = item.appliance_type;
                                
                                const brandInput = document.getElementById('brand');
                                const modelInput = document.getElementById('model');
                                if (brandInput) brandInput.value = item.brand;
                                if (modelInput) modelInput.value = item.model;
                                
                                suggBox.classList.remove('open');
                            });
                            suggBox.appendChild(el);
                        });
                    }
                    suggBox.classList.add('open');
                })
                .catch(() => suggBox.classList.remove('open'));
        }, 280);
    });
    document.addEventListener('click', e => { if (!applianceInput.contains(e.target)) suggBox.classList.remove('open'); });
}
</script>
</body>
</html>
