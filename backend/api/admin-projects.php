<?php
// C:\xampp\htdocs\RemoteTeamPro\backend\api\admin-projects.php

// FIX: Add CORS headers if your front-end runs on a different port or domain (like localhost:3000)
header("Access-Control-Allow-Origin: *"); 
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();
$conn = $db->getConnection();

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
// Use Admin fallback for testing, but ideally use session data
$role = $_SESSION['role'] ?? 'Admin'; 
$company_id = $_SESSION['company_id'] ?? 1; 

function require_login() {
    global $user_id;
    if (!$user_id) respond(["error" => "Authentication required"], 401);
}
require_login();

try {
    // Start transaction only once
    $conn->beginTransaction();

    /* -------------------------------------------
        1) List all projects
    ------------------------------------------- */
    if ($action === 'list') {
        $stmt = $conn->prepare("
            SELECT p.*, 
                    CONCAT(m.first_name, ' ', m.last_name) AS manager_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS client_name
            FROM projects p
            LEFT JOIN users m ON p.manager_id = m.user_id
            LEFT JOIN users c ON p.client_id = c.user_id
            WHERE p.company_id = :cid
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /* -------------------------------------------
        2) Utility: List Clients
    ------------------------------------------- */
    if ($action === 'list-clients') {
        $stmt = $conn->prepare("
            SELECT user_id, CONCAT(first_name,' ',last_name) AS name 
            FROM users 
            WHERE role='Client' AND company_id=:cid AND status='Active'
        ");
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /* -------------------------------------------
        3) Utility: List Managers
    ------------------------------------------- */
    if ($action === 'list-managers') {
        $sql = "SELECT user_id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE role='Manager' AND company_id=:cid AND status='Active'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /* -------------------------------------------
        4) Create new project (Manual)
    ------------------------------------------- */
    if ($action === 'create' && $method === 'POST') {
        $data = $input;
        
        $insert = $conn->prepare("
            INSERT INTO projects (
                company_id, project_name, description, manager_id, client_id,
                status, deadline, budget_allocated
            ) VALUES (
                :company_id, :project_name, :description, :manager_id, :client_id,
                'Pending', :deadline, :budget_allocated
            )
        ");
        $insert->execute([
            ':company_id' => $company_id,
            ':project_name' => trim($data['project_name']),
            ':description' => $data['description'] ?: null,
            ':manager_id' => $data['manager_id'] ?: null,
            ':client_id' => $data['client_id'] ?: null,
            ':deadline' => $data['deadline'] ?: null,
            ':budget_allocated' => $data['budget_allocated'] ?: 0,
        ]);

        $conn->commit(); // Mutating action requires commit
        respond(["success" => true, "message" => "Project created successfully."]);
    }

    /* -------------------------------------------
        5) List approved requests ready for project creation
    ------------------------------------------- */
    if ($action === 'list-ready-projects') {
        $sql = "
            SELECT 
                cr.request_id, cr.project_name, cr.description, cr.budget_allocated, 
                cr.expected_date AS deadline, cr.client_id, cr.manager_id,
                CONCAT(u.first_name, ' ', u.last_name) AS client_name
            FROM clientrequests cr
            LEFT JOIN users u ON cr.client_id = u.user_id
            WHERE cr.company_id = :cid
              AND cr.status = 'Approved'
              AND NOT EXISTS (
                  SELECT 1 FROM projects p 
                  WHERE p.client_request_id = cr.request_id
              )
            ORDER BY COALESCE(cr.updated_at, cr.created_at) DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /* -------------------------------------------
        6) Convert approved request -> project (using form data)
    ------------------------------------------- */
    if ($action === 'convert-request' && $method === 'POST') {
        $data = $input;
        $reqId = $data['client_request_id'] ?? null;

        if (!$reqId) respond(["success" => false, "error" => "Missing client_request_id"], 400);
        
        // 1. Fetch original request data (read-only)
        $q = $conn->prepare("SELECT * FROM clientrequests WHERE request_id=:rid");
        $q->execute([':rid' => $reqId]);
        $req = $q->fetch(PDO::FETCH_ASSOC);
        if (!$req) respond(["success" => false, "error" => "Client Request not found or not approved"], 404);

        // 2. Insert into projects
        $insert = $conn->prepare("
            INSERT INTO projects 
            (company_id, project_name, description, manager_id, client_id, status, deadline, budget_allocated, client_request_id)
            VALUES 
            (:cid, :name, :desc, :mid, :clid, 'Active', :deadline, :budget, :rid)
        ");
        $insert->execute([
            ':cid' => $company_id,
            ':name' => trim($data['project_name']),
            ':desc' => $data['description'] ?: null,
            ':mid' => $data['manager_id'] ?: null, 
            ':clid' => $data['client_id'], 
            ':deadline' => $data['deadline'] ?: null,
            ':budget' => $data['budget_allocated'] ?: 0,
            ':rid' => $reqId
        ]);
        
        // 3. Update client request status 
        $u = $conn->prepare("UPDATE clientrequests SET status='Converted to Project', updated_at=NOW() WHERE request_id=:rid");
        $u->execute([':rid' => $reqId]);

        $conn->commit(); // Mutating action requires commit
        respond(["success" => true, "message" => "Request converted to project successfully."]);
    }

    /* -------------------------------------------
        7) Summary, Charts, etc. (Real Endpoints)
    ------------------------------------------- */
    if ($action === 'summary') {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total_projects, COALESCE(SUM(budget_allocated),0) AS total_budget, COALESCE(AVG(completion_percentage),0) AS avg_completion
            FROM projects WHERE company_id = :cid
        ");
        $stmt->execute([':cid' => $company_id]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        $mgr = $conn->prepare("SELECT COUNT(*) FROM users WHERE role='Manager' AND company_id=:cid AND status='Active'");
        $mgr->execute([':cid' => $company_id]);
        $summary['total_managers'] = (int)$mgr->fetchColumn();
        respond($summary);
    }

    if ($action === 'projects-per-manager') { 
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unassigned') AS manager_name, 
                COUNT(p.project_id) AS project_count
            FROM projects p
            LEFT JOIN users u ON p.manager_id = u.user_id
            WHERE p.company_id = :cid
            GROUP BY p.manager_id
            ORDER BY project_count DESC
        ");
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    }
    
    if ($action === 'status-distribution') { 
        $stmt = $conn->prepare("
            SELECT 
                status, 
                COUNT(project_id) AS count 
            FROM projects 
            WHERE company_id = :cid
            GROUP BY status
        ");
        $stmt->execute([':cid' => $company_id]);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    }
    
    if ($action === 'budget-trend') { 
        // This query groups budget allocations by the month of project creation
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') AS date_month,
                COALESCE(SUM(budget_allocated), 0) AS total
            FROM projects
            WHERE company_id = :cid
            GROUP BY date_month
            ORDER BY date_month
        ");
        $stmt->execute([':cid' => $company_id]);
        
        // Format the date for the frontend (e.g., "2023-10" to "Oct 2023" or similar)
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedResults = array_map(function($row) {
            // Using a simple format for the chart, e.g., 'Oct 23'
            $timestamp = strtotime($row['date_month'] . '-01');
            $row['date'] = date('M Y', $timestamp);
            unset($row['date_month']);
            return $row;
        }, $results);
        
        respond($formattedResults); 
    }


    // Rollback if we reach here and a transaction is active, though this should not happen if respond() is called.
    if ($conn->inTransaction()) $conn->rollBack(); 
    respond(["error" => "Unknown action"], 400);

} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    // Return a 500 status with error details
    respond(["error" => "Server error: " . $e->getMessage()], 500);
}