<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
session_start();

if (empty($_SESSION['user_id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = $_GET['conversation_id'] ?? null;

if (!$conversation_id) {
    echo json_encode(["error" => "Missing conversation_id"]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // ✅ Use correct table: messages
    $stmt = $conn->prepare("
        SELECT message_id 
        FROM messages 
        WHERE conversation_id = :cid
    ");
    $stmt->execute([':cid' => $conversation_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$messages) {
        echo json_encode(["success" => false, "debug" => "No messages found"]);
        exit;
    }

    // ✅ Insert or update read entries in messagereads
    $insertStmt = $conn->prepare("
        INSERT INTO messagereads (message_id, user_id, read_at)
        VALUES (:mid, :uid, NOW())
        ON DUPLICATE KEY UPDATE read_at = NOW()
    ");

    $count = 0;
    foreach ($messages as $mid) {
        $insertStmt->execute([':mid' => $mid, ':uid' => $user_id]);
        $count++;
    }

    echo json_encode(["success" => true, "updated" => $count, "messages" => $messages]);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
