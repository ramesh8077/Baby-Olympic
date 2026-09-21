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

    // --- Professional email wrapper ---
    $brandColor = '#8B3FE8';
    $headerHtml = '
    <div style="background:linear-gradient(135deg, #8B3FE8 0%, #3B1264 100%); padding:28px 24px; text-align:center; border-radius:12px 12px 0 0;">
      <h1 style="color:#fff; margin:0; font-family:Arial,sans-serif; font-size:22px; font-weight:700; letter-spacing:0.5px;">🏅 BABY OLYMPIC GAMES 2026</h1>
      <p style="color:#e0d0f5; margin:6px 0 0; font-family:Arial,sans-serif; font-size:13px;">Kanpur Olympic Association</p>
    </div>';
    $footerHtml = '
    <div style="background:#f8f5fc; padding:20px 24px; border-radius:0 0 12px 12px; text-align:center; border-top:1px solid #e8e0f0;">
      <p style="color:#6b5b7b; font-family:Arial,sans-serif; font-size:12px; margin:0;">Baby Olympic Games 2026 | Kanpur, Uttar Pradesh</p>
      <p style="color:#9a8baa; font-family:Arial,sans-serif; font-size:11px; margin:4px 0 0;">📧 babyolympicgames@gmail.com | 🌐 babyolympic.com</p>
    </div>';

    if ($formType === 'registration') {
        $regIdStr = htmlspecialchars($data['regId'] ?? 'Pending', ENT_QUOTES, 'UTF-8');
        $childNameStr = htmlspecialchars($data['childName'] ?? '', ENT_QUOTES, 'UTF-8');
        $dobStr = htmlspecialchars($data['dob'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $genderStr = htmlspecialchars($data['gender'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $ageStr = htmlspecialchars(isset($data['age']) ? $data['age'] . ' years' : 'Not provided', ENT_QUOTES, 'UTF-8');
        $categoryStr = htmlspecialchars($data['category'] ?? 'Auto-assigned', ENT_QUOTES, 'UTF-8');
        $gamesArr = isset($data['games']) && is_array($data['games']) ? $data['games'] : [];
        $gamesStr = !empty($gamesArr) ? htmlspecialchars(implode(', ', $gamesArr), ENT_QUOTES, 'UTF-8') : 'None selected';
        $schoolStr = htmlspecialchars($data['school'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $medicalStr = htmlspecialchars($data['medical'] ?? 'None', ENT_QUOTES, 'UTF-8');
        $stateStr = htmlspecialchars($data['state'] ?? '', ENT_QUOTES, 'UTF-8');
        $emNameStr = htmlspecialchars($data['emName'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $emPhoneStr = htmlspecialchars($data['emPhone'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $paymentIdStr = htmlspecialchars($data['paymentId'] ?? 'Pending', ENT_QUOTES, 'UTF-8');
        $hasPhoto = isset($data['photo']) && is_array($data['photo']) && !empty($data['photo']['data']) ? '✅ Attached' : '❌ Not uploaded';
        $hasCert = isset($data['cert']) && is_array($data['cert']) && !empty($data['cert']['data']) ? '✅ Attached' : '❌ Not uploaded';

        $trStyle = 'border-bottom:1px solid #f0ecf5;';
        $tdLabel = 'padding:10px 14px; font-weight:600; color:#3B1264; font-size:13px; width:40%; vertical-align:top; font-family:Arial,sans-serif;';
        $tdValue = 'padding:10px 14px; color:#333; font-size:13px; font-family:Arial,sans-serif;';

        $adminMail->Subject = "🏅 New Registration: {$childNameStr} | {$regIdStr}";
        $adminMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:24px;">
            <div style="background:#f0fdf4; border-left:4px solid #22c55e; padding:12px 16px; border-radius:6px; margin-bottom:20px;">
              <p style="margin:0; color:#15803d; font-size:14px; font-weight:600;">✅ New Registration Received</p>
              <p style="margin:4px 0 0; color:#166534; font-size:12px;">Registration ID: <strong>' . $regIdStr . '</strong> | Payment: <strong>' . $paymentIdStr . '</strong></p>
            </div>

            <h3 style="color:#3B1264; font-size:15px; margin:20px 0 10px; border-bottom:2px solid #8B3FE8; padding-bottom:6px;">👶 Child Information</h3>
            <table style="width:100%; border-collapse:collapse;">
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Child Name</td><td style="' . $tdValue . '"><strong>' . $childNameStr . '</strong></td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Date of Birth</td><td style="' . $tdValue . '">' . $dobStr . '</td></tr>
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Gender</td><td style="' . $tdValue . '">' . $genderStr . '</td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Age</td><td style="' . $tdValue . '">' . $ageStr . '</td></tr>
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Category</td><td style="' . $tdValue . '"><span style="background:#8B3FE8; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px;">' . $categoryStr . '</span></td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Selected Games</td><td style="' . $tdValue . '">' . $gamesStr . '</td></tr>
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">School</td><td style="' . $tdValue . '">' . $schoolStr . '</td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Medical Condition</td><td style="' . $tdValue . '">' . $medicalStr . '</td></tr>
            </table>

            <h3 style="color:#3B1264; font-size:15px; margin:24px 0 10px; border-bottom:2px solid #8B3FE8; padding-bottom:6px;">👨‍👩‍👧 Parent / Guardian Details</h3>
            <table style="width:100%; border-collapse:collapse;">
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Parent Name</td><td style="' . $tdValue . '"><strong>' . $safeName . '</strong></td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Mobile</td><td style="' . $tdValue . '">📱 ' . $safeMobile . '</td></tr>
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Email</td><td style="' . $tdValue . '">📧 ' . $safeEmail . '</td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">City</td><td style="' . $tdValue . '">' . $safeCity . '</td></tr>
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">State</td><td style="' . $tdValue . '">' . $stateStr . '</td></tr>
            </table>

            <h3 style="color:#3B1264; font-size:15px; margin:24px 0 10px; border-bottom:2px solid #8B3FE8; padding-bottom:6px;">🚨 Emergency Contact</h3>
            <table style="width:100%; border-collapse:collapse;">
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Contact Person</td><td style="' . $tdValue . '">' . $emNameStr . '</td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Emergency Phone</td><td style="' . $tdValue . '">📱 ' . $emPhoneStr . '</td></tr>
            </table>

            <h3 style="color:#3B1264; font-size:15px; margin:24px 0 10px; border-bottom:2px solid #8B3FE8; padding-bottom:6px;">📎 Documents</h3>
            <table style="width:100%; border-collapse:collapse;">
              <tr style="' . $trStyle . '"><td style="' . $tdLabel . '">Child Photograph</td><td style="' . $tdValue . '">' . $hasPhoto . '</td></tr>
              <tr style="' . $trStyle . ' background:#faf8fd;"><td style="' . $tdLabel . '">Birth Certificate</td><td style="' . $tdValue . '">' . $hasCert . '</td></tr>
            </table>
            <p style="color:#9a8baa; font-size:11px; margin-top:16px; text-align:center;">Documents are attached to this email if uploaded.</p>
          </div>
          ' . $footerHtml . '
        </div>';
    } else if ($formType === 'sponsor') {
        $adminMail->Subject = "🤝 New Sponsorship Inquiry: {$safeName}";
        $adminMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:24px;">
            <div style="background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:6px; margin-bottom:20px;">
              <p style="margin:0; color:#92400e; font-size:14px; font-weight:600;">🤝 New Sponsorship Inquiry</p>
            </div>
            ' . $detailsHtml . '
          </div>
          ' . $footerHtml . '
        </div>';
    } else {
        $adminMail->Subject = "📩 Contact Form: {$safeName}";
        $adminMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:24px;">
            <div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:12px 16px; border-radius:6px; margin-bottom:20px;">
              <p style="margin:0; color:#1e40af; font-size:14px; font-weight:600;">📩 New Contact Message</p>
            </div>
            ' . $detailsHtml . '
          </div>
          ' . $footerHtml . '
        </div>';
    }
    
    $adminMail->send();

    // ========== THANK YOU / ACKNOWLEDGMENT EMAIL ==========
    $visitorMail = configureMailer($gmailAddress, $gmailAppPassword);
    $visitorMail->addAddress($email, $name);
    
    if ($formType === 'registration') {
        $regIdStr = htmlspecialchars($data['regId'] ?? 'Pending', ENT_QUOTES, 'UTF-8');
        $childNameStr = htmlspecialchars($data['childName'] ?? '', ENT_QUOTES, 'UTF-8');
        $categoryStr = htmlspecialchars($data['category'] ?? 'Auto-assigned', ENT_QUOTES, 'UTF-8');
        $gamesArr = isset($data['games']) && is_array($data['games']) ? $data['games'] : [];
        $gamesChips = '';
        foreach ($gamesArr as $g) {
            $sg = htmlspecialchars($g, ENT_QUOTES, 'UTF-8');
            $gamesChips .= '<span style="display:inline-block; background:#f0ecf5; color:#3B1264; padding:4px 10px; border-radius:12px; font-size:12px; margin:2px 4px 2px 0;">' . $sg . '</span>';
        }
        if (empty($gamesChips)) $gamesChips = '<span style="color:#999;">None selected</span>';

        $visitorMail->Subject = "🎉 Registration Confirmed — Baby Olympic Games 2026 | {$regIdStr}";
        $visitorMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:28px 24px;">
            <h2 style="color:#3B1264; margin:0 0 4px; font-size:20px;">🎉 Congratulations, ' . $safeName . '!</h2>
            <p style="color:#6b5b7b; font-size:14px; margin:0 0 20px; line-height:1.5;">
              Your child <strong style="color:#8B3FE8;">' . $childNameStr . '</strong> has been successfully registered for the <strong>Baby Olympic Games 2026</strong> — India\'s youngest sporting movement!
            </p>

            <div style="background:#f8f5fc; border-radius:10px; padding:18px; margin-bottom:20px;">
              <table style="width:100%; font-size:13px; border-collapse:collapse;">
                <tr><td style="padding:6px 0; color:#6b5b7b; font-weight:600;">Registration ID</td><td style="padding:6px 0; color:#3B1264; font-weight:700;">' . $regIdStr . '</td></tr>
                <tr><td style="padding:6px 0; color:#6b5b7b; font-weight:600;">Child Name</td><td style="padding:6px 0; color:#333;">' . $childNameStr . '</td></tr>
                <tr><td style="padding:6px 0; color:#6b5b7b; font-weight:600;">Category</td><td style="padding:6px 0;"><span style="background:#8B3FE8; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px;">' . $categoryStr . '</span></td></tr>
                <tr><td style="padding:6px 0; color:#6b5b7b; font-weight:600; vertical-align:top;">Selected Games</td><td style="padding:6px 0;">' . $gamesChips . '</td></tr>
              </table>
            </div>

            <div style="background:linear-gradient(135deg, #fdf4ff 0%, #f0fdf4 100%); border:1px solid #e8e0f0; border-radius:10px; padding:18px; margin-bottom:20px;">
              <h3 style="color:#3B1264; margin:0 0 10px; font-size:15px;">📅 Event Details</h3>
              <table style="width:100%; font-size:13px; border-collapse:collapse;">
                <tr><td style="padding:5px 0; color:#6b5b7b;">📍 Venue</td><td style="padding:5px 0; color:#333; font-weight:600;">Kanpur, Uttar Pradesh</td></tr>
                <tr><td style="padding:5px 0; color:#6b5b7b;">🗓️ Dates</td><td style="padding:5px 0; color:#333; font-weight:600;">5 – 9 October 2026</td></tr>
                <tr><td style="padding:5px 0; color:#6b5b7b;">🏅 Organizer</td><td style="padding:5px 0; color:#333;">Kanpur Olympic Association</td></tr>
                <tr><td style="padding:5px 0; color:#6b5b7b;">👶 Age Group</td><td style="padding:5px 0; color:#333;">2 to 8 years</td></tr>
              </table>
            </div>

            <div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 16px; border-radius:6px; margin-bottom:20px;">
              <h3 style="color:#1e40af; margin:0 0 8px; font-size:14px;">📋 What Happens Next?</h3>
              <ol style="color:#334155; font-size:13px; margin:0; padding-left:18px; line-height:1.8;">
                <li>Our team will <strong>verify your registration details</strong> and uploaded documents.</li>
                <li>You will receive a <strong>confirmation call/SMS</strong> with venue details and schedule.</li>
                <li>An <strong>ID card</strong> will be prepared for your child before the event.</li>
                <li>Arrive at the venue on the event day with your child and this email for reference.</li>
              </ol>
            </div>

            <div style="text-align:center; margin:24px 0 10px;">
              <a href="https://babyolympic.com" style="display:inline-block; background:linear-gradient(135deg, #8B3FE8, #3B1264); color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-size:14px; font-weight:600;">Visit Baby Olympic Website →</a>
            </div>

            <p style="color:#9a8baa; font-size:12px; text-align:center; margin-top:20px; line-height:1.5;">
              If you have any questions, reply to this email or contact us at<br>
              📧 babyolympicgames@gmail.com
            </p>
          </div>
          ' . $footerHtml . '
        </div>';
    } else if ($formType === 'sponsor') {
        $visitorMail->Subject = "🤝 Thank You for Your Interest — Baby Olympic Games 2026";
        $visitorMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:28px 24px;">
            <h2 style="color:#3B1264; margin:0 0 6px; font-size:20px;">🤝 Thank You, ' . $safeName . '!</h2>
            <p style="color:#6b5b7b; font-size:14px; line-height:1.6; margin:0 0 16px;">
              We have received your sponsorship inquiry for the <strong>Baby Olympic Games 2026</strong>. We\'re excited about the opportunity to partner with you!
            </p>
            <p style="color:#6b5b7b; font-size:14px; line-height:1.6; margin:0 0 16px;">
              Baby Olympic Games is India\'s biggest sporting event for children aged 2-8, organized by the Kanpur Olympic Association. Your support will help inspire the next generation of champions.
            </p>
            <div style="background:#fef3c7; border-left:4px solid #f59e0b; padding:14px 16px; border-radius:6px; margin-bottom:20px;">
              <p style="margin:0; color:#92400e; font-size:13px;"><strong>Next Steps:</strong> Our partnership team will review your details and reach out within 24-48 hours to discuss collaboration opportunities and sponsorship tiers.</p>
            </div>
            <p style="color:#9a8baa; font-size:12px; text-align:center; margin-top:20px;">
              📧 babyolympicgames@gmail.com | 🌐 babyolympic.com
            </p>
          </div>
          ' . $footerHtml . '
        </div>';
    } else {
        $visitorMail->Subject = "📩 We Received Your Message — Baby Olympic Games 2026";
        $visitorMail->Body = '
        <div style="max-width:600px; margin:0 auto; font-family:Arial,sans-serif; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(59,18,100,0.08);">
          ' . $headerHtml . '
          <div style="padding:28px 24px;">
            <h2 style="color:#3B1264; margin:0 0 6px; font-size:20px;">Thank You, ' . $safeName . '!</h2>
            <p style="color:#6b5b7b; font-size:14px; line-height:1.6; margin:0 0 16px;">
              We have received your message and our team will get back to you shortly.
            </p>
            <div style="background:#f8f5fc; border-radius:8px; padding:14px 16px; margin-bottom:16px;">
              <p style="margin:0 0 4px; color:#3B1264; font-weight:600; font-size:13px;">Your Message:</p>
              <p style="margin:0; color:#333; font-size:13px; line-height:1.5;">' . $safeMessage . '</p>
            </div>
            <p style="color:#6b5b7b; font-size:13px; line-height:1.5; margin:0 0 16px;">
              <strong>Baby Olympic Games 2026</strong> is India\'s largest sporting event for children aged 2-8, taking place in <strong>Kanpur from 5-9 October 2026</strong>. Visit <a href="https://babyolympic.com" style="color:#8B3FE8;">babyolympic.com</a> to learn more.
            </p>
            <p style="color:#9a8baa; font-size:12px; text-align:center; margin-top:20px;">
              📧 babyolympicgames@gmail.com | 🌐 babyolympic.com
            </p>
          </div>
          ' . $footerHtml . '
        </div>';
    }
    
    $visitorMail->send();

    respond(200, ['success' => true]);

} catch (Exception $exception) {
    $errorMsg = date('Y-m-d H:i:s') . " - Mailer Error: " . $exception->getMessage() . "\n";
    file_put_contents(__DIR__ . '/mail_errors.log', $errorMsg, FILE_APPEND);
    error_log('Contact email error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Email could not be sent. Error: ' . $exception->getMessage()]);
}

