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
        UPDATE Notifications
        SET is_read = 1
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Mark notifications read failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
