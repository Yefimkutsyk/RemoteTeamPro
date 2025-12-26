<?php
session_start();
header("Content-Type: application/json");
require_once "../../config/database.php";

$db = new Database();
$pdo = $db->getConnection();

$conversationId = $_GET['conversation_id'] ?? null;
$currentUserId = $_SESSION['user_id'] ?? null;

if (!$conversationId) {
    echo json_encode(["error" => "Missing conversation_id"]);
    exit;
}

try {
    $query = $pdo->prepare("
        SELECT 
            m.message_id,
            m.sender_id,
            m.recipient_id,
            m.message_content,
            m.sent_at,
            u.first_name,
            u.last_name
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.conversation_id = ?
        ORDER BY m.sent_at ASC
    ");
    $query->execute([$conversationId]);
    $messages = $query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as &$msg) {
        $msg['sender_name'] = trim($msg['first_name'] . ' ' . $msg['last_name']);
        $msg['is_own'] = ($currentUserId && $msg['sender_id'] == $currentUserId);
    }

    echo json_encode(["messages" => $messages]);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
