<?php
// backend/api/system_status.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS"); // Add other methods as needed

// Handle pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection and functions (if you want to log activity from this stub)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php';

$database = new Database();
$db = $database->getConnection();
$db_functions = new DB_Functions($db);

try {
    // Placeholder data for system status
    $system_status = [
        [
            "label" => "Database Connection",
            "value" => "Operational", // Or query your DB to check its health
            "color" => "green-400",
            "progress" => "100%"
        ],
        [
            "label" => "API Uptime",
            "value" => "99.9%",
            "color" => "green-400",
            "progress" => "99.9%"
        ],
        [
            "label" => "Storage Usage",
            "value" => "45GB / 100GB",
            "color" => "yellow-400",
            "progress" => "45%"
        ],
        // Add more relevant system metrics here
    ];

    http_response_code(200);
    echo json_encode($system_status);
    $db_functions->logActivity(null, 'System Status API', "System status data requested.");

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(array("message" => "Database error for system status: " . $e->getMessage(), "data" => []));
    error_log("System status error: " . $e->getMessage());
    $db_functions->logActivity(null, 'System Status API Failed', "Database error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500); // General error
    echo json_encode(array("message" => "Error generating system status: " . $e->getMessage(), "data" => []));
    error_log("System status general error: " . $e->getMessage());
    $db_functions->logActivity(null, 'System Status API Failed', "General error: " . $e->getMessage());
}

$db = null; // Close connection
?>
