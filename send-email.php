<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit();
}

// Read raw JSON input
$input = file_get_contents("php://input");
$requestData = json_decode($input, true);

if (!$requestData || !isset($requestData['data'])) {
    echo json_encode(["success" => false, "error" => "Missing form data"]);
    exit();
}

$formType = $requestData['formType'] ?? 'general';
$data = $requestData['data'];

$toEmail = "babyolympicgames@gmail.com";
$smtpHost = "ssl://smtp.gmail.com";
$smtpPort = 465;
$smtpUser = "babyolympicgames@gmail.com";
$smtpPass = "cxqmazxwadaydnmy";

$subject = "New Enquiry - Baby Olympic Games";
$body = "";
$attachments = [];

if ($formType === 'contact') {
    $name = htmlspecialchars($data['name'] ?? 'N/A');
    $mobile = htmlspecialchars($data['mobile'] ?? 'N/A');
    $email = htmlspecialchars($data['email'] ?? 'N/A');
    $type = htmlspecialchars($data['type'] ?? 'General');
    $msg = nl2br(htmlspecialchars($data['msg'] ?? ''));

    $subject = "📩 Contact Enquiry from " . $name;
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; background-color: #ffffff;'>
      <h2 style='color: #3B1264; border-bottom: 2px solid #8B3FE8; padding-bottom: 10px;'>Baby Olympic Games - Contact Enquiry</h2>
      <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
        <tr><td style='padding: 8px; font-weight: bold; width: 140px; color: #555;'>Name:</td><td style='padding: 8px;'>$name</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 8px; font-weight: bold; color: #555;'>Mobile:</td><td style='padding: 8px;'><a href='tel:$mobile'>$mobile</a></td></tr>
        <tr><td style='padding: 8px; font-weight: bold; color: #555;'>Email:</td><td style='padding: 8px;'><a href='mailto:$email'>$email</a></td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 8px; font-weight: bold; color: #555;'>Enquiry Type:</td><td style='padding: 8px;'>$type</td></tr>
        <tr><td style='padding: 8px; font-weight: bold; color: #555; vertical-align: top;'>Message:</td><td style='padding: 8px; line-height: 1.5;'>$msg</td></tr>
      </table>
      <div style='margin-top: 25px; padding-top: 15px; border-top: 1px solid #eeeeee; font-size: 12px; color: #888; text-align: center;'>
        Sent automatically from Baby Olympic Games Kanpur 2026 Website
      </div>
    </div>";

} else if ($formType === 'registration') {
    $regId = htmlspecialchars($data['regId'] ?? 'N/A');
    $parentName = htmlspecialchars($data['parentName'] ?? 'N/A');
    $mobile = htmlspecialchars($data['mobile'] ?? 'N/A');
    $email = htmlspecialchars($data['email'] ?? 'N/A');
    $city = htmlspecialchars($data['city'] ?? '');
    $state = htmlspecialchars($data['state'] ?? '');
    $childName = htmlspecialchars($data['childName'] ?? 'N/A');
    $dob = htmlspecialchars($data['dob'] ?? 'N/A');
    $gender = htmlspecialchars($data['gender'] ?? 'N/A');
    $category = htmlspecialchars($data['category'] ?? 'N/A');
    $games = is_array($data['games'] ?? null) ? implode(", ", $data['games']) : htmlspecialchars($data['games'] ?? 'N/A');
    $school = htmlspecialchars($data['school'] ?? 'Not specified');
    $medical = htmlspecialchars($data['medical'] ?? 'None');
    $emName = htmlspecialchars($data['emName'] ?? 'N/A');
    $emPhone = htmlspecialchars($data['emPhone'] ?? 'N/A');

    $photoAttached = !empty($data['photo']['data']) ? "Attached (" . htmlspecialchars($data['photo']['name']) . ")" : "Not uploaded";
    $certAttached = !empty($data['cert']['data']) ? "Attached (" . htmlspecialchars($data['cert']['name']) . ")" : "Not uploaded";

    if (!empty($data['photo']['data'])) {
        $attachments[] = $data['photo'];
    }
    if (!empty($data['cert']['data'])) {
        $attachments[] = $data['cert'];
    }

    $subject = "🏅 New Registration Enquiry [$regId] - " . $childName;
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; background-color: #ffffff;'>
      <h2 style='color: #3B1264; border-bottom: 2px solid #FFD400; padding-bottom: 10px;'>Baby Olympic Games - Registration Details</h2>
      <div style='background-color: #f0e4fa; padding: 12px; border-radius: 6px; font-weight: bold; color: #3B1264; margin-bottom: 15px;'>
        Registration ID: $regId
      </div>
      
      <h3 style='color: #8B3FE8; margin-top: 20px;'>Parent Information</h3>
      <table style='width: 100%; border-collapse: collapse;'>
        <tr><td style='padding: 6px; font-weight: bold; width: 150px; color: #555;'>Parent Name:</td><td style='padding: 6px;'>$parentName</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>Mobile:</td><td style='padding: 6px;'><a href='tel:$mobile'>$mobile</a></td></tr>
        <tr><td style='padding: 6px; font-weight: bold; color: #555;'>Email:</td><td style='padding: 6px;'><a href='mailto:$email'>$email</a></td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>City / State:</td><td style='padding: 6px;'>$city, $state</td></tr>
      </table>

      <h3 style='color: #8B3FE8; margin-top: 20px;'>Child Details</h3>
      <table style='width: 100%; border-collapse: collapse;'>
        <tr><td style='padding: 6px; font-weight: bold; width: 150px; color: #555;'>Child Name:</td><td style='padding: 6px;'>$childName</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>Date of Birth:</td><td style='padding: 6px;'>$dob</td></tr>
        <tr><td style='padding: 6px; font-weight: bold; color: #555;'>Gender:</td><td style='padding: 6px;'>$gender</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>Category:</td><td style='padding: 6px;'>$category</td></tr>
        <tr><td style='padding: 6px; font-weight: bold; color: #555;'>Selected Games:</td><td style='padding: 6px;'>$games</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>School Name:</td><td style='padding: 6px;'>$school</td></tr>
        <tr><td style='padding: 6px; font-weight: bold; color: #555;'>Medical Notes:</td><td style='padding: 6px;'>$medical</td></tr>
      </table>

      <h3 style='color: #8B3FE8; margin-top: 20px;'>Emergency Contact</h3>
      <table style='width: 100%; border-collapse: collapse;'>
        <tr><td style='padding: 6px; font-weight: bold; width: 150px; color: #555;'>Contact Name:</td><td style='padding: 6px;'>$emName</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>Emergency Phone:</td><td style='padding: 6px;'><a href='tel:$emPhone'>$emPhone</a></td></tr>
      </table>

      <h3 style='color: #8B3FE8; margin-top: 20px;'>Uploaded Documents</h3>
      <table style='width: 100%; border-collapse: collapse;'>
        <tr><td style='padding: 6px; font-weight: bold; width: 150px; color: #555;'>Child Photograph:</td><td style='padding: 6px;'>$photoAttached</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 6px; font-weight: bold; color: #555;'>Birth Certificate:</td><td style='padding: 6px;'>$certAttached</td></tr>
      </table>

      <div style='margin-top: 25px; padding-top: 15px; border-top: 1px solid #eeeeee; font-size: 12px; color: #888; text-align: center;'>
        Sent automatically from Baby Olympic Games Kanpur 2026 Website
      </div>
    </div>";

} else if ($formType === 'sponsor') {
    $fullName = htmlspecialchars($data['fullName'] ?? 'N/A');
    $company = htmlspecialchars($data['company'] ?? 'N/A');
    $email = htmlspecialchars($data['email'] ?? 'N/A');
    $mobile = htmlspecialchars($data['mobile'] ?? 'N/A');
    $designation = htmlspecialchars($data['designation'] ?? 'N/A');
    $message = nl2br(htmlspecialchars($data['message'] ?? ''));

    $subject = "💼 Sponsorship Enquiry from " . ($company !== 'N/A' ? $company : $fullName);
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; background-color: #ffffff;'>
      <h2 style='color: #3B1264; border-bottom: 2px solid #00B894; padding-bottom: 10px;'>Baby Olympic Games - Sponsorship Enquiry</h2>
      <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
        <tr><td style='padding: 8px; font-weight: bold; width: 140px; color: #555;'>Full Name:</td><td style='padding: 8px;'>$fullName</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 8px; font-weight: bold; color: #555;'>Company Name:</td><td style='padding: 8px;'>$company</td></tr>
        <tr><td style='padding: 8px; font-weight: bold; color: #555;'>Designation:</td><td style='padding: 8px;'>$designation</td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 8px; font-weight: bold; color: #555;'>Email:</td><td style='padding: 8px;'><a href='mailto:$email'>$email</a></td></tr>
        <tr><td style='padding: 8px; font-weight: bold; color: #555;'>Mobile:</td><td style='padding: 8px;'><a href='tel:$mobile'>$mobile</a></td></tr>
        <tr style='background-color: #f9f9f9;'><td style='padding: 8px; font-weight: bold; color: #555; vertical-align: top;'>Message:</td><td style='padding: 8px; line-height: 1.5;'>$message</td></tr>
      </table>
      <div style='margin-top: 25px; padding-top: 15px; border-top: 1px solid #eeeeee; font-size: 12px; color: #888; text-align: center;'>
        Sent automatically from Baby Olympic Games Kanpur 2026 Website
      </div>
    </div>";
}

// Send email using SMTP via Socket or PHP mail()
function sendSmtpEmail($host, $port, $user, $pass, $to, $subject, $htmlContent, $attachments = []) {
    $boundary = "----=_NextPart_" . md5(time() . rand());

    if (!empty($attachments)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: Baby Olympic Games <" . $user . ">\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject . "\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

        $mailHeaders  = "MIME-Version: 1.0\r\n";
        $mailHeaders .= "From: Baby Olympic Games <" . $user . ">\r\n";
        $mailHeaders .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

        $emailBody  = "--" . $boundary . "\r\n";
        $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
        $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $emailBody .= $htmlContent . "\r\n\r\n";

        foreach ($attachments as $att) {
            if (empty($att['data'])) continue;
            $attName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $att['name'] ?? 'attachment');
            $attType = !empty($att['type']) ? $att['type'] : 'application/octet-stream';
            
            $base64Data = $att['data'];
            if (strpos($base64Data, ',') !== false) {
                $base64Data = explode(',', $base64Data)[1];
            }
            $chunkedData = chunk_split($base64Data);

            $emailBody .= "--" . $boundary . "\r\n";
            $emailBody .= "Content-Type: " . $attType . "; name=\"" . $attName . "\"\r\n";
            $emailBody .= "Content-Disposition: attachment; filename=\"" . $attName . "\"\r\n";
            $emailBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $emailBody .= $chunkedData . "\r\n\r\n";
        }
        $emailBody .= "--" . $boundary . "--";
    } else {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: Baby Olympic Games <" . $user . ">\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject . "\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        $mailHeaders  = "MIME-Version: 1.0\r\n";
        $mailHeaders .= "From: Baby Olympic Games <" . $user . ">\r\n";
        $mailHeaders .= "Content-Type: text/html; charset=UTF-8\r\n";

        $emailBody = $htmlContent;
    }

    $socket = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) {
        // Fallback to PHP mail()
        return mail($to, $subject, $emailBody, $mailHeaders);
    }

    $read = function($sock) {
        $data = "";
        while ($str = fgets($sock, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $data;
    };

    $send = function($sock, $cmd) use ($read) {
        fputs($sock, $cmd . "\r\n");
        return $read($sock);
    };

    $read($socket);
    $send($socket, "EHLO " . gethostname());
    $send($socket, "AUTH LOGIN");
    $send($socket, base64_encode($user));
    $send($socket, base64_encode($pass));
    $send($socket, "MAIL FROM: <" . $user . ">");
    $send($socket, "RCPT TO: <" . $to . ">");
    $send($socket, "DATA");

    $content = $headers . "\r\n" . $emailBody . "\r\n.";
    $send($socket, $content);
    $send($socket, "QUIT");
    fclose($socket);

    return true;
}

$sent = sendSmtpEmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $toEmail, $subject, $body, $attachments);

if ($sent) {
    echo json_encode(["success" => true, "message" => "Email sent successfully to " . $toEmail]);
} else {
    echo json_encode(["success" => false, "error" => "Failed to send email"]);
}
?>
