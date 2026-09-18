<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
require __DIR__ . '/vendor/autoload.php';

// EDIT THESE TWO LINES EXACTLY
$gmailAddress = 'babyolympicgames@gmail.com';
$gmailAppPassword = 'rlzxeagqhghizbxa'; 

echo "<pre>";
echo "Testing SMTP for: " . $gmailAddress . "\n\n";

$mail = new PHPMailer(true);
$mail->SMTPDebug = 3;
$mail->Debugoutput = 'html';
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = $gmailAddress;
$mail->Password = $gmailAppPassword;
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
$mail->setFrom($gmailAddress, 'Test');
$mail->addAddress($gmailAddress);
$mail->Subject = 'Test Email';
$mail->Body = 'This is a test email.';

try {
    $mail->send();
    echo "\nSUCCESS! Email was sent.";
} catch (Exception $e) {
    echo "\nFAILED! " . $e->getMessage();
}
echo "</pre>";
