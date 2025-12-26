<?php
// backend/api/employee-projects.php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Include database connection
require_once __DIR__ . "/../config/database.php";

// Initialize database
$db = new Database();
$conn = $db->getConnection();

// Validate employee (user) ID
if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "No employee ID provided."
    ]);
    exit;
}

$user_id = intval($_GET['user_id']);

try {
    // ✅ Fetch all projects assigned to this employee
    $query = "
        SELECT 
            p.project_id,
            p.project_name,
            p.description AS project_description,
            p.status,
            p.deadline,
            p.completion_percentage,
            p.budget_allocated,
            p.start_date,
            p.end_date,
            u.first_name AS manager_first_name,
            u.last_name AS manager_last_name
        FROM Projects p
        LEFT JOIN ProjectAssignments pa ON p.project_id = pa.project_id
        LEFT JOIN Users u ON p.manager_id = u.user_id
        WHERE pa.user_id = :user_id
        GROUP BY p.project_id
        ORDER BY p.created_at DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
    $stmt->execute();

    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$projects || count($projects) === 0) {
        echo json_encode([
            "success" => true,
            "message" => "No projects found for this employee.",
            "data" => []
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => $projects
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
