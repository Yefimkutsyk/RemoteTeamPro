<?php

// The plain-text password to be hashed
$password = "12345678";

// Hash the password using the default algorithm (currently bcrypt)
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Output the hashed password (this would typically be stored in a database)
echo "Hashed Password: " . $hashedPassword;

?>