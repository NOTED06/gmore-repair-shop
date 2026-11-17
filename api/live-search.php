<?php

require_once '../config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['products' => [], 'repairs' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode(['products' => [], 'repairs' => []]);
    exit;
}

$db = getDB();
$term = "%" . escapeLike($q) . "%";
$role = $_SESSION['role'] ?? '';

$stmt = $db->prepare("
    SELECT p.product_id, p.product_name, p.price, p.quantity, p.status, p.image,
           pc.category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id
    WHERE p.product_name LIKE ? OR p.description LIKE ? OR pc.category_name LIKE ?
    ORDER BY p.status ASC, p.product_name ASC
    LIMIT 6
");
$stmt->execute([$term, $term, $term]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as &$p) {
    $p['price_formatted'] = '₱' . number_format($p['price'], 2);
    $p['image_url'] = $p['image'] ? SITE_URL . '/' . $p['image'] : '';
}
unset($p);

$repairs = [];
if ($role === 'Customer') {
    $stmt = $db->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $customerId = $stmt->fetchColumn();

    if ($customerId) {
        $stmt = $db->prepare("
            SELECT r.repair_id, r.appliance_type, r.brand, r.model, r.repair_status,
                   r.date_requested
            FROM repairs r
            WHERE r.customer_id = ?
              AND (r.appliance_type LIKE ? OR r.brand LIKE ? OR r.model LIKE ?)
            ORDER BY r.date_requested DESC
            LIMIT 4
        ");
        $stmt->execute([$customerId, $term, $term, $term]);
        $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($role === 'Admin') {
    $stmt = $db->prepare("
        SELECT r.repair_id, r.appliance_type, r.brand, r.model, r.repair_status,
               r.date_requested, c.full_name as customer_name
        FROM repairs r
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.appliance_type LIKE ? OR r.brand LIKE ? OR r.model LIKE ?
              OR c.full_name LIKE ?
        ORDER BY r.date_requested DESC
        LIMIT 4
    ");
    $stmt->execute([$term, $term, $term, $term]);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'Technician') {
    $stmt = $db->prepare("SELECT technician_id FROM technicians WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $techId = $stmt->fetchColumn();

    if ($techId) {
        $stmt = $db->prepare("
            SELECT r.repair_id, r.appliance_type, r.brand, r.model, r.repair_status,
                   r.date_requested
            FROM repairs r
            WHERE r.technician_id = ?
              AND (r.appliance_type LIKE ? OR r.brand LIKE ? OR r.model LIKE ?)
            ORDER BY r.date_requested DESC
            LIMIT 4
        ");
        $stmt->execute([$techId, $term, $term, $term]);
        $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

foreach ($repairs as &$r) {
    $r['date_formatted'] = date('M d, Y', strtotime($r['date_requested']));
}
unset($r);

$customers = [];
$technicians = [];
if ($role === 'Admin') {
    $stmt = $db->prepare("
        SELECT c.customer_id, c.full_name, c.contact_no,
               (SELECT COUNT(*) FROM repairs r WHERE r.customer_id = c.customer_id) as repair_count
        FROM customers c
        WHERE c.full_name LIKE ? OR c.contact_no LIKE ?
        ORDER BY c.full_name ASC
        LIMIT 4
    ");
    $stmt->execute([$term, $term]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT t.technician_id, t.full_name, t.specialization, t.availability
        FROM technicians t
        WHERE t.full_name LIKE ? OR t.specialization LIKE ?
        ORDER BY t.full_name ASC
        LIMIT 4
    ");
    $stmt->execute([$term, $term]);
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'products'    => $products,
    'repairs'     => $repairs,
    'customers'   => $customers,
    'technicians' => $technicians,
    'role'        => strtolower($role)
]);
