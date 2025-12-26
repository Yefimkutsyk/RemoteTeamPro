<?php
// backend/api/companies.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS"); // Add POST, PUT, DELETE as needed

// Handle pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection and functions
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php'; // Required for logging

$database = new Database();
$db = $database->getConnection();
$db_functions = new DB_Functions($db);

// Get HTTP method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Handle GET requests to retrieve companies
        handleGetCompanies($db, $db_functions);
        break;
    // Add cases for POST, PUT, DELETE for full CRUD functionality later
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(array("message" => "Method not allowed."));
        break;
}

/**
 * Handles GET requests to retrieve all companies.
 * @param PDO $db Database connection object.
 * @param DB_Functions $db_functions DB_Functions instance for logging.
 */
function handleGetCompanies($db, $db_functions) {
    try {
        // The line below is where you should put the new query.
        $stmt = $db->query("SELECT company_id, company_name, services, created_at FROM Companies ORDER BY created_at DESC");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($companies) > 0) {
            http_response_code(200); // OK
            echo json_encode(array("data" => $companies));
            $db_functions->logActivity(null, 'Company List View', "Retrieved list of all companies.");
        } else {
            http_response_code(200); // OK (but no content)
            echo json_encode(array("message" => "No companies found.", "data" => []));
            $db_functions->logActivity(null, 'Company List View', "No companies found in the database.");
        }
    } catch (PDOException $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(array("message" => "Database error fetching companies: " . $e->getMessage(), "data" => []));
        error_log("Companies API error: " . $e->getMessage());
        $db_functions->logActivity(null, 'Company List API Failed', "Database error: " . $e->getMessage());
    }
}

$db = null; // Close connection
?>