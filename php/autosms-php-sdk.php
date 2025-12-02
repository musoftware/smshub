<?php
/**
 * AutoSMS Payment Hub - PHP SDK
 *
 * Production-ready SDK for integrating AutoSMS Payment Hub into your website.
 *
 * @version 1.0.0
 * @link https://github.com/musoftware/smshub
 */

class AutoSMSClient
{
    private $apiUrl;
    private $apiToken;
    private $verificationSecret;
    private $timeout = 30;
    private $lastError = null;

    /**
     * Initialize AutoSMS Client
     *
     * @param string $apiUrl Your AutoSMS API URL (e.g., https://yourdomain.com)
     * @param string $apiToken Your API token for authentication
     * @param string $verificationSecret Your verification secret for HMAC validation
     */
    public function __construct($apiUrl, $apiToken, $verificationSecret = null)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiToken = $apiToken;
        $this->verificationSecret = $verificationSecret;
    }

    /**
     * Verify a transaction by phone number
     *
     * @param string $phoneNumber The phone number to verify
     * @param bool $verifySignature Whether to verify HMAC signature (recommended for production)
     * @return array|false Transaction data or false on failure
     */
    public function verifyTransaction($phoneNumber, $verifySignature = true)
    {
        try {
            $response = $this->makeRequest('/api/auto-sms/verify-transaction', [
                'phone_number' => $phoneNumber
            ]);

            if (!$response) {
                return false;
            }

            // Verify HMAC signature if enabled and secret is available
            if ($verifySignature && $this->verificationSecret) {
                if (!$this->verifyHMACSignature($response['body'], $response['headers'])) {
                    $this->lastError = 'HMAC signature verification failed';
                    return false;
                }
            }

            $data = json_decode($response['body'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lastError = 'Invalid JSON response: ' . json_last_error_msg();
                return false;
            }

            return $data;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload The raw webhook payload (request body)
     * @param string $signature The X-Webhook-Signature header value
     * @param string $webhookSecret Your webhook secret
     * @return bool True if signature is valid
     */
    public static function verifyWebhookSignature($payload, $signature, $webhookSecret)
    {
        if (empty($signature) || empty($webhookSecret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Use constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle incoming webhook request
     *
     * @param string $webhookSecret Your webhook secret
     * @param callable $callback Function to call with validated transaction data
     * @return bool True if webhook was processed successfully
     */
    public static function handleWebhook($webhookSecret, callable $callback)
    {
        try {
            // Get raw POST data
            $payload = file_get_contents('php://input');

            if (empty($payload)) {
                http_response_code(400);
                echo json_encode(['error' => 'Empty payload']);
                return false;
            }

            // Get signature from headers
            $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;

            if (!$signature) {
                http_response_code(401);
                echo json_encode(['error' => 'Missing signature']);
                return false;
            }

            // Verify signature
            if (!self::verifyWebhookSignature($payload, $signature, $webhookSecret)) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature']);
                return false;
            }

            // Parse and validate payload
            $data = json_decode($payload, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                return false;
            }

            // Call user's callback function
            $result = $callback($data);

            // Send success response
            http_response_code(200);
            echo json_encode(['success' => true, 'result' => $result]);
            return true;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get the last error message
     *
     * @return string|null
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Set request timeout in seconds
     *
     * @param int $seconds
     */
    public function setTimeout($seconds)
    {
        $this->timeout = (int)$seconds;
    }

    /**
     * Make HTTP request to API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|false Response with body and headers
     */
    private function makeRequest($endpoint, $data = [])
    {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init($url);

        if ($ch === false) {
            $this->lastError = 'Failed to initialize cURL';
            return false;
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json'
        ];

        // Capture response headers
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) >= 2) {
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            }
            return $len;
        });

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($responseBody === false) {
            $this->lastError = 'cURL error: ' . $curlError;
            return false;
        }

        if ($httpCode !== 200) {
            $this->lastError = 'HTTP error ' . $httpCode . ': ' . $responseBody;
            return false;
        }

        return [
            'body' => $responseBody,
            'headers' => $responseHeaders,
            'status' => $httpCode
        ];
    }

    /**
     * Verify HMAC signature from response
     *
     * @param string $responseBody Raw response body
     * @param array $responseHeaders Response headers
     * @return bool True if signature is valid
     */
    private function verifyHMACSignature($responseBody, $responseHeaders)
    {
        if (!$this->verificationSecret) {
            return true; // Skip verification if no secret provided
        }

        $receivedSignature = $responseHeaders['x-autosms-signature'] ?? null;

        if (!$receivedSignature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $responseBody, $this->verificationSecret);

        // Use constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $receivedSignature);
    }
}

/**
 * AutoSMS Webhook Handler (Standalone Function)
 *
 * Quick helper for handling webhooks without instantiating the class
 *
 * Example usage:
 *
 * ```php
 * require_once 'autosms-php-sdk.php';
 *
 * handleAutoSMSWebhook('your-webhook-secret', function($transaction) {
 *     // Your business logic here
 *     error_log("Payment received: " . $transaction['amount']);
 *
 *     // Update your database, send confirmation email, etc.
 *     // Return any data you want to include in the response
 *     return ['order_id' => 12345];
 * });
 * ```
 */
function handleAutoSMSWebhook($webhookSecret, callable $callback)
{
    return AutoSMSClient::handleWebhook($webhookSecret, $callback);
}

