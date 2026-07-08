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

// 6. Today's Net Profit
$today_net = $today_sales['total_sales'] - $today_expenses;

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

// 9. Low Stock Products
$stmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE branch_id = ? AND quantity <= reorder_level 
    ORDER BY quantity ASC 
    LIMIT 10
");
$stmt->execute([$branch_id]);
$low_stock_products = $stmt->fetchAll();

// 10. Weekly Sales Chart Data
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as date,
        COALESCE(SUM(grand_total), 0) as total
    FROM sales 
    WHERE branch_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_date
");
$stmt->execute([$branch_id]);
$weekly_data = $stmt->fetchAll();

include '../includes/header.php';
?>

<!-- ============================================
DASHBOARD CONTENT
============================================ -->

<div class="row">
    <!-- Welcome Message -->
    <div class="col-12 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h2><i class="fas fa-tachometer-alt me-2 text-primary"></i>Dashboard</h2>
                <p class="text-muted">
                    <i class="fas fa-store me-1"></i>
                    <?php echo htmlspecialchars($branch_name); ?> Branch
                    <span class="mx-2">|</span>
                    <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm" onclick="refreshPage()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
STATS CARDS
============================================ -->
<div class="row g-3 mb-4">
    <!-- Total Products -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Total Products</p>
                    <h3 class="stat-value"><?php echo number_format($total_products); ?></h3>
                </div>
                <div class="stat-icon primary">
                    <i class="fas fa-box"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Low Stock -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Low Stock</p>
                    <h3 class="stat-value text-<?php echo $low_stock > 0 ? 'danger' : 'success'; ?>">
                        <?php echo number_format($low_stock); ?>
                    </h3>
                </div>
                <div class="stat-icon <?php echo $low_stock > 0 ? 'danger' : 'success'; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
            <?php if($low_stock > 0): ?>
            <small class="text-danger">
                <i class="fas fa-circle pulse me-1" style="font-size: 8px;"></i>
                Needs attention
            </small>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Today's Sales -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Today's Sales</p>
                    <h3 class="stat-value text-success">
                        <?php echo number_format($today_sales['total_sales'], 0); ?>
                    </h3>
                    <small class="text-muted">
                        <?php echo $today_sales['transaction_count']; ?> transactions
                    </small>
                </div>
                <div class="stat-icon success">
                    <i class="fas fa-cash-register"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Today's Net Profit -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Today's Net Profit</p>
                    <h3 class="stat-value text-<?php echo $today_net >= 0 ? 'primary' : 'danger'; ?>">
                        <?php echo number_format($today_net, 0); ?>
                    </h3>
                    <small class="text-muted">
                        Expenses: <?php echo number_format($today_expenses, 0); ?>
                    </small>
                </div>
                <div class="stat-icon primary">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Second Row Stats -->
<div class="row g-3 mb-4">
    <!-- Monthly Sales -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Monthly Sales</p>
                    <h4 class="stat-value"><?php echo number_format($month_sales, 0); ?></h4>
                    <small class="text-muted">This month</small>
                </div>
                <div class="stat-icon info">
                    <i class="fas fa-calendar-alt"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stock Value -->
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="stat-label">Stock Value</p>
                    <h4 class="stat-value"><?php echo number_format($stock_value, 0); ?></h4>
                    <small class="text-muted">Total inventory cost</small>
                </div>
                <div class="stat-icon warning">
                    <i class="fas fa-warehouse"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cash vs Mobile Money -->
    <div class="col-12 col-md-6">
        <div class="stat-card">
            <p class="stat-label">Today's Payment Methods</p>
            <div class="row mt-2">
                <div class="col-6">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-money-bill-wave text-success me-2"></i>
                        <div>
                            <small class="text-muted d-block">Cash</small>
                            <strong><?php echo number_format($today_sales['total_cash'], 0); ?></strong>
                            <small class="text-muted">RWF</small>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-mobile-alt text-primary me-2"></i>
                        <div>
                            <small class="text-muted d-block">Mobile Money</small>
                            <strong><?php echo number_format($today_sales['total_momo'], 0); ?></strong>
                            <small class="text-muted">RWF</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="progress mt-2" style="height: 8px;">
                <?php 
                $total_payments = $today_sales['total_cash'] + $today_sales['total_momo'];
                $cash_percent = $total_payments > 0 ? ($today_sales['total_cash'] / $total_payments) * 100 : 0;
                ?>
                <div class="progress-bar bg-success" style="width: <?php echo $cash_percent; ?>%"></div>
                <div class="progress-bar bg-primary" style="width: <?php echo 100 - $cash_percent; ?>%"></div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
WEEKLY SALES CHART
============================================ -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Weekly Sales (Last 7 Days)</h5>
                <span class="badge bg-primary"><?php echo count($weekly_data); ?> days</span>
            </div>
            <div class="card-body">
                <canvas id="weeklySalesChart" height="200"></canvas>
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
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Sales</h5>
                <a href="sales.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Amount</th>
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
                                <td><strong><?php echo number_format($sale['grand_total'], 0); ?></strong></td>
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
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Low Stock Products
                </h5>
                <a href="products.php?filter=low" class="btn btn-sm btn-warning">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Reorder</th>
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
                                <td><strong class="text-danger"><?php echo $product['quantity']; ?></strong></td>
                                <td><?php echo $product['reorder_level']; ?></td>
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

<!-- ============================================
SCRIPTS FOR CHARTS
============================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Weekly Sales Chart
    const ctx = document.getElementById('weeklySalesChart').getContext('2d');
    
    <?php
    $chart_labels = [];
    $chart_data = [];
    
    // Fill missing dates with 0
    $dates = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[$date] = 0;
    }
    
    foreach ($weekly_data as $row) {
        $dates[$row['date']] = $row['total'];
    }
    
    foreach ($dates as $date => $amount) {
        $chart_labels[] = date('D', strtotime($date));
        $chart_data[] = $amount;
    }
    ?>
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Daily Sales (RWF)',
                data: <?php echo json_encode($chart_data); ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.6)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 2,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + ' RWF';
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>