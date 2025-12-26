<?php
// backend/api/auth/resend-otp.php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_functions.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

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
    $db_functions = new DB_Functions($pdo);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Check if user exists and needs verification
        $stmt = $pdo->prepare("SELECT user_id, email_verified, first_name, last_name FROM Users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('User not found');
        }

        if ($user['email_verified']) {
            throw new Exception('Email is already verified');
        }

        // Delete any existing OTP
        $stmt = $pdo->prepare("DELETE FROM UserRegistrationOTP WHERE email = ?");
        $stmt->execute([$email]);

        // Generate new OTP
        $otp = generateOTP();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Store new OTP
        $stmt = $pdo->prepare("INSERT INTO UserRegistrationOTP (email, otp, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $otp, $expiresAt]);

        // Configure email
        $mail = configureMailer();
        $mail->clearAddresses();
        $mail->addAddress($email, "{$user['first_name']} {$user['last_name']}");
        $mail->Subject = 'Email Verification - RemoteTeamPro';

        // Get email template
        $firstName = $user['first_name'];
        $lastName = $user['last_name'];
        include __DIR__ . '/../../../templates/email/registration-otp-template.php';
        $mail->Body = $emailContent;
        $mail->AltBody = "Your verification code is: {$otp}";

        // Send email
        if (!$mail->send()) {
            throw new Exception('Failed to send verification email: ' . $mail->ErrorInfo);
        }

        // Commit transaction
        $pdo->commit();

        // Log the resend
        $db_functions->logActivity($user['user_id'], 'OTP Resend', "Verification code resent to email");

        echo json_encode([
            'success' => true,
            'message' => 'New verification code sent successfully'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("OTP Resend error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}