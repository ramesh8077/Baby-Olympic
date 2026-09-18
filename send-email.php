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

$secretsFile = dirname(__DIR__) . '/secrets.php';
if (file_exists($secretsFile)) {
    require_once $secretsFile;
}

$gmailAddress = trim(getenv('BABY_OLYMPIC_GMAIL_ADDRESS') ?: ($_ENV['BABY_OLYMPIC_GMAIL_ADDRESS'] ?? ''));
$gmailAppPassword = trim(getenv('BABY_OLYMPIC_GMAIL_APP_PASSWORD') ?: ($_ENV['BABY_OLYMPIC_GMAIL_APP_PASSWORD'] ?? ''));
$adminEmail = trim(getenv('BABY_OLYMPIC_ADMIN_EMAIL') ?: ($_ENV['BABY_OLYMPIC_ADMIN_EMAIL'] ?? $gmailAddress));

if ($gmailAddress === '' || $gmailAppPassword === '' || $adminEmail === '') {
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
        ? array_merge($decoded['data'], ['formType' => $decoded['formType'] ?? ''])
        : $decoded;
} else {
    $data = $_POST;
}

    $formType = strtolower(clean($data['formType'] ?? ''));
    $name = clean($data['name'] ?? $data['parentName'] ?? $data['fullName'] ?? $data['company'] ?? '');
    $mobile = clean($data['mobile'] ?? $data['phone'] ?? '');
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
    $mail->SMTPDebug = 3;
    $mail->Debugoutput = function($str, $level) {
        file_put_contents(__DIR__ . '/mail_debug.log', $str, FILE_APPEND);
    };
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $gmailAddress;
    $mail->Password = $gmailAppPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Try 587 STARTTLS again
    $mail->Port = 587; 
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom($gmailAddress, 'Baby Olympic Games');
    return $mail;
}

// --- Notify Admin Panel (MOVED BEFORE EMAIL SEND) ---
$admin_api_url = $_ENV['ADMIN_API_URL'] ?? getenv('ADMIN_API_URL') ?: 'https://admin.babyolympic.com';
$admin_api_key = $_ENV['ADMIN_API_KEY'] ?? getenv('ADMIN_API_KEY') ?: 'bog-2026-public-api-key';

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

try {
    $adminMail = configureMailer($gmailAddress, $gmailAppPassword);
    $adminMail->addAddress($adminEmail);
    $adminMail->addReplyTo($email, $name);
    
    // Attachments for Admin
    if (isset($data['photo']) && is_array($data['photo']) && !empty($data['photo']['data'])) {
        $b64 = preg_replace('#^data:image/[^;]+;base64,#', '', $data['photo']['data']);
        $decoded = base64_decode($b64);
        if ($decoded) {
            $adminMail->addStringAttachment($decoded, $data['photo']['name'] ?? 'photo.jpg');
        }
    }
    if (isset($data['cert']) && is_array($data['cert']) && !empty($data['cert']['data'])) {
        $b64 = preg_replace('#^data:(image|application)/[^;]+;base64,#', '', $data['cert']['data']);
        $decoded = base64_decode($b64);
        if ($decoded) {
            $adminMail->addStringAttachment($decoded, $data['cert']['name'] ?? 'certificate.pdf');
        }
    }

    if ($formType === 'registration') {
        $regIdStr = htmlspecialchars($data['regId'] ?? 'Pending', ENT_QUOTES, 'UTF-8');
        $adminMail->Subject = "New Registration: {$name} ({$regIdStr})";
        $adminMail->Body = '<h2>New Registration Received</h2>' . $detailsHtml;
    } else if ($formType === 'sponsor') {
        $adminMail->Subject = "New Sponsorship Inquiry from {$name}";
        $adminMail->Body = '<h2>New Sponsor Inquiry</h2>' . $detailsHtml;
    } else {
        $adminMail->Subject = "New contact enquiry from {$name}";
        $adminMail->Body = '<h2>New Contact Form Submission</h2>' . $detailsHtml;
    }
    
    $adminMail->send();

    // Send a polite acknowledgment to the visitor.
    $visitorMail = configureMailer($gmailAddress, $gmailAppPassword);
    $visitorMail->addAddress($email, $name);
    
    if ($formType === 'registration') {
        $regIdStr = htmlspecialchars($data['regId'] ?? 'Pending', ENT_QUOTES, 'UTF-8');
        $childNameStr = htmlspecialchars($data['childName'] ?? '', ENT_QUOTES, 'UTF-8');
        $visitorMail->Subject = "Baby Olympic Registration Confirmation - {$regIdStr}";
        $visitorMail->Body = '<h2>Thank you for registering, ' . $safeName . '!</h2>
<p>We have successfully received your child\'s registration for the Baby Olympic Games 2026.</p>
<p><strong>Registration ID:</strong> ' . $regIdStr . '</p>
<p><strong>Child Name:</strong> ' . $childNameStr . '</p>
<p>Our team will verify the details and contact you shortly.</p>
<br>
<p>Best regards,<br>Baby Olympic Games Team</p>';
    } else if ($formType === 'sponsor') {
        $visitorMail->Subject = "Thank you for your interest in Baby Olympic Games";
        $visitorMail->Body = '<h2>Thank you, ' . $safeName . '!</h2>
<p>We have successfully received your sponsorship inquiry.</p>
<p>Our partnership team will review your details and contact you shortly to discuss collaboration opportunities.</p>
<br>
<p>Best regards,<br>Baby Olympic Games Team</p>';
    } else {
        $visitorMail->Subject = "Thank you for contacting Baby Olympic Games";
        $visitorMail->Body = '<h2>Thank you, ' . $safeName . '!</h2>
<p>We have received your message and will get back to you shortly.</p>
<p><strong>Your Message:</strong><br>' . $safeMessage . '</p>
<br>
<p>Best regards,<br>Baby Olympic Games Team</p>';
    }
    
    $visitorMail->send();

    respond(200, ['success' => true]);

} catch (Exception $exception) {
    $errorMsg = date('Y-m-d H:i:s') . " - Mailer Error: " . $exception->getMessage() . "\n";
    file_put_contents(__DIR__ . '/mail_errors.log', $errorMsg, FILE_APPEND);
    error_log('Contact email error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Email could not be sent. Error: ' . $exception->getMessage()]);
}
