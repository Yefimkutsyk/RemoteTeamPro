<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../../config/database.php';
session_start();

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$participants = $input['participants'] ?? [];
$company_id = $input['company_id'] ?? null;
$title = $input['title'] ?? null;

if (count($participants) < 1 || !$company_id) {
    echo json_encode(["error" => "Missing participants or company_id"]);
    exit;
}

try {
    // ✅ Check for existing 1:1 conversation
    if (count($participants) === 1) {
        $other = $participants[0];
        $stmt = $conn->prepare("
            SELECT c.conversation_id
            FROM conversations c
            JOIN conversation_participants p1 ON c.conversation_id = p1.conversation_id
            JOIN conversation_participants p2 ON c.conversation_id = p2.conversation_id
            WHERE c.is_group = 0 AND p1.user_id = :u1 AND p2.user_id = :u2
        ");
        $stmt->execute([':u1' => $user_id, ':u2' => $other]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["conversation_id" => $row['conversation_id']]);
            exit;
        }
    }

    // ✅ Create conversation
    $stmt = $conn->prepare("INSERT INTO conversations (title, is_group, company_id) VALUES (:title, :is_group, :company_id)");
    $stmt->execute([
        ':title' => $title ?: 'Chat',
        ':is_group' => count($participants) > 1 ? 1 : 0,
        ':company_id' => $company_id
    ]);
    $conversation_id = $conn->lastInsertId();

    // ✅ Add creator and participants
    $all_participants = array_unique(array_merge([$user_id], $participants));
    $stmt = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (:cid, :uid)");
    foreach ($all_participants as $uid) {
        $stmt->execute([':cid' => $conversation_id, ':uid' => $uid]);
    }

    echo json_encode(["success" => true, "conversation_id" => $conversation_id]);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
