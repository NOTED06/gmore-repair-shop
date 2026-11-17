<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="<?= getSetting('site_name', SITE_NAME) ?> — Customer Portal">
    <meta name="theme-color" content="#0A0E14">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' — ' . getSetting('site_name', SITE_NAME) : getSetting('site_name', SITE_NAME) ?></title>

    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fonts/fonts.css">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const USER_ROLE = '<?= $_SESSION['role'] ?? '' ?>';
    </script>

    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">

    
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/fontawesome/css/all.min.css">

    
    <script src="<?= SITE_URL ?>/assets/vendor/lucide/lucide.min.js" defer></script>

    
    <script src="<?= SITE_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>

    
    <script src="<?= SITE_URL ?>/assets/vendor/gsap/gsap.min.js" defer></script>
    
    <script src="<?= SITE_URL ?>/assets/vendor/qrcode/qrcode.min.js"></script>
</head>
