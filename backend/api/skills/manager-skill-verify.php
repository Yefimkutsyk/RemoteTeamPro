<?php
// backend/api/skills/manager-skill-verify.php
header('Content-Type: application/json; charset=UTF-8');
session_start();
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $manager_id = $_SESSION['user_id'] ?? 0;
    if (!$manager_id) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    // fetch manager's company_id so manager only sees employees in same company
    $stmt = $pdo->prepare("SELECT company_id FROM Users WHERE user_id = :mid");
    $stmt->execute([':mid' => $manager_id]);
    $mgr = $stmt->fetch(PDO::FETCH_ASSOC);
    $manager_company_id = $mgr['company_id'] ?? null;

    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $status = $_GET['status'] ?? 'all';
        $query = "
            SELECT us.user_skill_id, us.user_id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
                   s.skill_name, us.proficiency_level, us.verification_status, us.verified_at
            FROM UserSkills us
            JOIN Users u ON us.user_id = u.user_id
            JOIN Skills s ON us.skill_id = s.skill_id
            WHERE 1=1
        ";
        $params = [];

        // restrict to manager company if available
        if ($manager_company_id) {
            $query .= " AND u.company_id = :company_id";
            $params[':company_id'] = $manager_company_id;
        }

        if ($status !== 'all') {
            $query .= " AND us.verification_status = :status";
            $params[':status'] = $status;
        }

        $query .= " ORDER BY u.first_name ASC, s.skill_name ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($action === 'approve' || $action === 'reject') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'id is required']);
            exit;
        }

        // ensure the skill belongs to an employee in manager's company
        $checkQ = "
            SELECT us.user_skill_id
            FROM UserSkills us
            JOIN Users u ON us.user_id = u.user_id
            WHERE us.user_skill_id = :id
        ";
        $params = [':id' => $id];
        if ($manager_company_id) {
            $checkQ .= " AND u.company_id = :company_id";
            $params[':company_id'] = $manager_company_id;
        }
        $ch = $pdo->prepare($checkQ);
        $ch->execute($params);
        if (!$ch->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Not found or not authorized']);
            exit;
        }

        $newStatus = ($action === 'approve') ? 'Verified' : 'Rejected';
        $upd = $pdo->prepare("UPDATE UserSkills SET verification_status = :status, verified_by = :mid, verified_at = NOW() WHERE user_skill_id = :id");
        $upd->execute([':status' => $newStatus, ':mid' => $manager_id, ':id' => $id]);

        echo json_encode(['status' => 'success', 'message' => "Skill {$newStatus}"]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
