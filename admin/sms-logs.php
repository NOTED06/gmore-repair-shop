<?php

require_once '../config/config.php';
require_once '../includes/HttpSmsGateway.php';
$pageTitle = 'SMS Management';
require_once '../includes/header.php';
requireRole('Admin');

$db = getDB();
$smsGateway = new HttpSmsGateway($db);
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_manual_sms'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        $to = sanitize($_POST['to_number'] ?? '');
        $message = sanitize($_POST['message'] ?? '');
        
        if (empty($to) || empty($message)) {
            $errors[] = 'Phone number and message are required.';
        } else {
            require_once '../includes/SmsQueue.php';
            $smsQueue = new SmsQueue($db);
            $queued = $smsQueue->add($to, $message);
            if ($queued) {
                setFlashMessage('SMS queued and sending in background!', 'success');
                header("Location: sms-logs.php");
                exit;
            } else {
                $errors[] = 'Failed to queue SMS. Please try again.';
            }
        }
    }
}

session_write_close();

$stats = $smsGateway->getStatistics();
$logs = $smsGateway->getSMSLogs(50);
?>

<style>

.sms-page-wrapper {
    --card-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    --border-color: #E2E8F0;
    --text-dark: #0F172A;
    --text-muted: #64748B;
    --accent-indigo: #4F46E5;
    font-family: 'Inter', system-ui, sans-serif;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: #fff;
    border-radius: 20px;
    padding: 24px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-info h3 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-dark); }
.stat-info p { margin: 0; font-size: 14px; color: var(--text-muted); font-weight: 500; }

.modern-card {
    background: #fff;
    border-radius: 24px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 32px;
}

.card-header-pro {
    padding: 24px 32px;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header-pro h2 {
    margin: 0; font-size: 18px; font-weight: 700; color: var(--text-dark);
    display: flex; align-items: center; gap: 12px;
}

.table-modern {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table-modern th {
    padding: 16px 32px;
    text-align: left;
    background: #f8fafc;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    font-weight: 700;
    border-bottom: 1px solid var(--border-color);
}

.table-modern td {
    padding: 16px 32px;
    font-size: 14px;
    color: var(--text-dark);
    border-bottom: 1px solid var(--border-color);
}

.badge {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
}

.badge-sent { background: #dcfce7; color: #166534; }
.badge-failed { background: #fee2e2; color: #991b1b; }
.badge-pending { background: #fef9c3; color: #854d0e; }

.btn-config {
    background: var(--accent-indigo);
    color: #fff;
    padding: 10px 20px;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.btn-config:hover {
    background: #4338ca;
    transform: translateY(-1px);
}
</style>

<div class="sms-page-wrapper">
    <div class="page-header" style="margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center;">
        <h1 style="font-family: 'Syne', sans-serif; font-weight: 800; color: var(--text-dark);">
            <i class="fas fa-sms" style="color: var(--accent-indigo); margin-right: 12px;"></i> SMS Delivery Logs
        </h1>
        <a href="system-settings.php" class="btn-config">
            <i class="fas fa-cog"></i> Gateway Settings
        </a>
    </div>

    
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 32px; margin-bottom: 32px; align-items: start;">
        
        
        <?php $phoneStatus = $smsGateway->getPhoneStatus(); ?>
        <div class="stats-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 0;">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0f2fe; color: #0369a1;"><i class="fas fa-paper-plane"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['sent_today']) ?></h3>
                    <p>Sent Today</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fee2e2; color: #b91c1c;"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['failed_today']) ?></h3>
                    <p>Failed Today</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #dcfce7; color: #15803d;"><i class="fas fa-chart-line"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['sent_this_month']) ?></h3>
                    <p>Sent This Month</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: <?= $phoneStatus['online'] ? '#dcfce7' : '#fee2e2' ?>; color: <?= $phoneStatus['online'] ? '#15803d' : '#b91c1c' ?>;">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $phoneStatus['online'] ? 'Online' : 'Offline' ?></h3>
                    <p>Gateway Status</p>
                </div>
            </div>
        </div>

        
        <div class="modern-card" style="margin-bottom: 0;">
            <div class="card-header-pro" style="padding: 16px 24px;">
                <h2 style="font-size: 16px;"><i class="fas fa-paper-plane"></i> Quick Message</h2>
            </div>
            <div style="padding: 24px;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">Recipient Number</label>
                        <input type="text" name="to_number" class="form-input" placeholder="0917xxxxxxx" required style="padding: 10px; font-size: 14px;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 12px;">Message</label>
                        <textarea name="message" class="form-input" rows="3" placeholder="Enter message..." required style="padding: 10px; font-size: 14px; resize: none;"></textarea>
                    </div>
                    <button type="submit" name="send_manual_sms" class="btn-config" style="width: 100%; justify-content: center; padding: 12px;">
                        <i class="fas fa-paper-plane"></i> Send Now
                    </button>
                </form>
            </div>
        </div>
    </div>

    
    <div class="modern-card">
        <div class="card-header-pro">
            <h2><i class="fas fa-list-ul"></i> Recent Deliveries</h2>
            <button onclick="window.location.reload()" class="btn-config" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>To Number</th>
                        <th>Message</th>
                        <th>Gateway</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 48px; color: var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size: 32px; display: block; margin-bottom: 12px;"></i>
                                No SMS logs found yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= date('h:i A', strtotime($log['sent_at'])) ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= date('M d, Y', strtotime($log['sent_at'])) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($log['to_number']) ?></div>
                                </td>
                                <td>
                                    <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($log['message']) ?>">
                                        <?= htmlspecialchars($log['message']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($log['from_number']) ?></span>
                                </td>
                                <td>
                                    <?php if ($log['status'] === 'sent'): ?>
                                        <span class="badge badge-sent"><i class="fas fa-check-circle"></i> Sent</span>
                                    <?php elseif ($log['status'] === 'failed'): ?>
                                        <span class="badge badge-failed" title="<?= htmlspecialchars($log['error_message'] ?? 'Unknown error') ?>">
                                            <i class="fas fa-times-circle"></i> Failed
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
