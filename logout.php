<?php
session_start();
require_once 'config/db.php';

// Log logout
if (isset($_SESSION['user_id'])) {
    logAction($pdo, 'Logout', "User logged out");
}

// Destroy session
$_SESSION = array();
session_destroy();

// Redirect to login
header("Location: login.php");
exit();
?>