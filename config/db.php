<?php
// ============================================
// MyStock v2.0 - Database Configuration
// ============================================

// Prevent caching of all pages
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Database settings
$host = 'localhost';
$dbname = 'mystock_v2';
$username = 'root';
$password = '';

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Session management
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// SESSION FUNCTIONS
// ============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isManager() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'manager';
}

function isWorker() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'worker';
}

function getCurrentBranch() {
    return $_SESSION['branch_id'] ?? null;
}

function getCurrentBranchName() {
    return $_SESSION['branch_name'] ?? 'No Branch Selected';
}

function hasBranchAccess($branch_id) {
    if (isAdmin()) return true;
    return isset($_SESSION['user_branches']) && in_array($branch_id, $_SESSION['user_branches']);
}

// ============================================
// BRANCH FUNCTIONS
// ============================================

function getUserBranches($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT b.*, ub.role 
        FROM branches b
        JOIN user_branches ub ON ub.branch_id = b.id
        WHERE ub.user_id = ? AND b.status = 'active'
        ORDER BY b.name
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function getBranchName($pdo, $branch_id) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    return $stmt->fetchColumn();
}

function switchBranch($branch_id) {
    $_SESSION['branch_id'] = $branch_id;
    $_SESSION['branch_name'] = getBranchName($GLOBALS['pdo'], $branch_id);
    return true;
}

// ============================================
// LOGGING FUNCTIONS
// ============================================

function logAction($pdo, $action, $details = '') {
    try {
        // Create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            branch_id INT,
            username VARCHAR(50),
            action VARCHAR(100),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, branch_id, username, action, details, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $_SESSION['branch_id'] ?? null,
                $_SESSION['username'] ?? 'unknown',
                $action,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    } catch(PDOException $e) {
        // Silent fail - don't break the app
        error_log("LogAction failed: " . $e->getMessage());
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function generateInvoiceNo($prefix = 'INV') {
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);
}

function formatCurrency($amount) {
    return number_format($amount, 0) . ' RWF';
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function getLowStockProducts($pdo, $branch_id = null) {
    $sql = "SELECT * FROM products WHERE quantity <= reorder_level";
    $params = [];
    
    if ($branch_id) {
        $sql .= " AND branch_id = ?";
        $params[] = $branch_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================
// WHATSAPP FUNCTIONS
// ============================================

function getWhatsAppLink($phone, $message) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $phone = ltrim($phone, '0');
    if (strlen($phone) == 9) {
        $phone = '250' . $phone;
    }
    return "https://wa.me/{$phone}?text=" . urlencode($message);
}

function generatePOMessage($po_number, $supplier_name, $items, $total) {
    $message = "📋 *PURCHASE ORDER* 📋\n\n";
    $message .= "PO #: " . $po_number . "\n";
    $message .= "Supplier: " . $supplier_name . "\n";
    $message .= "Date: " . date('Y-m-d') . "\n\n";
    $message .= "*Items Ordered:*\n";
    $message .= "------------------------\n";
    
    foreach ($items as $item) {
        $message .= "• " . $item['product_name'] . "\n";
        $message .= "  Qty: " . $item['quantity_ordered'] . "\n";
        $message .= "  Price: " . number_format($item['unit_price'], 0) . " RWF\n\n";
    }
    
    $message .= "------------------------\n";
    $message .= "*Total: " . number_format($total, 0) . " RWF*\n\n";
    $message .= "Please confirm receipt of this order.";
    
    return $message;
}

// ============================================
// REQUIRE FUNCTIONS
// ============================================

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function requireBranchAccess() {
    if (!getCurrentBranch()) {
        redirect('../index.php');
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        redirect('index.php');
    }
}
?>