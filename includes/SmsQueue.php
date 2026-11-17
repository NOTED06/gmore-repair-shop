<?php

require_once __DIR__ . '/HttpSmsGateway.php';

class SmsQueue {
    private $db;
    private $gateway;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->gateway = new HttpSmsGateway($db);
    }

    
    public function add($toNumber, $message) {
        $stmt = $this->db->prepare("INSERT INTO sms_queue (to_number, message, status) VALUES (?, ?, 'pending')");
        $success = $stmt->execute([$toNumber, $message]);

        if (!$success) {
            return false;
        }

        
        self::triggerBackgroundWorker();

        return true;
    }

    
    public static function triggerBackgroundWorker() {
        try {
            $scriptPath = realpath(__DIR__ . '/../api/process-sms-queue.php');
            if (!$scriptPath) {
                $scriptPath = __DIR__ . '/../api/process-sms-queue.php';
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $phpBin = 'C:\\xampp\\php\\php.exe';
                if (!file_exists($phpBin)) {
                    $phpBin = PHP_BINARY ?: 'php';
                }
                $cmd = 'start /B "" "' . $phpBin . '" "' . $scriptPath . '" > NUL 2>&1';
                @pclose(@popen($cmd, 'r'));
            } else {
                $phpBin = PHP_BINARY ?: 'php';
                $cmd = '"' . $phpBin . '" "' . $scriptPath . '" > /dev/null 2>&1 &';
                @exec($cmd);
            }
        } catch (Throwable $e) {
            error_log('Failed to launch background SMS worker: ' . $e->getMessage());
        }
    }

    
    public function process($limit = 10) {
        
        $stmt = $this->db->prepare("
            SELECT * FROM sms_queue 
            WHERE status = 'pending' 
               OR (status = 'failed' AND attempts < 3)
               OR (status = 'processing' AND last_attempt < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND attempts < 3)
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll();

        $results = [];
        foreach ($messages as $msg) {
            
            $updateStmt = $this->db->prepare("UPDATE sms_queue SET status = 'processing', attempts = attempts + 1, last_attempt = NOW() WHERE id = ?");
            $updateStmt->execute([$msg['id']]);

            
            try {
                $result = $this->gateway->sendSMS($msg['to_number'], $msg['message']);
            } catch (Throwable $e) {
                $result = ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
            }

            if ($result['success']) {
                $statusStmt = $this->db->prepare("UPDATE sms_queue SET status = 'sent', error_message = NULL WHERE id = ?");
                $statusStmt->execute([$msg['id']]);
                $results[] = ['id' => $msg['id'], 'status' => 'sent'];
            } else {
                $statusStmt = $this->db->prepare("UPDATE sms_queue SET status = 'failed', error_message = ? WHERE id = ?");
                $statusStmt->execute([$result['message'] ?? 'Send failed', $msg['id']]);
                $results[] = ['id' => $msg['id'], 'status' => 'failed', 'error' => $result['message'] ?? 'Send failed'];
            }
        }
        return $results;
    }
}
