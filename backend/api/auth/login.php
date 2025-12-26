<?php
// backend/api/auth/login.php

/**
 * User Login API Endpoint
 *
 * This script handles user authentication for the API. It expects a POST request
 * with user email and password in JSON format. Upon successful authentication, it
 * starts a session and returns a JSON object with user details.
 */

// Start a PHP session
session_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/login_errors.log');

// Set content type to JSON for API response
header('Content-Type: application/json');

// Set CORS headers
header("Access-Control-Allow-Origin: http://localhost");  // Adjust this to  frontend URL
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS requests (common for CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Include necessary files with correct relative paths
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/helpers.php';
    require_once __DIR__ . '/../../includes/db_functions.php';

    // Get the raw POST data (JSON payload)
    $input = file_get_contents('php://input');
    error_log("Login attempt - Raw input: " . $input);
    $data = json_decode($input, true); // Decode JSON into an associative array
    error_log("Login attempt - Parsed data: " . print_r($data, true));

    // Basic input validation
    if (empty($data['email']) || empty($data['password'])) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        http_response_code(400); // Bad Request
        exit();
    }

    $email = sanitizeInput($data['email']);
    $password = $data['password'];

    // Instantiate classes
    $database = new Database();
    $db_functions = new DB_Functions($database->getConnection());

    try {
        $pdo = $database->getConnection();

        // Fetch user from the database (include email so it can be returned)
        $stmt = $pdo->prepare("SELECT user_id, company_id, email, password_hash, first_name, last_name, role 
                               FROM Users WHERE email = :email");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify the password hash
        if ($user && password_verify($password, $user['password_hash'])) {
            // Generate OTP
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['login_otp'] = $otp;
            $_SESSION['login_otp_email'] = $email;
            $_SESSION['login_otp_expiry'] = time() + (5 * 60); // 5 minutes validity
            
            try {
                // Get PHPMailer setup
                $mailer = configureMailer(); // This includes SMTP config and creates mailer instance
                $mailer->clearAddresses(); // Clear any existing addresses
                $mailer->addAddress($email);
                
                // Get the email template
                error_log("Loading OTP email template from: " . __DIR__ . '/../../../templates/email/otp-email-template.php');
                require_once __DIR__ . '/../../../templates/email/otp-email-template.php';
                
                $mailer->Subject = "RemoteTeamPro Security Verification Code";
                $mailer->isHTML(true);
                $mailer->Body = getOtpEmailTemplate($otp);
                $mailer->AltBody = "Your RemoteTeamPro verification code is: $otp\nThis code will expire in 5 minutes.\nFor security, never share this code with anyone.";
                
                error_log("Attempting to send OTP email to: " . $email);
                if($mailer->send()) {
                    error_log("OTP email sent successfully to: " . $email);
                    // Store user data in session for later use
                    $_SESSION['temp_user_data'] = [
                        'user_id' => $user['user_id'],
                        'company_id' => $user['company_id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'role' => $user['role']
                    ];
                    
                    // Return a success JSON response with OTP status
                    echo json_encode([
                        'success' => true,
                        'message' => 'OTP sent successfully.',
                        'requires_otp' => true,
                        'email' => $email
                    ]);
                    http_response_code(200);
                } else {
                    error_log("Failed to send OTP email to: " . $email);
                    echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
                    http_response_code(500);
                }
            } catch (Exception $e) {
                error_log("Email sending error for " . $email . ": " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
                http_response_code(500);
            }

        } else {
            // Authentication failed
            $db_functions->logActivity(null, 'Login Failed', "Failed login attempt for email: '{$email}'.");
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            http_response_code(401); // Unauthorized
        }

    } catch (PDOException $e) {
        error_log("Login database error: " . $e->getMessage());
        $db_functions->logActivity(null, 'Database Error', "Login database error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
        http_response_code(500); // Internal Server Error
    } finally {
        $pdo = null;
    }

} else {
    // If not a POST request
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    http_response_code(405); // Method Not Allowed
}
?>
