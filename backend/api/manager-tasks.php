<?php
// File: /backend/api/manager-tasks.php
session_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once(__DIR__ . '/../config/database.php');
$database = new Database();
$db = $database->getConnection();

// --- AUTH CHECK ---
$manager_user_id = $_SESSION['user_id'] ?? null;
if (!$manager_user_id) sendJsonResponse(["message" => "Authentication Required. Please log in."], 401);

$auth = $db->prepare("SELECT company_id, role FROM Users WHERE user_id = :uid");
$auth->bindParam(":uid", $manager_user_id, PDO::PARAM_INT);
$auth->execute();
$user = $auth->fetch(PDO::FETCH_ASSOC);

if (!$user) sendJsonResponse(["message" => "Authentication failed: user not found."], 401);
$company_id = $user['company_id'];
$role = $user['role'];

if (!in_array($role, ['Manager', 'Admin']))
    sendJsonResponse(["message" => "Unauthorized: Only Managers/Admins can access this endpoint."], 403);

// --- ROUTER ---
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = ($method === 'POST') ? json_decode(file_get_contents("php://input")) : null;

switch ($action) {
    case 'get_projects':         handleGetProjects($db, $company_id); break;
    case 'get_manager_tasks':    handleGetManagerTasks($db, $company_id); break;
    case 'get_employees':        handleGetEmployees($db, $company_id); break;
    case 'create_task':          
        if ($method === 'POST') handleCreateTask($db, $company_id, $manager_user_id, $data);
        else sendJsonResponse(["message" => "Invalid method"], 405);
        break;
    default:                     
        sendJsonResponse(["message" => "Invalid or missing action parameter."], 400);
}

// ======================== HANDLERS ========================

function handleGetProjects($db, $company_id) {
    $q = "SELECT project_id, project_name FROM Projects 
          WHERE company_id = :cid AND status != 'Completed' ORDER BY project_name";
    $st = $db->prepare($q);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    sendJsonResponse($st->fetchAll(PDO::FETCH_ASSOC));
}

function handleGetEmployees($db, $company_id) {
    $q = "SELECT user_id, first_name, last_name, role 
          FROM Users WHERE company_id = :cid AND role IN ('Employee','Manager') ORDER BY first_name";
    $st = $db->prepare($q);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    sendJsonResponse($st->fetchAll(PDO::FETCH_ASSOC));
}

function handleGetManagerTasks($db, $company_id) {
    $q = "SELECT 
                t.task_id, 
                t.task_name, 
                t.description, 
                t.due_date, 
                t.status, 
                t.priority, 
                t.estimated_hours,
                t.actual_hours,
                p.project_name, 
                CONCAT(u.first_name,' ',u.last_name) AS assigned_to_name
          FROM Tasks t
          JOIN Projects p ON t.project_id = p.project_id
          JOIN Users u ON t.assigned_to_user_id = u.user_id
          WHERE p.company_id = :cid
          ORDER BY t.due_date DESC";
    $st = $db->prepare($q);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    sendJsonResponse($st->fetchAll(PDO::FETCH_ASSOC));
}

function handleCreateTask($db, $company_id, $manager_user_id, $data) {
    if (empty($data->project_id) || empty($data->task_name) || empty($data->due_date))
        sendJsonResponse(["message" => "Missing required fields (project_id, task_name, due_date)."], 400);

    // Validate project ownership
    if (!validateProject($db, $data->project_id, $company_id))
        sendJsonResponse(["message" => "Invalid project for this company."], 403);

    // Determine assignment target
    $assigned_to_user_id = $data->assigned_to_user_id ?? null;
    $assigned_to_team_id = $data->assigned_to_team_id ?? null;

    // Estimated hours handling
    $estimated_hours = isset($data->estimated_hours) && is_numeric($data->estimated_hours)
        ? floatval($data->estimated_hours)
        : null;

    // Default to manager themselves if not specified
    if (empty($assigned_to_user_id) && empty($assigned_to_team_id)) {
        $assigned_to_user_id = $manager_user_id;
    }

    // --- SINGLE USER ASSIGNMENT ---
    if (!empty($assigned_to_user_id)) {
        if (!validateUser($db, $assigned_to_user_id, $company_id))
            sendJsonResponse(["message" => "Invalid assigned user for this company."], 403);

        try {
            $q = "INSERT INTO Tasks 
                    (project_id, assigned_to_user_id, task_name, description, priority, due_date, estimated_hours, status)
                  VALUES 
                    (:pid, :uid, :name, :desc, :prio, :due, :est, 'To Do')";
            $st = $db->prepare($q);
            $st->bindParam(":pid", $data->project_id, PDO::PARAM_INT);
            $st->bindParam(":uid", $assigned_to_user_id, PDO::PARAM_INT);
            $st->bindParam(":name", $data->task_name);
            $st->bindParam(":desc", $data->description);
            $st->bindParam(":prio", $data->priority);
            $st->bindParam(":due", $data->due_date);
            $st->bindParam(":est", $estimated_hours);
            $st->execute();

            sendJsonResponse(["message" => "Task '{$data->task_name}' created successfully."], 201);
        } catch (PDOException $e) {
            sendJsonResponse(["message" => "Database error: " . $e->getMessage()], 500);
        }
    }

    // --- TEAM ASSIGNMENT (duplicate task for each member) ---
    if (!empty($assigned_to_team_id)) {
        if (!validateTeam($db, $assigned_to_team_id, $company_id))
            sendJsonResponse(["message" => "Invalid team for this company."], 403);

        $members = $db->prepare("SELECT user_id FROM TeamMembers WHERE team_id = :tid");
        $members->bindParam(":tid", $assigned_to_team_id, PDO::PARAM_INT);
        $members->execute();
        $user_ids = $members->fetchAll(PDO::FETCH_COLUMN);

        if (!$user_ids) sendJsonResponse(["message" => "Selected team has no members."], 400);

        try {
            $db->beginTransaction();
            $q = "INSERT INTO Tasks 
                    (project_id, assigned_to_user_id, task_name, description, priority, due_date, estimated_hours, status)
                  VALUES 
                    (:pid, :uid, :name, :desc, :prio, :due, :est, 'To Do')";
            $st = $db->prepare($q);
            foreach ($user_ids as $uid) {
                $st->execute([
                    ':pid' => $data->project_id,
                    ':uid' => $uid,
                    ':name' => $data->task_name,
                    ':desc' => $data->description,
                    ':prio' => $data->priority,
                    ':due' => $data->due_date,
                    ':est' => $estimated_hours
                ]);
            }
            $db->commit();
            sendJsonResponse(["message" => "Task created for " . count($user_ids) . " team members."], 201);
        } catch (PDOException $e) {
            $db->rollBack();
            sendJsonResponse(["message" => "Database error: " . $e->getMessage()], 500);
        }
    }
}

// ======================== HELPERS ========================

function sendJsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function validateProject($db, $project_id, $company_id) {
    $q = "SELECT project_id FROM Projects WHERE project_id = :pid AND company_id = :cid";
    $st = $db->prepare($q);
    $st->bindParam(":pid", $project_id, PDO::PARAM_INT);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    return $st->rowCount() > 0;
}

function validateUser($db, $user_id, $company_id) {
    $q = "SELECT user_id FROM Users WHERE user_id = :uid AND company_id = :cid";
    $st = $db->prepare($q);
    $st->bindParam(":uid", $user_id, PDO::PARAM_INT);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    return $st->rowCount() > 0;
}

function validateTeam($db, $team_id, $company_id) {
    $q = "SELECT team_id FROM Teams WHERE team_id = :tid AND company_id = :cid";
    $st = $db->prepare($q);
    $st->bindParam(":tid", $team_id, PDO::PARAM_INT);
    $st->bindParam(":cid", $company_id, PDO::PARAM_INT);
    $st->execute();
    return $st->rowCount() > 0;
}
?>
