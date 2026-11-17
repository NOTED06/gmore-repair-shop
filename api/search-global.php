<?php

require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] !== 'Customer') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$userId]);
$customerId = $stmt->fetchColumn();

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 1) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$results = [];

$stmt = $db->prepare("
    SELECT product_id, product_name AS title, description AS subtitle, price, image, 'product' AS type
    FROM products
    WHERE product_name LIKE ? OR description LIKE ?
    LIMIT 5
");
$like = '%' . $query . '%';
$stmt->execute([$like, $like]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $p) {
    $results[] = [
        'id' => $p['product_id'],
        'title' => $p['title'],
        'subtitle' => '₱' . number_format($p['price'], 2),
        'type' => 'product',
        'url' => SITE_URL . '/customer/dashboard.php?open_product=' . $p['product_id']
    ];
}

if ($customerId) {
    $stmt = $db->prepare("
        SELECT repair_id, appliance_type AS title, brand, model, 'repair' AS type
        FROM repairs
        WHERE customer_id = ? AND (appliance_type LIKE ? OR brand LIKE ? OR model LIKE ?)
        LIMIT 5
    ");
    $stmt->execute([$customerId, $like, $like, $like]);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($repairs as $r) {
        $brandModel = trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? ''));
        $results[] = [
            'id' => $r['repair_id'],
            'title' => 'Repair: ' . $r['title'],
            'subtitle' => $brandModel ?: 'Repair Ticket',
            'type' => 'repair',
            'url' => SITE_URL . '/customer/repair-detail.php?id=' . $r['repair_id']
        ];
    }
}

echo json_encode(['success' => true, 'results' => $results]);
