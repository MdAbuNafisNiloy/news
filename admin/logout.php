<?php
// Start session for user authentication
session_start();

// Include necessary files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Process logout
$result = processLogout();

// Redirect to login page
header("Location: login.php?logout=success");
exit();
?>