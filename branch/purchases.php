<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';

// ============================================
// GET DATA
// ============================================

// All products (for dropdown)
$products = $pdo->prepare("
    SELECT id, name, sku, quantity, cost_price, unit 
    FROM products 
    WHERE branch_id = ? 
    ORDER BY name
");
$products->execute([$branch_id]);
$products = $products->fetchAll();

// All suppliers (for dropdown)
$suppliers = $pdo->prepare("
    SELECT id, name, phone 
    FROM suppliers 
    WHERE branch_id = ? 
    ORDER BY name
");
$suppliers->execute([$branch_id]);
$suppliers = $suppliers->fetchAll();

// Purchase history (last 20)
$purchases = $pdo->prepare("
    SELECT p.*, 
           s.name as supplier_name,
           u.full_name as created_by_name
    FROM purchases p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.branch_id = ?
    ORDER BY p.created_at DESC
    LIMIT 20
");
$purchases->execute([$branch_id]);
$purchases = $purchases->fetchAll();

// ============================================
// PROCESS PURCHASE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_stock'])) {
    try {
        $pdo->beginTransaction();
        
        $product_id = $_POST['product_id'];
        $supplier_id = $_POST['supplier_id'] ?: null;
        $quantity = intval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $invoice_no = $_POST['invoice_no'] ?: 'PO-' . date('Ymd') . '-' . rand(100, 999);
        
        if ($quantity <= 0) {
            throw new Exception("Quantity must be greater than 0.");
        }
        if ($unit_price < 0) {
            throw new Exception("Unit price cannot be negative.");
        }
        
        // Get current product details
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
        $stmt->execute([$product_id, $branch_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception("Product not found.");
        }
        
        // Calculate new cost price (weighted average)
        $current_qty = $product['quantity'];
        $current_cost = $product['cost_price'];
        $new_qty = $current_qty + $quantity;
        $new_cost = (($current_qty * $current_cost) + ($quantity * $unit_price)) / $new_qty;
        
        // Insert purchase record
        $stmt = $pdo->prepare("
            INSERT INTO purchases (
                branch_id, supplier_id, invoice_no, purchase_date, 
                total_amount, created_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $total_amount = $quantity * $unit_price;
        $stmt->execute([$branch_id, $supplier_id, $invoice_no, $purchase_date, $total_amount, $_SESSION['user_id']]);
        $purchase_id = $pdo->lastInsertId();
        
        // Insert purchase items
        $stmt = $pdo->prepare("
            INSERT INTO purchase_items (
                purchase_id, product_id, quantity, unit_price, subtotal
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$purchase_id, $product_id, $quantity, $unit_price, $total_amount]);
        
        // Update product stock and cost
        $stmt = $pdo->prepare("
            UPDATE products 
            SET quantity = ?, 
                cost_price = ?,
                last_purchase_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_qty, round($new_cost, 2), $purchase_date, $product_id]);
        
        $pdo->commit();
        
        logAction($pdo, 'Stock In', "Added $quantity of {$product['name']} (Branch: $branch_id)");
        
        $message = '<div class="alert alert-success alert-permanent">
            <i class="fas fa-check-circle me-2"></i>
            <strong>✅ Stock added successfully!</strong>
            <br>' . $quantity . ' ' . $product['unit'] . ' of "' . htmlspecialchars($product['name']) . '" added.
            <br>New stock: ' . $new_qty . ' | New cost: ' . number_format($new_cost, 0) . ' RWF
        </div>';
        
        // Refresh data
        $products = $pdo->prepare("SELECT id, name, sku, quantity, cost_price, unit FROM products WHERE branch_id = ? ORDER BY name");
        $products->execute([$branch_id]);
        $products = $products->fetchAll();
        
        $purchases = $pdo->prepare("
            SELECT p.*, s.name as supplier_name, u.full_name as created_by_name
            FROM purchases p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.branch_id = ?
            ORDER BY p.created_at DESC
            LIMIT 20
        ");
        $purchases->execute([$branch_id]);
        $purchases = $purchases->fetchAll();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-truck me-2 text-primary"></i>Stock In / Purchases</h2>
        <p class="text-muted">
            Add stock to products and track purchase history
        </p>
    </div>
    <div>
        <span class="badge bg-primary fs-6">
            <?php echo date('l, F j, Y'); ?>
        </span>
    </div>
</div>

<?php echo $message; ?>

<!-- ============================================
ADD STOCK FORM
============================================ -->
<div class="row">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Stock</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Product *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Select Product --</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> 
                                (Stock: <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" required min="1" placeholder="e.g., 10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit Price (RWF) *</label>
                            <input type="number" name="unit_price" class="form-control" required min="0" step="100" placeholder="e.g., 8500">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="">-- No Supplier --</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice / Reference</label>
                            <input type="text" name="invoice_no" class="form-control" placeholder="Optional invoice number">
                        </div>
                    </div>
                    
                    <button type="submit" name="add_stock" class="btn btn-success w-100">
                        <i class="fas fa-save me-2"></i> Add Stock
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="col-lg-6 mt-3 mt-lg-0">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Stock Summary</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6 mb-3">
                        <small class="text-muted">Total Products</small>
                        <h4 class="text-primary">
                            <?php 
                            $total = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ?");
                            $total->execute([$branch_id]);
                            echo $total->fetchColumn();
                            ?>
                        </h4>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted">Total Stock Value</small>
                        <h4 class="text-success">
                            <?php 
                            $value = $pdo->prepare("SELECT COALESCE(SUM(quantity * cost_price), 0) FROM products WHERE branch_id = ?");
                            $value->execute([$branch_id]);
                            echo number_format($value->fetchColumn(), 0);
                            ?>
                        </h4>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Low Stock Items</small>
                        <h4 class="text-warning">
                            <?php 
                            $low = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ? AND quantity <= reorder_level");
                            $low->execute([$branch_id]);
                            echo $low->fetchColumn();
                            ?>
                        </h4>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Total Purchases</small>
                        <h4 class="text-info">
                            <?php 
                            $total_p = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE branch_id = ?");
                            $total_p->execute([$branch_id]);
                            echo $total_p->fetchColumn();
                            ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
PURCHASE HISTORY
============================================ -->
<div class="card shadow-sm mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Purchases</h5>
        <span class="badge bg-secondary">Last 20 entries</span>
    </div>
    <div class="card-body p-0">
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Added By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($purchases)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">
                            No purchases recorded yet. Add stock to get started.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    // We need to get items for each purchase to show quantity
                    foreach($purchases as $purchase): 
                        $items = $pdo->prepare("
                            SELECT pi.*, pr.name as product_name 
                            FROM purchase_items pi
                            JOIN products pr ON pi.product_id = pr.id
                            WHERE pi.purchase_id = ?
                        ");
                        $items->execute([$purchase['id']]);
                        $items = $items->fetchAll();
                        $total_qty = array_sum(array_column($items, 'quantity'));
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($purchase['invoice_no']); ?></strong>
                            <br><small class="text-muted"><?php echo count($items); ?> item(s)</small>
                        </td>
                        <td><?php echo $purchase['purchase_date']; ?></td>
                        <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? '—'); ?></td>
                        <td><?php echo number_format($total_qty); ?></td>
                        <td><strong><?php echo number_format($purchase['total_amount'], 0); ?></strong></td>
                        <td><?php echo htmlspecialchars($purchase['created_by_name'] ?? 'System'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<?php include '../includes/footer.php'; ?>