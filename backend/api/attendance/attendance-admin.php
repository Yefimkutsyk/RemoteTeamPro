<?php
// backend/api/attendance/attendance-admin.php
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
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

// Optional: admin session check (for testing not enforced)
$admin_id = $_SESSION['user_id'] ?? ($_GET['admin_id'] ?? null);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch last 30 days attendance with joined user/company data
    $days = intval($_GET['days'] ?? 30);

    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.attendance_id,
                a.user_id,
                DATE_FORMAT(a.attendance_date, '%Y-%m-%d') AS attendance_date,
                a.check_in_time,
                a.check_out_time,
                a.status,
                a.notes,
                a.hours_worked,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                u.role,
                c.company_name
            FROM Attendance a
            JOIN Users u ON a.user_id = u.user_id
            LEFT JOIN Companies c ON u.company_id = c.company_id
            WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY a.attendance_date DESC, full_name ASC
            LIMIT 1000
        ");
        $stmt->bindParam(1, $days, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize output for JS
        foreach ($records as &$r) {
            $r['hours_worked'] = $r['hours_worked'] !== null ? floatval($r['hours_worked']) : null;
        }

        echo json_encode($records);
        exit;
    } catch (PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $data['action'] ?? null;

    try {
        if ($action === 'force_checkout') {
            $user_id = $data['user_id'] ?? null;
            $checkout_time = $data['checkout_time'] ?? date('Y-m-d H:i:s');
            if (!$user_id) {
                echo json_encode(["error" => "Missing user_id"]);
                exit;
            }

            $today = date('Y-m-d');
            $rec = $pdo->prepare("SELECT * FROM Attendance WHERE user_id = ? AND attendance_date = ?");
            $rec->execute([$user_id, $today]);
            $row = $rec->fetch(PDO::FETCH_ASSOC);

            if (!$row || !$row['check_in_time']) {
                echo json_encode(["error" => "No active check-in found for today"]);
                exit;
            }

            // Calculate hours between check_in and checkout_time
            $stmtCalc = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS mins");
            $stmtCalc->execute([$row['check_in_time'], $checkout_time]);
            $mins = $stmtCalc->fetchColumn();
            $hours = $mins !== null ? round(($mins / 60), 2) : null;

            $upd = $pdo->prepare("
                UPDATE Attendance 
                SET check_out_time = ?, hours_worked = ?, updated_at = NOW() 
                WHERE user_id = ? AND attendance_date = ?
            ");
            $upd->execute([$checkout_time, $hours, $user_id, $today]);

            echo json_encode(["success" => true, "hours" => $hours]);
            exit;
        }

        if ($action === 'mark') {
            $user_id = $data['user_id'] ?? null;
            $status = $data['status'] ?? 'Present';
            $notes = trim($data['notes'] ?? '');
            if (!$user_id) {
                echo json_encode(["error" => "Missing user_id"]);
                exit;
            }

            $date = $data['attendance_date'] ?? date('Y-m-d');

            $stmt = $pdo->prepare("
                INSERT INTO Attendance (user_id, attendance_date, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    notes = CONCAT(IFNULL(notes,''), ?),
                    updated_at = NOW()
            ");
            $append = $notes ? ("\n" . $notes) : '';
            $stmt->execute([$user_id, $date, $status, $notes, $append]);

            echo json_encode(["success" => true]);
            exit;
        }

        echo json_encode(["error" => "Unknown or missing action"]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }
}

echo json_encode(["error" => "Unsupported method"]);
exit;
?>
