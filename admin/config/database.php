<?php
// Database configuration
define('DB_HOST', 'panel.alphasoft.world');  // Your Plesk server's IP or domain name
define('DB_USER', 'admin_alphanews');         // Your MySQL username
define('DB_PASS', 'Niloynafis234@');         // Your MySQL password
define('DB_NAME', 'admin_alphanews');         // Database name

// Create connection
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
