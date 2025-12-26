<?php
// backend/api/activity-log.php
header("Content-Type: application/json; charset=UTF-8");
session_start();

require_once __DIR__ . "/../config/database.php";

$db = new Database();
$pdo = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Helper: Log an activity
function logActivity(PDO $pdo, ?int $user_id, string $action_type, string $details = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO ActivityLog (user_id, action_type, details, ip_address)
        VALUES (:user_id, :action_type, :details, :ip_address)
    ");
    $stmt->execute([
        ':user_id' => $user_id,
        ':action_type' => $action_type,
        ':details' => $details,
        ':ip_address' => $ip_address
    ]);
}

// ROUTES
switch ($method) {
    case 'POST':
        if ($action === 'add') {
            $user_id = $_SESSION['user_id'] ?? null;
            $data = json_decode(file_get_contents("php://input"), true);

            $action_type = $data['action_type'] ?? '';
            $details = $data['details'] ?? '';

            if (!$action_type) {
                echo json_encode(["status" => "error", "message" => "Missing action_type"]);
                exit;
            }

            logActivity($pdo, $user_id, $action_type, $details);
            echo json_encode(["status" => "success", "message" => "Activity logged"]);
        }
        break;

    case 'GET':
        if ($action === 'list') {
            $user_id = $_SESSION['user_id'] ?? null;
            $role = $_SESSION['role'] ?? '';

            if (!$user_id) {
                echo json_encode(["status" => "error", "message" => "Not logged in"]);
                exit;
            }

            if (strtolower($role) === 'admin') {
                // Admin: view all logs for the same company
                $stmt = $pdo->prepare("
                    SELECT 
                        a.*, 
                        CONCAT(u.first_name, ' ', u.last_name) AS user_name, 
                        u.company_id
                    FROM ActivityLog a
                    LEFT JOIN Users u ON a.user_id = u.user_id
                    WHERE u.company_id = (SELECT company_id FROM Users WHERE user_id = :uid)
                    ORDER BY a.timestamp DESC
                ");
                $stmt->execute([':uid' => $user_id]);
            } else {
                // Regular user: view own logs
                $stmt = $pdo->prepare("
                    SELECT 
                        a.*, 
                        CONCAT(u.first_name, ' ', u.last_name) AS user_name
                    FROM ActivityLog a
                    LEFT JOIN Users u ON a.user_id = u.user_id
                    WHERE a.user_id = :uid
                    ORDER BY a.timestamp DESC
                ");
                $stmt->execute([':uid' => $user_id]);
            }

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "success", "logs" => $logs]);
        }
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Invalid request"]);
}
?>
