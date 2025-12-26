<?php
// backend/api/create_company.php
// Expects POST: company_name, services, admin_name, admin_email, admin_password
// Returns JSON (always valid) — triggers OTP verification redirect after success

header('Content-Type: application/json; charset=utf-8');

// ---------------------- Load PHPMailer ----------------------
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// ------------------------------------------------------------

// include database and create connection
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

function json_exit($arr) {
    echo json_encode($arr, JSON_PRETTY_PRINT);
    exit;
}

try {
    // Collect input
    $company_name = trim($_POST['company_name'] ?? '');
    $services     = trim($_POST['services'] ?? '');
    $admin_name   = trim($_POST['admin_name'] ?? '');
    $admin_email  = trim($_POST['admin_email'] ?? '');
    $admin_pass   = $_POST['admin_password'] ?? '';

    if (!$company_name || !$admin_email || !$admin_pass || !$admin_name) {
        json_exit(['success' => false, 'message' => 'Missing required fields.']);
    }

    // Split admin name
    $names = explode(' ', $admin_name, 2);
    $admin_first = $names[0];
    $admin_last  = $names[1] ?? '';

    // Check duplicates
    $stmt = $pdo->prepare("SELECT company_id FROM Companies WHERE company_name = ?");
    $stmt->execute([$company_name]);
    if ($stmt->fetch()) json_exit(['success' => false, 'message' => 'Company name already exists.']);

    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
    $stmt->execute([$admin_email]);
    if ($stmt->fetch()) json_exit(['success' => false, 'message' => 'Admin email already registered.']);

    $pdo->beginTransaction();

    // Generate admin key
    $admin_key = bin2hex(random_bytes(16)); // 32 hex chars

    // Insert company
    $stmt = $pdo->prepare("
        INSERT INTO Companies (company_name, services, admin_key, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$company_name, $services, $admin_key]);
    $company_id = $pdo->lastInsertId();

    // Create admin user
    $password_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
    $status = 'Inactive';
    $role = 'Admin';

    $stmt = $pdo->prepare("
        INSERT INTO Users (company_id, email, password_hash, first_name, last_name, role, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$company_id, $admin_email, $password_hash, $admin_first, $admin_last, $role, $status]);
    $admin_user_id = $pdo->lastInsertId();

    // Update Companies.admin_user_id
    $stmt = $pdo->prepare("UPDATE Companies SET admin_user_id = ? WHERE company_id = ?");
    $stmt->execute([$admin_user_id, $company_id]);

    // Create OTP
    $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO UserOTPs (user_id, email, company_id, otp_code, purpose, used, expiry, created_at)
        VALUES (?, ?, ?, ?, 'register', 0, ?, NOW())
    ");
    $stmt->execute([$admin_user_id, $admin_email, $company_id, $otp_code, $expiry]);

    $pdo->commit();

    // ---------------------- PHPMailer Email OTP ----------------------
    $mail = new PHPMailer(true);
    $mailSent = false;

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'examplemail@mail.com';   // placeholder email for publishing
        $mail->Password   = 'example password';        // placeholder: replace with real SMTP app password in production
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('examplemail@mail.com', 'RemoteTeamPro');
        $mail->addAddress($admin_email, $admin_first);

        $mail->Subject = "Your RemoteTeamPro Registration OTP";

        $otp_html = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h3>Hello {$admin_first},</h3>
            <p>Thank you for registering with <b>RemoteTeamPro</b>.</p>
            <p>Use the following verification code to complete your registration:</p>
            <h2 style='color:#6b46c1;'>{$otp_code}</h2>
            <p>This code will expire in 15 minutes.</p>
            <p>If you did not request this, please ignore this email.</p>
            <br><p>Best regards,<br>RemoteTeamPro Team</p>
        </body>
        </html>";

        $mail->isHTML(true);
        $mail->Body    = $otp_html;
        $mail->AltBody = "Hello {$admin_first}, your OTP is {$otp_code}. It expires in 15 minutes.";

        $mail->send();
        $mailSent = true;
    } catch (Exception $e) {
        $mailSent = false;
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
    }
    // ----------------------------------------------------------------

    // ✅ Return safe JSON with redirect link
$verify_url = "http://localhost/RemoteTeamPro/backend/api/verify_admin_otp_page.php?user_id={$admin_user_id}&email=" . urlencode($admin_email);

    json_exit([
        'success' => true,
        'message' => $mailSent
            ? 'Company created successfully. OTP sent to admin email.'
            : 'Company created, but OTP email could not be sent (check logs).',
        'admin_key' => $admin_key,
        'company_id' => (int)$company_id,
        'admin_user_id' => (int)$admin_user_id,
        'mail_sent' => $mailSent,
        'redirect_url' => $verify_url
    ]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("create_company.php error: " . $e->getMessage());
    json_exit(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
