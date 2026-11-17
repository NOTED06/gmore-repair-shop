<?php

require_once '../config/config.php';
$pageTitle = 'System Settings';
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $settings = [
            'site_name' => sanitize($_POST['site_name'] ?? ''),
            'sms_enabled' => isset($_POST['sms_enabled']) ? '1' : '0',
            'sms_gateway_type' => sanitize($_POST['sms_gateway_type'] ?? 'semaphore'),
            'semaphore_api_key' => sanitize($_POST['semaphore_api_key'] ?? ''),
            'semaphore_sender_name' => sanitize($_POST['semaphore_sender_name'] ?? 'RepairShop'),
            'twilio_account_sid' => sanitize($_POST['twilio_account_sid'] ?? ''),
            'twilio_auth_token' => sanitize($_POST['twilio_auth_token'] ?? ''),
            'twilio_phone_number' => sanitize($_POST['twilio_phone_number'] ?? ''),
            'smsgate_local_url' => sanitize($_POST['smsgate_local_url'] ?? ''),
            'smsgate_local_username' => sanitize($_POST['smsgate_local_username'] ?? 'sms'),
            'smsgate_local_password' => sanitize($_POST['smsgate_local_password'] ?? ''),
            'smsgate_public_url' => sanitize($_POST['smsgate_public_url'] ?? ''),
            'smsgate_cloud_url' => sanitize($_POST['smsgate_cloud_url'] ?? 'https://api.sms-gate.app'),
            'smsgate_cloud_token' => sanitize($_POST['smsgate_cloud_token'] ?? ''),
            'smsgate_device_id' => sanitize($_POST['smsgate_device_id'] ?? ''),
            'smsgate_cloud_username' => sanitize($_POST['smsgate_cloud_username'] ?? ''),
            'smsgate_cloud_password' => sanitize($_POST['smsgate_cloud_password'] ?? ''),
        ];
        
        if (empty($settings['site_name'])) {
            $errors[] = 'Store name cannot be empty.';
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                foreach ($settings as $key => $value) {
                    $stmt->execute([$key, $value, $value]);
                }
                $db->commit();
                $successMessage = 'System settings updated successfully!';
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to update settings: ' . $e->getMessage();
            }
        }
    }
}

$settings = [
    'site_name' => getSetting('site_name', SITE_NAME),
    'sms_enabled' => getSetting('sms_enabled', '0'),
    'sms_gateway_type' => getSetting('sms_gateway_type', 'semaphore'),
    'semaphore_api_key' => getSetting('semaphore_api_key', ''),
    'semaphore_sender_name' => getSetting('semaphore_sender_name', 'RepairShop'),
    'twilio_account_sid' => getSetting('twilio_account_sid', ''),
    'twilio_auth_token' => getSetting('twilio_auth_token', ''),
    'twilio_phone_number' => getSetting('twilio_phone_number', ''),
    'smsgate_local_url' => getSetting('smsgate_local_url', ''),
    'smsgate_local_username' => getSetting('smsgate_local_username', 'sms'),
    'smsgate_local_password' => getSetting('smsgate_local_password', ''),
    'smsgate_public_url' => getSetting('smsgate_public_url', ''),
    'smsgate_cloud_url' => getSetting('smsgate_cloud_url', 'https://api.sms-gate.app'),
    'smsgate_cloud_token' => getSetting('smsgate_cloud_token', ''),
    'smsgate_device_id' => getSetting('smsgate_device_id', ''),
    'smsgate_cloud_username' => getSetting('smsgate_cloud_username', ''),
    'smsgate_cloud_password' => getSetting('smsgate_cloud_password', ''),
];
?>

<style>

.admin-page-wrapper {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
    max-width: 800px;
    margin: 0 auto;
}

.page-header-modern {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
}
.page-header-modern h1 {
    margin: 0; font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);
    font-size: 28px; display: flex; align-items: center; gap: 12px;
}

.modern-admin-card {
    background: #fff; border-radius: 20px; box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 32px;
    padding: 32px;
}

.form-group { margin-bottom: 24px; }
.form-label { display: block; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; font-size: 14px; }
.form-input {
    width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 12px;
    font-size: 15px; color: var(--text-dark); transition: all 0.2s; background: #f8fafc;
}
.form-input:focus { outline: none; border-color: var(--accent-indigo); background: #fff; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

.btn-save {
    background: var(--accent-indigo); color: #fff; border: none; padding: 12px 24px;
    border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
}
.btn-save:hover { background: #4338ca; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2); }

.alert { padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; gap: 12px; }
.alert-success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.alert-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
</style>

<div class="admin-page-wrapper">
    <div class="page-header-modern">
        <h1><i class="fas fa-cogs" style="color: var(--accent-indigo);"></i> System Settings</h1>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $successMessage ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <?php foreach ($errors as $error): ?>
                    <p style="margin:0"><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <style>
    .settings-tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 2px; }
    .tab-btn {
        background: none; border: none; padding: 12px 24px; font-weight: 700; font-size: 14px;
        color: var(--text-muted); cursor: pointer; position: relative; transition: all 0.2s;
    }
    .tab-btn.active { color: var(--accent-indigo); }
    .tab-btn.active::after {
        content: ''; position: absolute; bottom: -2px; left: 0; right: 0; height: 3px;
        background: var(--accent-indigo); border-radius: 3px;
    }
    .settings-panel { display: none; }
    .settings-panel.active { display: block; animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    .gateway-config { display: none; padding: 20px; background: #f8fafc; border-radius: 12px; margin-top: 16px; border: 1px solid #e2e8f0; }
    .gateway-config.active { display: block; }

    .toggle-switch { display: flex; align-items: center; gap: 12px; cursor: pointer; }
    .toggle-switch input { display: none; }
    .slider {
        width: 44px; height: 24px; background: #CBD5E1; border-radius: 12px;
        position: relative; transition: all 0.3s;
    }
    .slider::before {
        content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px;
        background: #fff; border-radius: 50%; transition: all 0.3s;
    }
    input:checked + .slider { background: var(--accent-indigo); }
    input:checked + .slider::before { transform: translateX(20px); }
    </style>

    <div class="settings-tabs">
        <button class="tab-btn active" onclick="switchTab('general')">General Settings</button>
        <button class="tab-btn" onclick="switchTab('sms')">SMS Notifications</button>
    </div>

    <div class="modern-admin-card">
        <form method="POST">
            <?= csrfField() ?>
            
            
            <div id="general-panel" class="settings-panel active">
                <div class="form-group">
                    <label class="form-label" for="site_name">Store Name / System Title</label>
                    <input type="text" name="site_name" id="site_name" class="form-input" 
                           value="<?= htmlspecialchars($settings['site_name']) ?>" 
                           placeholder="e.g. RepairShop Management System">
                    <p style="margin: 8px 0 0; font-size: 13px; color: var(--text-muted);">
                        This name will appear in the sidebar, browser title, and reports.
                    </p>
                </div>
            </div>

            
            <div id="sms-panel" class="settings-panel">
                <div class="form-group">
                    <label class="toggle-switch">
                        <input type="checkbox" name="sms_enabled" value="1" <?= $settings['sms_enabled'] == '1' ? 'checked' : '' ?>>
                        <span class="slider"></span>
                        <span class="form-label" style="margin:0">Enable SMS Notifications</span>
                    </label>
                    <p style="margin: 8px 0 0; font-size: 13px; color: var(--text-muted);">
                        If enabled, the system will send SMS updates to customers when repair status changes.
                    </p>
                </div>

                <div class="form-group" style="margin-top: 32px;">
                    <label class="form-label" for="sms_gateway_type">SMS Gateway Provider</label>
                    <select name="sms_gateway_type" id="sms_gateway_type" class="form-input" onchange="switchGateway(this.value)">
                        <option value="semaphore" <?= $settings['sms_gateway_type'] == 'semaphore' ? 'selected' : '' ?>>Semaphore (Philippines)</option>
                        <option value="twilio" <?= $settings['sms_gateway_type'] == 'twilio' ? 'selected' : '' ?>>Twilio (International)</option>
                        <option value="smsgate" <?= $settings['sms_gateway_type'] == 'smsgate' ? 'selected' : '' ?>>SMS Gate (Cloud/Android)</option>
                    </select>
                </div>

                
                <div id="config-semaphore" class="gateway-config <?= $settings['sms_gateway_type'] == 'semaphore' ? 'active' : '' ?>">
                    <div class="form-group">
                        <label class="form-label">Semaphore API Key</label>
                        <input type="password" name="semaphore_api_key" class="form-input" value="<?= htmlspecialchars($settings['semaphore_api_key']) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Sender Name</label>
                        <input type="text" name="semaphore_sender_name" class="form-input" value="<?= htmlspecialchars($settings['semaphore_sender_name']) ?>">
                    </div>
                </div>

                
                <div id="config-twilio" class="gateway-config <?= $settings['sms_gateway_type'] == 'twilio' ? 'active' : '' ?>">
                    <div class="form-group">
                        <label class="form-label">Account SID</label>
                        <input type="text" name="twilio_account_sid" class="form-input" value="<?= htmlspecialchars($settings['twilio_account_sid']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Auth Token</label>
                        <input type="password" name="twilio_auth_token" class="form-input" value="<?= htmlspecialchars($settings['twilio_auth_token']) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Twilio Phone Number</label>
                        <input type="text" name="twilio_phone_number" class="form-input" value="<?= htmlspecialchars($settings['twilio_phone_number']) ?>">
                    </div>
                </div>

                
                <div id="config-smsgate" class="gateway-config <?= $settings['sms_gateway_type'] == 'smsgate' ? 'active' : '' ?>">
                    <div style="background: #FFFBEB; border-left: 4px solid #F59E0B; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <p style="margin:0; font-size: 13px; color: #92400E; font-weight: 500;">
                            <i class="fas fa-info-circle"></i> SMS Gate Apps uses your Android phone to send SMS. Select your connection method below.
                        </p>
                    </div>

                    
                    <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                        <h4 style="margin: 0 0 12px; color: #166534; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-network-wired"></i> Local Service (Same Network)
                        </h4>
                        <div class="form-group">
                            <label class="form-label" style="color: #166534; font-size: 12px;">Local URL</label>
                            <input type="text" name="smsgate_local_url" class="form-input" value="<?= htmlspecialchars($settings['smsgate_local_url']) ?>" placeholder="http://192.168.x.x:8080">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" style="color: #166534; font-size: 12px;">Username</label>
                                <input type="text" name="smsgate_local_username" class="form-input" value="<?= htmlspecialchars($settings['smsgate_local_username']) ?>">
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" style="color: #166534; font-size: 12px;">Password</label>
                                <input type="password" name="smsgate_local_password" class="form-input" value="<?= htmlspecialchars($settings['smsgate_local_password']) ?>">
                            </div>
                        </div>
                    </div>

                    
                    <div style="background: #FFF7ED; border: 1px solid #FFEDD5; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                        <h4 style="margin: 0 0 12px; color: #9A3412; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-globe"></i> Public Service (Home Public IP)
                        </h4>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" style="color: #9A3412; font-size: 12px;">Public URL</label>
                            <input type="text" name="smsgate_public_url" class="form-input" value="<?= htmlspecialchars($settings['smsgate_public_url']) ?>" placeholder="http://your-ip:8080">
                        </div>
                    </div>

                    
                    <div style="background: #EFF6FF; border: 1px solid #DBEAFE; border-radius: 12px; padding: 16px;">
                        <h4 style="margin: 0 0 12px; color: #1E40AF; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-cloud"></i> Cloud Server (Works Anywhere)
                        </h4>
                        <div class="form-group">
                            <label class="form-label" style="color: #1E40AF; font-size: 12px;">Cloud API URL</label>
                            <input type="text" name="smsgate_cloud_url" class="form-input" value="<?= htmlspecialchars($settings['smsgate_cloud_url']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="color: #1E40AF; font-size: 12px;">Private Token (Bearer)</label>
                            <input type="password" name="smsgate_cloud_token" class="form-input" value="<?= htmlspecialchars($settings['smsgate_cloud_token']) ?>" placeholder="Enter Bearer Token">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="color: #1E40AF; font-size: 12px;">Device ID</label>
                            <input type="text" name="smsgate_device_id" class="form-input" value="<?= htmlspecialchars($settings['smsgate_device_id']) ?>" placeholder="e.g. android_xxxx">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" style="color: #1E40AF; font-size: 12px;">Username (Legacy Basic)</label>
                                <input type="text" name="smsgate_cloud_username" class="form-input" value="<?= htmlspecialchars($settings['smsgate_cloud_username']) ?>">
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" style="color: #1E40AF; font-size: 12px;">Password (Legacy Basic)</label>
                                <input type="password" name="smsgate_cloud_password" class="form-input" value="<?= htmlspecialchars($settings['smsgate_cloud_password']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn-save" style="background: var(--text-muted);" onclick="testSMS()">
                    <i class="fas fa-paper-plane"></i> Test SMS
                </button>
                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save System Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.settings-panel').forEach(panel => panel.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(tab + '-panel').classList.add('active');
}

function switchGateway(type) {
    document.querySelectorAll('.gateway-config').forEach(config => config.classList.remove('active'));
    document.getElementById('config-' + type).classList.add('active');
}

async function testSMS() {
    let phone = prompt('Enter recipient phone number (e.g. 09123456789):');
    if (!phone) return;
    
    phone = phone.trim().replace(/[\s-]/g, '');
    const cleanDigits = phone.replace(/[^0-9]/g, '');
    if (phone.startsWith('09') && cleanDigits.length !== 11) {
        alert('Invalid mobile number: You entered ' + cleanDigits.length + ' digits (' + phone + '). Philippine mobile numbers must be exactly 11 digits (e.g. 09123456789).');
        return;
    }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    try {
        const form = document.querySelector('form');
        const formData = new FormData(form);
        const config = {};
        formData.forEach((value, key) => config[key] = value);
        
        const testData = new FormData();
        testData.append('phone', phone);
        testData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
        testData.append('gateway_config', JSON.stringify(config));
        
        const response = await fetch('ajax-test-sms.php', {
            method: 'POST',
            body: testData
        });
        
        const text = await response.text();
        try {
            const data = JSON.parse(text);
            alert(data.message);
        } catch (parseError) {
            console.error('Response Text:', text);
            alert('Server Error: ' + text.substring(0, 200));
        }
    } catch (e) {
        alert('Connection error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
