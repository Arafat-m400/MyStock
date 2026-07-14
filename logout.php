<?php
session_start();
require_once 'config/db.php';

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Log logout
if (isset($_SESSION['user_id'])) {
    logAction($pdo, 'Logout', "User logged out");
}

// Clear session variables
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Clear any other cookies
if (isset($_COOKIE['PHPSESSID'])) {
    unset($_COOKIE['PHPSESSID']);
}

// Redirect to login with cache prevention headers
header("Location: login.php");
exit();
?>