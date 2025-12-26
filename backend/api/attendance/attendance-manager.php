<?php
// backend/api/attendance/attendance-manager.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__ . '/../../config/database.php';
session_start();

try {
    $db = new Database();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// 🧠 Session / Auth check
$manager_id = $_SESSION['user_id'] ?? null;
$role = strtolower($_SESSION['role'] ?? '');
if (!$manager_id || $role !== 'manager') {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $data['action'] ?? '';
    $now = date('Y-m-d H:i:s');

    if ($action === 'force_checkout') {
        $user_id = intval($data['user_id'] ?? 0);
        if (!$user_id) {
            echo json_encode(["success" => false, "message" => "Missing user_id"]);
            exit;
        }

        // find today's attendance
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT * FROM Attendance WHERE user_id = ? AND attendance_date = ?");
        $stmt->execute([$user_id, $today]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec || !$rec['check_in_time'] || $rec['check_out_time']) {
            echo json_encode(["success" => false, "message" => "Cannot force checkout"]);
            exit;
        }

        $checkin_time = strtotime($today . ' ' . $rec['check_in_time']);
        $checkout_time = strtotime($now);
        $hours = round(($checkout_time - $checkin_time) / 3600, 2);

        $upd = $pdo->prepare("
            UPDATE Attendance
            SET check_out_time = ?, hours_worked = ?, updated_at = NOW(), notes = CONCAT(IFNULL(notes,''), '\n[Forced checkout by manager]')
            WHERE attendance_id = ?
        ");
        $upd->execute([$now, $hours, $rec['attendance_id']]);

        echo json_encode(["success" => true, "message" => "Force checkout done", "hours_worked" => $hours]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit;
}

// 📊 GET attendance list for manager’s company/team
if ($method === 'GET') {
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    // find manager’s company
    $stmt = $pdo->prepare("SELECT company_id FROM Users WHERE user_id = ?");
    $stmt->execute([$manager_id]);
    $company_id = $stmt->fetchColumn();

    if (!$company_id) {
        echo json_encode(["success" => false, "message" => "No company found"]);
        exit;
    }

    // build query
    $sql = "
        SELECT 
            a.attendance_id,
            a.user_id,
            CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.email,
            a.attendance_date,
            a.check_in_time,
            a.check_out_time,
            a.hours_worked,
            a.status,
            a.notes
        FROM Attendance a
        INNER JOIN Users u ON a.user_id = u.user_id
        WHERE u.company_id = :company_id
    ";

    $params = [':company_id' => $company_id];

    if ($start && $end) {
        $sql .= " AND a.attendance_date BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end'] = $end;
    }

    $sql .= " ORDER BY a.attendance_date DESC, a.user_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "data" => $data]);
    exit;
}

echo json_encode(["success" => false, "message" => "Unsupported method"]);
