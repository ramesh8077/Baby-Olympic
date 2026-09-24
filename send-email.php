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

$safeAge = isset($data['age']) ? htmlspecialchars((string)$data['age'], ENT_QUOTES, 'UTF-8') : 'Not provided';
$safeCategory = isset($data['category']) && $data['category'] !== '' ? htmlspecialchars($data['category'], ENT_QUOTES, 'UTF-8') : 'Not provided';
$ageAndCategory = $safeCategory; // The category string already contains the age range (e.g. "Champion Squad (6½–8½ Years)")

$detailsHtml = '';
$adminSubject = '';

if ($formType === 'registration') {
    $safeChildName = htmlspecialchars(clean($data['childName'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeDob = htmlspecialchars(clean($data['dob'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeGender = htmlspecialchars(clean($data['gender'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeRegId = htmlspecialchars(clean($data['regId'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safePaymentId = htmlspecialchars(clean($data['paymentId'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    $gamesCount = (isset($data['games']) && is_array($data['games'])) ? count($data['games']) : 0;
    $amountValue = max(1, $gamesCount) * 250;
    $safeAmount = "₹" . number_format($amountValue, 2);

    if (isset($data['games']) && is_array($data['games'])) {
         $safeGames = htmlspecialchars(implode(', ', $data['games']), ENT_QUOTES, 'UTF-8');
    } else {
         $safeGames = 'Not provided';
    }

    $adminSubject = "New Registration: {$safeChildName} | {$safeRegId}";
    $detailsHtml = <<<HTML
<div style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <div style="background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
        <h2 style="color: #6B21C9;">New Registration Received!</h2>
        <p>Here are the details of the new registration:</p>
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
            <tr><td style="padding: 8px; font-weight: bold; width: 40%; border: 1px solid #ddd;">Registration ID:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeRegId}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Child Name:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeChildName}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Age Group:</td><td style="padding: 8px; border: 1px solid #ddd;">{$ageAndCategory}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">DOB:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeDob}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Gender:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeGender}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Parent Name:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeName}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Phone:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeMobile}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Email:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeEmail}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">City:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeCity}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Amount:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeAmount}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Payment:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safePaymentId}</td></tr>
            <tr><td style="padding: 8px; font-weight: bold; border: 1px solid #ddd;">Games:</td><td style="padding: 8px; border: 1px solid #ddd;">{$safeGames}</td></tr>
        </table>
    </div>
</div>
HTML;
} else {
    $adminSubject = "New contact enquiry from {$name}";
    $detailsHtml = <<<HTML
<h2>New Contact Form Submission</h2>
<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif">
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Name</td><td style="padding:8px;border:1px solid #ddd;">{$safeName}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Mobile Number</td><td style="padding:8px;border:1px solid #ddd;">{$safeMobile}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Email</td><td style="padding:8px;border:1px solid #ddd;">{$safeEmail}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">City</td><td style="padding:8px;border:1px solid #ddd;">{$safeCity}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Age &amp; Category</td><td style="padding:8px;border:1px solid #ddd;">{$ageAndCategory}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Preferred Model</td><td style="padding:8px;border:1px solid #ddd;">{$safePreferredModel}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;border:1px solid #ddd;">Enquiry Type</td><td style="padding:8px;border:1px solid #ddd;">{$safeEnquiryType}</td></tr>
  <tr><td style="padding:8px;font-weight:bold;vertical-align:top;border:1px solid #ddd;">Message</td><td style="padding:8px;border:1px solid #ddd;">{$safeMessage}</td></tr>
</table>
HTML;
}

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
    $disclaimerHtml = '<hr style="margin-top: 20px; border: 0; border-top: 1px solid #ccc;"><p style="font-size: 11px; color: #666; text-align: justify;"><strong>Disclaimer:</strong><br>This email and any attachments are confidential and intended solely for the named recipient. If you have received this email in error, please notify the sender immediately and delete it from your system. Any unauthorised review, use, disclosure or distribution is prohibited. The views expressed in this email are those of the sender and do not necessarily represent those of the Kanpur Olympic Association. While reasonable care has been taken, the sender does not accept liability for any damage caused by viruses or errors in transmission.</p>';

    // Send visitor information to the admin Gmail account.
    $adminMail = configureMailer($gmailAddress, $gmailAppPassword);
    $adminMail->addAddress($adminEmail);
    $adminMail->addReplyTo($email, $name);
    $adminMail->Subject = $adminSubject;
    $adminMail->Body = $detailsHtml . $disclaimerHtml;
    
    // Process base64 attachments if available
    if ($formType === 'registration') {
        if (isset($data['photo']) && is_array($data['photo']) && isset($data['photo']['data'])) {
            $base64 = preg_replace('#^data:image/[^;]+;base64,#', '', $data['photo']['data']);
            $adminMail->addStringAttachment(base64_decode($base64), $data['photo']['name'], 'base64', $data['photo']['type']);
        }
        if (isset($data['cert']) && is_array($data['cert']) && isset($data['cert']['data'])) {
            $base64 = preg_replace('#^data:[^;]+;base64,#', '', $data['cert']['data']);
            $adminMail->addStringAttachment(base64_decode($base64), $data['cert']['name'], 'base64', $data['cert']['type']);
        }
    }
    
    $adminMail->send();

    // Send a polite acknowledgment to the visitor.
    $visitorMail = configureMailer($gmailAddress, $gmailAppPassword);
    $visitorMail->addAddress($email, $name);
    
    if ($formType === 'registration') {
        $visitorMail->Subject = "Registration Confirmation - Baby Olympic Games";
        $visitorMail->Body = '<h2>Thank you for registering, ' . $safeChildName . '!</h2>
<p>We are thrilled to welcome you to the Baby Olympic Games. Your registration ID is <strong>' . $safeRegId . '</strong>.</p>
<p>We have successfully received your registration and payment details.</p>
<br>
<p>Best regards,<br>Baby Olympic Games Team</p>' . $disclaimerHtml;
    } else {
        $visitorMail->Subject = "Thank you for contacting Baby Olympic Games";
        $visitorMail->Body = '<h2>Thank you, ' . $safeName . '!</h2>
<p>We have received your message and will get back to you shortly.</p>
<p><strong>Your Message:</strong><br>' . $safeMessage . '</p>
<br>
<p>Best regards,<br>Baby Olympic Games Team</p>' . $disclaimerHtml;
    }
    
    $visitorMail->send();

    respond(200, ['success' => true]);

} catch (Exception $exception) {
    $errorMsg = date('Y-m-d H:i:s') . " - Mailer Error: " . $exception->getMessage() . "\n";
    file_put_contents(__DIR__ . '/mail_errors.log', $errorMsg, FILE_APPEND);
    error_log('Contact email error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Email could not be sent. Error: ' . $exception->getMessage()]);
}
