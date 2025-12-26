<?php
// backend/api/attendance/attendance-employee.php
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
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// identify user (prefer session, fallback query param)
$user_id = $_SESSION['user_id'] ?? ($_GET['user_id'] ?? null);
if (!$user_id) {
    echo json_encode(["error" => "User not logged in"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $action = $data['action'] ?? 'mark'; // check_in / check_out / mark
    $notes = $data['notes'] ?? ($data['note'] ?? '');
    $status = $data['status'] ?? null;
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    try {
        // 🕒 CHECK IN
        if ($action === 'check_in') {
            $chk = $pdo->prepare("SELECT * FROM Attendance WHERE user_id = ? AND attendance_date = ?");
            $chk->execute([$user_id, $today]);
            $exists = $chk->fetch(PDO::FETCH_ASSOC);

            if ($exists && $exists['check_in_time']) {
                echo json_encode(["success" => false, "message" => "Already checked in today"]);
                exit;
            }

            if ($exists) {
                $stmt = $pdo->prepare("
                    UPDATE Attendance 
                    SET check_in_time = ?, status = COALESCE(status, 'Present'),
                        notes = CONCAT(IFNULL(notes,''), ?), updated_at = NOW()
                    WHERE attendance_id = ?
                ");
                $append = $notes ? ("\n" . $notes) : '';
                $stmt->execute([$now, $append, $exists['attendance_id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO Attendance (user_id, attendance_date, check_in_time, status, notes, created_at, updated_at)
                    VALUES (?, ?, ?, 'Present', ?, NOW(), NOW())
                ");
                $stmt->execute([$user_id, $today, $now, $notes]);
            }

            echo json_encode(["success" => true, "action" => "check_in", "time" => $now]);
            exit;
        }

        // 🕔 CHECK OUT
        if ($action === 'check_out') {
            $chk = $pdo->prepare("SELECT * FROM Attendance WHERE user_id = ? AND attendance_date = ?");
            $chk->execute([$user_id, $today]);
            $rec = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$rec || !$rec['check_in_time']) {
                echo json_encode(["success" => false, "message" => "No check-in found for today"]);
                exit;
            }

            if ($rec['check_out_time']) {
                echo json_encode(["success" => false, "message" => "Already checked out"]);
                exit;
            }

            $checkout_time = $data['checkout_time'] ?? $now;

            // ✅ calculate hours precisely using UNIX timestamps
            $checkin_dt = strtotime($today . ' ' . $rec['check_in_time']);
            $checkout_dt = strtotime($checkout_time);
            $diff_minutes = ($checkout_dt - $checkin_dt) / 60;
            $hours_worked = $diff_minutes > 0 ? round($diff_minutes / 60, 2) : 0;

            $stmt = $pdo->prepare("
                UPDATE Attendance 
                SET check_out_time = ?, hours_worked = ?, 
                    updated_at = NOW(), 
                    notes = CONCAT(IFNULL(notes,''), ?)
                WHERE user_id = ? AND attendance_date = ?
            ");
            $append = $notes ? ("\n" . $notes) : '';
            $stmt->execute([$checkout_time, $hours_worked, $append, $user_id, $today]);

            echo json_encode([
                "success" => true,
                "action" => "check_out",
                "time" => $checkout_time,
                "hours_worked" => $hours_worked
            ]);
            exit;
        }

        // 📝 MARK STATUS
        if ($action === 'mark') {
            $status = $status ?: 'Present';
            $stmt = $pdo->prepare("
                INSERT INTO Attendance (user_id, attendance_date, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    notes = CONCAT(IFNULL(notes,''), VALUES(notes), '\n'),
                    updated_at = NOW()
            ");
            $stmt->execute([$user_id, $today, $status, $notes]);
            echo json_encode(["success" => true, "action" => "mark", "status" => $status]);
            exit;
        }

        echo json_encode(["error" => "Unknown action"]);
    } catch (PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// 📊 GET attendance history
if ($method === 'GET') {
    $limit = intval($_GET['limit'] ?? 30);
    $stmt = $pdo->prepare("
        SELECT attendance_id, user_id, attendance_date, check_in_time, check_out_time,
               hours_worked, status, notes
        FROM Attendance
        WHERE user_id = ?
        ORDER BY attendance_date DESC
        LIMIT ?
    ");
    $stmt->execute([$user_id, $limit]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

echo json_encode(["error" => "Unsupported method"]);
