<?php
// backend/api/reports/generate-report.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

session_start();
require_once __DIR__ . '/../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$company_id = $_SESSION['company_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'generate';

try {
    // -------------------------
    // ACTION 1: list_projects
    // -------------------------
    if ($action === 'list_projects') {
        if (strcasecmp($role, 'Admin') === 0 || strcasecmp($role, 'Administrator') === 0) {
            $sql = "SELECT project_id, project_name 
                    FROM Projects 
                    WHERE company_id = :cid 
                    ORDER BY project_name";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
            $stmt->execute();
        } elseif (strcasecmp($role, 'Client') === 0) {
            $sql = "SELECT project_id, project_name 
                    FROM Projects 
                    WHERE company_id = :cid AND client_id = :uid 
                    ORDER BY project_name";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid role']);
            exit;
        }

        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'projects' => $projects]);
        exit;
    }

    // -------------------------
    // ACTION 2: Generate Report
    // -------------------------
    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($project_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'project_id required']);
        exit;
    }

    if (strcasecmp($role, 'Admin') === 0) {
        $sql = "SELECT p.*, u.email AS client_email, u.first_name AS client_first, u.last_name AS client_last
                FROM Projects p
                LEFT JOIN Users u ON p.client_id = u.user_id
                WHERE p.project_id = :pid AND p.company_id = :cid
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':pid', $project_id, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
    } elseif (strcasecmp($role, 'Client') === 0) {
        $sql = "SELECT p.*, u.email AS client_email, u.first_name AS client_first, u.last_name AS client_last
                FROM Projects p
                LEFT JOIN Users u ON p.client_id = u.user_id
                WHERE p.project_id = :pid 
                  AND p.company_id = :cid 
                  AND p.client_id = :uid
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':pid', $project_id, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $company_id, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid role']);
        exit;
    }

    $stmt->execute();
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found or access denied']);
        exit;
    }

    // -------------------------
    // Build project payload
    // -------------------------
    $projectPayload = [
        'project_id' => (int)$project['project_id'],
        'project_name' => $project['project_name'],
        'description' => $project['description'] ?? '',
        'manager_id' => isset($project['manager_id']) ? (int)$project['manager_id'] : null,
        'client_name' => trim(($project['client_first'] ?? '') . ' ' . ($project['client_last'] ?? '')),
        'client_email' => $project['client_email'] ?? '',
        'status' => $project['status'] ?? '',
        'start_date' => $project['start_date'] ?? null,
        'deadline' => $project['deadline'] ?? null,
        'end_date' => $project['end_date'] ?? null,
        'completion_percentage' => floatval($project['completion_percentage'] ?? 0),
        'budget_allocated' => isset($project['budget_allocated']) ? floatval($project['budget_allocated']) : null,
        'technology_stack' => $project['technology_stack'] ?? '',
        'updated_at' => $project['updated_at'] ?? null
    ];

    // -------------------------
    // Task summary
    // -------------------------
    $taskStatus = [];
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM Tasks WHERE project_id = :pid GROUP BY status");
    $stmt->execute([':pid' => $project_id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $taskStatus[$r['status']] = (int)$r['cnt'];
    }

    // -------------------------
    // Timesheet summary
    // -------------------------
    $timesheets = [];
    $stmt = $conn->prepare("
        SELECT CONCAT(u.first_name, ' ', u.last_name) AS name, SUM(t.hours_logged) AS hours
        FROM Timesheets t
        JOIN Tasks tk ON t.task_id = tk.task_id
        JOIN Users u ON t.user_id = u.user_id
        WHERE tk.project_id = :pid
        GROUP BY u.user_id
        ORDER BY hours DESC
    ");
    $stmt->execute([':pid' => $project_id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $timesheets[] = ['name' => $r['name'], 'hours' => floatval($r['hours'])];
    }

    echo json_encode([
        'success' => true,
        'project' => $projectPayload,
        'metrics' => [
            'task_status' => $taskStatus,
            'timesheets_by_user' => $timesheets
        ]
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>
