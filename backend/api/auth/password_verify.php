<?php
// Database connection details
$host = 'localhost';
$dbname = 'remoteteampro';
$user = 'root'; // Your database username
$pass = '';     // Your database password

// Create a new PDO instance
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Read the raw POST data from the request body
$json_data = file_get_contents('php://input');

// Decode the JSON data into a PHP object or array
$data = json_decode($json_data);

// Check if data was received and is valid
if (!$data || !isset($data->email) || !isset($data->password)) {
    // Send a JSON response for consistency
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
    exit;
}

// Get user input from the decoded JSON
$email_input = $data->email;
$password_input = $data->password;

// Query the database to get the stored user data
$stmt = $pdo->prepare("SELECT user_id, password_hash FROM Users WHERE email = ?");
$stmt->execute([$email_input]);
$user = $stmt->fetch();

// Check if a user was found
if ($user) {
    // Use password_verify() to compare the user's password with the stored hash
    if (password_verify($password_input, $user['password_hash'])) {
        // Passwords match!
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Login successful!']);
    } else {
        // Passwords do not match.
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid password.']);
    }
} else {
    // No user found with that email.
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid email.']);
}
?>