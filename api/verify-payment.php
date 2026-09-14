<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$razorpay_payment_id = $input['razorpay_payment_id'] ?? null;
$razorpay_order_id = $input['razorpay_order_id'] ?? null;
$razorpay_signature = $input['razorpay_signature'] ?? null;

if (!$razorpay_payment_id || !$razorpay_order_id || !$razorpay_signature) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment details']);
    exit;
}

$keyId = $_ENV['RAZORPAY_KEY_ID'] ?? null;
$keySecret = $_ENV['RAZORPAY_KEY_SECRET'] ?? null;

if (!$keyId || !$keySecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error. Missing credentials.']);
    exit;
}

$api = new Api($keyId, $keySecret);

try {
    $attributes = [
        'razorpay_order_id' => $razorpay_order_id,
        'razorpay_payment_id' => $razorpay_payment_id,
        'razorpay_signature' => $razorpay_signature
    ];
    
    // Will throw SignatureVerificationError if signature is invalid
    $api->utility->verifyPaymentSignature($attributes);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Payment verified successfully'
    ]);
} catch(SignatureVerificationError $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'error' => 'Signature mismatch', 
        'message' => $e->getMessage()
    ]);
} catch(\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'error' => $e->getMessage()
    ]);
}
