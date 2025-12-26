<?php
// Prevent PHP from outputting errors as HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/forgot_password_errors.log');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once '../../config/database.php';
    require_once '../../config/smtp.php';
    require_once '../../includes/helpers.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $input = file_get_contents('php://input');
    error_log("Raw input: " . $input);
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    if (!isset($data['email'])) {
        throw new Exception('Email is required');
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    $email = $data['email'];
    error_log("Processing email: " . $email);

    // Database connection
    $database = new Database();
    $pdo = $database->getConnection();
    error_log("Database connection established");
    
    // Check if email exists in database
    $stmt = $pdo->prepare("SELECT email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Email not found');
    }
    
    error_log("Email found in database");
    
    // Generate OTP
    $otp = generateOTP();
    
    error_log("Generated OTP: " . $otp);

    // Create password_reset_tokens table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(6) NOT NULL,
        expiry DATETIME NOT NULL,
        used BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_token (email, token),
        INDEX idx_expiry (expiry)
    )");
    
    // First, delete any existing tokens for this email
    $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
    $stmt->execute([$email]);
    
    // Store OTP in database with DB-side expiry to avoid timezone drift
    $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)");
    $stmt->execute([$email, $otp]);
    
    error_log("OTP stored in database");
    
    // Get email template
    $templatePath = __DIR__ . '/../../../templates/email/password-reset-template.html';
    error_log("Template path: " . $templatePath);
    
    if (!file_exists($templatePath)) {
        throw new Exception('Email template file not found at: ' . $templatePath);
    }
    
    $emailTemplate = file_get_contents($templatePath);
    if ($emailTemplate === false) {
        throw new Exception('Could not read email template file');
    }
    
    $emailTemplate = str_replace('{{OTP}}', $otp, $emailTemplate);
    $emailTemplate = str_replace('{{EXPIRY}}', '15 minutes', $emailTemplate);
    
    error_log("Email template prepared");
    
    // Configure and send email
    $mail = configureMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Password Reset Code - RemoteTeamPro';
    $mail->Body = $emailTemplate;
    $mail->isHTML(true);
    
    error_log("Attempting to send email");
    
    if (!$mail->send()) {
        throw new Exception('Failed to send email: ' . $mail->ErrorInfo);
    }
    
    error_log("Email sent successfully");
    
    echo json_encode([
        'success' => true,
        'message' => 'Reset code sent successfully',
        'redirect' => 'reset_password.html'
    ]);
    
} catch (Exception $e) {
    error_log("Error in forgot-password.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}