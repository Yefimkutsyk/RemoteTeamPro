<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/reset_password_errors.log');

require_once '../../config/database.php';
require_once '../../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

function sendJsonResponse($success, $message, $statusCode = 200, $extra = []) {
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

try {
    error_log("Starting password reset process");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Method not allowed', 405);
    }

    $input = file_get_contents('php://input');
    error_log("Received input: " . $input);

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(false, 'Invalid JSON input', 400);
    }

    // Validate required fields
    if (!isset($data['otp']) || !isset($data['newPassword']) || !isset($data['email'])) {
        sendJsonResponse(false, 'Missing required fields', 400);
    }

    $otp = $data['otp'];
    $newPassword = $data['newPassword'];
    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);

    if (!$email) {
        sendJsonResponse(false, 'Invalid email format', 400);
    }

    if (strlen($newPassword) < 8) {
        sendJsonResponse(false, 'Password must be at least 8 characters long', 400);
    }

    error_log("Processing reset for email: " . $email);

    // Database connection
    try {
        $database = new Database();
        $pdo = $database->getConnection();
        error_log("Database connection established");
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        sendJsonResponse(false, 'Database connection failed', 500);
    }
    
    // Check for verified OTP in session
    try {
        session_start();
        if (!isset($_SESSION['verified_reset_token']) || 
            !isset($_SESSION['verified_reset_email']) || 
            $_SESSION['verified_reset_token'] !== $otp || 
            $_SESSION['verified_reset_email'] !== $email) {
            error_log("Token not found in session or doesn't match for email: " . $email);
            sendJsonResponse(false, 'Please verify your OTP first', 400);
        }
        
        // Verify token is still valid in database
        $stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE email = ? AND token = ? AND expiry > NOW() AND used = 0");
        $stmt->execute([$email, $otp]);
        $token = $stmt->fetch();
        
        if (!$token) {
            error_log("Token not found or expired for email: " . $email);
            sendJsonResponse(false, 'Reset code has expired, please request a new one', 400);
        }
        
        error_log("Valid token found");
    } catch (PDOException $e) {
        error_log("Token verification failed: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to verify reset code', 500);
    }
    
    // Ensure new password differs from current
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($newPassword, $user['password_hash'])) {
        sendJsonResponse(false, 'New password cannot be the same as the current password', 400);
    }

    // Start transaction for atomic update
    $pdo->beginTransaction();
    
    // Update password within transaction
    try {
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hashedPassword, $email]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            throw new Exception("No user found with email: " . $email);
        }
        
        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE email = ? AND token = ?");
        $stmt->execute([$email, $otp]);
        
        // Commit the transaction
        $pdo->commit();
        
        // Clear the session variables after successful password reset
        unset($_SESSION['verified_reset_token']);
        unset($_SESSION['verified_reset_email']);
        
        error_log("Password updated successfully");
    } catch (Exception $e) {
        // Rollback on any error
        $pdo->rollBack();
        error_log("Password update failed: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to update password: ' . $e->getMessage(), 500);
    }
    
    // Success response
    sendJsonResponse(true, 'Password reset successfully', 200, ['redirect' => 'login.html']);

} catch (Exception $e) {
    error_log("Unhandled error in reset-password.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJsonResponse(false, 'Unexpected error: ' . $e->getMessage(), 500);
}