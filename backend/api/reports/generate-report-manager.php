<?php
// backend/api/reports/generate-report-manager.php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$company_id = $_SESSION['company_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if (!$user_id || strcasecmp($role, 'Manager') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Optional filter by project_id
    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

    $sql = "
        SELECT 
            p.project_id,
            p.project_name,
            p.description,
            p.status,
            p.technology_stack,
            p.completion_percentage,
            p.start_date,
            p.end_date,
            p.budget_allocated AS budget,                  -- ✅ Added budget field
            p.created_at,
            p.updated_at,
            c.company_name,
            u.first_name AS client_first,
            u.last_name AS client_last,
            u.email AS client_email
        FROM Projects p
        LEFT JOIN Companies c ON p.company_id = c.company_id
        LEFT JOIN Users u ON p.client_id = u.user_id
        WHERE p.company_id = :cid 
          AND p.manager_id = :mid
    ";

    if ($project_id > 0) {
        $sql .= " AND p.project_id = :pid";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
    $stmt->bindValue(':mid', $user_id, PDO::PARAM_INT);
    if ($project_id > 0) $stmt->bindValue(':pid', $project_id, PDO::PARAM_INT);

    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$projects) {
        echo json_encode(['success' => false, 'message' => 'No projects found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Manager report data loaded successfully',
        'data' => $projects
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
?>
