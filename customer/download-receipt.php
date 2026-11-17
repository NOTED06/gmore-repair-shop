<?php

require_once __DIR__ . '/../config/config.php';
requireLogin();

$repairId = (int)($_GET['id'] ?? 0);
$format = sanitize($_GET['format'] ?? '');
$isImage = ($format === 'image' || (isset($_GET['image']) && $_GET['image'] == '1'));

if ($repairId > 0) {
    if ($isImage) {
        header("Location: receipt.php?id=" . $repairId . "&image=1");
    } else {
        header("Location: receipt.php?id=" . $repairId . "&download=1");
    }
    exit;
} else {
    header("Location: repairs.php");
    exit;
}
