<?php
// backend/includes/helpers.php

// Include PHPMailer files
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../otp_app/php/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Helper Functions for RemoteTeamPro Backend
 *
 * This file contains reusable utility functions for input sanitization,
 * data manipulation, and other common tasks.
 */

/**
 * Sanitizes input data to prevent common web vulnerabilities (e.g., XSS).
 *
 * @param string $data The input string to sanitize.
 * @return string The sanitized string.
 */
function sanitizeInput($data) {
    $data = trim($data); // Remove whitespace from the beginning and end of string
    $data = stripslashes($data); // Remove backslashes
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // Convert special characters to HTML entities
    return $data;
}

/**
 * Generates a random 6-digit OTP
 *
 * @return string The generated OTP
 */
function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Configures PHPMailer with SMTP settings
 *
 * @return PHPMailer Configured PHPMailer instance
 * @throws Exception if mail configuration fails
 */
/**
 * Check if an email is already registered
 *
 * @param PDO $pdo Database connection
 * @param string $email Email to check
 * @return bool True if email exists, false otherwise
 */
function isEmailRegistered($pdo, $email) {
    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    return (bool)$stmt->fetch();
}

function configureMailer() {
    try {
        // Include SMTP configuration
        require_once __DIR__ . '/../config/smtp.php';
        
        $mail = new PHPMailer(true);

        // Disable debug output for production (was causing issues)
        $mail->SMTPDebug = 0;
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        
        // SSL/TLS settings to fix connection issues
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Connection timeout settings
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = true;

        // Sender
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Content settings
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        return $mail;
    } catch (Exception $e) {
        error_log("Mail configuration error: " . $e->getMessage());
        throw new Exception('Failed to configure email. Please try again later.');
    }
}

?>
