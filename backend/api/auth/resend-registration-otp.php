<?php
// backend/api/auth/resend-registration-otp.php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

// CORS headers
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Cookie');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (empty($data['email'])) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit();
}

$email = sanitizeInput($data['email']);

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Check if there's a pending registration for this email
    $stmt = $pdo->prepare("SELECT * FROM UserRegistrationOTP WHERE email = ? AND expires_at > NOW()");
    $stmt->execute([$email]);
    $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingRecord) {
        echo json_encode(['success' => false, 'message' => 'No pending registration found for this email. Please register again.']);
        exit();
    }
    
    // Generate new OTP and extend expiry by 10 minutes (computed in DB for consistency)
    $newOtp = generateOTP();
    
    // Update the existing record with new OTP and new expiry
    // Use email in WHERE clause to avoid PK naming differences across migrations
    $stmt = $pdo->prepare("UPDATE UserRegistrationOTP SET otp = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email = ?");
    $stmt->execute([$newOtp, $email]);
    
    // Decode user data to get name for email
    $userData = json_decode($existingRecord['user_data'], true);
    $firstName = $userData['first_name'] ?? 'User';
    $lastName = $userData['last_name'] ?? '';
    
    // Configure email
    $mail = configureMailer();
    $mail->clearAddresses();
    $mail->addAddress($email, "$firstName $lastName");
    $mail->Subject = 'Email Verification - RemoteTeamPro (Resent)';
    
    // Get email template
    // The template expects $otp, $firstName, $lastName variables to be set
    $otp = $newOtp;
    include __DIR__ . '/../../../templates/email/registration-otp-template.php';
    $mail->Body = $emailContent;
    $mail->AltBody = "Your new verification code is: {$newOtp}";
    
    // Send email
    $emailSent = false;
    if (!$mail->send()) {
        $errorInfo = $mail->ErrorInfo;
        error_log("SMTP Error (Resend): " . $errorInfo);
        $emailSent = false;
    } else {
        $emailSent = true;
    }
    
    $message = $emailSent 
        ? 'New verification code sent to your email.'
        : 'Failed to send email. Please try again later.';
    
    echo json_encode([
        'success' => $emailSent,
        'message' => $message,
        'email' => $email,
        'otp' => $emailSent ? null : $newOtp  // Include OTP in response if email failed (for testing)
    ]);
    
} catch (Exception $e) {
    error_log("Resend OTP error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while resending OTP']);
}
?>