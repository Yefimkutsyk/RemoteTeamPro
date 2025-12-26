<?php
// Enable error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/otp_verification_errors.log');

// Include required files
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Start session
session_start();

// Set headers
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Function to send JSON response and exit
function sendJsonResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'user' => $data
    ]);
    exit();
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJsonResponse(true, '');
}

// Handle main request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        error_log("Starting OTP verification process");
        
        // Get and validate input
        $input = file_get_contents('php://input');
        error_log("Raw input: " . $input);
        
        if (!$input) {
            error_log("No input received");
            sendJsonResponse(false, 'No input received', null, 400);
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }

        // Validate OTP
        if (empty($data['otp'])) {
            sendJsonResponse(false, 'OTP is required', null, 400);
        }

        // Validate session data
        if (!isset($_SESSION['login_otp']) || !isset($_SESSION['login_otp_email']) || !isset($_SESSION['login_otp_expiry'])) {
            sendJsonResponse(false, 'Session expired. Please login again', null, 400);
        }

        $submitted_otp = trim($data['otp']);

        // Check OTP expiration
        if (time() > $_SESSION['login_otp_expiry']) {
            unset($_SESSION['login_otp'], $_SESSION['login_otp_email'], $_SESSION['login_otp_expiry']);
            sendJsonResponse(false, 'OTP has expired. Please request a new one', null, 400);
        }

        // Verify OTP
        if ($submitted_otp === $_SESSION['login_otp']) {
            if (!isset($_SESSION['temp_user_data'])) {
                sendJsonResponse(false, 'Session data not found. Please login again', null, 400);
            }

            $userData = $_SESSION['temp_user_data'];

            // Set up session data
            $_SESSION['user_id'] = $userData['user_id'];
            $_SESSION['company_id'] = $userData['company_id'];
            $_SESSION['email'] = $userData['email'];
            $_SESSION['role'] = $userData['role'];
            $_SESSION['first_name'] = $userData['first_name'];
            $_SESSION['last_name'] = $userData['last_name'];

            // Clean up temporary data
            unset(
                $_SESSION['temp_user_data'],
                $_SESSION['login_otp'],
                $_SESSION['login_otp_email'],
                $_SESSION['login_otp_expiry']
            );

            // Send success response
            sendJsonResponse(true, 'Login successful', [
                'user_id' => $userData['user_id'],
                'company_id' => $userData['company_id'],
                'email' => $userData['email'],
                'role' => $userData['role'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name']
            ], 200);
        } else {
            sendJsonResponse(false, 'Invalid OTP', null, 400);
        }
    } catch (Exception $e) {
        error_log("OTP Verification Error: " . $e->getMessage());
        sendJsonResponse(false, 'An error occurred during verification. Please try again', null, 500);
    }
} else {
    sendJsonResponse(false, 'Invalid request method', null, 405);
}