<?php
// C:\xampp\htdocs\RemoteTeamPro\backend\api\manager-projects.php

header("Access-Control-Allow-Origin: http://localhost"); 
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT"); 
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"); 
header("Content-Type: application/json; charset=UTF-8");

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// NOTE: Ensure your database.php file is correctly configured for connection.
require_once __DIR__ . '/../config/database.php'; 

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();
$conn = $db->getConnection();

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

$user_id = $_SESSION['user_id'] ?? 0; 
$role = $_SESSION['role'] ?? ''; 
$company_id = $_SESSION['company_id'] ?? 0; 

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function require_manager_login() {
    global $user_id, $role;
    if (!$user_id || $role !== 'Manager') {
        // Log out the user if role is incorrect or session is missing
        session_unset();
        session_destroy();
        respond(["success" => false, "error" => "Authentication failed. Manager role required."], 401);
    }
}
require_manager_login();

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* -------------------------------------------
        1) READ: List Projects Assigned to Manager 
    ------------------------------------------- */
    if ($action === 'get-manager-projects' && $method === 'GET') {
        $stmt = $conn->prepare("
            SELECT 
                p.project_id, p.project_name AS name, p.description, p.status, p.deadline, 
                p.completion_percentage AS completion, p.budget_allocated AS budget, p.client_id, p.manager_id,
                COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'N/A') AS client_name,
                (SELECT COUNT(user_id) FROM ProjectAssignments pa WHERE pa.project_id = p.project_id AND pa.user_id IS NOT NULL) AS team_size,
                CONCAT(m.first_name, ' ', m.last_name) AS manager_name
            FROM Projects p
            LEFT JOIN Users c ON p.client_id = c.user_id
            LEFT JOIN Users m ON p.manager_id = m.user_id
            WHERE p.manager_id = :mid AND p.company_id = :cid
            ORDER BY FIELD(p.status, 'Active', 'Pending', 'On Hold', 'Completed', 'Cancelled'), p.created_at DESC
        ");
        $stmt->execute([':mid' => $user_id, ':cid' => $company_id]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- METRIC CALCULATION (Same as before) ---
        $total_employees_stmt = $conn->prepare("
            SELECT COUNT(user_id) FROM Users 
            WHERE company_id = :cid AND role IN ('Employee', 'Manager') AND user_id != :mid
        ");
        $total_employees_stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
        $total_employees = $total_employees_stmt->fetchColumn();

        $assigned_employees_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT u.user_id)
            FROM ProjectAssignments pa
            JOIN Users u ON pa.user_id = u.user_id
            JOIN Projects p ON pa.project_id = p.project_id
            WHERE u.company_id = :cid 
            AND u.user_id != :mid 
            AND pa.user_id IS NOT NULL
            AND p.status = 'Active' -- Only count employees on active projects
        ");
        $assigned_employees_stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
        $assigned_employees = $assigned_employees_stmt->fetchColumn();

        $employees_available = max(0, $total_employees - $assigned_employees);
        
        // Calculate status counts for Chart.js
        $statusCounts = array_count_values(array_column($projects, 'status'));
        
        $stats = [
            'totalProjects' => count($projects),
            'totalBudget' => array_sum(array_column($projects, 'budget')),
            'avgCompletion' => count($projects) > 0 ? array_sum(array_column($projects, 'completion')) / count($projects) : 0,
            'employeesAssigned' => $assigned_employees,
            'employeesAvailable' => $employees_available,
            'statusCounts' => $statusCounts, // <-- Added for chart data
        ];
        
        respond(["success" => true, "projects" => $projects, "stats" => $stats]);
    }

    /* -------------------------------------------
        2) CREATE: Create New Project 
    ------------------------------------------- */
    if ($action === 'create-project' && $method === 'POST') {
        $conn->beginTransaction();
        
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $budget = $input['budget'] ?? 0.00;
        $deadline = $input['deadline'] ?? null;
        $client_id = $input['client_id'] ?? null; 
        
        if (empty($name) || empty($description)) {
            respond(["success" => false, "error" => "Project name and description are required."], 400);
        }

        $stmt = $conn->prepare("
            INSERT INTO Projects (project_name, description, budget_allocated, deadline, manager_id, client_id, company_id, status)
            VALUES (:name, :desc, :budget, :deadline, :mid, :cid_fk, :cid, 'Pending')
        ");
        $stmt->execute([
            ':name' => $name,
            ':desc' => $description,
            ':budget' => $budget,
            ':deadline' => $deadline,
            ':mid' => $user_id, // Assigned to the session manager
            ':cid_fk' => $client_id ?: null,
            ':cid' => $company_id
        ]);
        
        $new_project_id = $conn->lastInsertId();
        
        $conn->commit();
        respond(["success" => true, "message" => "Project created successfully.", "project_id" => $new_project_id], 201);
    }
    
    /* -------------------------------------------
        3) UPDATE: Update Project Details
    ------------------------------------------- */
    if ($action === 'update-project' && $method === 'PUT') {
        $conn->beginTransaction();
        $project_id = $input['project_id'] ?? 0;

        $stmt = $conn->prepare("
            UPDATE Projects 
            SET project_name = :name, description = :desc, budget_allocated = :budget, 
                deadline = :deadline, status = :status, completion_percentage = :completion
            WHERE project_id = :pid AND manager_id = :mid AND company_id = :cid
        ");

        $result = $stmt->execute([
            ':name' => $input['name'] ?? '',
            ':desc' => $input['description'] ?? '',
            ':budget' => $input['budget'] ?? 0.00,
            ':deadline' => $input['deadline'] ?? null,
            ':status' => $input['status'] ?? 'Pending',
            ':completion' => $input['completion'] ?? 0.00,
            ':pid' => $project_id,
            ':mid' => $user_id,
            ':cid' => $company_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            respond(["success" => false, "error" => "Project not found or not managed by you."], 404);
        }

        $conn->commit();
        respond(["success" => true, "message" => "Project details updated."], 200);
    }
    
    /* -------------------------------------------
        4) DELETE: Delete Project 
    ------------------------------------------- */
    if ($action === 'delete-project' && $method === 'DELETE') {
        $conn->beginTransaction();
        $project_id = $input['project_id'] ?? 0;
        
        $stmt = $conn->prepare("
            DELETE FROM Projects 
            WHERE project_id = :pid AND manager_id = :mid AND company_id = :cid
        ");
        $stmt->execute([
            ':pid' => $project_id,
            ':mid' => $user_id,
            ':cid' => $company_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            respond(["success" => false, "error" => "Project not found or not managed by you."], 404);
        }

        $conn->commit();
        respond(["success" => true, "message" => "Project deleted successfully."], 200);
    }

    /* -------------------------------------------
        5) TEAM HELPER: List All Employees with Skills
    ------------------------------------------- */
    if ($action === 'list-all-employees' && $method === 'GET') {
        $stmt = $conn->prepare("
            SELECT user_id, CONCAT(first_name, ' ', last_name) as name, role 
            FROM Users 
            WHERE company_id = :cid AND role IN ('Employee', 'Manager') AND user_id != :mid
            ORDER BY role, name
        ");
        $stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $skills_stmt = $conn->prepare("
            SELECT us.user_id, s.skill_name
            FROM UserSkills us
            JOIN Skills s ON us.skill_id = s.skill_id
            WHERE us.user_id IN (SELECT user_id FROM Users WHERE company_id = :cid AND role IN ('Employee', 'Manager'))
        ");
        $skills_stmt->execute([':cid' => $company_id]);
        $skills_data = $skills_stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

        foreach ($employees as &$emp) {
            $emp['skills'] = isset($skills_data[$emp['user_id']]) ? array_column($skills_data[$emp['user_id']], 'skill_name') : [];
        }
        unset($emp); 

        respond(["success" => true, "employees" => $employees]);
    }
    
    /* -------------------------------------------
        6) TEAM HELPER: Get Current Project Team
    ------------------------------------------- */
    if ($action === 'get-project-team' && $method === 'GET') {
        $project_id = $_GET['project_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT pa.user_id, pa.role_on_project AS role, u.role as user_role, CONCAT(u.first_name, ' ', u.last_name) AS name
            FROM ProjectAssignments pa
            JOIN Users u ON pa.user_id = u.user_id
            WHERE pa.project_id = :pid AND pa.user_id IS NOT NULL 
        ");
        $stmt->execute([':pid' => $project_id]);
        $team = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        respond(["success" => true, "team" => $team]); 
    }

    /* -------------------------------------------
        7) TEAM ACTION: Assign/Update Project Team
    ------------------------------------------- */
    if ($action === 'assign-team' && $method === 'POST') {
        $conn->beginTransaction();
        
        $project_id = $input['project_id'] ?? 0;
        $assignments = $input['assignments'] ?? []; 
        
        if (empty($project_id) || !is_array($assignments)) {
            respond(["success" => false, "error" => "Invalid project ID or assignments data."], 400);
        }

        // Check if manager owns the project (security)
        $check_stmt = $conn->prepare("SELECT manager_id FROM Projects WHERE project_id = :pid AND manager_id = :mid");
        $check_stmt->execute([':pid' => $project_id, ':mid' => $user_id]);
        if ($check_stmt->rowCount() === 0) {
             respond(["success" => false, "error" => "Project not found or not managed by you."], 404);
        }

        // Clear existing team assignments
        $delete_stmt = $conn->prepare("DELETE FROM ProjectAssignments WHERE project_id = :pid AND team_id IS NULL");
        $delete_stmt->execute([':pid' => $project_id]);
        
        $count = 0;
        if (!empty($assignments)) {
            // Insert new individual assignments
            $insert_stmt = $conn->prepare("
                INSERT INTO ProjectAssignments (project_id, user_id, role_on_project)
                VALUES (:pid, :uid, :role)
            ");
            
            foreach ($assignments as $assignment) {
                if (!empty($assignment['user_id']) && !empty($assignment['role'])) {
                    $insert_stmt->execute([
                        ':pid' => $project_id,
                        ':uid' => $assignment['user_id'],
                        ':role' => $assignment['role']
                    ]);
                    $count++;
                }
            }
        }
        
        // Update Project Status
        $new_status = $count > 0 ? 'Active' : 'Pending';
        // Only set status to Active/Pending if it's not already Completed/Cancelled
        $update_status_stmt = $conn->prepare("UPDATE Projects SET status = :status WHERE project_id = :pid AND status NOT IN ('Completed', 'Cancelled')");
        $update_status_stmt->execute([':status' => $new_status, ':pid' => $project_id]);
        
        $conn->commit();
        respond(["success" => true, "message" => "Team successfully assigned. Total {$count} members assigned."], 200);
    }
    
    /* -------------------------------------------
        8) HELPER: List Clients
    ------------------------------------------- */
    if ($action === 'list-clients' && $method === 'GET') {
        $stmt = $conn->prepare("
            SELECT user_id, CONCAT(first_name, ' ', last_name) as name
            FROM Users 
            WHERE company_id = :cid AND role = 'Client'
            ORDER BY name
        ");
        $stmt->execute([':cid' => $company_id]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        respond(["success" => true, "clients" => $clients]);
    }
    
    /* -------------------------------------------
        9) READ: Get Available Employee Count Only
    ------------------------------------------- */
    if ($action === 'get-available-employee-count' && $method === 'GET') {
        
        // 1. Get total employees (excluding current manager)
        $total_employees_stmt = $conn->prepare("
            SELECT COUNT(user_id) FROM Users 
            WHERE company_id = :cid AND role IN ('Employee', 'Manager') AND user_id != :mid
        ");
        $total_employees_stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
        $total_employees = $total_employees_stmt->fetchColumn();

        // 2. Get assigned employees (to active projects)
        $assigned_employees_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT u.user_id)
            FROM ProjectAssignments pa
            JOIN Users u ON pa.user_id = u.user_id
            JOIN Projects p ON pa.project_id = p.project_id
            WHERE u.company_id = :cid 
            AND u.user_id != :mid 
            AND pa.user_id IS NOT NULL
            AND p.status = 'Active' 
        ");
        $assigned_employees_stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
        $assigned_employees = $assigned_employees_stmt->fetchColumn();

        // 3. Calculate available
        $employees_available = max(0, $total_employees - $assigned_employees);
        
        // 4. Respond with just the count as an integer
        respond(["success" => true, "available_employees" => (int)$employees_available]);
    }

    /* -------------------------------------------
   10) LIST: Employees Not Assigned to Any Project
------------------------------------------- */
if ($action === 'list-available-employees' && $method === 'GET') {
    // Step 1: Select employees who are not in any active project
    $stmt = $conn->prepare("
        SELECT 
            u.user_id,
            CONCAT(u.first_name, ' ', u.last_name) AS name,
            u.role
        FROM Users u
        WHERE 
            u.company_id = :cid
            AND u.role IN ('Employee', 'Manager')
            AND u.user_id != :mid
            AND u.user_id NOT IN (
                SELECT DISTINCT pa.user_id
                FROM ProjectAssignments pa
                JOIN Projects p ON pa.project_id = p.project_id
                WHERE p.company_id = :cid AND p.status = 'Active'
            )
        ORDER BY u.role, name
    ");
    $stmt->execute([':cid' => $company_id, ':mid' => $user_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Attach skills for each employee
    $skills_stmt = $conn->prepare("
        SELECT us.user_id, s.skill_name
        FROM UserSkills us
        JOIN Skills s ON us.skill_id = s.skill_id
        WHERE us.user_id IN (
            SELECT u.user_id
            FROM Users u
            WHERE u.company_id = :cid
              AND u.role IN ('Employee', 'Manager')
        )
    ");
    $skills_stmt->execute([':cid' => $company_id]);
    $skills_data = $skills_stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

    foreach ($employees as &$emp) {
        $emp['skills'] = isset($skills_data[$emp['user_id']])
            ? array_column($skills_data[$emp['user_id']], 'skill_name')
            : [];
    }
    unset($emp);

    respond(["success" => true, "employees" => $employees]);
}

    if ($conn->inTransaction()) $conn->rollBack(); 
    respond(["success" => false, "error" => "Unknown action"], 400);

} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("Manager Projects API Error: " . $e->getMessage()); 
    respond(["success" => false, "error" => "An internal server error occurred.", "details" => $e->getMessage(), "line" => $e->getLine()], 500);
}
