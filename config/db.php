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
// PATH HELPER - Detect current directory
// ============================================

function getBasePath() {
    // Get the current script path relative to document root
    $script_path = $_SERVER['PHP_SELF'];
    
    // Check if we're in a subfolder
    if (strpos($script_path, '/admin/') !== false) {
        return '../';
    } elseif (strpos($script_path, '/branch/') !== false) {
        return '../';
    } else {
        // In root directory
        return '';
    }
}

// ============================================
// SESSION FUNCTIONS
// ============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
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
    // Admin gets ALL branches (for management view)
    $stmt = $pdo->prepare("
        SELECT b.*, ub.role 
        FROM branches b
        JOIN user_branches ub ON ub.branch_id = b.id
        WHERE ub.user_id = ?
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
// GET COMPANY NAME
// ============================================

function getCompanyName($pdo) {
    $stmt = $pdo->prepare("SELECT company_name FROM settings WHERE id = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['company_name'] ?? 'MyStock';
}

// ============================================
// PRODUCT DETAILS FUNCTION (Updated for deleted products)
// ============================================

/**
 * Get product details including sales, purchases, and stock history
 * Handles deleted products (product_id = NULL) gracefully
 */
function getProductDetails($pdo, $product_id, $branch_id) {
    // Sales history
$sales = $pdo->prepare("
    SELECT 
        s.id,
        s.invoice_no,
        s.sale_date,
        si.quantity,
        si.selling_price,
        si.subtotal,
        COALESCE(c.name, 'Deleted Product') as customer_name
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE si.product_id = ? AND s.branch_id = ?
    ORDER BY s.sale_date DESC
    LIMIT 20
");
    $sales->execute([$product_id, $branch_id]);
    $sales_data = $sales->fetchAll();
    
    // Purchase history
    $purchases = $pdo->prepare("
        SELECT 
            pi.purchase_id,
            pi.quantity,
            pi.unit_price,
            pi.subtotal,
            p.purchase_date,
            p.invoice_no,
            COALESCE(s.name, 'Deleted Supplier') as supplier_name
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE pi.product_id = ? AND p.branch_id = ?
        ORDER BY p.purchase_date DESC
        LIMIT 20
    ");
    $purchases->execute([$product_id, $branch_id]);
    $purchase_data = $purchases->fetchAll();
    
    // Stock history (from purchases only)
    $stock_history = $pdo->prepare("
        SELECT 
            p.purchase_date as date,
            CASE 
                WHEN p.invoice_no LIKE 'PO-%' THEN 'Advance PO'
                ELSE 'Regular Purchase'
            END as type,
            COALESCE(s.name, 'Deleted Supplier') as supplier_name,
            pi.quantity as quantity_change,
            pi.unit_price,
            p.invoice_no,
            'in' as direction
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE pi.product_id = ? AND p.branch_id = ?
        ORDER BY date DESC
        LIMIT 50
    ");
    $stock_history->execute([$product_id, $branch_id]);
    $stock_history_data = $stock_history->fetchAll();
    
    // Profit/Loss summary
    $summary = $pdo->prepare("
        SELECT 
            COALESCE(SUM(si.quantity), 0) as total_sold,
            COALESCE(SUM(si.subtotal), 0) as total_revenue,
            COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as total_cost,
            COALESCE(SUM(si.subtotal - (si.quantity * si.cost_price_at_sale)), 0) as total_profit
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE si.product_id = ? AND s.branch_id = ?
    ");
    $summary->execute([$product_id, $branch_id]);
    $summary_data = $summary->fetch();
    
    return [
        'sales' => $sales_data,
        'purchases' => $purchase_data,
        'stock_history' => $stock_history_data,
        'summary' => $summary_data
    ];
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