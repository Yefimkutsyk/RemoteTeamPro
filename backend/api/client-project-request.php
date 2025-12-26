<?php
// backend/api/client-project-request.php
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if ($method === 'POST' || $method === 'PUT') {
    $raw = file_get_contents('php://input');
    if ($raw) $input = json_decode($raw, true) ?: [];
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
$company_id = $_SESSION['company_id'] ?? null;

function require_login() {
    global $user_id;
    if (!$user_id) respond(["error" => "Authentication required"], 401);
}

require_login();

try {
    // 1️ Create new project request (Client)
    if ($action === 'create') {
        if (strcasecmp($role, 'Client') !== 0)
            respond(["error" => "Only clients can request new projects"], 403);

        // Crucial Check: Ensure client belongs to a company before creating the request
        if (!$company_id) respond(["error" => "Client must be associated with a company."], 403);

        $data = $input ?: $_POST;
        $project_name = trim($data['project_name'] ?? '');
        if ($project_name === '') respond(["error" => "Project name required"], 400);

        $description = $data['description'] ?? '';
        $expected_date = !empty($data['expected_date']) ? $data['expected_date'] : null;
        $budget_allocated = !empty($data['budget']) ? floatval($data['budget']) : null;

        $stmt = $conn->prepare("
            INSERT INTO clientrequests (
                client_id, project_name, description, expected_date,
                budget_allocated, status, company_id, created_at, updated_at
            ) VALUES (
                :client_id, :project_name, :description, :expected_date,
                :budget_allocated, 'Pending', :company_id, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':client_id' => $user_id,
            ':project_name' => $project_name,
            ':description' => $description,
            ':expected_date' => $expected_date,
            ':budget_allocated' => $budget_allocated,
            ':company_id' => $company_id // <-- CORRECTLY LINKS TO CLIENT'S COMPANY
        ]);

        $request_id = $conn->lastInsertId();

        // Log activity
        $log = $conn->prepare("
            INSERT INTO activitylog (user_id, action_type, details)
            VALUES (:uid, 'ClientProjectRequested', :details)
        ");
        $log->execute([
            ':uid' => $user_id,
            ':details' => "Client requested new project (#$request_id): $project_name"
        ]);

        respond(["success" => true, "request_id" => $request_id], 201);
    }

    // 2️ List project requests
    if ($action === 'list') {
        if (strcasecmp($role, 'Client') === 0) {
            // Client view
            $stmt = $conn->prepare("
                SELECT 
                    cr.*, 
                    CONCAT(u.first_name, ' ', u.last_name) AS client_name,
                    u.email AS client_email,
                    rv.role AS reviewer_role,
                    cr.review_message
                FROM clientrequests cr
                JOIN users u ON cr.client_id = u.user_id
                LEFT JOIN users rv ON cr.reviewed_by = rv.user_id
                WHERE cr.client_id = :client_id
                ORDER BY cr.request_id DESC
            ");
            $stmt->bindParam(':client_id', $user_id, PDO::PARAM_INT);
        } elseif (in_array($role, ['Admin', 'Manager'])) {
            // Admin / Manager view: Filters by the Admin/Manager's company_id from session
            $stmt = $conn->prepare("
                SELECT 
                    cr.*, 
                    CONCAT(u.first_name, ' ', u.last_name) AS client_name,
                    u.email AS client_email
                FROM clientrequests cr
                JOIN users u ON cr.client_id = u.user_id
                WHERE cr.company_id = :company_id 
                ORDER BY cr.request_id DESC
            ");
            $stmt->bindParam(':company_id', $company_id, PDO::PARAM_INT);
        } else {
            respond(["error" => "Unauthorized"], 403);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond($results);
    }

    // 3️ Update request status (Admin/Manager)
    if ($action === 'update-status' && $method === 'POST') {
        if (!in_array($role, ['Admin', 'Manager'])) {
            respond(["error" => "Unauthorized"], 403);
        }

        $data = $input ?: $_POST;
        $request_id = intval($data['request_id'] ?? 0);
        $status = trim($data['status'] ?? '');
        $message = trim($data['message'] ?? '');

        $valid_statuses = ['Pending', 'Approved', 'Rejected'];
        if (!$request_id || !in_array($status, $valid_statuses)) {
            respond(["error" => "Invalid data"], 400);
        }

        $stmt = $conn->prepare("
            UPDATE clientrequests 
            SET 
                status = :status,
                review_message = :message,
                reviewed_by = :reviewer,
                updated_at = NOW()
            WHERE request_id = :request_id
        ");
        $stmt->execute([
            ':status' => $status,
            ':message' => $message ?: null,
            ':reviewer' => $user_id,
            ':request_id' => $request_id
        ]);

        respond(["success" => true, "message" => "Request $status successfully"]);
    }

    respond(["error" => "Unknown action"], 400);

} catch (PDOException $e) {
    respond(["error" => $e->getMessage()], 500);
}
?>