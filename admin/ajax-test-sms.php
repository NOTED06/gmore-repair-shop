<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/HttpSmsGateway.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $phone = sanitize($_POST['phone'] ?? '');
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
        exit;
    }

    
    $sms = new HttpSmsGateway(getDB());
    
    
    if (isset($_POST['gateway_config'])) {
        $config = json_decode($_POST['gateway_config'], true);
        if (is_array($config)) {
            $sms->setConfiguration($config);
        }
    }

    $result = $sms->sendSMS($phone, "RepairShop Test SMS: If you received this, your SMS gateway configuration is working correctly!");

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
