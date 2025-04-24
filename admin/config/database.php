<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'admin_alphanews'); // Change to your MySQL username
define('DB_PASS', 'Niloynafis234@'); // Change to your MySQL password
define('DB_NAME', 'admin_alphanews'); // Database name

// Create connection
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}