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

function safe(mixed $value, string $fallback = 'Not provided'): string
{
    $trimmed = clean($value);
    return $trimmed !== '' ? htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') : $fallback;
}

/**
 * One consistent, well-formatted email "shell" (purple header banner,
 * white card body, footer, and a disclaimer that lives INSIDE the same
 * card instead of being tacked on after it). Every email we send goes
 * through this so the layout never breaks again.
 */
function renderEmailShell(string $headerEmoji, string $headerTitle, string $headerSubtitle, string $bodyHtml, string $disclaimerHtml): string
{
    return <<<HTML
<div style="font-family: Arial, Helvetica, sans-serif; background-color:#f4f4f4; padding:24px 12px;">
  <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
    <div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:28px 24px;text-align:center;color:#ffffff;">
      <div style="font-size:24px;font-weight:bold;">{$headerEmoji} {$headerTitle}</div>
      <div style="font-size:13px;opacity:0.9;margin-top:6px;">{$headerSubtitle}</div>
    </div>
    <div style="padding:24px;color:#333333;">
      {$bodyHtml}
    </div>
    <div style="background:#f4f0fb;padding:16px 24px;text-align:center;font-size:12px;color:#6B21C9;">
      Baby Olympic Games 2026 | Kanpur, Uttar Pradesh<br>
      babyolympicgames@gmail.com &nbsp;|&nbsp; babyolympic.com
    </div>
    <div style="padding:14px 24px 20px;">
      <hr style="border:0;border-top:1px solid #eee;margin:0 0 12px;">
      {$disclaimerHtml}
    </div>
  </div>
</div>
HTML;
}

function sectionHeading(string $emoji, string $title): string
{
    return "<h3 style=\"color:#6B21C9;border-bottom:2px solid #6B21C9;padding-bottom:6px;margin:22px 0 10px;font-size:15px;\">{$emoji} {$title}</h3>";
}

function detailRow(string $label, string $value): string
{
    return "<tr><td style=\"padding:9px 10px;font-weight:bold;width:42%;border:1px solid #eee;background:#faf8ff;font-size:13px;color:#444;\">{$label}</td><td style=\"padding:9px 10px;border:1px solid #eee;font-size:13px;color:#222;\">{$value}</td></tr>";
}

function detailTable(array $rows): string
{
    return '<table style="width:100%;border-collapse:collapse;">' . implode('', $rows) . '</table>';
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

$safeName = safe($name);
$safeMobile = safe($mobile);
$safeEmail = safe($email);
$safeCity = safe($city);
$safePreferredModel = safe($preferredModel);
$safeEnquiryType = safe($enquiryType);
$safeMessage = nl2br(htmlspecialchars($message !== '' ? $message : 'Not provided', ENT_QUOTES, 'UTF-8'));

$safeAge = isset($data['age']) && $data['age'] !== '' ? safe((string) $data['age']) : 'Not provided';
$safeCategory = safe($data['category'] ?? '');
$ageAndCategory = $safeCategory; // The category string already contains the age range (e.g. "Champion Squad (6½–8½ Years)")

// Disclaimer text lives inside the card in renderEmailShell(), so this is
// just the paragraph itself (no <hr>, that's part of the shell already).
$disclaimerHtml = '<p style="font-size: 11px; color: #888; text-align: justify; line-height:1.5; margin:0;"><strong>Disclaimer:</strong> This email and any attachments are confidential and intended solely for the named recipient. If you have received this email in error, please notify the sender immediately and delete it from your system. Any unauthorised review, use, disclosure or distribution is prohibited. The views expressed in this email are those of the sender and do not necessarily represent those of the Kanpur Olympic Association. While reasonable care has been taken, the sender does not accept liability for any damage caused by viruses or errors in transmission.</p>';

$adminSubject = '';
$adminMailBody = '';
$visitorSubject = '';
$visitorMailBody = '';

if ($formType === 'registration') {
    $safeChildName = safe($data['childName'] ?? '');
    $safeDob = safe($data['dob'] ?? '');
    $safeGender = safe($data['gender'] ?? '');
    $safeRegId = safe($data['regId'] ?? '');
    $safePaymentId = safe($data['paymentId'] ?? '');
    $safeSchool = safe($data['school'] ?? '');
    $safeMedical = safe($data['medical'] ?? '', 'None');
    $safeRelation = safe($data['relation'] ?? '');
    $safeState = safe($data['state'] ?? '');
    $safeEmergencyName = safe(
        $data['emName']
        ?? $data['emergencyName']
        ?? $data['emergency_name']
        ?? $data['emergencyContactName']
        ?? $data['emergencyContactPerson']
        ?? $data['emergency_contact_person']
        ?? $data['emergencyContact']
        ?? $data['emergencyPerson']
        ?? ''
    );
    $safeEmergencyPhone = safe(
        $data['emPhone']
        ?? $data['emergencyPhone']
        ?? $data['emergency_phone']
        ?? $data['emergencyContactPhone']
        ?? $data['emergency_contact_phone']
        ?? $data['emergencyMobile']
        ?? $data['emergencyNumber']
        ?? ''
    );

    if (isset($data['amount']) && is_numeric($data['amount']) && (float) $data['amount'] > 0) {
        // Razorpay amounts are always in paise — convert to rupees for display.
        $amountValue = ((float) $data['amount']) / 100;
    } else {
        $gamesCount = (isset($data['games']) && is_array($data['games'])) ? count($data['games']) : 0;
        $amountValue = max(1, $gamesCount) * 250;
    }
    $safeAmount = "₹" . number_format($amountValue, 2);
    $safeGames = (isset($data['games']) && is_array($data['games']) && $data['games'] !== [])
        ? htmlspecialchars(implode(', ', $data['games']), ENT_QUOTES, 'UTF-8')
        : 'Not provided';

    $hasPhoto = isset($data['photo']) && is_array($data['photo']) && isset($data['photo']['data']);
    $hasCert = isset($data['cert']) && is_array($data['cert']) && isset($data['cert']['data']);

    $successBox = "<div style=\"background:#e9f9ee;border:1px solid #b7e8c4;border-radius:8px;padding:14px 16px;margin-bottom:6px;\">
        <div style=\"color:#1a7f37;font-weight:bold;font-size:14px;\">✅ New Registration Received</div>
        <div style=\"color:#2f6f45;font-size:12px;margin-top:4px;\">Registration ID: <strong>{$safeRegId}</strong> &nbsp;|&nbsp; Payment: <strong>{$safePaymentId}</strong></div>
    </div>";

    $categoryBadge = "<span style=\"background:#6B21C9;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;\">{$safeCategory}</span>";

    $childTable = detailTable([
        detailRow('Child Name', $safeChildName),
        detailRow('Date of Birth', $safeDob),
        detailRow('Gender', $safeGender),
        detailRow('Age', $safeAge),
        detailRow('Age &amp; Category', $categoryBadge),
        detailRow('Selected Games', $safeGames),
        detailRow('School', $safeSchool),
        detailRow('Medical Condition', $safeMedical),
    ]);

    $parentTable = detailTable([
        detailRow('Parent / Guardian Name', $safeName),
        detailRow('Relation with Child', $safeRelation),
        detailRow('Mobile', $safeMobile),
        detailRow('Email', $safeEmail),
        detailRow('City', $safeCity),
        detailRow('State', $safeState),
    ]);

    $emergencyTable = detailTable([
        detailRow('Contact Person', $safeEmergencyName),
        detailRow('Emergency Phone', $safeEmergencyPhone),
    ]);

    $paymentTable = detailTable([
        detailRow('Amount', $safeAmount),
        detailRow('Payment ID', $safePaymentId),
    ]);

    $docsTable = detailTable([
        detailRow('Child Photograph', $hasPhoto ? '✅ Attached' : '— Not attached'),
        detailRow('Birth Certificate', $hasCert ? '✅ Attached' : '— Not attached'),
    ]) . '<p style="font-size:11px;color:#999;text-align:center;margin-top:8px;">Documents are attached to this email if uploaded.</p>';

    $registrationBody = $successBox
        . sectionHeading('👶', 'Child Information') . $childTable
        . sectionHeading('👨‍👩‍👦', 'Parent / Guardian Details') . $parentTable
        . sectionHeading('🚨', 'Emergency Contact') . $emergencyTable
        . sectionHeading('💳', 'Payment') . $paymentTable
        . sectionHeading('📎', 'Documents') . $docsTable;

    $adminSubject = "New Registration: {$safeChildName} | {$safeRegId}";
    $adminMailBody = renderEmailShell('🏅', 'BABY OLYMPIC GAMES 2026', 'Kanpur Olympic Association', $registrationBody, $disclaimerHtml);

    $visitorSubject = "Registration Confirmation - Baby Olympic Games";
    $visitorBody = "<h2 style=\"color:#6B21C9;margin-top:0;\">Thank you for registering, {$safeChildName}!</h2>
        <p>We are thrilled to welcome you to the Baby Olympic Games. Your registration ID is <strong>{$safeRegId}</strong>.</p>
        <p>We have successfully received your registration and payment details.</p>
        <p style=\"margin-top:20px;\">Best regards,<br>Baby Olympic Games Team</p>";
    $visitorMailBody = renderEmailShell('🏅', 'Registration Confirmed', 'Baby Olympic Games 2026', $visitorBody, $disclaimerHtml);
} else {
    $contactTable = detailTable([
        detailRow('Name', $safeName),
        detailRow('Mobile Number', $safeMobile),
        detailRow('Email', $safeEmail),
        detailRow('City', $safeCity),
        detailRow('Age &amp; Category', $ageAndCategory),
        detailRow('Preferred Model', $safePreferredModel),
        detailRow('Enquiry Type', $safeEnquiryType),
        detailRow('Message', $safeMessage),
    ]);

    $adminSubject = "New contact enquiry from {$name}";
    $adminMailBody = renderEmailShell('✉️', 'New Contact Form Submission', 'Baby Olympic Games 2026', $contactTable, $disclaimerHtml);

    $visitorSubject = "Thank you for contacting Baby Olympic Games";
    $visitorBody = "<h2 style=\"color:#6B21C9;margin-top:0;\">Thank you, {$safeName}!</h2>
        <p>We have received your message and will get back to you shortly.</p>
        <p><strong>Your Message:</strong><br>{$safeMessage}</p>
        <p style=\"margin-top:20px;\">Best regards,<br>Baby Olympic Games Team</p>";
    $visitorMailBody = renderEmailShell('✉️', 'Message Received', 'Baby Olympic Games 2026', $visitorBody, $disclaimerHtml);
}

function configureMailer(string $gmailAddress, string $gmailAppPassword): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 3;
    $mail->Debugoutput = function ($str, $level) {
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
        'age' => isset($data['age']) ? (int) $data['age'] : null,
        'category' => clean($data['category'] ?? ''),
        'games' => isset($data['games']) && is_array($data['games']) ? $data['games'] : [],
        'city' => clean($data['city'] ?? $city),
        'state' => clean($data['state'] ?? ''),
        'school' => clean($data['school'] ?? ''),
        'medical' => clean($data['medical'] ?? ''),
        'regId' => clean($data['regId'] ?? ''),
        'relation' => clean($data['relation'] ?? ''),
        'relationWithChild' => clean($data['relation'] ?? ''),
        'emName' => clean($data['emName'] ?? $data['emergencyName'] ?? $data['emergency_name'] ?? $data['emergencyContactName'] ?? $data['emergencyContactPerson'] ?? $data['emergency_contact_person'] ?? $data['emergencyContact'] ?? $data['emergencyPerson'] ?? ''),
        'emergencyName' => clean($data['emName'] ?? $data['emergencyName'] ?? $data['emergency_name'] ?? $data['emergencyContactName'] ?? $data['emergencyContactPerson'] ?? $data['emergency_contact_person'] ?? $data['emergencyContact'] ?? $data['emergencyPerson'] ?? ''),
        'emergency_name' => clean($data['emName'] ?? $data['emergencyName'] ?? $data['emergency_name'] ?? $data['emergencyContactName'] ?? $data['emergencyContactPerson'] ?? $data['emergency_contact_person'] ?? $data['emergencyContact'] ?? $data['emergencyPerson'] ?? ''),
        'emPhone' => clean($data['emPhone'] ?? $data['emergencyPhone'] ?? $data['emergency_phone'] ?? $data['emergencyContactPhone'] ?? $data['emergency_contact_phone'] ?? $data['emergencyMobile'] ?? $data['emergencyNumber'] ?? ''),
        'emergencyPhone' => clean($data['emPhone'] ?? $data['emergencyPhone'] ?? $data['emergency_phone'] ?? $data['emergencyContactPhone'] ?? $data['emergency_contact_phone'] ?? $data['emergencyMobile'] ?? $data['emergencyNumber'] ?? ''),
        'emergency_phone' => clean($data['emPhone'] ?? $data['emergencyPhone'] ?? $data['emergency_phone'] ?? $data['emergencyContactPhone'] ?? $data['emergency_contact_phone'] ?? $data['emergencyMobile'] ?? $data['emergencyNumber'] ?? ''),
    ]);
} elseif ($formType === 'sponsor') {
    $endpoint = '/api/public/sponsor';
    $payload = json_encode([
        'companyName' => clean($data['company'] ?? 'Unknown Company'),
        'contactName' => clean($data['fullName'] ?? $name),
        'phone' => clean($data['mobile'] ?? $mobile),
        'email' => clean($data['email'] ?? $email),
        'tier' => clean($data['designation'] ?? 'general'),
        'interestedIn' => clean($data['message'] ?? $message),
    ]);
} else {
    $endpoint = '/api/public/enquiry';
    $payload = json_encode([
        'name' => $name,
        'phone' => $mobile,
        'email' => $email,
        'message' => $message !== '' ? $message : 'No message provided',
    ]);
}

$ch = curl_init($admin_api_url . $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $admin_api_key,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_exec($ch);
curl_close($ch);
// --- End Notify Admin Panel ---

try {
    // Send visitor information to the admin Gmail account.
    $adminMail = configureMailer($gmailAddress, $gmailAppPassword);
    $adminMail->addAddress($adminEmail);
    $adminMail->addReplyTo($email, $name);
    $adminMail->Subject = $adminSubject;
    $adminMail->Body = $adminMailBody;

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
    $visitorMail->Subject = $visitorSubject;
    $visitorMail->Body = $visitorMailBody;
    $visitorMail->send();

    respond(200, ['success' => true]);
} catch (Exception $exception) {
    $errorMsg = date('Y-m-d H:i:s') . " - Mailer Error: " . $exception->getMessage() . "\n";
    file_put_contents(__DIR__ . '/mail_errors.log', $errorMsg, FILE_APPEND);
    error_log('Contact email error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Email could not be sent. Error: ' . $exception->getMessage()]);
}