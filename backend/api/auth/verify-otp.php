<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/otp_verification_errors.log');

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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Method not allowed', 405);
    }

    $input = file_get_contents('php://input');
    error_log("Raw input: " . $input);

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(false, 'Invalid JSON input', 400);
    }

    if (!isset($data['email']) || !isset($data['otp'])) {
        sendJsonResponse(false, 'Missing required fields', 400);
    }

    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    $otp = $data['otp'];

    if (!$email) {
        sendJsonResponse(false, 'Invalid email format', 400);
    }

    error_log("Processing OTP verification for email: " . $email);

    // Database connection
    try {
        $database = new Database();
        $pdo = $database->getConnection();
        error_log("Database connection established");
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        sendJsonResponse(false, 'Database connection failed', 500);
    }

    // Verify OTP for password reset
    try {
        $stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE email = ? AND token = ? AND expiry > NOW() AND used = 0");
        $stmt->execute([$email, $otp]);
        $token = $stmt->fetch();

        if (!$token) {
            error_log("Invalid or expired token for email: " . $email);
            sendJsonResponse(false, 'Invalid or expired reset code', 400);
        }

        // Keep verification in session for the reset step
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['verified_reset_token'] = $token['token'];
        $_SESSION['verified_reset_email'] = $email;

        error_log("Valid reset token verified for email: " . $email);
        sendJsonResponse(true, 'OTP verified successfully');

    } catch (PDOException $e) {
        error_log("Token verification failed: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to verify reset code', 500);
    }

} catch (Exception $e) {
    error_log("Unhandled error in verify-otp.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJsonResponse(false, 'An unexpected error occurred', 500);
}