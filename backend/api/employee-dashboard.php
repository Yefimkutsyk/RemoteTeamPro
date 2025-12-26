<?php
// backend/api/employee-dashboard.php
header("Content-Type: application/json; charset=UTF-8");
session_start();
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

$db = new Database();
$conn = $db->getConnection();

// ✅ Ensure valid session
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if (!$user_id || $user_role !== 'Employee') {
  echo json_encode(["error" => "Unauthorized Access. Please log in again."]);
  exit;
}

try {
  // 🔹 Fetch task progress
  $stmt = $conn->prepare("
      SELECT 
          COUNT(*) AS total_tasks,
          SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks,
          SUM(CASE WHEN status IN ('To Do', 'In Progress', 'Under Review') THEN 1 ELSE 0 END) AS pending_tasks
      FROM Tasks 
      WHERE assigned_to_user_id = ?
  ");
  $stmt->execute([$user_id]);
  $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_tasks' => 0,
    'completed_tasks' => 0,
    'pending_tasks' => 0
  ];

  // Calculate progress %
  $completed = (int)$stats['completed_tasks'];
  $pending = (int)$stats['pending_tasks'];
  $total = max($completed + $pending, 1);
  $progress = [
    "completed" => $completed,
    "pending" => $pending,
    "percent" => round(($completed / $total) * 100, 1)
  ];

  // 🔹 Workload over time (tasks created per week)
  $stmt2 = $conn->prepare("
      SELECT 
          DATE_FORMAT(created_at, '%b %d') AS week_label,
          COUNT(*) AS task_count
      FROM Tasks 
      WHERE assigned_to_user_id = ?
      GROUP BY YEARWEEK(created_at)
      ORDER BY YEARWEEK(created_at)
      LIMIT 8
  ");
  $stmt2->execute([$user_id]);
  $workload = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // 🔹 Return final JSON
  echo json_encode([
    "progress" => $progress,
    "workload" => $workload
  ]);
  
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database error: " . $e->getMessage()]);
  exit;
}
?>
