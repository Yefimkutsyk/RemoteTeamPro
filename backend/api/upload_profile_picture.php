<?php
// upload_profile_picture.php
// Handles profile picture upload and returns the image URL
header('Content-Type: application/json');

$targetDir = $_SERVER['DOCUMENT_ROOT'] . '/RemoteTeamPro/uploads/profile_pictures/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (!isset($_FILES['avatar'])) {
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['avatar'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type.']);
    exit;
}

$filename = 'user_' . time() . '_' . rand(1000,9999) . '.' . $ext;
$targetFile = $targetDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetFile)) {
    $url = '/RemoteTeamPro/uploads/profile_pictures/' . $filename;
    echo json_encode(['status' => 'success', 'url' => $url]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file.']);
}
