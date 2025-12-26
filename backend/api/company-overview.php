<?php
// backend/api/companies.php
header("Content-Type: application/json; charset=UTF-8");
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php';

$db = (new Database())->getConnection();
$db_functions = new DB_Functions($db);

// Only allow logged-in admins/managers
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$company_id = $_SESSION['company_id'];

try {
    // Only fetch this company’s info
    $stmt = $db->prepare("
        SELECT 
            c.company_id, 
            c.company_name, 
            c.services, 
            c.created_at,
            COUNT(DISTINCT u.user_id) AS total_users,
            COUNT(DISTINCT t.team_id) AS total_teams,
            COUNT(DISTINCT p.project_id) AS total_projects
        FROM Companies c
        LEFT JOIN Users u ON c.company_id = u.company_id
        LEFT JOIN Teams t ON c.company_id = t.company_id
        LEFT JOIN Projects p ON c.company_id = p.company_id
        WHERE c.company_id = :cid
        GROUP BY c.company_id
    ");
    $stmt->execute([':cid' => $company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($company) {
        echo json_encode([
            "success" => true,
            "data" => $company
        ]);
        $db_functions->logActivity($_SESSION['user_id'], 'Company Overview', 'Viewed company details');
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Company not found"
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    error_log("Company API Error: " . $e->getMessage());
}