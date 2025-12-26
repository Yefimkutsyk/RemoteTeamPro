<?php
// File: /backend/api/employee-tasks.php
session_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once(__DIR__ . '/../config/database.php');
$db = (new Database())->getConnection();

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) sendJson(["message" => "Authentication required"], 401);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = ($method === 'POST') ? json_decode(file_get_contents("php://input")) : null;

switch ($action) {
  case 'get_my_tasks': handleGetMyTasks($db, $user_id); break;
  case 'update_task': if ($method === 'POST') handleUpdateTask($db, $user_id, $data); else sendJson(["message" => "Invalid method"], 405); break;
  default: sendJson(["message" => "Invalid or missing action"], 400);
}

function handleGetMyTasks($db, $uid) {
  $q = "SELECT t.task_id, t.task_name, t.description, t.priority, t.status,
               t.estimated_hours, t.actual_hours, t.due_date, p.project_name
        FROM Tasks t
        JOIN Projects p ON t.project_id = p.project_id
        WHERE t.assigned_to_user_id = :uid
        ORDER BY t.due_date ASC";
  $s = $db->prepare($q);
  $s->bindParam(":uid", $uid, PDO::PARAM_INT);
  $s->execute();
  sendJson($s->fetchAll(PDO::FETCH_ASSOC));
}

function handleUpdateTask($db, $uid, $data) {
  if (empty($data->task_id) || empty($data->status))
    sendJson(["message" => "Missing task_id or status"], 400);

  $q = "UPDATE Tasks
        SET status = :status,
            actual_hours = IFNULL(:actual_hours, actual_hours),
            updated_at = CURRENT_TIMESTAMP
        WHERE task_id = :tid AND assigned_to_user_id = :uid";
  $s = $db->prepare($q);
  $s->bindParam(":status", $data->status);
  $s->bindParam(":tid", $data->task_id, PDO::PARAM_INT);
  $s->bindParam(":uid", $uid, PDO::PARAM_INT);
  $s->bindParam(":actual_hours", $data->actual_hours);
  $s->execute();

  if ($s->rowCount() > 0)
    sendJson(["message" => "Task updated successfully."]);
  else
    sendJson(["message" => "Task not found or not assigned to you."], 404);
}

function sendJson($d, $c=200){http_response_code($c);echo json_encode($d);exit;}
?>
