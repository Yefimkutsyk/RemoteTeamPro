<?php
// backend/api/admin-tasks.php
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php';

$db = (new Database())->getConnection();
$db_functions = new DB_Functions($db);

// ✅ Require admin login and company session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$company_id = $_SESSION['company_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['download']) && $_GET['download'] === 'csv') {
            exportTasksCSV($db, $company_id);
        } else {
            getTasks($db, $company_id);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        break;
}

// ✅ Get tasks (filtered by company via the Projects table)
function getTasks($db, $company_id)
{
    try {
        $query = "
            SELECT 
                t.task_id,
                t.task_name,
                t.status,
                t.priority,
                t.due_date AS deadline,
                p.project_name,
                CONCAT(u.first_name, ' ', u.last_name) AS assigned_to
            FROM Tasks t
            INNER JOIN Projects p ON t.project_id = p.project_id
            LEFT JOIN Users u ON t.assigned_to_user_id = u.user_id
            WHERE p.company_id = :company_id
            ORDER BY t.due_date DESC, t.task_id DESC
        ";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':company_id', $company_id);
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "data" => $tasks]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}

// ✅ CSV Export (same filter)
function exportTasksCSV($db, $company_id)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=company_tasks.csv');
    $output = fopen('php://output', 'w');
    if (!$output) {
        http_response_code(500);
        echo "Could not open output stream.";
        exit;
    }

    fputcsv($output, ['Task ID', 'Task Name', 'Status', 'Priority', 'Deadline', 'Project', 'Assigned To']);

    $query = "
        SELECT 
            t.task_id,
            t.task_name,
            t.status,
            t.priority,
            t.due_date AS deadline,
            p.project_name,
            CONCAT(u.first_name, ' ', u.last_name) AS assigned_to
        FROM Tasks t
        INNER JOIN Projects p ON t.project_id = p.project_id
        LEFT JOIN Users u ON t.assigned_to_user_id = u.user_id
        WHERE p.company_id = :company_id
        ORDER BY t.due_date DESC, t.task_id DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':company_id', $company_id);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['task_id'] ?? '',
            $row['task_name'] ?? '',
            $row['status'] ?? '',
            $row['priority'] ?? '',
            $row['deadline'] ?? '',
            $row['project_name'] ?? '',
            $row['assigned_to'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}
?>
