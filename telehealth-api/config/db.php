<?php
// config/db.php
// Database connection using MySQLi
// Change DB_PASS if your XAMPP MySQL has a password (default is empty)

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');           // XAMPP default: no password
define('DB_NAME', 'telehealth_db');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }

    $conn->set_charset('utf8');
    return $conn;
}