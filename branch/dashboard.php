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

// 5. Today's Expenses (regular branch expenses)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE branch_id = ? AND expense_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_expenses = $stmt->fetchColumn();

// 6. Today's Workspace Production Costs (from workspace_costs)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(wc.amount), 0) as total
    FROM workspace_costs wc
    JOIN workspaces w ON wc.workspace_id = w.id
    WHERE w.branch_id = ? AND wc.cost_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_workspace_costs = $stmt->fetchColumn();

// 7. Today's REAL profit (revenue - cost of goods sold - expenses - workspace costs)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE s.branch_id = ? AND s.sale_date = CURDATE()
");
$stmt->execute([$branch_id]);
$today_cogs = $stmt->fetchColumn();

$today_gross_profit = $today_sales['total_sales'] - $today_cogs;
$today_total_expenses = $today_expenses + $today_workspace_costs;
$today_net = $today_gross_profit - $today_total_expenses;

// 7b. This month's REAL profit
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

// Month workspace costs
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(wc.amount), 0) as total
    FROM workspace_costs wc
    JOIN workspaces w ON wc.workspace_id = w.id
    WHERE w.branch_id = ? AND MONTH(wc.cost_date) = MONTH(CURDATE()) AND YEAR(wc.cost_date) = YEAR(CURDATE())
");
$stmt->execute([$branch_id]);
$month_workspace_costs = $stmt->fetchColumn();

$month_gross_profit = $month_sales - $month_cogs;
$month_total_expenses = $month_expenses + $month_workspace_costs;
$month_net_profit = $month_gross_profit - $month_total_expenses;

// 8. Stock Value
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(quantity * cost_price), 0) as total 
    FROM products 
    WHERE branch_id = ?
");
$stmt->execute([$branch_id]);
$stock_value = $stmt->fetchColumn();

// ============================================
// WORKSPACE STATS
// ============================================

// 9. Workspace Summary
$workspace_summary = $pdo->prepare("
    SELECT 
        COUNT(*) as total_workspaces,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        COALESCE(SUM(
            (SELECT COALESCE(SUM(total_cost), 0) FROM workspace_inputs WHERE workspace_id = w.id)
        ), 0) as total_input_cost,
        COALESCE(SUM(
            (SELECT COALESCE(SUM(amount), 0) FROM workspace_costs WHERE workspace_id = w.id)
        ), 0) as total_production_cost,
        COALESCE(SUM(
            (SELECT COALESCE(SUM(total_value), 0) FROM workspace_outputs WHERE workspace_id = w.id)
        ), 0) as total_output_value
    FROM workspaces w
    WHERE w.branch_id = ?
");
$workspace_summary->execute([$branch_id]);
$workspace_stats = $workspace_summary->fetch();

// Calculate total workspace profit/loss
$workspace_profit_loss = $workspace_stats['total_output_value'] - $workspace_stats['total_input_cost'] - $workspace_stats['total_production_cost'];

// 10. Active Workspaces (for list)
$active_workspaces = $pdo->prepare("
    SELECT w.*,
           COALESCE((SELECT SUM(total_cost) FROM workspace_inputs WHERE workspace_id = w.id), 0) as total_input_cost,
           COALESCE((SELECT SUM(amount) FROM workspace_costs WHERE workspace_id = w.id), 0) as total_production_cost,
           COALESCE((SELECT SUM(total_value) FROM workspace_outputs WHERE workspace_id = w.id), 0) as total_output_value,
           (SELECT COUNT(*) FROM workspace_outputs WHERE workspace_id = w.id) as output_count
    FROM workspaces w
    WHERE w.branch_id = ? AND w.status IN ('active', 'paused')
    ORDER BY w.created_at DESC
    LIMIT 5
");
$active_workspaces->execute([$branch_id]);
$active_workspaces = $active_workspaces->fetchAll();

// 11. All Products (for product cards)
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

// 12. Low Stock Products (for list)
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
                            <small class="text-muted">
                                COGS: <?php echo number_format($today_cogs, 0); ?> | 
                                Expenses: <?php echo number_format($today_total_expenses, 0); ?>
                            </small>
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
    STATS CARDS - Second Row (with Workspace Stats)
    ============================================ -->
    <div class="row g-3 mb-4">
        <!-- Monthly Sales & Profit -->
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
        
        <!-- Workspace Summary - Active -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Active Workspaces</p>
                            <h4 class="stat-value mb-0 text-success"><?php echo $workspace_stats['active_count'] ?? 0; ?></h4>
                            <small class="text-muted">
                                <?php echo ($workspace_stats['paused_count'] ?? 0); ?> paused | 
                                <?php echo ($workspace_stats['completed_count'] ?? 0); ?> completed
                            </small>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3">
                            <i class="fas fa-industry fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Workspace Profit/Loss -->
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stat-label text-muted mb-1">Workspace P/L</p>
                            <h4 class="stat-value mb-0 text-<?php echo $workspace_profit_loss >= 0 ? 'primary' : 'danger'; ?>">
                                <?php echo number_format($workspace_profit_loss, 0); ?>
                            </h4>
                            <small class="text-muted">
                                In: <?php echo number_format($workspace_stats['total_input_cost'] ?? 0, 0); ?> | 
                                Out: <?php echo number_format($workspace_stats['total_output_value'] ?? 0, 0); ?>
                            </small>
                        </div>
                        <div class="stat-icon bg-<?php echo $workspace_profit_loss >= 0 ? 'primary' : 'danger'; ?> bg-opacity-10 text-<?php echo $workspace_profit_loss >= 0 ? 'primary' : 'danger'; ?> rounded-3 p-3">
                            <i class="fas fa-chart-pie fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    PAYMENT METHODS
    ============================================ -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card stat-card shadow-sm">
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
    WORKSPACE OVERVIEW & LOW STOCK
    ============================================ -->
    <div class="row">
        <!-- Workspace Overview (replaces Recent Sales) -->
        <div class="col-md-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-industry me-2 text-primary"></i>Active Workspaces</h5>
                    <a href="workspaces.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if(empty($active_workspaces)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-industry fa-2x mb-2 d-block"></i>
                        No active workspaces.
                        <a href="workspaces.php?tab=create" class="d-block mt-2">Create your first workspace</a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Workspace</th>
                                    <th>Status</th>
                                    <th class="text-end">P/L</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_workspaces as $ws):
                                    $ws_profit = $ws['total_output_value'] - $ws['total_input_cost'] - $ws['total_production_cost'];
                                    $profit_class = $ws_profit >= 0 ? 'text-success' : 'text-danger';
                                    $status_badge = $ws['status'] == 'active' ? 'success' : 'warning';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ws['name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo $ws['output_count']; ?> outputs
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $status_badge; ?>">
                                            <?php echo strtoupper($ws['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end <?php echo $profit_class; ?>">
                                        <strong><?php echo number_format($ws_profit, 0); ?></strong>
                                    </td>
                                    <td>
                                        <a href="workspace_details.php?id=<?php echo $ws['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
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