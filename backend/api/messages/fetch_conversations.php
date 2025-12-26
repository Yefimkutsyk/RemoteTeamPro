<?php
session_start();
header("Content-Type: application/json");
require_once "../../config/database.php";

$db = new Database();
$pdo = $db->getConnection();

$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            c.conversation_id,
            c.title,
            u.user_id,
            CONCAT(u.first_name, ' ', u.last_name) AS name,
            u.role
        FROM conversations c
        JOIN conversation_participants cp1 ON c.conversation_id = cp1.conversation_id
        JOIN conversation_participants cp2 ON c.conversation_id = cp2.conversation_id
        JOIN users u ON cp2.user_id = u.user_id
        WHERE cp1.user_id = ? AND cp2.user_id != ?
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute([$currentUserId, $currentUserId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($conversations ?: []);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
