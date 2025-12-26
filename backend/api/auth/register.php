<?php
// backend/api/auth/register.php

session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/registration_errors.log');

// Include necessary files
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_functions.php';

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
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    http_response_code(405);
    exit();
}

try {
    // Read JSON input
    $input = file_get_contents('php://input');
    error_log("Raw input received: " . $input);
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data received: ' . json_last_error_msg());
    }
    error_log("Parsed data: " . print_r($data, true));
    
    // Add immediate response for debugging
    if (empty($input)) {
        echo json_encode(['success' => false, 'message' => 'No input data received']);
        exit();
    }

    // Validate required fields
    $requiredFields = ['company_id', 'email', 'password', 'first_name', 'last_name', 'role'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    $companyId = $data['company_id'];
    $email = sanitizeInput($data['email']);
    $password = $data['password'];
    $firstName = sanitizeInput($data['first_name']);
    $lastName = sanitizeInput($data['last_name']);
    $role = $data['role'];

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Validate password strength
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    // Validate role
    $allowedRoles = ['Admin', 'Manager', 'Employee', 'Client'];
    if (!in_array($role, $allowedRoles)) {
        throw new Exception('Invalid user role specified');
    }

    $database = new Database();
    $pdo = $database->getConnection();

    // Check if email already exists in Users table
    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Email already registered. Please use a different email or try logging in.'
        ]);
        http_response_code(400);
        exit();
    }

    // Check if email already exists in pending registrations
    $stmt = $pdo->prepare("SELECT 1 FROM UserRegistrationOTP WHERE email = ? AND expires_at > NOW()");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Registration already in progress for this email. Please check your email for the verification code or wait for it to expire.'
        ]);
        http_response_code(400);
        exit();
    }

    // Check company exists and get admin_key
    $stmt = $pdo->prepare("SELECT company_id, admin_key FROM Companies WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $companyData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$companyData) {
        echo json_encode([
            'success' => false,
            'message' => 'Company ID does not exist. Please check your company ID.'
        ]);
        http_response_code(400);
        exit();
    }

    // Admin key check if role === 'Admin'
    if ($role === 'Admin') {
        $adminKeyFromDb = $companyData['admin_key'];
        $submittedAdminKey = $data['admin_key'] ?? '';
        if (empty($adminKeyFromDb) || $submittedAdminKey !== $adminKeyFromDb) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid admin key. Please contact your administrator for the correct admin key.'
            ]);
            http_response_code(400);
            exit();
        }
    }

    // Generate OTP
    $otp = generateOTP();

    // Prepare user data for temporary storage
    $userData = [
        'company_id' => $companyId,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT), // Hash password before storing
        'first_name' => $firstName,
        'last_name' => $lastName,
        'role' => $role,
        'admin_key' => $data['admin_key'] ?? null
    ];

    // Store registration data temporarily
    $stmt = $pdo->prepare("INSERT INTO UserRegistrationOTP (email, otp, expires_at, user_data) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)");
    $stmt->execute([$email, $otp, json_encode($userData)]);

    // Send OTP email
    try {
        $mail = configureMailer();
        $mail->addAddress($email, $firstName . ' ' . $lastName);
        $mail->Subject = 'Verify Your Email - RemoteTeamPro';
        $mail->Body = "
            <h2>Email Verification</h2>
            <p>Hello {$firstName},</p>
            <p>Thank you for registering with RemoteTeamPro. Please use the following verification code to complete your registration:</p>
            <h3 style='color: #7c3aed; font-size: 24px; letter-spacing: 3px; text-align: center; padding: 20px; background: #f3f4f6; border-radius: 8px;'>{$otp}</h3>
            <p>This code will expire in 10 minutes.</p>
            <p>If you didn't request this verification, please ignore this email.</p>
            <p>Best regards,<br>RemoteTeamPro Team</p>
        ";
        
        $mail->send();
        $emailSent = true;
        error_log("OTP email sent successfully to: $email");
    } catch (Exception $e) {
        $emailSent = false;
        error_log("Failed to send OTP email to $email: " . $e->getMessage());
    }

    // Return response
    echo json_encode([
        'success' => true,
        'message' => 'Registration data received. Please check your email for verification code.',
        'email' => $email,
        'email_sent' => $emailSent,
        'otp' => $otp  // Include OTP for testing
    ]);
    
    // Log registration attempt
    error_log("Registration attempt for email: $email, OTP: $otp, Email sent: " . ($emailSent ? 'Yes' : 'No'));

} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug_info' => [
            'error_type' => get_class($e),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
    http_response_code(400);
}
?>