<?php
session_start();
header("Content-Type: application/json");
require_once "../../config/database.php";

$db = new Database();
$pdo = $db->getConnection();

$input = json_decode(file_get_contents("php://input"), true);

$conversation_id = $input['conversation_id'] ?? null;
$message = trim($input['message'] ?? '');
$sender_id = $_SESSION['user_id'] ?? null;
$sender_role = $_SESSION['role'] ?? 'Client'; // Role from session

if (!$conversation_id || !$message || !$sender_id) {
    echo json_encode(["error" => "Missing conversation_id or message"]);
    exit;
}

try {
    // 1️⃣ Find recipient (the other participant in this conversation)
    $stmt = $pdo->prepare("
        SELECT user_id FROM conversation_participants
        WHERE conversation_id = ? AND user_id != ?
        LIMIT 1
    ");
    $stmt->execute([$conversation_id, $sender_id]);
    $recipient_id = $stmt->fetchColumn();

    if (!$recipient_id) {
        echo json_encode(["error" => "No recipient found"]);
        exit;
    }

    // 2️⃣ Insert message
    $insert = $pdo->prepare("
        INSERT INTO messages (sender_id, recipient_id, message_content, conversation_id, sent_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $insert->execute([$sender_id, $recipient_id, $message, $conversation_id]);

    // 3️⃣ Get sender's name
    $nameQuery = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $nameQuery->execute([$sender_id]);
    $sender = $nameQuery->fetch(PDO::FETCH_ASSOC);
    $sender_name = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? ''));
    if ($sender_name === '') $sender_name = 'Someone';

    // 4️⃣ Create notification (no link redirection)
    createNotification(
        $pdo,
        $recipient_id,
        "📩 New Message",
        "You’ve received a new message from {$sender_name} ({$sender_role})."
    );

    echo json_encode([
        "success" => true,
        "sent_by" => $sender_name,
        "conversation_id" => $conversation_id
    ]);
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}

// ✅ Helper
function createNotification($db, $userId, $title, $body) {
    $stmt = $db->prepare("
        INSERT INTO Notifications (user_id, title, body, is_read)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([$userId, $title, $body]);
}
?>
