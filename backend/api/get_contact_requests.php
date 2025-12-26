<?php
header('Content-Type: application/json');

// Database connection
$conn = new mysqli("localhost", "root", "", "remoteteampro");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'contact_messages'");
if ($tableCheck->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Table contact_messages does not exist']);
    $conn->close();
    exit;
}

// Get all contact messages
$query = "SELECT * FROM contact_messages ORDER BY created_at DESC";
$result = $conn->query($query);

if ($result) {
    $contact_requests = array();
    while ($row = $result->fetch_assoc()) {
        $contact_requests[] = array(
            'id' => $row['id'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'email' => $row['company_email'],
            'topic' => $row['topic'],
            'message' => $row['message'],
            'date' => $row['created_at']
        );
    }
    
    // Log the response for debugging
    error_log("Sending response with " . count($contact_requests) . " contact requests");
    
    echo json_encode([
        'success' => true, 
        'data' => $contact_requests,
        'count' => count($contact_requests)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error fetching contact requests']);
}

$conn->close();