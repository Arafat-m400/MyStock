<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';

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
            logAction($pdo, 'Update Product', "Updated product: $name");
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
            logAction($pdo, 'Add Product', "Added product: $name");
        }
    } catch(PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// DELETE PRODUCT - UPDATED
// ============================================

if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    
    // Check if product is used in active records
    $sale_check = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?");
    $sale_check->execute([$id]);
    $sale_count = $sale_check->fetchColumn();

    $purchase_check = $pdo->prepare("SELECT COUNT(*) FROM purchase_items WHERE product_id = ?");
    $purchase_check->execute([$id]);
    $purchase_count = $purchase_check->fetchColumn();

    $po_check = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE product_id = ?");
    $po_check->execute([$id]);
    $po_item_count = $po_check->fetchColumn();
    
    $workspace_input_check = $pdo->prepare("SELECT COUNT(*) FROM workspace_inputs WHERE product_id = ?");
    $workspace_input_check->execute([$id]);
    $workspace_input_count = $workspace_input_check->fetchColumn();
    
    $workspace_output_check = $pdo->prepare("SELECT COUNT(*) FROM workspace_outputs WHERE product_id = ?");
    $workspace_output_check->execute([$id]);
    $workspace_output_count = $workspace_output_check->fetchColumn();

    $has_history = ($sale_count > 0 || $purchase_count > 0 || $po_item_count > 0 || 
                    $workspace_input_count > 0 || $workspace_output_count > 0);

    if ($has_history) {
        // Warn user but allow deletion with ON DELETE SET NULL
        $parts = [];
        if ($sale_count > 0)     $parts[] = "$sale_count sale record(s)";
        if ($purchase_count > 0) $parts[] = "$purchase_count purchase record(s)";
        if ($po_item_count > 0)  $parts[] = "$po_item_count purchase order item(s)";
        if ($workspace_input_count > 0)  $parts[] = "$workspace_input_count workspace input(s)";
        if ($workspace_output_count > 0) $parts[] = "$workspace_output_count workspace output(s)";
        
        // Show warning with option to proceed
        $warning_message = '⚠️ This product has ' . implode(' and ', $parts) . ' in history. Deleting will set product_id to NULL in those records (history remains intact).';
        
        // Check if user confirmed the delete with history
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                // Delete product (ON DELETE SET NULL will handle the rest)
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND branch_id = ?");
                $stmt->execute([$id, $branch_id]);
                $message = '<div class="alert alert-success">✅ Product deleted! History records preserved (product references set to NULL).</div>';
                logAction($pdo, 'Delete Product', "Deleted product ID: $id (with history)");
            } catch (PDOException $e) {
                $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
            }
        } else {
            // Show warning with delete button
            $message = '<div class="alert alert-warning">
                <strong>' . $warning_message . '</strong>
                <br><br>
                <a href="?delete=' . $id . '&confirm=yes" class="btn btn-danger">
                    <i class="fas fa-trash me-1"></i> Yes, Delete Anyway
                </a>
                <a href="products.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>';
        }
    } else {
        // No history - safe to delete
        try {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Product deleted successfully!</div>';
            logAction($pdo, 'Delete Product', "Deleted product ID: $id");
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
        }
    }
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

// Get product details if viewing (using the function from db.php)
$product_details = null;
if ($detail_product) {
    $product_details = getProductDetails($pdo, $detail_product['id'], $branch_id);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-box me-2 text-primary"></i>Products</h2>
            <p class="text-muted">Manage your products and monitor stock levels</p>
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
                    <input type="text" id="searchInput" class="form-control" onkeyup="filterProducts()">
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
            <div class="card product-card h-100">
                <div class="card-body">
                    <a href="?view=<?php echo $product['id']; ?>" class="text-decoration-none">
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
                    </a>
                    
                    <!-- ===== DELETE BUTTON ON PRODUCT CARD ===== -->
                    <?php if($is_admin): ?>
                    <div class="mt-2 text-end">
                        <a href="?delete=<?php echo $product['id']; ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirmDeleteProduct('<?php echo htmlspecialchars($product['name']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
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
                                           value="<?php echo htmlspecialchars($edit_product['unit'] ?? 'pcs'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-2">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" class="form-control"
                                           value="<?php echo $edit_product['quantity'] ?? 0; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-2">
                                    <label class="form-label">Reorder Level</label>
                                    <input type="number" name="reorder_level" class="form-control"
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
                                    <input type="number" name="cost_price" class="form-control"
                                           value="<?php echo $edit_product['cost_price'] ?? 0; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Selling Price (RWF)</label>
                                    <input type="number" name="selling_price" class="form-control"
                                           value="<?php echo $edit_product['selling_price'] ?? 0; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
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
                        <a href="?delete=<?php echo $detail_product['id']; ?>" class="btn btn-sm btn-danger me-2"
                           onclick="return confirmDeleteProduct('<?php echo htmlspecialchars($detail_product['name']); ?>')">
                            <i class="fas fa-trash"></i> Delete
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
                                <a href="sales.php?product=<?php echo $detail_product['id']; ?>" class="btn btn-success">
                                    <i class="fas fa-cash-register"></i> Sell Now
                                </a>
                                <a href="stock_in.php?product=<?php echo $detail_product['id']; ?>" class="btn btn-primary">
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
                                            <h6><i class="fas fa-history me-2 text-info"></i>Stock History</h6>
                                            <?php if(empty($product_details['stock_history'])): ?>
                                            <div class="text-center text-muted py-3">
                                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                                No stock history available
                                            </div>
                                            <?php else: ?>
                                            <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                                                <table class="table table-sm table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Source</th>
                                                            <th>Qty</th>
                                                            <th>Type</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($product_details['stock_history'] as $history): 
                                                            $qty_class = 'text-success';
                                                            $qty_prefix = '+';
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <small><?php echo date('M d, Y', strtotime($history['date'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <small><?php echo htmlspecialchars($history['supplier_name'] ?? 'Unknown'); ?></small>
                                                            </td>
                                                            <td class="<?php echo $qty_class; ?>">
                                                                <strong><?php echo $qty_prefix . $history['quantity_change']; ?></strong>
                                                                <br>
                                                                <small class="text-muted">@ <?php echo number_format($history['unit_price'], 0); ?> RWF</small>
                                                            </td>
                                                            <td>
                                                                <?php if($history['type'] == 'Advance PO'): ?>
                                                                <span class="badge bg-success">📦 Advance PO</span>
                                                                <?php else: ?>
                                                                <span class="badge bg-primary">📥 <?php echo $history['type']; ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>
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
                                                <a href="view_invoice.php?id=<?php echo $sale['id']; ?>" target="_blank">
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
                    <a href="?delete=<?php echo $detail_product['id']; ?>" class="btn btn-danger"
                       onclick="return confirmDeleteProduct('<?php echo htmlspecialchars($detail_product['name']); ?>')">
                        <i class="fas fa-trash me-1"></i> Delete Product
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

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

function confirmDeleteProduct(name) {
    return confirm('⚠️ Delete product "' + name + '"?\n\n' +
                   '• Past sales and purchase records will be preserved\n' +
                   '• Product references in history will become "Deleted Product"\n' +
                   '• Stock value and future calculations will update\n\n' +
                   'This action CANNOT be undone!');
}

// Auto-show modal if add/edit parameter is present
<?php if(isset($_GET['add']) || $edit_product): ?>
document.addEventListener('DOMContentLoaded', function() {
    const modal = new bootstrap.Modal(document.getElementById('productModal'));
    modal.show();
});
<?php endif; ?>
</script>

<style>
.product-card {
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
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