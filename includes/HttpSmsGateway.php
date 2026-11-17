<?php

class HttpSmsGateway {
    private $db;
    private $gateway_type;
    
    
    private $smsgate_local_url;
    private $smsgate_local_username;
    private $smsgate_local_password;
    private $smsgate_public_url;
    private $smsgate_cloud_url;
    private $smsgate_cloud_token; 
    private $smsgate_device_id;
    private $smsgate_cloud_username;
    private $smsgate_cloud_password;
    
    
    private $semaphore_api_key;
    private $semaphore_sender;
    
    
    private $twilio_sid;
    private $twilio_token;
    private $twilio_phone;

    private $sms_enabled;
    
    public function __construct(PDO $db_connection) {
        $this->db = $db_connection;
        $this->loadSettings();
    }
    
    
    public function setConfiguration(array $config) {
        if (isset($config['sms_gateway_type'])) $this->gateway_type = $config['sms_gateway_type'];
        if (isset($config['sms_enabled'])) $this->sms_enabled = (bool)$config['sms_enabled'];
        
        
        if (isset($config['smsgate_local_url'])) $this->smsgate_local_url = $config['smsgate_local_url'];
        if (isset($config['smsgate_local_username'])) $this->smsgate_local_username = $config['smsgate_local_username'];
        if (isset($config['smsgate_local_password'])) $this->smsgate_local_password = $config['smsgate_local_password'];
        if (isset($config['smsgate_public_url'])) $this->smsgate_public_url = $config['smsgate_public_url'];
        if (isset($config['smsgate_cloud_url'])) $this->smsgate_cloud_url = $config['smsgate_cloud_url'];
        if (isset($config['smsgate_cloud_token'])) $this->smsgate_cloud_token = $config['smsgate_cloud_token'];
        if (isset($config['smsgate_device_id'])) $this->smsgate_device_id = $config['smsgate_device_id'];
        if (isset($config['smsgate_cloud_username'])) $this->smsgate_cloud_username = $config['smsgate_cloud_username'];
        if (isset($config['smsgate_cloud_password'])) $this->smsgate_cloud_password = $config['smsgate_cloud_password'];
        
        
        if (isset($config['semaphore_api_key'])) $this->semaphore_api_key = $config['semaphore_api_key'];
        if (isset($config['semaphore_sender_name'])) $this->semaphore_sender = $config['semaphore_sender_name'];
        
        
        if (isset($config['twilio_account_sid'])) $this->twilio_sid = $config['twilio_account_sid'];
        if (isset($config['twilio_auth_token'])) $this->twilio_token = $config['twilio_auth_token'];
        if (isset($config['twilio_phone_number'])) $this->twilio_phone = $config['twilio_phone_number'];
    }

    
    private function loadSettings() {
        $this->gateway_type = getSetting('sms_gateway_type', 'semaphore');
        $this->sms_enabled = (bool)getSetting('sms_enabled', '0');
        
        
        $this->semaphore_api_key = getSetting('semaphore_api_key', '');
        $this->semaphore_sender = getSetting('semaphore_sender_name', 'RepairShop');
        
        
        $this->twilio_sid = getSetting('twilio_account_sid', '');
        $this->twilio_token = getSetting('twilio_auth_token', '');
        $this->twilio_phone = getSetting('twilio_phone_number', '');
        
        
        $this->smsgate_local_url = getSetting('smsgate_local_url', '');
        $this->smsgate_local_username = getSetting('smsgate_local_username', 'sms');
        $this->smsgate_local_password = getSetting('smsgate_local_password', '');
        $this->smsgate_public_url = getSetting('smsgate_public_url', '');
        $this->smsgate_cloud_url = getSetting('smsgate_cloud_url', 'https://api.sms-gate.app');
        $this->smsgate_cloud_token = getSetting('smsgate_cloud_token', '');
        $this->smsgate_device_id = getSetting('smsgate_device_id', '');
        $this->smsgate_cloud_username = getSetting('smsgate_cloud_username', '');
        $this->smsgate_cloud_password = getSetting('smsgate_cloud_password', '');
    }
    
    
    public function sendSMS($to, $message) {
        if (!$this->sms_enabled) {
            return ['success' => false, 'message' => 'SMS notifications are disabled in settings.'];
        }

        if (!$this->isValidPhoneNumber($to)) {
            return [
                'success' => false,
                'message' => 'Invalid phone number: "' . htmlspecialchars($to) . '". Philippine mobile numbers must be 11 digits (e.g. 09123456789 or +639123456789).'
            ];
        }

        switch($this->gateway_type) {
            case 'semaphore':
                return $this->sendViaSemaphore($to, $message);
            case 'twilio':
                return $this->sendViaTwilio($to, $message);
            case 'smsgate':
                
                return $this->sendViaSMSGate($to, $message);
            default:
                return $this->sendViaSemaphore($to, $message);
        }
    }

    
    private function sendViaSMSGate($to, $message) {
        $phone = $this->formatPhoneNumber($to);
        $errors = [];
        
        
        if (!empty($this->smsgate_local_url)) {
            $result = $this->sendViaSMSGateLocal($phone, $message);
            if ($result['success']) return $result;
            $errors[] = "Local: " . ($result['message'] ?? 'Failed');
        }
        
        
        if (!empty($this->smsgate_public_url)) {
            $result = $this->sendViaSMSGatePublic($phone, $message);
            if ($result['success']) return $result;
            $errors[] = "Public: " . ($result['message'] ?? 'Failed');
        }
        
        
        $hasCloudCreds = !empty($this->smsgate_cloud_token) || (!empty($this->smsgate_cloud_username) && !empty($this->smsgate_cloud_password));
        if ($hasCloudCreds) {
            $result = $this->sendViaSMSGateCloud($phone, $message);
            if ($result['success']) return $result;
            $errors[] = "Cloud: " . ($result['message'] ?? 'Failed');
        }
        
        $this->logSMS($to, $message, 'failed', null, implode('; ', $errors));
        return ['success' => false, 'message' => 'All SMS Gate methods failed: ' . implode('; ', $errors)];
    }
    
    
    private function sendViaSemaphore($to, $message) {
        if (empty($this->semaphore_api_key)) {
            return ['success' => false, 'message' => 'Semaphore API key not configured'];
        }
        
        $url = 'https://api.semaphore.co/api/v4/messages';
        $data = [
            'apikey' => $this->semaphore_api_key,
            'number' => $this->formatPhoneNumber($to),
            'message' => $message,
            'sendername' => $this->semaphore_sender
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result[0]['status']) && $result[0]['status'] === 'Pending') {
                $this->logSMS($to, $message, 'sent', $result[0]['message_id'] ?? uniqid());
                return ['success' => true, 'message' => 'SMS sent via Semaphore', 'response' => $result];
            }
        }
        
        $this->logSMS($to, $message, 'failed', null, $response);
        return ['success' => false, 'message' => 'Semaphore API error', 'response' => $response];
    }
    
    
    private function sendViaTwilio($to, $message) {
        if (empty($this->twilio_sid) || empty($this->twilio_token)) {
            return ['success' => false, 'message' => 'Twilio credentials not configured'];
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilio_sid}/Messages.json";
        $data = [
            'From' => $this->twilio_phone,
            'To' => $this->formatPhoneNumber($to),
            'Body' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->twilio_sid . ':' . $this->twilio_token);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            $result = json_decode($response, true);
            $this->logSMS($to, $message, 'sent', $result['sid'] ?? uniqid());
            return ['success' => true, 'message' => 'SMS sent via Twilio', 'response' => $result];
        }
        
        $this->logSMS($to, $message, 'failed', null, $response);
        return ['success' => false, 'message' => 'Twilio API error', 'response' => $response];
    }

    
    private function sendViaSMSGateLocal($phone, $message) {
        $url = rtrim($this->smsgate_local_url, '/') . '/message';
        $data = ['message' => $message, 'phoneNumbers' => [$phone]];
        return $this->curlSMSGate($url, $data, $this->smsgate_local_username, $this->smsgate_local_password, 'local');
    }

    
    private function sendViaSMSGatePublic($phone, $message) {
        $url = rtrim($this->smsgate_public_url, '/') . '/message';
        $data = ['message' => $message, 'phoneNumbers' => [$phone]];
        return $this->curlSMSGate($url, $data, $this->smsgate_local_username, $this->smsgate_local_password, 'public');
    }
    
    
    private function sendViaSMSGateCloud($phone, $message) {
        $baseUrl = rtrim($this->smsgate_cloud_url, '/');
        
        $baseUrl = preg_replace('#/(mobile|3rdparty)/v\d+.*$#', '', $baseUrl);
        $url = $baseUrl . '/3rdparty/v1/messages';
        
        $data = [
            'textMessage' => ['text' => $message],
            'phoneNumbers' => [$phone]
        ];

        $authType = !empty($this->smsgate_cloud_token) ? 'bearer' : 'basic';
        return $this->curlSMSGate($url, $data, $this->smsgate_cloud_username, $this->smsgate_cloud_password, 'cloud', $authType, $this->smsgate_cloud_token);
    }

    
    private function curlSMSGate($url, $data, $user, $pass, $type, $authType = 'basic', $token = '') {
        
        if (!empty($this->smsgate_device_id)) {
            $data['deviceId'] = $this->smsgate_device_id;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        
        $connectTimeout = ($type === 'cloud') ? 5 : 2;
        $totalTimeout = ($type === 'cloud') ? 15 : 3;
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $totalTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = ['Content-Type: application/json'];
        if ($authType === 'bearer' && !empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        } else if (!empty($user)) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            
            $logMessage = isset($data['textMessage']['text']) ? $data['textMessage']['text'] : ($data['message'] ?? '');
            $this->logSMS($data['phoneNumbers'][0], $logMessage, 'sent', $result['id'] ?? uniqid(), $type);
            return ['success' => true, 'message' => "SMS sent via SMS Gate $type", 'server' => $type];
        }
        
        return ['success' => false, 'message' => "HTTP $httpCode - " . ($error ?: $response)];
    }

    
    public function getPhoneStatus() {
        if ($this->gateway_type !== 'smsgate') {
            return ['success' => true, 'online' => true, 'message' => 'Gateway active'];
        }

        $status = ['success' => true, 'online' => false, 'methods' => []];
        
        
        if (!empty($this->smsgate_local_url)) {
            $ch = curl_init(rtrim($this->smsgate_local_url, '/') . '/status');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); 
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $status['methods']['local'] = ($code >= 200 && $code < 400);
        }

        
        if (!empty($this->smsgate_cloud_url)) {
            $baseUrl = rtrim($this->smsgate_cloud_url, '/');
            $baseUrl = preg_replace('#/(mobile|3rdparty)/v\d+.*$#', '', $baseUrl);
            
            
            $url = $baseUrl . '/3rdparty/v1/devices';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            
            if (!empty($this->smsgate_cloud_token)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->smsgate_cloud_token]);
            } else if (!empty($this->smsgate_cloud_username)) {
                curl_setopt($ch, CURLOPT_USERPWD, "$this->smsgate_cloud_username:$this->smsgate_cloud_password");
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            }

            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code >= 200 && $code < 300) {
                $devices = json_decode($response, true);
                if (is_array($devices)) {
                    if (!empty($this->smsgate_device_id)) {
                        $found = false;
                        foreach ($devices as $dev) {
                            if (isset($dev['id']) && $dev['id'] === $this->smsgate_device_id) {
                                $found = true;
                                break;
                            }
                        }
                        $status['methods']['cloud'] = $found;
                    } else {
                        $status['methods']['cloud'] = !empty($devices);
                    }
                } else {
                    $status['methods']['cloud'] = true;
                }
            } else {
                
                $hCh = curl_init($baseUrl . '/3rdparty/v1/health');
                curl_setopt($hCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($hCh, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($hCh, CURLOPT_TIMEOUT, 4);
                curl_setopt($hCh, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($hCh);
                $hCode = curl_getinfo($hCh, CURLINFO_HTTP_CODE);
                curl_close($hCh);
                $status['methods']['cloud'] = ($hCode >= 200 && $hCode < 300);
            }
        }

        $status['online'] = !empty(array_filter($status['methods']));
        return $status;
    }

    
    public function getStatistics() {
        $stats = [
            'sent_today' => 0,
            'failed_today' => 0,
            'sent_this_month' => 0,
            'total_sent' => 0
        ];
        
        try {
            
            $stmt = $this->db->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
            $stats['sent_today'] = $stmt->fetchColumn();
            
            
            $stmt = $this->db->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'failed' AND DATE(sent_at) = CURDATE()");
            $stats['failed_today'] = $stmt->fetchColumn();
            
            
            $stmt = $this->db->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'sent' AND MONTH(sent_at) = MONTH(CURDATE()) AND YEAR(sent_at) = YEAR(CURDATE())");
            $stats['sent_this_month'] = $stmt->fetchColumn();
            
            
            $stmt = $this->db->query("SELECT COUNT(*) FROM sms_logs WHERE status = 'sent'");
            $stats['total_sent'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            
        }
        
        return $stats;
    }

    
    public function getSMSLogs($limit = 100) {
        $this->createTableIfNotExists();
        $stmt = $this->db->prepare("SELECT * FROM sms_logs ORDER BY sent_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    
    public function isValidPhoneNumber($phone) {
        $clean = preg_replace('/[^0-9+]/', '', $phone);
        
        if (preg_match('/^(\+?639\d{9}|09\d{9}|9\d{9})$/', $clean)) {
            return true;
        }
        
        return (bool)preg_match('/^\+[1-9]\d{9,14}$/', $clean);
    }

    
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (substr($phone, 0, 1) === '0' && strlen($phone) === 11) {
            $phone = '+63' . substr($phone, 1);
        } else if (substr($phone, 0, 2) === '63' && substr($phone, 0, 1) !== '+' && strlen($phone) === 12) {
            $phone = '+' . $phone;
        } else if (substr($phone, 0, 3) !== '+63' && strlen($phone) === 10 && substr($phone, 0, 1) === '9') {
            $phone = '+63' . $phone;
        }
        
        return $phone;
    }
    
    
    private function logSMS($to, $message, $status, $message_id = null, $error = null) {
        $this->createTableIfNotExists();
        
        $from = 'System';  
        
        $query = "INSERT INTO sms_logs (from_number, to_number, message, status, message_id, error_message, sent_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->db->prepare($query);
        
        if ($stmt) {
            $stmt->execute([$from, $to, $message, $status, $message_id, $error]);
        }
    }
    
    
    private function createTableIfNotExists() {
        if ($this->db->inTransaction()) {
            return;
        }
        $query = "CREATE TABLE IF NOT EXISTS `sms_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `from_number` VARCHAR(50) NOT NULL,
            `to_number` VARCHAR(20) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
            `message_id` VARCHAR(100) DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `to_number` (`to_number`),
            KEY `status` (`status`),
            KEY `sent_at` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->db->exec($query);
    }
}
?>
