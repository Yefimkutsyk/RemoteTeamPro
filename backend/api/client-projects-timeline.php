<?php
// backend/api/client-projects-timeline.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();

require_once __DIR__ . '/../config/database.php';

// Connect via PDO class
$database = new Database();
$conn = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// --- OPTIONS (CORS preflight)
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- AUTH check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$client_id = $_SESSION['user_id'];

// --- GET: Fetch client projects and tasks timeline
if ($method === 'GET') {
    try {
        // Fetch projects for this client
        $stmt = $conn->prepare("
            SELECT 
                p.project_id,
                p.project_name,
                p.description,
                p.status,
                p.start_date,
                p.deadline,
                p.completion_percentage,
                p.budget_allocated,
                CONCAT(u.first_name, ' ', u.last_name) AS manager_name
            FROM Projects p
            LEFT JOIN Users u ON p.manager_id = u.user_id
            WHERE p.client_id = :cid
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':cid' => $client_id]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each project, get task timeline
        foreach ($projects as &$project) {
            $taskStmt = $conn->prepare("
                SELECT 
                    t.task_id,
                    t.task_name,
                    t.status,
                    t.priority,
                    t.due_date,
                    t.estimated_hours,
                    t.actual_hours
                FROM Tasks t
                WHERE t.project_id = :pid
                ORDER BY t.due_date ASC
            ");
            $taskStmt->execute([':pid' => $project['project_id']]);
            $project['tasks'] = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'projects' => $projects]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error', 'details' => $e->getMessage()]);
    }
    exit;
}

// --- Invalid method
echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;
