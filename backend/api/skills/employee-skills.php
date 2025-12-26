<?php
// backend/api/skills/employee-skills.php
header('Content-Type: application/json; charset=UTF-8');
session_start();
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    // logged-in user id (session). allow ?user_id= for testing
    $user_id = $_SESSION['user_id'] ?? (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized or user_id missing']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT us.user_skill_id, us.user_id, s.skill_id, s.skill_name, us.proficiency_level, us.verification_status, us.verified_at
            FROM UserSkills us
            JOIN Skills s ON us.skill_id = s.skill_id
            WHERE us.user_id = :uid
            ORDER BY s.skill_name ASC
        ");
        $stmt->execute([':uid' => $user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $rows]);
        exit;
    }

    if ($action === 'add' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $skill_id = isset($input['skill_id']) ? (int)$input['skill_id'] : 0;
        $level = trim($input['proficiency_level'] ?? '');

        if (!$skill_id || $level === '') {
            echo json_encode(['status' => 'error', 'message' => 'skill_id and proficiency_level are required']);
            exit;
        }

        // ensure skill exists
        $checkSkill = $pdo->prepare("SELECT skill_id FROM Skills WHERE skill_id = :sid");
        $checkSkill->execute([':sid' => $skill_id]);
        if (!$checkSkill->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Skill not found']);
            exit;
        }

        // check duplicate
        $check = $pdo->prepare("SELECT user_skill_id FROM UserSkills WHERE user_id = :uid AND skill_id = :sid");
        $check->execute([':uid' => $user_id, ':sid' => $skill_id]);
        if ($check->fetch()) {
            // update level and reset verification to Pending
            $upd = $pdo->prepare("UPDATE UserSkills SET proficiency_level = :lvl, verification_status = 'Pending', verified_by = NULL, verified_at = NULL WHERE user_id = :uid AND skill_id = :sid");
            $upd->execute([':lvl' => $level, ':uid' => $user_id, ':sid' => $skill_id]);
            echo json_encode(['status' => 'success', 'message' => 'Skill updated and set to Pending']);
            exit;
        }

        $ins = $pdo->prepare("INSERT INTO UserSkills (user_id, skill_id, proficiency_level, verification_status) VALUES (:uid, :sid, :lvl, 'Pending')");
        $ins->execute([':uid' => $user_id, ':sid' => $skill_id, ':lvl' => $level]);
        echo json_encode(['status' => 'success', 'message' => 'Skill added and pending verification']);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'id is required']);
            exit;
        }

        // ensure ownership
        $check = $pdo->prepare("SELECT user_skill_id FROM UserSkills WHERE user_skill_id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $user_id]);
        if (!$check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Skill not found or unauthorized']);
            exit;
        }

        $del = $pdo->prepare("DELETE FROM UserSkills WHERE user_skill_id = :id");
        $del->execute([':id' => $id]);
        echo json_encode(['status' => 'success', 'message' => 'Skill removed']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
