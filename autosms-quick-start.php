<?php
/**
 * AutoSMS Payment Hub - Quick Start Examples
 *
 * Copy these examples into your project and customize as needed.
 * Requires: autosms-php-sdk.php
 */

require_once 'autosms-php-sdk.php';

// =============================================================================
// CONFIGURATION - Add these to your .env file (recommended)
// =============================================================================

/*
# Add to your .env file:
AUTOSMS_API_URL=https://www.musoftwares.com
AUTOSMS_API_TOKEN=your_api_token_here
AUTOSMS_VERIFICATION_SECRET=your_verification_secret_here
AUTOSMS_WEBHOOK_SECRET=your_webhook_secret_here
*/

// Load from environment variables (recommended for production)
$config = [
    'api_url' => getenv('AUTOSMS_API_URL'),
    'api_token' => getenv('AUTOSMS_API_TOKEN'),
    'verification_secret' => getenv('AUTOSMS_VERIFICATION_SECRET'),
    'webhook_secret' => getenv('AUTOSMS_WEBHOOK_SECRET'),
];

// =============================================================================
// EXAMPLE 1: Verify Transaction (Simple)
// =============================================================================

function example1_simpleVerification()
{
    global $config;

    $client = new AutoSMSClient(
        $config['api_url'],
        $config['api_token'],
        $config['verification_secret']
    );

    $phoneNumber = '01015218548'; // Replace with actual phone number
    $result = $client->verifyTransaction($phoneNumber);

    if ($result === false) {
        echo "Error: " . $client->getLastError() . "\n";
        return false;
    }

    if ($result['success'] && isset($result['transaction'])) {
        $transaction = $result['transaction'];
        echo "Payment verified!\n";
        echo "Amount: {$transaction['amount']} {$transaction['currency']}\n";
        echo "Sender: {$transaction['sender_name']}\n";
        return $transaction;
    } else {
        echo "No transaction found for this phone number.\n";
        return false;
    }
}

// =============================================================================
// EXAMPLE 2: Verify Transaction (Production-Ready with Error Handling)
// =============================================================================

function example2_productionVerification($phoneNumber, $expectedAmount = null)
{
    global $config;

    try {
        // Validate input
        if (empty($phoneNumber)) {
            throw new Exception('Phone number is required');
        }

        // Clean phone number (remove spaces, dashes, etc.)
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Initialize client
        $client = new AutoSMSClient(
            $config['api_url'],
            $config['api_token'],
            $config['verification_secret']
        );

        // Set timeout (optional)
        $client->setTimeout(15);

        // Verify transaction with HMAC signature verification
        $result = $client->verifyTransaction($phoneNumber, true);

        if ($result === false) {
            throw new Exception('Verification failed: ' . $client->getLastError());
        }

        // Check if transaction was found
        if (!$result['success'] || !isset($result['transaction'])) {
            return [
                'success' => false,
                'error' => 'No transaction found',
                'message' => 'No payment was found from this phone number.'
            ];
        }

        $transaction = $result['transaction'];

        // Optional: Verify expected amount
        if ($expectedAmount !== null && $transaction['amount'] != $expectedAmount) {
            return [
                'success' => false,
                'error' => 'amount_mismatch',
                'message' => "Expected {$expectedAmount}, but received {$transaction['amount']}",
                'transaction' => $transaction
            ];
        }

        // Success!
        return [
            'success' => true,
            'transaction' => $transaction,
            'message' => 'Payment verified successfully!'
        ];

    } catch (Exception $e) {
        // Log error (in production, use proper logging)
        error_log('AutoSMS verification error: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'exception',
            'message' => $e->getMessage()
        ];
    }
}

// =============================================================================
// EXAMPLE 3: Webhook Handler (Simple)
// =============================================================================

/**
 * Save this as webhook-handler.php on your server
 * Configure your webhook URL to: https://yoursite.com/webhook-handler.php
 */
function example3_simpleWebhook()
{
    global $config;

    handleAutoSMSWebhook($config['webhook_secret'], function($transaction) {
        // Your business logic here
        error_log("Payment received: {$transaction['amount']} {$transaction['currency']}");

        // Example: Update database
        // updateOrderStatus($transaction['transaction_id'], 'paid');

        // Example: Send confirmation email
        // sendPaymentConfirmation($transaction);

        return ['processed' => true];
    });
}

// =============================================================================
// EXAMPLE 4: Webhook Handler (Production-Ready with Database)
// =============================================================================

function example4_productionWebhook()
{
    global $config;

    handleAutoSMSWebhook($config['webhook_secret'], function($transaction) {
        try {
            // Connect to your database
            $pdo = new PDO(
                'mysql:host=localhost;dbname=your_database',
                'your_username',
                'your_password',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Start transaction
            $pdo->beginTransaction();

            // Check if transaction already processed (prevent duplicate processing)
            $stmt = $pdo->prepare(
                "SELECT id FROM payments WHERE autosms_transaction_id = ?"
            );
            $stmt->execute([$transaction['transaction_id']]);

            if ($stmt->fetch()) {
                $pdo->rollBack();
                return ['message' => 'Transaction already processed'];
            }

            // Insert payment record
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    autosms_transaction_id,
                    phone_number,
                    amount,
                    currency,
                    sender_name,
                    transaction_date,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())
            ");

            $stmt->execute([
                $transaction['transaction_id'],
                $transaction['phone_number'],
                $transaction['amount'],
                $transaction['currency'],
                $transaction['sender_name'] ?? null,
                $transaction['transaction_date']
            ]);

            $paymentId = $pdo->lastInsertId();

            // Update related order (if applicable)
            // Example: Find order by phone number or custom field
            // $this->updateOrderStatus($phoneNumber, 'paid');

            // Commit transaction
            $pdo->commit();

            // Send notification (email, SMS, etc.)
            // sendPaymentNotification($transaction);

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'message' => 'Payment processed successfully'
            ];

        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }

            error_log('Webhook processing error: ' . $e->getMessage());

            throw $e; // Re-throw to return 500 error
        }
    });
}

// =============================================================================
// EXAMPLE 5: E-commerce Integration (Complete Flow)
// =============================================================================

class EcommercePaymentProcessor
{
    private $autoSMS;
    private $pdo;

    public function __construct($config, $pdo)
    {
        $this->autoSMS = new AutoSMSClient(
            $config['api_url'],
            $config['api_token'],
            $config['verification_secret']
        );
        $this->pdo = $pdo;
    }

    /**
     * Process payment verification for an order
     */
    public function verifyOrderPayment($orderId, $phoneNumber)
    {
        try {
            // Get order details
            $stmt = $this->pdo->prepare("
                SELECT * FROM orders
                WHERE id = ? AND status = 'pending_payment'
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found or already processed'
                ];
            }

            // Verify transaction
            $result = $this->autoSMS->verifyTransaction($phoneNumber, true);

            if ($result === false) {
                return [
                    'success' => false,
                    'error' => $this->autoSMS->getLastError()
                ];
            }

            if (!$result['success'] || !isset($result['transaction'])) {
                return [
                    'success' => false,
                    'error' => 'No payment found from this phone number'
                ];
            }

            $transaction = $result['transaction'];

            // Verify amount matches order total
            if ($transaction['amount'] < $order['total_amount']) {
                return [
                    'success' => false,
                    'error' => 'Payment amount does not match order total',
                    'expected' => $order['total_amount'],
                    'received' => $transaction['amount']
                ];
            }

            // Update order status
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE orders
                SET status = 'paid',
                    payment_method = 'autosms',
                    payment_phone = ?,
                    autosms_transaction_id = ?,
                    paid_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $phoneNumber,
                $transaction['transaction_id'],
                $orderId
            ]);

            $this->pdo->commit();

            // Send confirmation
            $this->sendOrderConfirmation($order, $transaction);

            return [
                'success' => true,
                'order_id' => $orderId,
                'transaction' => $transaction,
                'message' => 'Payment verified and order updated successfully!'
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('Order payment verification error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'An error occurred while processing payment'
            ];
        }
    }

    private function sendOrderConfirmation($order, $transaction)
    {
        // Implement your notification logic here
        // Send email, SMS, push notification, etc.
    }
}

// =============================================================================
// USAGE EXAMPLES
// =============================================================================

// Uncomment to test:

// Example 1: Simple verification
// example1_simpleVerification();

// Example 2: Production verification
// $result = example2_productionVerification('01015218548', 100.00);
// print_r($result);

// Example 3: Simple webhook (use this in webhook-handler.php)
// example3_simpleWebhook();

// Example 4: Production webhook (use this in webhook-handler.php)
// example4_productionWebhook();

// Example 5: E-commerce integration
/*
$pdo = new PDO('mysql:host=localhost;dbname=shop', 'user', 'password');
$processor = new EcommercePaymentProcessor($config, $pdo);
$result = $processor->verifyOrderPayment(12345, '01015218548');
print_r($result);
*/

