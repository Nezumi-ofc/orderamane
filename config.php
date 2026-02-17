<?php
// Database Configuration
define('DB_SERVER', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'orderamane_db');

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Koneksi database gagal: ' . $conn->connect_error]));
}

$conn->set_charset("utf8");

// Session start
session_start();

// Security
define('SECRET_KEY', 'your_secret_key_here_change_this');

// Pagination
define('ITEMS_PER_PAGE', 10);

// Upload settings
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

// Time zone
date_default_timezone_set('Asia/Jakarta');
?>