<?php

class PayMongo {
    
    private $secret_key;
    private $public_key;
    private $api_base = 'https://api.paymongo.com/v1';
    
    public function __construct() {
        $this->loadApiKeys();
    }
    
    private function loadApiKeys() {
        
        if (function_exists('getSetting')) {
            $this->secret_key = getSetting('paymongo_secret_key') ?: '';
            $this->public_key = getSetting('paymongo_public_key') ?: '';
        }
        
        
        if (empty($this->secret_key)) {
            $this->secret_key = 'YOUR_PAYMONGO_SECRET_KEY';
        }
    }
    
    public function setApiKeys($secret_key, $public_key = '') {
        $this->secret_key = $secret_key;
        $this->public_key = $public_key;
    }
    
    public function createGcashSource($amount, $description, $success_url, $failed_url, $metadata = []) {
        return $this->createSource('gcash', $amount, $description, $success_url, $failed_url, $metadata);
    }
    
    public function createMayaSource($amount, $description, $success_url, $failed_url, $metadata = []) {
        return $this->createSource('grab_pay', $amount, $description, $success_url, $failed_url, $metadata);
    }
    
    public function createSource($type, $amount, $description, $success_url, $failed_url, $metadata = []) {
        $amount_centavos = (int) round($amount * 100);
        
        
        if ($amount_centavos < 10000) {
            return [
                'status' => 'failed',
                'msg' => 'Minimum amount for GCash payment is ₱100.00'
            ];
        }
        
        $flat_metadata = [];
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $flat_metadata[$key] = json_encode($value);
            } else {
                $flat_metadata[$key] = (string) $value;
            }
        }
        
        $data = [
            'data' => [
                'attributes' => [
                    'amount' => $amount_centavos,
                    'currency' => 'PHP',
                    'type' => $type,
                    'redirect' => [
                        'success' => $success_url,
                        'failed' => $failed_url
                    ]
                ]
            ]
        ];
        
        if (!empty($flat_metadata)) {
            $data['data']['attributes']['metadata'] = $flat_metadata;
        }
        
        $response = $this->makeRequest('POST', '/sources', $data);
        
        if (isset($response['data']['id'])) {
            return [
                'status' => 'success',
                'source_id' => $response['data']['id'],
                'checkout_url' => $response['data']['attributes']['redirect']['checkout_url'],
                'status_source' => $response['data']['attributes']['status'],
                'raw' => $response
            ];
        }
        
        $error_msg = 'Payment initialization failed';
        if (isset($response['errors'][0]['detail'])) {
            $error_msg = $response['errors'][0]['detail'];
        }
        
        return [
            'status' => 'failed',
            'msg' => $error_msg,
            'raw' => $response
        ];
    }
    
    public function getSource($source_id) {
        $response = $this->makeRequest('GET', '/sources/' . $source_id);
        
        if (isset($response['data']['id'])) {
            return [
                'status' => 'success',
                'source_id' => $response['data']['id'],
                'source_status' => $response['data']['attributes']['status'],
                'amount' => $response['data']['attributes']['amount'] / 100,
                'type' => $response['data']['attributes']['type'],
                'metadata' => $response['data']['attributes']['metadata'] ?? [],
                'raw' => $response
            ];
        }
        
        return [
            'status' => 'failed',
            'msg' => 'Source not found',
            'raw' => $response
        ];
    }
    
    public function createPayment($source_id, $amount, $description = '') {
        $amount_centavos = (int) round($amount * 100);
        
        $data = [
            'data' => [
                'attributes' => [
                    'amount' => $amount_centavos,
                    'currency' => 'PHP',
                    'description' => $description,
                    'source' => [
                        'id' => $source_id,
                        'type' => 'source'
                    ]
                ]
            ]
        ];
        
        $response = $this->makeRequest('POST', '/payments', $data);
        
        if (isset($response['data']['id'])) {
            return [
                'status' => 'success',
                'payment_id' => $response['data']['id'],
                'payment_status' => $response['data']['attributes']['status'],
                'amount' => $response['data']['attributes']['amount'] / 100,
                'raw' => $response
            ];
        }
        
        $error_msg = 'Payment creation failed';
        if (isset($response['errors'][0]['detail'])) {
            $error_msg = $response['errors'][0]['detail'];
        }
        
        return [
            'status' => 'failed',
            'msg' => $error_msg,
            'raw' => $response
        ];
    }
    
    public function getPayment($payment_id) {
        $response = $this->makeRequest('GET', '/payments/' . $payment_id);
        
        if (isset($response['data']['id'])) {
            return [
                'status' => 'success',
                'payment_id' => $response['data']['id'],
                'payment_status' => $response['data']['attributes']['status'],
                'amount' => $response['data']['attributes']['amount'] / 100,
                'raw' => $response
            ];
        }
        
        return [
            'status' => 'failed',
            'msg' => 'Payment not found',
            'raw' => $response
        ];
    }
    
    public function isTestMode() {
        return strpos($this->secret_key, 'sk_test_') === 0;
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->api_base . $endpoint;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($this->secret_key . ':')
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("PayMongo cURL error: " . $error);
            return ['error' => 'Connection error: ' . $error];
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("PayMongo JSON decode error: " . json_last_error_msg());
            return ['error' => 'Invalid response from payment gateway'];
        }
        
        if ($http_code >= 400 && $this->isTestMode()) {
            error_log("PayMongo API Error [{$http_code}]: " . $response);
        }
        
        return $decoded;
    }
}
