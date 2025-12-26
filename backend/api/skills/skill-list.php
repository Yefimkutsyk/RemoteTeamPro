<?php
// backend/api/skills/skill-list.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $stmt = $pdo->query("SELECT skill_id, skill_name FROM Skills ORDER BY skill_name ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
