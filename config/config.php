<?php

define('APP_ENV', 'development');   

define('DB_HOST', 'localhost');
define('DB_NAME', 'repair_shop_db');
define('DB_USER', 'root');           
define('DB_PASS', '');               

define('SITE_NAME', 'Repair Shop Services');
define('SITE_URL', 'http://localhost/repair_shop');   

define('SESSION_LIFETIME', 3600);  

define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);  

date_default_timezone_set('Asia/Manila');

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/php_error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    $path          = parse_url(SITE_URL, PHP_URL_PATH) ?: '/';
    $isHttps       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                     || APP_ENV === 'production';   

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => $path,
        'domain'   => '',
        'secure'   => $isHttps,   
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn     = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                
                error_log('DB connection error: ' . $e->getMessage());
                http_response_code(503);
                die('Service temporarily unavailable. Please try again later.');
            }
        }
        return self::$instance;
    }
}

function getDB(): PDO {
    return Database::getInstance();
}

function getSetting(string $key, $default = null) {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

require_once __DIR__ . '/../includes/functions.php';

try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key   VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    
    $defaultSettings = [
        'site_name'              => SITE_NAME,
        'sms_gateway_type'       => 'semaphore',
        'semaphore_api_key'      => '',
        'semaphore_sender_name'  => 'RepairShop',
        'twilio_account_sid'     => '',
        'twilio_auth_token'      => '',
        'twilio_phone_number'    => '',
        'smsgate_local_url'      => 'http://192.168.1.100:8080',
        'smsgate_local_username' => 'sms',
        'smsgate_local_password' => '',
        'smsgate_public_url'     => '',
        'smsgate_cloud_url'      => 'https://api.sms-gate.app',
        'smsgate_cloud_token'    => '',
        'smsgate_device_id'      => '',
        'smsgate_cloud_username' => '',
        'smsgate_cloud_password' => '',
        'sms_enabled'            => '0',
    ];

    $stmt = $db->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaultSettings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
} catch (Exception $e) {
    
    error_log('system_settings init error: ' . $e->getMessage());
}
