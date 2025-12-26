<?php
// backend/includes/db_functions.php

/**
 * Database Helper Functions
 *
 * This file contains reusable functions for common database operations,
 * such as logging activities.
 */
class DB_Functions {
    private $conn;

    // Constructor to inject the database connection
    public function __construct($db){
        $this->conn = $db;
    }

    /**
     * Logs an activity into the ActivityLog table.
     *
     * @param int|null $userId The ID of the user who performed the action (null for system actions).
     * @param string $actionType The type of action (e.g., 'User Login', 'Project Created').
     * @param string|null $details Additional details about the action.
     * @return bool True on success, false on failure.
     */
    public function logActivity($userId, $actionType, $details = null) {
        $query = "INSERT INTO ActivityLog (user_id, action_type, details, ip_address)
                  VALUES (:user_id, :action_type, :details, :ip_address)";

        $stmt = $this->conn->prepare($query);

        // Sanitize data (basic sanitization, more robust validation might be needed depending on context)
        // Using htmlspecialchars and strip_tags to prevent XSS when logging details
        $sanitizedUserId = ($userId !== null) ? htmlspecialchars(strip_tags($userId), ENT_QUOTES, 'UTF-8') : null;
        $sanitizedActionType = htmlspecialchars(strip_tags($actionType), ENT_QUOTES, 'UTF-8');
        $sanitizedDetails = ($details !== null) ? htmlspecialchars(strip_tags($details), ENT_QUOTES, 'UTF-8') : null;

        // Get user's IP address (simple way, might need more robust solution for production)
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

        // Bind parameters
        $stmt->bindParam(':user_id', $sanitizedUserId, PDO::PARAM_INT);
        $stmt->bindParam(':action_type', $sanitizedActionType, PDO::PARAM_STR);
        $stmt->bindParam(':details', $sanitizedDetails, PDO::PARAM_STR);
        $stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return true;
        }
        // Log the actual PDO error for debugging purposes (to your PHP error log)
        error_log("Error logging activity: " . implode(":", $stmt->errorInfo()));
        return false;
    }
}
?>
