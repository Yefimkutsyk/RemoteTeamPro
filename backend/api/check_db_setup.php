<?php
// backend/api/check_db_setup.php

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Check if Users table has email_verified column
    $stmt = $pdo->query("DESCRIBE Users email_verified");
    $hasEmailVerified = $stmt->fetch();
    
    if (!$hasEmailVerified) {
        // Add email_verified column if it doesn't exist
        $pdo->exec("ALTER TABLE Users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
    }
    
    // Check if UserRegistrationOTP table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'UserRegistrationOTP'");
    $hasOtpTable = $stmt->fetch();
    
    if (!$hasOtpTable) {
        // Create UserRegistrationOTP table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS UserRegistrationOTP (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            otp VARCHAR(6) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            user_data TEXT,
            verified TINYINT(1) DEFAULT 0,
            INDEX idx_email (email),
            INDEX idx_otp (otp)
        )");
    }
    
    echo json_encode(['success' => true, 'message' => 'Database setup checked and updated if needed']);
    
} catch (Exception $e) {
    error_log("Database setup error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error checking database setup: ' . $e->getMessage()]);
}
?>