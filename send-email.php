<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$gmailAddress = $_ENV['BABY_OLYMPIC_GMAIL_ADDRESS'] ?? getenv('BABY_OLYMPIC_GMAIL_ADDRESS');
$gmailAppPassword = $_ENV['BABY_OLYMPIC_GMAIL_APP_PASSWORD'] ?? getenv('BABY_OLYMPIC_GMAIL_APP_PASSWORD');
$adminEmail = $_ENV['BABY_OLYMPIC_ADMIN_EMAIL'] ?? getenv('BABY_OLYMPIC_ADMIN_EMAIL') ?: $gmailAddress;

if ($gmailAddress === false || $gmailAddress === '' || $gmailAppPassword === false || $gmailAppPassword === '' || $adminEmail === false || $adminEmail === '') {
    respond(500, ['success' => false, 'error' => 'Email service is not configured.']);
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function clean(mixed $value): string
{
    return trim((string) $value);
}

// Accept both JSON fetch requests and normal HTML form submissions.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        respond(400, ['success' => false, 'error' => 'Invalid JSON request']);
    }
    $data = isset($decoded['data']) && is_array($decoded['data'])
        ? $decoded['data']
        : $decoded;
} else {
    $data = $_POST;
}

    $formType = strtolower(clean($data['formType'] ?? ''));
    $name = clean($data['name'] ?? $data['parentName'] ?? '');
    $mobile = clean($data['mobile'] ?? '');
    $email = clean($data['email'] ?? '');
    $city = clean($data['city'] ?? '');
    $preferredModel = clean($data['preferredModel'] ?? $data['preferred_model'] ?? $data['model'] ?? implode(', ', (array) ($data['games'] ?? '')));
    $enquiryType = clean($data['enquiryType'] ?? $data['enquiry_type'] ?? $data['type'] ?? ($formType === 'registration' ? 'Registration' : ''));
    $message = clean($data['message'] ?? $data['msg'] ?? '');

    if ($name === '' || $mobile === '' || $email === '' || ($formType === 'contact' && $message === '') || ($formType === 'registration' && ($city === '' || $preferredModel === ''))) {
        respond(422, ['success' => false, 'error' => 'Please complete all required fields.']);
    }

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['success' => false, 'error' => 'Please enter a valid email address.']);
}

$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeMobile = htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeCity = htmlspecialchars($city, ENT_QUOTES, 'UTF-8');
$safePreferredModel = htmlspecialchars($preferredModel, ENT_QUOTES, 'UTF-8');
$safeEnquiryType = htmlspecialchars($enquiryType, ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message !== '' ? $message : 'Not provided', ENT_QUOTES, 'UTF-8'));

$detailsHtml = <<<HTML
<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif">
  <tr><td style="padding:8px;font-weight:bold">Name</td><td style="padding:8px">{$safeName}</td></tr>
  <tr><td style="padding:8px;font-weight:bold">Mobile Number</td><td style="padding:8px">{$safeMobile}</td></tr>
  <tr><td style="padding:8px;font-weight:bold">Email</td><td style="padding:8px">{$safeEmail}</td></tr>
  <tr><td style="padding:8px;font-weight:bold">City</td><td style="padding:8px">{$safeCity}</td></tr>
  <tr><td style="padding:8px;font-weight:bold">Preferred Model</td><td style="padding:8px">{$safePreferredModel}</td></tr>
  <tr><td style="padding:8px;font-weight:bold">Enquiry Type</td><td style="padding:8px">{$safeEnquiryType}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;vertical-align:top">Message</td><td style="padding:8px">{$safeMessage}</td></tr>
</table>
HTML;

function configureMailer(string $gmailAddress, string $gmailAppPassword): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $gmailAddress;
    $mail->Password = $gmailAppPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom($gmailAddress, 'Baby Olympic Games');
    return $mail;
}

try {
    // Send visitor information to the admin Gmail account.
    $adminMail = configureMailer($gmailAddress, $gmailAppPassword);
    $adminMail->addAddress($adminEmail);
    $adminMail->addReplyTo($email, $name);
    $adminMail->Subject = "New contact enquiry from {$name}";
    $adminMail->Body = '<h2>New Contact Form Submission</h2>' . $detailsHtml;
    $adminMail->AltBody = "New contact enquiry\nName: {$name}\nMobile: {$mobile}\nEmail: {$email}\nCity: {$city}\nPreferred Model: {$preferredModel}\nEnquiry Type: {$enquiryType}\nMessage: " . ($message !== '' ? $message : 'Not provided');
    $adminMail->send();

    // Send a confirmation email to the visitor.
    $visitorMail = configureMailer($gmailAddress, $gmailAppPassword);
    $visitorMail->addAddress($email, $name);
    $visitorMail->Subject = 'Your query has been submitted - Baby Olympic Games';
    $visitorMail->Body = "<p>Dear {$safeName},</p><p>Your query has been submitted successfully. Thank you for contacting Baby Olympic Games. We have received your details and will get back to you soon.</p><h3>Your submitted details</h3>" . $detailsHtml;
    $visitorMail->AltBody = "Dear {$name},\n\nYour query has been submitted successfully. Thank you for contacting Baby Olympic Games. We have received your details and will get back to you soon.";
    $visitorMail->send();

    // --- Notify Admin Panel ---
    $admin_api_url = getenv('ADMIN_API_URL') ?: 'https://admin.babyolympic.com';
    $admin_api_key = getenv('ADMIN_API_KEY') ?: 'bog-2026-public-api-key';
    
    if ($formType === 'registration') {
        $endpoint = '/api/public/register';
        $payload = json_encode([
            'childName' => clean($data['childName'] ?? ''),
            'parentName' => clean($data['parentName'] ?? $name),
            'phone' => clean($data['phone'] ?? $data['mobile'] ?? $mobile),
            'email' => clean($data['email'] ?? $email),
            'dob' => clean($data['dob'] ?? ''),
            'age' => isset($data['age']) ? (int)$data['age'] : null,
            'category' => clean($data['category'] ?? ''),
            'games' => isset($data['games']) && is_array($data['games']) ? $data['games'] : [],
            'city' => clean($data['city'] ?? $city),
            'state' => clean($data['state'] ?? ''),
            'school' => clean($data['school'] ?? ''),
            'medical' => clean($data['medical'] ?? ''),
            'regId' => clean($data['regId'] ?? '')
        ]);
    } else if ($formType === 'sponsor') {
        $endpoint = '/api/public/sponsor';
        $payload = json_encode([
            'companyName' => clean($data['company'] ?? 'Unknown Company'),
            'contactName' => clean($data['fullName'] ?? $name),
            'phone' => clean($data['mobile'] ?? $mobile),
            'email' => clean($data['email'] ?? $email),
            'tier' => clean($data['designation'] ?? 'general'),
            'interestedIn' => clean($data['message'] ?? $message)
        ]);
    } else {
        $endpoint = '/api/public/enquiry';
        $payload = json_encode([
            'name' => $name,
            'phone' => $mobile,
            'email' => $email,
            'message' => $message !== '' ? $message : 'No message provided'
        ]);
    }
    
    $ch = curl_init($admin_api_url . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $admin_api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    curl_close($ch);
    // --- End Notify Admin Panel ---

    respond(200, ['success' => true, 'message' => 'Your enquiry was sent successfully. A confirmation email has been sent to you.']);
} catch (Exception $exception) {
    error_log('Contact email error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Email could not be sent. Please try again later.']);
}
