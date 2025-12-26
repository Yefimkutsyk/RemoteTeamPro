<?php
// backend/api/auth/change-email.php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_functions.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    http_response_code(401);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (empty($data['new_email'])) {
    echo json_encode(['success' => false, 'message' => 'New email is required']);
    exit();
}

$userId = $_SESSION['user_id'];
$newEmail = sanitizeInput($data['new_email']);

// Validate email format
if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Check if new email already exists
    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ? AND user_id != ?");
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already in use']);
        exit();
    }
    
    // Get user details
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Generate OTP
    $otp = generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Store OTP
    $stmt = $pdo->prepare("INSERT INTO UserRegistrationOTP (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$newEmail, $otp, $expiresAt]);
    
    // Send verification email
    try {
        $mail = configureMailer();
        $mail->addAddress($newEmail, $user['first_name'] . ' ' . $user['last_name']);
        $mail->Subject = 'Verify Your New Email - RemoteTeamPro';
        
        $firstName = $user['first_name'];
        $lastName = $user['last_name'];
        
        // Get email template
        ob_start();
        require __DIR__ . '/../../../templates/email/registration-otp-template.php';
        $mail->Body = $emailContent;
        
        $mail->send();
        
        echo json_encode([
            'success' => true,
            'message' => 'Verification code sent to your new email address',
            'email' => $newEmail
        ]);
        
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to send verification email']);
        exit();
    }
    
} catch (Exception $e) {
    error_log("Change email error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request']);
}
?>