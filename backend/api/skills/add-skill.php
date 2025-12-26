<?php
// backend/api/skills/add-skill.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $skill_name = trim($input['skill_name'] ?? '');

    if ($skill_name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Skill name is required']);
        exit;
    }

    // check existing (case-insensitive)
    $check = $pdo->prepare("SELECT skill_id FROM Skills WHERE LOWER(skill_name) = LOWER(?)");
    $check->execute([$skill_name]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode(['status' => 'success', 'skill_id' => (int)$existing['skill_id'], 'message' => 'Skill already exists']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO Skills (skill_name) VALUES (?)");
    $stmt->execute([$skill_name]);
    $newId = (int)$pdo->lastInsertId();

    echo json_encode(['status' => 'success', 'skill_id' => $newId, 'message' => 'Skill added successfully']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
