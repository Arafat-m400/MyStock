<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';
$is_admin = isAdmin();

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

// Add/Edit Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_product'])) {
    $id = $_POST['id'] ?? 0;
    $name = sanitize($_POST['name']);
    $sku = sanitize($_POST['sku']);
    $category_id = $_POST['category_id'] ?: null;
    $quantity = $_POST['quantity'] ?? 0;
    $reorder_level = $_POST['reorder_level'] ?? 5;
    $cost_price = $_POST['cost_price'] ?? 0;
    $selling_price = $_POST['selling_price'] ?? 0;
    $unit = sanitize($_POST['unit'] ?? 'pcs');
    $description = sanitize($_POST['description']);
    
    try {
        if ($id > 0) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE products 
                SET name=?, sku=?, category_id=?, quantity=?, reorder_level=?, 
                    cost_price=?, selling_price=?, unit=?, description=? 
                WHERE id=? AND branch_id=?
            ");
            $stmt->execute([$name, $sku, $category_id, $quantity, $reorder_level, 
                           $cost_price, $selling_price, $unit, $description, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Product updated successfully!</div>';
            logAction($pdo, 'Update Product', "Updated product: $name (Branch: $branch_id)");
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO products (branch_id, name, sku, category_id, quantity, reorder_level, 
                                      cost_price, selling_price, unit, description) 
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$branch_id, $name, $sku, $category_id, $quantity, $reorder_level, 
                           $cost_price, $selling_price, $unit, $description]);
            $message = '<div class="alert alert-success">✅ Product added successfully!</div>';
            logAction($pdo, 'Add Product', "Added product: $name (Branch: $branch_id)");
        }
    } catch(PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Product
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $branch_id]);
    $message = '<div class="alert alert-success">✅ Product deleted!</div>';
    logAction($pdo, 'Delete Product', "Deleted product ID: $id (Branch: $branch_id)");
}

// ============================================
// GET DATA
// ============================================

// Products with category names
$products = $pdo->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.branch_id = ?
    ORDER BY p.name
");
$products->execute([$branch_id]);
$products = $products->fetchAll();

// Categories for dropdown
$categories = $pdo->prepare("SELECT * FROM categories WHERE branch_id = ? ORDER BY name");
$categories->execute([$branch_id]);
$categories = $categories->fetchAll();

// Get product details for modal
$detail_product = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ? AND p.branch_id = ?
    ");
    $stmt->execute([$_GET['view'], $branch_id]);
    $detail_product = $stmt->fetch();
}

// Get edit product data
$edit_product = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit'], $branch_id]);
    $edit_product = $stmt->fetch();
}

// ============================================
// PRODUCT DETAILS DATA
// ============================================

function getProductDetails($pdo, $product_id, $branch_id) {
    // Sales history
    $sales = $pdo->prepare("
        SELECT 
            s.invoice_no,
            s.sale_date,
            si.quantity,
            si.selling_price,
            si.subtotal,
            c.name as customer_name
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
            s.name as supplier_name
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE pi.product_id = ? AND p.branch_id = ?
        ORDER BY p.purchase_date DESC
        LIMIT 20
    ");
    $purchases->execute([$product_id, $branch_id]);
    $purchase_data = $purchases->fetchAll();
    
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
        'summary' => $summary_data
    ];
}

// Get product details if viewing
$product_details = null;
if ($detail_product) {
    $product_details = getProductDetails($pdo, $detail_product['id'], $branch_id);
}

include '../includes/header.php';
?>

<!-- ============================================
PRODUCTS PAGE
============================================ -->

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-box me-2 text-primary"></i>Products</h2>
        <p class="text-muted">
            Manage your products and monitor stock levels
        </p>
    </div>
    <?php if($is_admin): ?>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Product
        </a>
    </div>
    <?php endif; ?>
</div>

<?php echo $message; ?>

<!-- ============================================
FILTERS & SEARCH
============================================ -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" id="searchInput" class="form-control" placeholder="🔍 Search products..." onkeyup="filterProducts()">
            </div>
            <div class="col-md-3">
                <label class="form-label">Filter by Category</label>
                <select id="categoryFilter" class="form-select" onchange="filterProducts()">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['name']); ?>">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Stock Status</label>
                <select id="stockFilter" class="form-select" onchange="filterProducts()">
                    <option value="">All Products</option>
                    <option value="low">⚠️ Low Stock</option>
                    <option value="out">🚫 Out of Stock</option>
                    <option value="normal">✅ In Stock</option>
                </select>
            </div>
            <div class="col-md-3 text-end">
                <span class="badge bg-primary fs-6">
                    <?php echo count($products); ?> Products
                </span>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
PRODUCT GRID
============================================ -->
<div class="row g-3" id="productGrid">
    <?php foreach($products as $product): 
        $stock_status = 'normal';
        $status_class = 'success';
        $status_icon = 'fa-check-circle';
        $status_text = 'In Stock';
        
        if ($product['quantity'] <= 0) {
            $stock_status = 'out';
            $status_class = 'danger';
            $status_icon = 'fa-times-circle';
            $status_text = 'Out of Stock';
        } elseif ($product['quantity'] <= $product['reorder_level']) {
            $stock_status = 'low';
            $status_class = 'warning';
            $status_icon = 'fa-exclamation-triangle';
            $status_text = 'Low Stock';
        }
    ?>
    <div class="col-md-4 col-lg-3 product-item" 
         data-name="<?php echo strtolower(htmlspecialchars($product['name'])); ?>"
         data-category="<?php echo strtolower(htmlspecialchars($product['category_name'] ?? '')); ?>"
         data-stock="<?php echo $stock_status; ?>">
        <a href="?view=<?php echo $product['id']; ?>" class="text-decoration-none">
            <div class="card product-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="product-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <span class="badge bg-<?php echo $status_class; ?>">
                            <i class="fas <?php echo $status_icon; ?>"></i>
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                    
                    <h6 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h6>
                    
                    <?php if($product['category_name']): ?>
                    <p class="card-text small text-muted">
                        <i class="fas fa-tag me-1"></i>
                        <?php echo htmlspecialchars($product['category_name']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <div class="row mt-2">
                        <div class="col-6">
                            <small class="text-muted d-block">Stock</small>
                            <strong class="text-<?php echo $status_class; ?>">
                                <?php echo number_format($product['quantity']); ?>
                                <?php echo htmlspecialchars($product['unit']); ?>
                            </strong>
                        </div>
                        <div class="col-6 text-end">
                            <small class="text-muted d-block">Price</small>
                            <strong><?php echo number_format($product['selling_price'], 0); ?></strong>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <div class="progress" style="height: 4px;">
                            <?php 
                            $max_stock = max($product['quantity'], $product['reorder_level'], 1);
                            $percent = ($product['quantity'] / $max_stock) * 100;
                            $bar_class = 'bg-success';
                            if ($percent < 30) $bar_class = 'bg-danger';
                            elseif ($percent < 60) $bar_class = 'bg-warning';
                            ?>
                            <div class="progress-bar <?php echo $bar_class; ?>" 
                                 style="width: <?php echo min($percent, 100); ?>%"></div>
                        </div>
                        <small class="text-muted">
                            Reorder at <?php echo $product['reorder_level']; ?> <?php echo $product['unit']; ?>
                        </small>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
    
    <?php if(empty($products)): ?>
    <div class="col-12 text-center py-5">
        <i class="fas fa-box-open fa-4x text-muted mb-3 d-block"></i>
        <h5>No Products Found</h5>
        <p class="text-muted">Start adding products to your inventory.</p>
        <?php if($is_admin): ?>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add First Product
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================
ADD/EDIT PRODUCT MODAL
============================================ -->
<?php if($is_admin): ?>
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-<?php echo $edit_product ? 'edit' : 'plus'; ?> me-2"></i>
                    <?php echo $edit_product ? 'Edit Product' : 'Add Product'; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $edit_product['id'] ?? 0; ?>">
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-2">
                                <label class="form-label">Product Name *</label>
                                <input type="text" name="name" class="form-control" required
                                       value="<?php echo htmlspecialchars($edit_product['name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label">SKU</label>
                                <input type="text" name="sku" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_product['sku'] ?? ''); ?>">
                                <small class="text-muted">Unique identifier</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="">-- No Category --</option>
                                    <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"
                                        <?php echo ($edit_product['category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Unit</label>
                                <input type="text" name="unit" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_product['unit'] ?? 'pcs'); ?>"
                                       placeholder="pcs, kg, boxes...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="0"
                                       value="<?php echo $edit_product['quantity'] ?? 0; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" class="form-control" min="0"
                                       value="<?php echo $edit_product['reorder_level'] ?? 5; ?>">
                                <small class="text-muted">Alert when stock ≤ this</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo ($edit_product['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($edit_product['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Cost Price (RWF)</label>
                                <input type="number" step="0.01" name="cost_price" class="form-control"
                                       value="<?php echo $edit_product['cost_price'] ?? 0; ?>" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Selling Price (RWF)</label>
                                <input type="number" step="0.01" name="selling_price" class="form-control"
                                       value="<?php echo $edit_product['selling_price'] ?? 0; ?>" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Product description (optional)"><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
PRODUCT DETAILS MODAL
============================================ -->
<?php if($detail_product && $product_details): ?>
<div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-box me-2"></i>
                    <?php echo htmlspecialchars($detail_product['name']); ?>
                    <span class="badge bg-light text-dark ms-2">
                        <?php echo htmlspecialchars($detail_product['sku'] ?: 'No SKU'); ?>
                    </span>
                </h5>
                <div>
                    <?php if($is_admin): ?>
                    <a href="?edit=<?php echo $detail_product['id']; ?>" class="btn btn-sm btn-light me-2">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php endif; ?>
                    <a href="products.php" class="btn-close btn-close-white"></a>
                </div>
            </div>
            <div class="modal-body">
                <!-- Product Summary -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <p class="stat-label">Current Stock</p>
                            <h3 class="text-<?php echo $detail_product['quantity'] <= $detail_product['reorder_level'] ? 'danger' : 'success'; ?>">
                                <?php echo number_format($detail_product['quantity']); ?>
                                <small><?php echo htmlspecialchars($detail_product['unit']); ?></small>
                            </h3>
                            <small class="text-muted">Reorder: <?php echo $detail_product['reorder_level']; ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <p class="stat-label">Total Sold</p>
                            <h3><?php echo number_format($product_details['summary']['total_sold']); ?></h3>
                            <small class="text-muted">Units</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <p class="stat-label">Revenue</p>
                            <h3 class="text-success"><?php echo number_format($product_details['summary']['total_revenue'], 0); ?></h3>
                            <small class="text-muted">RWF</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <p class="stat-label">Net Profit</p>
                            <h3 class="text-primary"><?php echo number_format($product_details['summary']['total_profit'], 0); ?></h3>
                            <small class="text-muted">RWF</small>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="btn-group w-100">
                            <a href="../sales.php?product=<?php echo $detail_product['id']; ?>" class="btn btn-success">
                                <i class="fas fa-cash-register"></i> Sell Now
                            </a>
                            <a href="../purchases.php?product=<?php echo $detail_product['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-truck"></i> Add Stock
                            </a>
                            <?php if($is_admin): ?>
                            <a href="?edit=<?php echo $detail_product['id']; ?>" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <ul class="nav nav-tabs" id="productTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#sales">Sales History</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#purchases">Purchase History</a>
                    </li>
                </ul>
                
                <div class="tab-content mt-3">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active" id="overview">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Product Information</h6>
                                        <table class="table table-sm">
                                            <tr><td><strong>Category</strong></td><td><?php echo htmlspecialchars($detail_product['category_name'] ?? 'Uncategorized'); ?></td></tr>
                                            <tr><td><strong>Unit</strong></td><td><?php echo htmlspecialchars($detail_product['unit']); ?></td></tr>
                                            <tr><td><strong>Cost Price</strong></td><td><?php echo number_format($detail_product['cost_price'], 0); ?> RWF</td></tr>
                                            <tr><td><strong>Selling Price</strong></td><td><?php echo number_format($detail_product['selling_price'], 0); ?> RWF</td></tr>
                                            <tr><td><strong>Profit Margin</strong></td><td>
                                                <?php 
                                                $margin = $detail_product['cost_price'] > 0 
                                                    ? (($detail_product['selling_price'] - $detail_product['cost_price']) / $detail_product['cost_price']) * 100 
                                                    : 0;
                                                echo number_format($margin, 1) . '%';
                                                ?>
                                            </td></tr>
                                            <tr><td><strong>Description</strong></td><td><?php echo nl2br(htmlspecialchars($detail_product['description'] ?? 'No description')); ?></td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Stock History</h6>
                                        <canvas id="stockHistoryChart" height="150"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sales History Tab -->
                    <div class="tab-pane fade" id="sales">
                        <div class="table-container">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($product_details['sales'])): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">
                                            No sales recorded for this product
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($product_details['sales'] as $sale): ?>
                                    <tr>
                                        <td>
                                            <a href="../view_invoice.php?id=<?php echo $sale['invoice_no']; ?>" target="_blank">
                                                <?php echo htmlspecialchars($sale['invoice_no']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                        <td><?php echo $sale['sale_date']; ?></td>
                                        <td><?php echo $sale['quantity']; ?></td>
                                        <td><?php echo number_format($sale['selling_price'], 0); ?></td>
                                        <td><?php echo number_format($sale['subtotal'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Purchases Tab -->
                    <div class="tab-pane fade" id="purchases">
                        <div class="table-container">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>PO #</th>
                                        <th>Supplier</th>
                                        <th>Date</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($product_details['purchases'])): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">
                                            No purchases recorded for this product
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($product_details['purchases'] as $purchase): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($purchase['invoice_no'] ?? 'PO-' . $purchase['purchase_id']); ?></td>
                                        <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo $purchase['purchase_date']; ?></td>
                                        <td><?php echo $purchase['quantity']; ?></td>
                                        <td><?php echo number_format($purchase['unit_price'], 0); ?></td>
                                        <td><?php echo number_format($purchase['subtotal'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="products.php" class="btn btn-secondary">Close</a>
                <?php if($is_admin): ?>
                <a href="?edit=<?php echo $detail_product['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit me-1"></i> Edit Product
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
SCRIPTS
============================================ -->
<script>
function filterProducts() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const category = document.getElementById('categoryFilter').value.toLowerCase();
    const stock = document.getElementById('stockFilter').value;
    
    const items = document.querySelectorAll('.product-item');
    
    items.forEach(item => {
        const name = item.dataset.name;
        const cat = item.dataset.category;
        const stockStatus = item.dataset.stock;
        
        let show = true;
        
        if (search && !name.includes(search)) show = false;
        if (category && cat !== category) show = false;
        if (stock && stockStatus !== stock) show = false;
        
        item.style.display = show ? '' : 'none';
    });
}

// Auto-show modal if add/edit parameter is present
<?php if(isset($_GET['add']) || $edit_product): ?>
document.addEventListener('DOMContentLoaded', function() {
    const modal = new bootstrap.Modal(document.getElementById('productModal'));
    modal.show();
});
<?php endif; ?>

// Stock History Chart (if viewing product)
<?php if($detail_product): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('stockHistoryChart');
    if (ctx) {
        // Sample data - you can expand this with real stock history
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Stock Level',
                    data: [50, 45, 30, 25, 20, <?php echo $detail_product['quantity']; ?>],
                    borderColor: 'rgba(13, 110, 253, 1)',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }
});
<?php endif; ?>
</script>

<style>
.product-card {
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.product-card:hover {
    transform: translateY(-5px);
    border-color: #0d6efd;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}
.product-icon {
    width: 40px;
    height: 40px;
    background: #e7f1ff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0d6efd;
    font-size: 20px;
}
.stat-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 10px;
}
</style>

<?php include '../includes/footer.php'; ?>