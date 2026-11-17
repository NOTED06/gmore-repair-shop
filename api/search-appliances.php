<?php

header('Content-Type: application/json');
require_once '../config/config.php';

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$query = escapeLike(trim($_GET['q'] ?? ''));
$type = $_GET['type'] ?? '';

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

try {
    if ($type === 'types') {
        
        $stmt = $db->prepare("
            SELECT DISTINCT appliance_type as value, appliance_type as label
            FROM appliances
            WHERE appliance_type LIKE ?
            ORDER BY appliance_type ASC
            LIMIT 10
        ");
        $stmt->execute(["%$query%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($type === 'brands') {
        
        $applianceType = $_GET['appliance_type'] ?? '';
        if ($applianceType) {
            $stmt = $db->prepare("
                SELECT DISTINCT brand as value, brand as label
                FROM appliances
                WHERE appliance_type = ? AND brand LIKE ?
                ORDER BY brand ASC
                LIMIT 10
            ");
            $stmt->execute([$applianceType, "%$query%"]);
        } else {
            $stmt = $db->prepare("
                SELECT DISTINCT brand as value, brand as label
                FROM appliances
                WHERE brand LIKE ?
                ORDER BY brand ASC
                LIMIT 10
            ");
            $stmt->execute(["%$query%"]);
        }
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($type === 'models') {
        
        $applianceType = $_GET['appliance_type'] ?? '';
        $brand = $_GET['brand'] ?? '';
        
        if ($applianceType && $brand) {
            $stmt = $db->prepare("
                SELECT appliance_id, model as value, model as label, description
                FROM appliances
                WHERE appliance_type = ? AND brand = ? AND model LIKE ?
                ORDER BY model ASC
                LIMIT 10
            ");
            $stmt->execute([$applianceType, $brand, "%$query%"]);
        } elseif ($brand) {
            $stmt = $db->prepare("
                SELECT appliance_id, model as value, model as label, description
                FROM appliances
                WHERE brand = ? AND model LIKE ?
                ORDER BY model ASC
                LIMIT 10
            ");
            $stmt->execute([$brand, "%$query%"]);
        } else {
            $stmt = $db->prepare("
                SELECT appliance_id, model as value, model as label, description
                FROM appliances
                WHERE model LIKE ?
                ORDER BY model ASC
                LIMIT 10
            ");
            $stmt->execute(["%$query%"]);
        }
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        
        $stmt = $db->prepare("
            SELECT appliance_id, 
                   CONCAT(appliance_type, ' - ', brand, ' ', model) as label,
                   appliance_type, brand, model, description
            FROM appliances
            WHERE appliance_type LIKE ? OR brand LIKE ? OR model LIKE ?
            ORDER BY appliance_type, brand, model
            LIMIT 20
        ");
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($results);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
