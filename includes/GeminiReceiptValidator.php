<?php

final class GeminiReceiptValidator
{
    public static function validate(string $imagePath, string $mimeType, float $expectedAmount): array
    {
        $localConfig = [];
        $localConfigPath = 'C:/xampp/gemini-secret.php';
        if (is_file($localConfigPath)) {
            $loadedConfig = require $localConfigPath;
            if (is_array($loadedConfig)) $localConfig = $loadedConfig;
        }

        $apiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ($localConfig['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Receipt scanning is not configured. Please contact the administrator.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Receipt scanning is unavailable on this server.');
        }

        $model = getenv('GEMINI_MODEL') ?: ($localConfig['model'] ?? 'gemini-3.8-flash');
        $prompt = 'Analyze this uploaded payment image. Return JSON only. Determine whether it is clearly a GCash payment receipt, whether the visible transaction status is successful/completed, and extract the paid amount. Reject generic photos, QR screens, edited mockups, other wallets, incomplete screens, and receipts whose amount does not match PHP ' . number_format($expectedAmount, 2, '.', '') . '. Be conservative. Use amount 0 when no amount is readable. JSON keys: is_gcash_receipt (boolean), payment_successful (boolean), amount (number), confidence (number 0 to 1), reason (short string).';

        $payload = [
            'model' => $model,
            'store' => false,
            'input' => [
                ['type' => 'text', 'text' => $prompt],
                [
                    'type' => 'image',
                    'data' => base64_encode((string)file_get_contents($imagePath)),
                    'mime_type' => $mimeType,
                ],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'is_gcash_receipt' => ['type' => 'boolean'],
                        'payment_successful' => ['type' => 'boolean'],
                        'amount' => ['type' => 'number'],
                        'confidence' => ['type' => 'number'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['is_gcash_receipt', 'payment_successful', 'amount', 'confidence', 'reason'],
                ],
            ],
            'generation_config' => [
                'temperature' => 0,
                'thinking_level' => 'low',
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/interactions';
        $models = array_values(array_unique([$model, 'gemini-3.7-flash', 'gemini-3.5-flash-lite']));
        $body = false;
        $status = 0;
        $curlError = '';

        foreach ($models as $candidateModel) {
            $payload['model'] = $candidateModel;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($body !== false && $status >= 200 && $status < 300) break;
            if (!in_array($status, [429, 503], true)) break;
            error_log('Gemini model ' . $candidateModel . ' busy; trying fallback model.');
        }

        if ($body === false || $status < 200 || $status >= 300) {
            error_log('Gemini receipt scan failed (' . $status . '): ' . ($curlError ?: substr((string)$body, 0, 300)));
            throw new RuntimeException('Receipt scanning is temporarily unavailable. Please try again.');
        }

        $response = json_decode($body, true);
        $text = $response['output_text'] ?? ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $modelOutputs = $response['steps'] ?? ($response['outputs'] ?? []);
        if ($text === '' && is_array($modelOutputs)) {
            foreach ($modelOutputs as $output) {
                if (($output['type'] ?? '') === 'model_output') {
                    $text = $output['text'] ?? ($output['content'][0]['text'] ?? '');
                    if ($text !== '') break;
                }
            }
        }
        $result = json_decode($text, true);
        if (!is_array($result)) {
            throw new RuntimeException('The receipt could not be read. Please upload a clearer GCash receipt.');
        }

        $amount = isset($result['amount']) && is_numeric($result['amount']) ? (float)$result['amount'] : null;
        $amountMatches = $amount !== null && abs($amount - $expectedAmount) < 0.01;
        $valid = !empty($result['is_gcash_receipt'])
            && !empty($result['payment_successful'])
            && $amountMatches
            && (float)($result['confidence'] ?? 0) >= 0.75;

        $reason = (string)($result['reason'] ?? 'Unable to verify the receipt image.');
        if (!empty($result['is_gcash_receipt']) && !empty($result['payment_successful']) && !$amountMatches) {
            $detected = $amount === null ? 'unreadable' : 'PHP ' . number_format($amount, 2);
            $reason = 'Receipt amount is ' . $detected . '; required payment is PHP ' . number_format($expectedAmount, 2) . '.';
        }

        return [
            'valid' => $valid,
            'confidence' => (float)($result['confidence'] ?? 0),
            'amount' => $amount,
            'reason' => $reason,
        ];
    }
}
