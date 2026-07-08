<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();

// ============================================
// GET DATE RANGE
// ============================================

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'sales';

// ============================================
// SALES REPORT
// ============================================

if ($report_type == 'sales') {
    // Daily sales breakdown
    $daily_sales = $pdo->prepare("
        SELECT 
            sale_date,
            COUNT(*) as transactions,
            COALESCE(SUM(grand_total), 0) as total,
            COALESCE(SUM(cash_amount), 0) as cash,
            COALESCE(SUM(mobile_money_amount), 0) as momo,
            COALESCE(SUM(discount), 0) as discounts
        FROM sales
        WHERE branch_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY sale_date
        ORDER BY sale_date DESC
    ");
    $daily_sales->execute([$branch_id, $start_date, $end_date]);
    $daily_sales = $daily_sales->fetchAll();
    
    // Total summary
    $total_sales = $pdo->prepare("
        SELECT 
            COALESCE(SUM(grand_total), 0) as total,
            COUNT(*) as transactions,
            COALESCE(SUM(cash_amount), 0) as total_cash,
            COALESCE(SUM(mobile_money_amount), 0) as total_momo,
            COALESCE(SUM(discount), 0) as total_discounts
        FROM sales
        WHERE branch_id = ? AND sale_date BETWEEN ? AND ?
    ");
    $total_sales->execute([$branch_id, $start_date, $end_date]);
    $total_sales = $total_sales->fetch();
    
    // Top products
    $top_products = $pdo->prepare("
        SELECT 
            p.id,
            p.name,
            COALESCE(SUM(si.quantity), 0) as quantity_sold,
            COALESCE(SUM(si.subtotal), 0) as revenue,
            COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as cost,
            COALESCE(SUM(si.subtotal - (si.quantity * si.cost_price_at_sale)), 0) as profit
        FROM products p
        LEFT JOIN sale_items si ON si.product_id = p.id
        LEFT JOIN sales s ON si.sale_id = s.id
        WHERE p.branch_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY revenue DESC
        LIMIT 10
    ");
    $top_products->execute([$branch_id, $start_date, $end_date]);
    $top_products = $top_products->fetchAll();
}

// ============================================
// PROFIT & LOSS REPORT
// ============================================

if ($report_type == 'profit_loss') {
    // Sales and cost breakdown
    $pl_data = $pdo->prepare("
        SELECT 
            COALESCE(SUM(si.subtotal), 0) as total_revenue,
            COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as total_cogs,
            COALESCE(SUM(si.subtotal - (si.quantity * si.cost_price_at_sale)), 0) as gross_profit,
            COUNT(DISTINCT s.id) as transactions,
            COALESCE(SUM(s.discount), 0) as total_discounts
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        WHERE s.branch_id = ? AND s.sale_date BETWEEN ? AND ?
    ");
    $pl_data->execute([$branch_id, $start_date, $end_date]);
    $pl_data = $pl_data->fetch();
    
    // Expenses
    $total_expenses = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM expenses 
        WHERE branch_id = ? AND expense_date BETWEEN ? AND ?
    ");
    $total_expenses->execute([$branch_id, $start_date, $end_date]);
    $total_expenses = $total_expenses->fetchColumn();
    
    $net_profit = $pl_data['gross_profit'] - $total_expenses;
    
    // Daily profit trend
    $daily_profit = $pdo->prepare("
        SELECT 
            s.sale_date,
            COALESCE(SUM(si.subtotal), 0) as revenue,
            COALESCE(SUM(si.quantity * si.cost_price_at_sale), 0) as cost,
            COALESCE(SUM(si.subtotal - (si.quantity * si.cost_price_at_sale)), 0) as profit
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        WHERE s.branch_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY s.sale_date
        ORDER BY s.sale_date DESC
    ");
    $daily_profit->execute([$branch_id, $start_date, $end_date]);
    $daily_profit = $daily_profit->fetchAll();
}

// ============================================
// CUSTOMER REPORT
// ============================================

if ($report_type == 'customers') {
    $top_customers = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.phone,
            c.total_spent,
            COUNT(s.id) as transactions,
            COALESCE(SUM(s.grand_total), 0) as total,
            COALESCE(AVG(s.grand_total), 0) as average
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id AND s.branch_id = ? AND s.sale_date BETWEEN ? AND ?
        WHERE c.branch_id = ?
        GROUP BY c.id
        ORDER BY total DESC
        LIMIT 20
    ");
    $top_customers->execute([$branch_id, $start_date, $end_date, $branch_id]);
    $top_customers = $top_customers->fetchAll();
    
    // Customer with debts
    $customers_with_debt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.phone,
            c.total_spent,
            COALESCE(SUM(cd.remaining), 0) as total_debt
        FROM customers c
        JOIN customer_debts cd ON cd.customer_id = c.id
        WHERE c.branch_id = ? AND cd.status IN ('pending', 'partial')
        GROUP BY c.id
        ORDER BY total_debt DESC
    ");
    $customers_with_debt->execute([$branch_id]);
    $customers_with_debt = $customers_with_debt->fetchAll();
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-chart-line me-2 text-primary"></i>Reports</h2>
        <p class="text-muted">
            <?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch
        </p>
    </div>
    <div>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print me-1"></i> Print Report
        </button>
    </div>
</div>

<!-- ============================================
REPORT FILTERS
============================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Report Type</label>
                <select name="report_type" class="form-select">
                    <option value="sales" <?php echo $report_type == 'sales' ? 'selected' : ''; ?>>📊 Sales</option>
                    <option value="profit_loss" <?php echo $report_type == 'profit_loss' ? 'selected' : ''; ?>>💰 Profit & Loss</option>
                    <option value="customers" <?php echo $report_type == 'customers' ? 'selected' : ''; ?>>👥 Customers</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(7)">7 Days</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(30)">30 Days</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(90)">90 Days</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(365)">1 Year</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
SALES REPORT
============================================ -->
<?php if($report_type == 'sales'): ?>
<div class="row">
    <!-- Summary Cards -->
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Total Sales</p>
            <h4 class="text-success"><?php echo number_format($total_sales['total'], 0); ?></h4>
            <small>RWF</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Transactions</p>
            <h4 class="text-primary"><?php echo number_format($total_sales['transactions']); ?></h4>
            <small>orders</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Average Sale</p>
            <h4 class="text-info">
                <?php 
                $avg = $total_sales['transactions'] > 0 ? $total_sales['total'] / $total_sales['transactions'] : 0;
                echo number_format($avg, 0); 
                ?>
            </h4>
            <small>RWF per transaction</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Cash vs MOMO</p>
            <h4 class="text-warning">
                <?php 
                $total = $total_sales['total_cash'] + $total_sales['total_momo'];
                if ($total > 0) {
                    echo round(($total_sales['total_cash'] / $total) * 100) . '% Cash';
                } else {
                    echo '0%';
                }
                ?>
            </h4>
            <small><?php echo number_format($total_sales['total_cash'], 0); ?> / <?php echo number_format($total_sales['total_momo'], 0); ?></small>
        </div>
    </div>
</div>

<!-- Daily Sales Table -->
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>Daily Sales Breakdown</h5>
    </div>
    <div class="card-body p-0">
        <?php if(empty($daily_sales)): ?>
        <div class="text-center py-4 text-muted">
            No sales data for this period.
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Cash</th>
                        <th>MOMO</th>
                        <th>Discounts</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $running_total = 0;
                    foreach($daily_sales as $day): 
                        $running_total += $day['total'];
                    ?>
                    <tr>
                        <td><strong><?php echo $day['sale_date']; ?></strong></td>
                        <td><?php echo $day['transactions']; ?></td>
                        <td><?php echo number_format($day['cash'], 0); ?></td>
                        <td><?php echo number_format($day['momo'], 0); ?></td>
                        <td><?php echo number_format($day['discounts'], 0); ?></td>
                        <td><strong><?php echo number_format($day['total'], 0); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-success">
                        <th><strong>Totals</strong></th>
                        <th><?php echo array_sum(array_column($daily_sales, 'transactions')); ?></th>
                        <th><?php echo number_format(array_sum(array_column($daily_sales, 'cash')), 0); ?></th>
                        <th><?php echo number_format(array_sum(array_column($daily_sales, 'momo')), 0); ?></th>
                        <th><?php echo number_format(array_sum(array_column($daily_sales, 'discounts')), 0); ?></th>
                        <th><strong><?php echo number_format(array_sum(array_column($daily_sales, 'total')), 0); ?></strong></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Top Products -->
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-crown me-2"></i>Top Selling Products</h5>
    </div>
    <div class="card-body p-0">
        <?php if(empty($top_products)): ?>
        <div class="text-center py-4 text-muted">
            No products sold in this period.
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Quantity Sold</th>
                        <th>Revenue</th>
                        <th>Cost</th>
                        <th>Profit</th>
                        <th>Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach($top_products as $p): 
                        $margin = $p['revenue'] > 0 ? ($p['profit'] / $p['revenue']) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($p['name']); ?></td>
                        <td><?php echo $p['quantity_sold']; ?></td>
                        <td><?php echo number_format($p['revenue'], 0); ?></td>
                        <td><?php echo number_format($p['cost'], 0); ?></td>
                        <td class="text-success"><?php echo number_format($p['profit'], 0); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $margin > 30 ? 'success' : ($margin > 10 ? 'warning' : 'danger'); ?>">
                                <?php echo number_format($margin, 1); ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
PROFIT & LOSS REPORT
============================================ -->
<?php if($report_type == 'profit_loss'): ?>
<div class="row">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Total Revenue</p>
            <h4 class="text-success"><?php echo number_format($pl_data['total_revenue'], 0); ?></h4>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">COGS</p>
            <h4 class="text-danger"><?php echo number_format($pl_data['total_cogs'], 0); ?></h4>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Gross Profit</p>
            <h4 class="text-primary"><?php echo number_format($pl_data['gross_profit'], 0); ?></h4>
            <small><?php echo $pl_data['total_revenue'] > 0 ? number_format(($pl_data['gross_profit'] / $pl_data['total_revenue']) * 100, 1) : 0; ?>% margin</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Net Profit</p>
            <h4 class="<?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                <?php echo number_format($net_profit, 0); ?>
            </h4>
            <small>After expenses</small>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Profit & Loss Summary</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <td><strong>Total Revenue</strong></td>
                        <td class="text-end"><?php echo number_format($pl_data['total_revenue'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>Cost of Goods Sold (COGS)</strong></td>
                        <td class="text-end text-danger"><?php echo number_format($pl_data['total_cogs'], 0); ?> RWF</td>
                    </tr>
                    <tr class="table-success">
                        <td><strong>Gross Profit</strong></td>
                        <td class="text-end"><strong><?php echo number_format($pl_data['gross_profit'], 0); ?> RWF</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Total Expenses</strong></td>
                        <td class="text-end text-danger"><?php echo number_format($total_expenses, 0); ?> RWF</td>
                    </tr>
                    <tr class="table-<?php echo $net_profit >= 0 ? 'success' : 'danger'; ?>">
                        <td><strong>Net Profit</strong></td>
                        <td class="text-end"><strong><?php echo number_format($net_profit, 0); ?> RWF</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Transactions</strong></td>
                        <td class="text-end"><?php echo $pl_data['transactions']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Discounts</strong></td>
                        <td class="text-end"><?php echo number_format($pl_data['total_discounts'], 0); ?> RWF</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Daily Profit Trend</h5>
            </div>
            <div class="card-body p-0">
                <?php if(empty($daily_profit)): ?>
                <div class="text-center py-4 text-muted">
                    No data for this period.
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Revenue</th>
                                <th>Cost</th>
                                <th>Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($daily_profit as $day): ?>
                            <tr>
                                <td><?php echo $day['sale_date']; ?></td>
                                <td><?php echo number_format($day['revenue'], 0); ?></td>
                                <td><?php echo number_format($day['cost'], 0); ?></td>
                                <td class="text-<?php echo $day['profit'] >= 0 ? 'success' : 'danger'; ?>">
                                    <strong><?php echo number_format($day['profit'], 0); ?></strong>
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
<?php endif; ?>

<!-- ============================================
CUSTOMER REPORT
============================================ -->
<?php if($report_type == 'customers'): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Top Customers (by spend)</h5>
            </div>
            <div class="card-body p-0">
                <?php if(empty($top_customers)): ?>
                <div class="text-center py-4 text-muted">
                    No customer data for this period.
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Transactions</th>
                                <th>Total Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach($top_customers as $c): ?>
                            <?php if($c['total'] > 0 || $rank <= 5): ?>
                            <tr>
                                <td>
                                    <?php if($rank == 1): ?>🥇
                                    <?php elseif($rank == 2): ?>🥈
                                    <?php elseif($rank == 3): ?>🥉
                                    <?php else: echo $rank; endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($c['name']); ?></td>
                                <td><?php echo $c['phone'] ?? '—'; ?></td>
                                <td><?php echo $c['transactions']; ?></td>
                                <td><strong><?php echo number_format($c['total'], 0); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <?php $rank++; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Customers with Debt</h5>
            </div>
            <div class="card-body p-0">
                <?php if(empty($customers_with_debt)): ?>
                <div class="text-center py-4 text-success">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                    No customers with outstanding debt! 🎉
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Total Spent</th>
                                <th>Debt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($customers_with_debt as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['name']); ?></td>
                                <td><?php echo $c['phone'] ?? '—'; ?></td>
                                <td><?php echo number_format($c['total_spent'], 0); ?></td>
                                <td class="text-danger"><strong><?php echo number_format($c['total_debt'], 0); ?></strong></td>
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
<?php endif; ?>

<script>
function setDateRange(days) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);
    
    document.querySelector('input[name="start_date"]').value = start.toISOString().split('T')[0];
    document.querySelector('input[name="end_date"]').value = end.toISOString().split('T')[0];
    document.querySelector('form').submit();
}
</script>

<style>
.stat-card {
    background: white;
    padding: 15px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.stat-card h4 {
    margin: 0;
    font-weight: 700;
}
.stat-card .stat-label {
    color: #6c757d;
    font-size: 13px;
    margin-top: 5px;
}
@media print {
    .btn, .no-print {
        display: none !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    .stat-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<?php include '../includes/footer.php'; ?>