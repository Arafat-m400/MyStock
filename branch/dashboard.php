<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$branch_name = getCurrentBranchName();

// ============================================
// DASHBOARD STATS
// ============================================

// 1. Total Products
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$total_products = $stmt->fetchColumn();

// 2. Low Stock Products
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE branch_id = ? AND quantity <= reorder_level");
$stmt->execute([$branch_id]);
$low_stock = $stmt->fetchColumn();

// 3. Today's Sales
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(grand_total), 0) as total_sales,
        COALESCE(SUM(cash_amount), 0) as total_cash,
        COALESCE(SUM(mobile_money_amount), 0) as total_momo,
        COUNT(*) as transaction_count
    FROM sales 
    WHERE branch_id = ? AND sale_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_sales = $stmt->fetch();

// 4. This Month's Sales
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(grand_total), 0) as total 
    FROM sales 
    WHERE branch_id = ? AND MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())
");
$stmt->execute([$branch_id]);
$month_sales = $stmt->fetchColumn();

// 5. Today's Expenses
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE branch_id = ? AND expense_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_expenses = $stmt->fetchColumn();

// 6. Today's REAL profit (revenue - cost of goods sold - expenses)
// FIX: previously "Today's Net Profit" was just sales minus expenses,
// which ignores cost of goods sold entirely — not actual profit.
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE s.branch_id = ? AND s.sale_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_cogs = $stmt->fetchColumn();
$today_gross_profit = $today_sales['total_sales'] - $today_cogs;
$today_net = $today_gross_profit - $today_expenses;

// 6b. This month's REAL profit (same COGS-based logic, for the Monthly card)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE s.branch_id = ? AND MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())
");
$stmt->execute([$branch_id]);
$month_cogs = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM expenses
    WHERE branch_id = ? AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())
");
$stmt->execute([$branch_id]);
$month_expenses = $stmt->fetchColumn();

$month_gross_profit = $month_sales - $month_cogs;
$month_net_profit   = $month_gross_profit - $month_expenses;

// 7. Stock Value
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(quantity * cost_price), 0) as total 
    FROM products 
    WHERE branch_id = ?
");
$stmt->execute([$branch_id]);
$stock_value = $stmt->fetchColumn();

// 8. Recent Sales (last 5)
$stmt = $pdo->prepare("
    SELECT s.*, c.name as customer_name 
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.branch_id = ?
    ORDER BY s.created_at DESC 
    LIMIT 5
");
$stmt->execute([$branch_id]);
$recent_sales = $stmt->fetchAll();

// 9. All Products (for product cards)
$stmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE branch_id = ? 
    ORDER BY 
        CASE WHEN quantity <= reorder_level THEN 0 ELSE 1 END,
        quantity ASC
    LIMIT 12
");
$stmt->execute([$branch_id]);
$all_products = $stmt->fetchAll();

// 10. Low Stock Products (for list)
$stmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE branch_id = ? AND quantity <= reorder_level 
    ORDER BY quantity ASC 
    LIMIT 10
");
$stmt->execute([$branch_id]);
$low_stock_products = $stmt->fetchAll();

// ============================================
// INCLUDE HEADER AND SIDEBAR
// ============================================

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- ============================================
MAIN CONTENT - col-md-10
============================================ -->
<div class="col-md-10 main-content">
    
    <!-- Welcome Message -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2><i class="fas fa-tachometer-alt me-2 text-primary"></i>Dashboard</h2>
                    <p class="text-muted">
                        <i class="fas fa-store me-1"></i>
                        <?php echo htmlspecialchars($branch_name); ?> Branch
                        <span class="mx-2">|</span>
                        <?php echo date('l, F j, Y'); ?>
                        <span class="mx-2">|</span>
                        <?php echo date('h:i A'); ?>
                    </p>
                </div>
                <div>
                    <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    STATS CARDS - First Row
    ============================================ -->
    <div class="row g-3 mb-4">
        <!-- Total Products -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Total Products</p>
                            <h3 class="stat-value mb-0"><?php echo number_format($total_products); ?></h3>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                            <i class="fas fa-box fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Low Stock -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Low Stock</p>
                            <h3 class="stat-value mb-0 text-<?php echo $low_stock > 0 ? 'danger' : 'success'; ?>">
                                <?php echo number_format($low_stock); ?>
                            </h3>
                        </div>
                        <div class="stat-icon bg-<?php echo $low_stock > 0 ? 'danger' : 'success'; ?> bg-opacity-10 text-<?php echo $low_stock > 0 ? 'danger' : 'success'; ?> rounded-3 p-3">
                            <i class="fas fa-exclamation-triangle fs-4"></i>
                        </div>
                    </div>
                    <?php if($low_stock > 0): ?>
                    <small class="text-danger">Needs attention</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Today's Sales -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Today's Sales</p>
                            <h3 class="stat-value mb-0 text-success"><?php echo number_format($today_sales['total_sales'], 0); ?></h3>
                            <small class="text-muted"><?php echo $today_sales['transaction_count']; ?> transactions</small>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3">
                            <i class="fas fa-cash-register fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Today's Net Profit -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Today's Net Profit</p>
                            <h3 class="stat-value mb-0 text-<?php echo $today_net >= 0 ? 'primary' : 'danger'; ?>">
                                <?php echo number_format($today_net, 0); ?>
                            </h3>
                            <small class="text-muted">After cost of goods (<?php echo number_format($today_cogs, 0); ?>) &amp; expenses (<?php echo number_format($today_expenses, 0); ?>)</small>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                            <i class="fas fa-chart-line fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    STATS CARDS - Second Row
    ============================================ -->
    <div class="row g-3 mb-4">
        <!-- Monthly Sales & Profit combined -->
        <!-- FIX: was "Monthly Sales" only (pure revenue); now shows both
             revenue and real COGS-based profit in one card instead of
             adding yet another card to an already crowded dashboard. -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Monthly Sales / Profit</p>
                            <h4 class="stat-value mb-0"><?php echo number_format($month_sales, 0); ?></h4>
                            <small class="text-<?php echo $month_net_profit >= 0 ? 'success' : 'danger'; ?>">
                                Profit: <?php echo number_format($month_net_profit, 0); ?>
                            </small>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10 text-info rounded-3 p-3">
                            <i class="fas fa-calendar-alt fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stock Value -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Stock Value</p>
                            <h4 class="stat-value mb-0"><?php echo number_format($stock_value, 0); ?></h4>
                            <small class="text-muted">Total inventory cost</small>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3">
                            <i class="fas fa-warehouse fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Methods -->
        <div class="col-12 col-md-6">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <p class="stat-label text-muted mb-2">Today's Payment Methods</p>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="d-flex align-items-center p-2 bg-light rounded-3">
                                <i class="fas fa-money-bill-wave text-success me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Cash</small>
                                    <strong><?php echo number_format($today_sales['total_cash'], 0); ?> RWF</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center p-2 bg-light rounded-3">
                                <i class="fas fa-mobile-alt text-primary me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Mobile Money</small>
                                    <strong><?php echo number_format($today_sales['total_momo'], 0); ?> RWF</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php 
                    $total_payments = $today_sales['total_cash'] + $today_sales['total_momo'];
                    $cash_percent = $total_payments > 0 ? ($today_sales['total_cash'] / $total_payments) * 100 : 0;
                    ?>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $cash_percent; ?>%"></div>
                        <div class="progress-bar bg-primary" style="width: <?php echo 100 - $cash_percent; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    PRODUCT CARDS
    ============================================ -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-box me-2 text-primary"></i>Product Overview</h5>
                    <a href="products.php" class="btn btn-sm btn-primary">View All Products</a>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if(empty($all_products)): ?>
                        <div class="col-12 text-center py-4 text-muted">
                            <i class="fas fa-box-open fa-3x mb-2 d-block"></i>
                            No products added yet.
                            <a href="products.php?add" class="d-block mt-2">Add your first product</a>
                        </div>
                        <?php else: ?>
                        <?php foreach($all_products as $product): 
                            $status_class = $product['quantity'] <= 0 ? 'danger' : ($product['quantity'] <= $product['reorder_level'] ? 'warning' : 'success');
                            $status_text = $product['quantity'] <= 0 ? 'Out of Stock' : ($product['quantity'] <= $product['reorder_level'] ? 'Low Stock' : 'In Stock');
                        ?>
                        <div class="col-6 col-md-3 col-lg-2">
                            <a href="products.php?view=<?php echo $product['id']; ?>" class="text-decoration-none">
                                <div class="card product-card h-100 shadow-sm">
                                    <div class="card-body text-center p-2">
                                        <div class="product-icon mx-auto mb-1">
                                            <i class="fas fa-box text-primary"></i>
                                        </div>
                                        <h6 class="card-title mb-0 text-truncate" title="<?php echo htmlspecialchars($product['name']); ?>">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </h6>
                                        <div class="stock-info mt-1">
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo $product['quantity']; ?> <?php echo $product['unit']; ?>
                                            </span>
                                            <small class="d-block text-muted"><?php echo $status_text; ?></small>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    RECENT SALES & LOW STOCK
    ============================================ -->
    <div class="row">
        <!-- Recent Sales -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock me-2 text-primary"></i>Recent Sales</h5>
                    <a href="sales.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($recent_sales)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">
                                        No sales today
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach($recent_sales as $sale): ?>
                                <tr>
                                    <td>
                                        <a href="view_invoice.php?id=<?php echo $sale['id']; ?>" target="_blank">
                                            <small><?php echo htmlspecialchars($sale['invoice_no']); ?></small>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td class="text-end"><strong><?php echo number_format($sale['grand_total'], 0); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Low Stock Products -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                        Low Stock Products
                    </h5>
                    <a href="products.php?filter=low" class="btn btn-sm btn-warning">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Stock</th>
                                    <th class="text-center">Reorder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($low_stock_products)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-success py-3">
                                        <i class="fas fa-check-circle me-2"></i>
                                        All products are well-stocked!
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach($low_stock_products as $product): ?>
                                <tr class="table-danger">
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td class="text-center"><strong class="text-danger"><?php echo $product['quantity']; ?></strong></td>
                                    <td class="text-center"><?php echo $product['reorder_level']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card {
    border: none !important;
    transition: all 0.3s ease;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08) !important;
}
.stat-card .stat-label {
    font-size: 13px;
    font-weight: 500;
}
.stat-card .stat-value {
    font-size: 26px;
    font-weight: 700;
}
.stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-card {
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    cursor: pointer;
}
.product-card:hover {
    border-color: #0d6efd;
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}
.product-card .product-icon {
    width: 40px;
    height: 40px;
    background: #e7f1ff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-card .card-title {
    font-size: 13px;
    font-weight: 600;
}
.product-card .stock-info .badge {
    font-size: 12px;
    padding: 3px 8px;
}
.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.table-responsive {
    overflow-x: auto;
}
@media (max-width: 576px) {
    .stat-card .stat-value {
        font-size: 20px;
    }
    .stat-icon {
        width: 38px;
        height: 38px;
    }
    .stat-icon i {
        font-size: 16px !important;
    }
    .product-card .card-title {
        font-size: 11px;
    }
    .product-card .stock-info .badge {
        font-size: 10px;
    }
}
</style>

<?php include '../includes/footer.php'; ?>