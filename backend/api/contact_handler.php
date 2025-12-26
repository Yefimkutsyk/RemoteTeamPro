<?php
header('Content-Type: application/json');

// Database connection
$conn = new mysqli("localhost", "root", "", "remoteteampro");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if POST data exists
if (!isset($_POST['first_name'])) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit;
}

// Sanitize and assign POST data
$first_name = $conn->real_escape_string($_POST['first_name']);
$last_name = $conn->real_escape_string($_POST['last_name']);
$company_email = $conn->real_escape_string($_POST['company_email']);
$topic = $conn->real_escape_string($_POST['topic']);
$message = $conn->real_escape_string($_POST['message']);

// Prepare and execute insert query
$stmt = $conn->prepare("INSERT INTO contact_MESSAGES (first_name, last_name, company_email, topic, message) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $first_name, $last_name, $company_email, $topic, $message);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Your message has been submitted successfully!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to submit your message.']);
}

$stmt->close();
$conn->close();
