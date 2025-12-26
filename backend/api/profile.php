<?php

session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($action === 'current') {
    $stmt = $pdo->prepare("SELECT user_id, company_id, first_name, last_name, email, profile_picture_url, contact_number FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $first_name = '';
    $last_name = '';
    $contact_number = '';
    $profile_picture_url = null;
    $email = null;
    if ($data) {
        $first_name = trim($data['first_name'] ?? '');
        $last_name = trim($data['last_name'] ?? '');
        $contact_number = trim($data['contact_number'] ?? '');
        $profile_picture_url = trim($data['profile_picture_url'] ?? '');
        $email = trim($data['email'] ?? '');
    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $profile_picture_url = trim($_POST['profile_picture_url'] ?? '');
        $email = trim($_POST['email'] ?? '');
    }

    // Email validation (if provided)
    if ($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(["error" => "Invalid email address"]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            echo json_encode(["error" => "Email already in use"]);
            exit;
        }
    }

    $sql = "UPDATE Users SET first_name = ?, last_name = ?, contact_number = ?, profile_picture_url = COALESCE(?, profile_picture_url), updated_at = NOW()" . ($email ? ", email = ?" : "") . " WHERE user_id = ?";
    $params = [$first_name, $last_name, $contact_number, $profile_picture_url];
    if ($email) $params[] = $email;
    $params[] = $userId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(["message" => "Profile updated successfully"]);
    exit;
}

echo json_encode(["error" => "Invalid action"]);
