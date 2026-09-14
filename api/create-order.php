<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use Razorpay\Api\Api;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? (int)$input['amount'] : 0;
$currency = isset($input['currency']) ? $input['currency'] : 'INR';
$receipt = isset($input['receipt']) ? $input['receipt'] : 'receipt_' . time();

if ($amount < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount must be at least 100 paise']);
    exit;
}

$keyId = $_ENV['RAZORPAY_KEY_ID'] ?? null;
$keySecret = $_ENV['RAZORPAY_KEY_SECRET'] ?? null;

if (!$keyId || !$keySecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error. Missing credentials.']);
    exit;
}

try {
    $api = new Api($keyId, $keySecret);
    
    $orderData = [
        'receipt'         => $receipt,
        'amount'          => $amount, // in paise
        'currency'        => $currency,
    ];

    $razorpayOrder = $api->order->create($orderData);
    
    echo json_encode([
        'order_id' => $razorpayOrder['id'],
        'amount' => $razorpayOrder['amount'],
        'currency' => $razorpayOrder['currency'],
        'key_id' => $keyId // Sent to frontend for checkout init (safe to expose key id)
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
