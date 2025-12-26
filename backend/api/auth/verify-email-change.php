<?php
// backend/api/auth/verify-email-change.php

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

if (empty($data['email']) || empty($data['otp'])) {
    echo json_encode(['success' => false, 'message' => 'Email and OTP are required']);
    exit();
}

$userId = $_SESSION['user_id'];
$email = sanitizeInput($data['email']);
$otp = sanitizeInput($data['otp']);

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Verify OTP
    $stmt = $pdo->prepare("SELECT * FROM UserRegistrationOTP WHERE email = ? AND otp = ? AND expires_at > NOW() AND verified = 0");
    $stmt->execute([$email, $otp]);
    $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$otpRecord) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired verification code']);
        exit();
    }
    
    // Update user's email
    $stmt = $pdo->prepare("UPDATE Users SET email = ?, email_verified = 1 WHERE user_id = ?");
    $stmt->execute([$email, $userId]);
    
    // Mark OTP as verified
    $stmt = $pdo->prepare("UPDATE UserRegistrationOTP SET verified = 1 WHERE email = ? AND otp = ?");
    $stmt->execute([$email, $otp]);
    
    // Update session email if needed
    $_SESSION['email'] = $email;
    
    echo json_encode([
        'success' => true,
        'message' => 'Email updated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Email verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while verifying your email']);
}
?>