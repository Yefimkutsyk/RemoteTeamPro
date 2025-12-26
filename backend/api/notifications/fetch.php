<?php
require_once '../../config/database.php';
session_start();

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT notification_id, title, body, link, is_read, created_at
        FROM Notifications
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    error_log('Fetch notifications failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
