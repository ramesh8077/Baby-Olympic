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

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$razorpay_payment_id = $input['razorpay_payment_id'] ?? $_POST['razorpay_payment_id'] ?? $_GET['razorpay_payment_id'] ?? null;
$razorpay_order_id = $input['razorpay_order_id'] ?? $_POST['razorpay_order_id'] ?? $_GET['razorpay_order_id'] ?? null;
$razorpay_signature = $input['razorpay_signature'] ?? $_POST['razorpay_signature'] ?? $_GET['razorpay_signature'] ?? null;
$registration_db_id = $input['registration_db_id'] ?? $_POST['registration_db_id'] ?? $_GET['registration_db_id'] ?? null;

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
    
    // --- Notify Admin Panel about successful payment ---
    $admin_api_url = $_ENV['ADMIN_API_URL'] ?? 'https://admin.babyolympic.com';
    $admin_api_key = $_ENV['ADMIN_API_KEY'] ?? 'bog-2026-public-api-key';
    
    $payment_amount = 25000;
    try {
        $paymentObj = $api->payment->fetch($razorpay_payment_id);
        if ($paymentObj && isset($paymentObj->amount)) {
            $payment_amount = (int)$paymentObj->amount;
        }
    } catch (\Exception $fetchErr) {}

    if ($registration_db_id) {
        $payment_payload = json_encode([
            'registrationId' => $registration_db_id,
            'razorpayPaymentId' => $razorpay_payment_id,
            'razorpayOrderId' => $razorpay_order_id,
            'amount' => (int)$payment_amount
        ]);

        $ch = curl_init($admin_api_url . '/api/public/payment-success');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payment_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $admin_api_key
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }
    // --- End Admin Panel Notification ---
    
    if (!empty($_POST['razorpay_payment_id'])) {
        header('Location: /register.html?payment=success&payment_id=' . urlencode($razorpay_payment_id) . '&reg_id=' . urlencode($registration_db_id ?? ''));
        exit;
    }

    echo json_encode([
        'status' => 'success', 
        'message' => 'Payment verified successfully'
    ]);
} catch(SignatureVerificationError $e) {
    if (!empty($_POST['razorpay_payment_id'])) {
        header('Location: /register.html?payment=failed&error=' . urlencode($e->getMessage()));
        exit;
    }
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'error' => 'Signature mismatch', 
        'message' => $e->getMessage()
    ]);
} catch(\Exception $e) {
    if (!empty($_POST['razorpay_payment_id'])) {
        header('Location: /register.html?payment=failed&error=' . urlencode($e->getMessage()));
        exit;
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'error' => $e->getMessage()
    ]);
}
