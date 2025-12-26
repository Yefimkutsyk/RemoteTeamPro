<?php
// backend/api/admin-dashboard.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();
$conn = $db->getConnection();

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// ---- SESSION & DEFAULTS ----
$user_id = $_SESSION['user_id'] ?? 1;
$role = $_SESSION['role'] ?? 'Admin';
$company_id = $_SESSION['company_id'] ?? 1;

if (!$company_id) {
    respond(["success" => false, "error" => "Missing company_id"], 400);
}

try {
    $dashboard = [];

    // --- SUMMARY (Projects, Users, Teams, Budget, Completion)
    $stmt = $conn->prepare("
        SELECT 
            COUNT(p.project_id) AS total_projects,
            COALESCE(SUM(p.budget_allocated), 0) AS total_budget,
            COALESCE(AVG(p.completion_percentage), 0) AS avg_completion
        FROM Projects p
        WHERE p.company_id = :cid
    ");
    $stmt->execute([':cid' => $company_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Active users
    $stmt = $conn->prepare("SELECT COUNT(*) AS total_active_users FROM Users WHERE company_id = :cid AND status='Active'");
    $stmt->execute([':cid' => $company_id]);
    $summary['total_active_users'] = $stmt->fetchColumn();

    // Total teams
    $stmt = $conn->prepare("SELECT COUNT(*) AS total_teams FROM Teams WHERE company_id = :cid");
    $stmt->execute([':cid' => $company_id]);
    $summary['total_teams'] = $stmt->fetchColumn();

    $dashboard['summary'] = $summary;

    // --- USERS BY ROLE ---
    $stmt = $conn->prepare("
        SELECT role, COUNT(user_id) AS count
        FROM Users
        WHERE company_id = :cid AND status = 'Active'
        GROUP BY role
    ");
    $stmt->execute([':cid' => $company_id]);
    $dashboard['users_by_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- TASKS & TIMESHEETS SUMMARY ---
    $stmt = $conn->prepare("
        SELECT 
            COUNT(t.task_id) AS total_tasks,
            SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks
        FROM Tasks t
        JOIN Projects p ON t.project_id = p.project_id
        WHERE p.company_id = :cid
    ");
    $stmt->execute([':cid' => $company_id]);
    $task_summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Pending Timesheets
    $stmt = $conn->prepare("
        SELECT COUNT(ts.timesheet_id) 
        FROM Timesheets ts
        JOIN Tasks t ON ts.task_id = t.task_id
        JOIN Projects p ON t.project_id = p.project_id
        WHERE p.company_id = :cid AND ts.status = 'Pending Approval'
    ");
    $stmt->execute([':cid' => $company_id]);
    $task_summary['pending_timesheets'] = $stmt->fetchColumn();

    $dashboard['task_summary'] = $task_summary;

    // --- CLIENT REQUESTS ---
    $stmt = $conn->prepare("
        SELECT 
            COUNT(request_id) AS total_requests,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_requests
        FROM ClientRequests
        WHERE company_id = :cid
    ");
    $stmt->execute([':cid' => $company_id]);
    $dashboard['client_requests'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- RECENT PROJECTS ---
    $stmt = $conn->prepare("
        SELECT p.project_name, p.status, p.deadline, p.completion_percentage,
               CONCAT(m.first_name, ' ', m.last_name) AS manager_name
        FROM Projects p
        LEFT JOIN Users m ON p.manager_id = m.user_id
        WHERE p.company_id = :cid
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':cid' => $company_id]);
    $dashboard['recent_projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- PROJECTS PER MANAGER ---
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unassigned') AS manager_name,
            COUNT(p.project_id) AS project_count
        FROM Projects p
        LEFT JOIN Users u ON p.manager_id = u.user_id
        WHERE p.company_id = :cid
        GROUP BY p.manager_id
    ");
    $stmt->execute([':cid' => $company_id]);
    $dashboard['projects_per_manager'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- BUDGET TREND (Monthly) ---
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(budget_allocated) AS total
        FROM Projects
        WHERE company_id = :cid
        GROUP BY ym
        ORDER BY ym ASC
    ");
    $stmt->execute([':cid' => $company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dashboard['budget_trend'] = array_map(fn($r) => [
        'month' => date('M Y', strtotime($r['ym'] . '-01')),
        'total' => (float)$r['total']
    ], $rows);

    // --- KPI TREND (Avg Completion, Active Users, Projects by Month) ---
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(p.created_at, '%Y-%m') AS ym,
            AVG(p.completion_percentage) AS avg_completion,
            COUNT(p.project_id) AS total_projects
        FROM Projects p
        WHERE p.company_id = :cid
        GROUP BY ym
        ORDER BY ym ASC
    ");
    $stmt->execute([':cid' => $company_id]);
    $dashboard['kpi_trend'] = array_map(fn($r) => [
        'month' => date('M Y', strtotime($r['ym'] . '-01')),
        'avg_completion' => (float)$r['avg_completion'],
        'total_projects' => (int)$r['total_projects']
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    respond(["success" => true, "data" => $dashboard]);
} catch (Throwable $e) {
    respond(["success" => false, "error" => $e->getMessage()], 500);
}
?>
