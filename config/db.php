<?php
$host = 'localhost';
$dbname = 'mystock_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isWorker() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'worker';
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function generateInvoiceNo($prefix = 'INV') {
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);

    function logAction($pdo, $action, $details = '') {
    if(isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SESSION['username'],
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'CLI'
        ]);
    }
}
}
?>