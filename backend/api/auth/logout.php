<?php
// backend/api/auth/logout.php

/**
 * User Logout API Endpoint
 *
 * This script handles user logout. It destroys the current session,
 * effectively logging the user out of the system.
 */

// Start the session if it's not already started
// This is crucial to access and destroy session variables.
session_start();

// Set content type to JSON for API response
header('Content-Type: application/json');

// Allow requests from all origins (for development purposes).
// In a production environment, you should restrict this to your frontend's domain.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Allow POST and OPTIONS for logout
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests (common for CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if the request method is POST (recommended for logout for security)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Unset all session variables
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Clear the session cookie if it exists
    // This ensures the session ID is removed from the client's browser.
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    http_response_code(200); // OK

} else {
    // If not a POST request
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Please use POST for logout.']);
    http_response_code(405); // Method Not Allowed
}
?>