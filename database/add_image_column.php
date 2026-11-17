<?php
require_once '../config/config.php';

try {
    $db = getDB();
    $db->exec("ALTER TABLE repairs ADD COLUMN appliance_image VARCHAR(255) NULL AFTER serial_number");
    echo "Column 'appliance_image' added successfully!";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column 'appliance_image' already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
