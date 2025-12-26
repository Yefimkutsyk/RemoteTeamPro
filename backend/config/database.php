<?php
// backend/config/database.php

/**
 * Database Connection Configuration
 *
 * This file defines the Database class to manage the MySQL database connection
 * using PDO (PHP Data Objects) for a secure and efficient connection.
 */

// Define database credentials if they aren't already defined
// This is typical for local XAMPP setups. For production, use environment variables.
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'remoteteampro');

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    // Constructor to initialize properties from constants
    public function __construct() {
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }

    /**
     * Get the database connection.
     *
     * @return PDO A PDO database connection object.
     * @throws PDOException If the connection fails.
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Disable emulation for better security and performance
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $exception) {
            // Log the error for debugging purposes
            error_log("Database connection failed: " . $exception->getMessage());
            // In a production environment, you would show a generic error page to the user
            // For development, you can display the error for debugging
            die("Database connection failed: " . $exception->getMessage());
        }
        return $this->conn;
    }
}
?>