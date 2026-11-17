<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/SmsQueue.php';

$isCli = (php_sapi_name() === 'cli');
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost', '']);
$isAuthorizedUser = isLoggedIn();
$isSecretTrigger = (isset($_GET['key']) && $_GET['key'] === 'repair_sms_queue_key');

if (!$isCli && !$isLocal && !$isAuthorizedUser && !$isSecretTrigger) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

session_write_close();

$lockDir  = defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows'
    ? 'C:\\xampp\\tmp'
    : sys_get_temp_dir();
$lockFile = $lockDir . DIRECTORY_SEPARATOR . 'sms_process.lock';
$fp = fopen($lockFile, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['success' => true, 'message' => 'Another process is already running.']);
    exit;
}

set_time_limit(60);

header('Content-Type: application/json');

try {
    $db = getDB();
    $queue = new SmsQueue($db);
    $results = $queue->process(10); 

    echo json_encode([
        'success' => true,
        'processed_count' => count($results),
        'results' => $results
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
