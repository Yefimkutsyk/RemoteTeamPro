<?php
// backend/api/admin-project-requests.php
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();
$conn = $db->getConnection();

// FIX: Corrected typo from 'php://php://input' to 'php://input'
$raw = file_get_contents('php://input'); 
$input = $raw ? (json_decode($raw, true) ?: []) : [];

$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? 'list-by-status');
$method = $_SERVER['REQUEST_METHOD'];

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
// FIX: Set company_id to 2 for testing purposes, assuming you're logged into Company 2
$company_id = $_SESSION['company_id'] ?? 2; 

function require_login() {
    global $user_id;
    if (!$user_id) respond(["error" => "Authentication required"], 401);
}

require_login();

try {

    /* -------------------------------------------
       1️ List all client requests by status
    ------------------------------------------- */
    if ($action === 'list-by-status') {
        if (!in_array($role, ['Admin', 'Manager'])) {
            respond(["error" => "Unauthorized"], 403);
        }

        $status = $_GET['status'] ?? 'Pending';
        $query = trim($_GET['q'] ?? '');

        //  Filter by cr.company_id directly (now that client-project-request.php is fixed)
        $sql = "
            SELECT 
                cr.request_id,
                cr.project_name,
                cr.description,
                cr.status,
                cr.budget_allocated,
                cr.created_at,
                cr.updated_at,
                cr.review_message,
                CONCAT(u.first_name, ' ', u.last_name) AS client_name,
                u.email AS client_email,
                reviewer.user_id AS reviewer_id,
                CONCAT(reviewer.first_name, ' ', reviewer.last_name) AS reviewer_name
            FROM clientrequests cr
            JOIN users u ON cr.client_id = u.user_id
            LEFT JOIN users reviewer ON cr.reviewed_by = reviewer.user_id
            WHERE cr.company_id = :company_id -- IMPROVEMENT: Use the dedicated column
             AND cr.status = :status
        ";

        if ($query !== '') {
            $sql .= "
                AND (
                    cr.project_name LIKE :q
                    OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q
                    OR u.email LIKE :q
                )
            ";
        }

        $sql .= " ORDER BY cr.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':company_id', $company_id, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);

        if ($query !== '') {
            $like = '%' . $query . '%';
            $stmt->bindParam(':q', $like, PDO::PARAM_STR);
        }

        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond($requests);
    }

    /* -------------------------------------------
       2️ Approve or Reject project request
    ------------------------------------------- */
    if ($action === 'update-status' && $method === 'POST') {
        if (!in_array($role, ['Admin', 'Manager'])) {
            respond(["error" => "Unauthorized"], 403);
        }

        $request_id = intval($input['request_id'] ?? 0);
        $status = trim($input['status'] ?? '');
        $message = trim($input['message'] ?? '');

        if (!$request_id || !in_array($status, ['Approved', 'Rejected'])) {
            respond(["error" => "Invalid data"], 400);
        }

        $stmt = $conn->prepare("
            UPDATE clientrequests
            SET 
                status = :status,
                review_message = :message,
                reviewed_by = :reviewer,
                updated_at = NOW()
            WHERE request_id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':message' => $message ?: null,
            ':reviewer' => $user_id,
            ':id' => $request_id
        ]);

        if ($stmt->rowCount() > 0) {
            respond([
                "success" => true,
                "message" => "Request successfully {$status}.",
                "request_id" => $request_id
            ]);
        } else {
            respond(["error" => "No rows updated. Check request ID."], 400);
        }
    }

    /* -------------------------------------------
       3️ Assign manager (Admin only)
    ------------------------------------------- */
    if ($action === 'assign-manager' && $method === 'POST') {
        if ($role !== 'Admin') {
            respond(["error" => "Only Admin can assign managers"], 403);
        }

        $request_id = intval($input['request_id'] ?? 0);
        $manager_id = intval($input['manager_id'] ?? 0);

        if (!$request_id || !$manager_id) {
            respond(["error" => "Invalid input"], 400);
        }

        $stmt = $conn->prepare("
            UPDATE clientrequests
            SET 
                manager_id = :manager,
                status = 'Approved',
                updated_at = NOW()
            WHERE request_id = :id
        ");
        $stmt->execute([
            ':manager' => $manager_id,
            ':id' => $request_id
        ]);

        respond(["success" => true, "message" => "Manager assigned successfully"]);
    }
/* -------------------------------------------
   4️ List approved requests ready for project creation
------------------------------------------- */
if ($action === 'list-ready-projects') {
    if (!in_array($role, ['Admin', 'Manager'])) {
        respond(["error" => "Unauthorized"], 403);
    }

    // Fetch only approved client requests that are not yet converted into projects
    $sql = "
        SELECT 
            cr.request_id,
            cr.project_name,
            cr.description,
            cr.budget_allocated,
            cr.status,
            cr.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS client_name
        FROM clientrequests cr
        JOIN users u ON cr.client_id = u.user_id
        WHERE cr.company_id = :company_id
          AND cr.status = 'Approved'
          -- CRITICAL FIX: Check the client_request_id column in the projects table
          AND cr.request_id NOT IN (
              SELECT client_request_id FROM projects WHERE client_request_id IS NOT NULL 
          )
        ORDER BY cr.updated_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':company_id', $company_id, PDO::PARAM_INT);
    $stmt->execute();
    $ready = $stmt->fetchAll(PDO::FETCH_ASSOC);
    respond($ready);
}

    respond(["error" => "Unknown action"], 400);

} catch (PDOException $e) {
    respond(["error" => "Database error: " . $e->getMessage()], 500);
} catch (Throwable $e) {
    respond(["error" => "Server error: " . $e->getMessage()], 500);
}


