<?php
// backend/api/manager-dashboard.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// ✅ TEMPORARY: simulate logged-in manager (for testing)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'Manager';
}

$manager_id = $_SESSION['user_id'];

// ✅ 1️⃣ TASK STATUS COUNTS (for manager’s projects)
$taskStatusQuery = "
    SELECT 
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) AS pending
    FROM Tasks t
    INNER JOIN Projects p ON t.project_id = p.project_id
    WHERE p.manager_id = ?
";
$stmt = $conn->prepare($taskStatusQuery);
$stmt->execute([$manager_id]);
$taskStatus = $stmt->fetch(PDO::FETCH_ASSOC);

// ✅ 2️⃣ PROJECT PROGRESS (average completion per project)
$projectProgressQuery = "
    SELECT 
        p.project_name,
        p.completion_percentage
    FROM Projects p
    WHERE p.manager_id = ?
";
$stmt = $conn->prepare($projectProgressQuery);
$stmt->execute([$manager_id]);
$projectProgress = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ 3️⃣ TEAM PERFORMANCE (completed tasks by week)
$teamPerformanceQuery = "
    SELECT 
        DATE_FORMAT(t.updated_at, '%Y-%u') AS week_label,
        COUNT(*) AS tasks_completed
    FROM Tasks t
    INNER JOIN Projects p ON t.project_id = p.project_id
    WHERE t.status = 'Completed' AND p.manager_id = ?
    GROUP BY week_label
    ORDER BY week_label ASC
";
$stmt = $conn->prepare($teamPerformanceQuery);
$stmt->execute([$manager_id]);
$teamPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ COMBINE RESPONSE
$response = [
    "taskStatus" => [
        "completed" => (int)($taskStatus['completed'] ?? 0),
        "in_progress" => (int)($taskStatus['in_progress'] ?? 0),
        "pending" => (int)($taskStatus['pending'] ?? 0)
    ],
    "projectProgress" => $projectProgress,
    "teamPerformance" => $teamPerformance
];

echo json_encode($response);
