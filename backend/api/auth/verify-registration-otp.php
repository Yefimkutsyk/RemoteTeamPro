<?php
// backend/api/auth/verify-registration-otp.php

session_start();

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
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (empty($data['email']) || empty($data['otp'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$email = sanitizeInput($data['email']);
$otp = sanitizeInput($data['otp']);

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $db_functions = new DB_Functions($pdo);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Check if user already exists (should not happen with new flow)
        $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            // This case is a failsafe. The registration endpoint should prevent this.
            http_response_code(409); // Conflict
            throw new Exception('This email address is already registered.');
        }

        // Check OTP and get registration data
        $stmt = $pdo->prepare("SELECT * FROM UserRegistrationOTP WHERE email = ? AND otp = ? AND expires_at > NOW() AND verified = 0");
        $stmt->execute([$email, $otp]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            http_response_code(400);
            throw new Exception('Invalid or expired verification code. Please try again or request a new one.');
        }

        // Decode user data
        $userData = json_decode($otpRecord['user_data'], true);
        if (!$userData) {
            throw new Exception('Invalid registration data');
        }

        // Create user in Users table
        $stmt = $pdo->prepare("INSERT INTO Users (company_id, email, password_hash, first_name, last_name, role, email_verified) 
                              VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $userData['company_id'],
            $userData['email'],
            $userData['password'],
            $userData['first_name'],
            $userData['last_name'],
            $userData['role']
        ]);
        
        $userId = $pdo->lastInsertId();

        /* ---------------------------------------------
   AUTO-ASSIGN CHAT CONVERSATIONS FOR NEW USER
---------------------------------------------- */
try {
    $autoUrl = "http://localhost/RemoteTeamPro/backend/api/messages/auto_assign_new_user.php?user_id=" . $userId;
    @file_get_contents($autoUrl);
} catch (Exception $e) {
    error_log("Auto chat assignment failed for user $userId: " . $e->getMessage());
}
/* ----------------------------------------------
   END AUTO CHAT ASSIGNMENT
----------------------------------------------- */

        // Mark OTP as used instead of deleting for audit purposes
        $stmt = $pdo->prepare("UPDATE UserRegistrationOTP SET verified = 1 WHERE email = ? AND otp = ?");
        $stmt->execute([$email, $otp]);

        // Log successful registration
        $db_functions->logActivity($userId, 'Registration', "User registered and email verified successfully");

        $pdo->commit();

        // Return success
        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully. Registration completed!',
            'user_id' => $userId
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Verification failed: " . $e->getMessage());
        // If no specific HTTP code was set, default to 400
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }

} catch (Exception $e) {
    error_log("OTP verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during verification']);
}
?>