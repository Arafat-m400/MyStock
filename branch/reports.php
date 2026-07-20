<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$report_tab = $_GET['report_tab'] ?? 'sales';

// ============================================
// GET DATE RANGE
// ============================================

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// ============================================
// SALES REPORT DATA
// ============================================

if ($report_tab == 'sales' || $report_tab == 'profit') {
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
    
    // Profit & Loss
    if ($report_tab == 'profit') {
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
        
        $total_expenses = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE branch_id = ? AND expense_date BETWEEN ? AND ?");
        $total_expenses->execute([$branch_id, $start_date, $end_date]);
        $total_expenses = $total_expenses->fetchColumn();
        $net_profit = $pl_data['gross_profit'] - $total_expenses;
    }
}

// ============================================
// CUSTOMER REPORT DATA
// ============================================

if ($report_tab == 'customers') {
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
}

// ============================================
// END OF DAY DATA
// ============================================

if ($report_tab == 'eod') {
    $eod_history = $pdo->prepare("
        SELECT e.*, u.full_name as created_by_name
        FROM end_of_day e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.branch_id = ?
        ORDER BY e.report_date DESC
        LIMIT 30
    ");
    $eod_history->execute([$branch_id]);
    $eod_history = $eod_history->fetchAll();
    
    // Get today's EOD status
    $today_eod = $pdo->prepare("
        SELECT * FROM end_of_day 
        WHERE branch_id = ? AND report_date = CURDATE()
    ");
    $today_eod->execute([$branch_id]);
    $today_eod = $today_eod->fetch();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-chart-line me-2 text-primary"></i>Reports</h2>
            <p class="text-muted">
                <?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch
                <span class="mx-2">|</span>
                <?php echo $start_date; ?> to <?php echo $end_date; ?>
            </p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $report_tab == 'sales' ? 'active' : ''; ?>" href="?report_tab=sales&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-chart-bar me-1"></i> Sales
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_tab == 'profit' ? 'active' : ''; ?>" href="?report_tab=profit&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-coins me-1"></i> Profit & Loss
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_tab == 'customers' ? 'active' : ''; ?>" href="?report_tab=customers&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-users me-1"></i> Customers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_tab == 'eod' ? 'active' : ''; ?>" href="?report_tab=eod">
                <i class="fas fa-calendar-check me-1"></i> End of Day
            </a>
        </li>
    </ul>

    <!-- Date Filter -->
    <?php if($report_tab != 'eod'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="report_tab" value="<?php echo $report_tab; ?>">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
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
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(7)">7D</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(30)">30D</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(90)">90D</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange(365)">1Y</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    SALES REPORT
    ============================================ -->
    <?php if($report_tab == 'sales'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <p class="stat-label">Total Sales</p>
                <h4 class="text-success"><?php echo number_format($total_sales['total'], 0); ?></h4>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <p class="stat-label">Transactions</p>
                <h4 class="text-primary"><?php echo number_format($total_sales['transactions']); ?></h4>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <p class="stat-label">Average Sale</p>
                <h4 class="text-info">
                    <?php 
                    $avg = $total_sales['transactions'] > 0 ? $total_sales['total'] / $total_sales['transactions'] : 0;
                    echo number_format($avg, 0); 
                    ?>
                </h4>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
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
            </div>
        </div>
    </div>

    <!-- Daily Sales Table -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>Daily Sales</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($daily_sales)): ?>
            <div class="text-center py-4 text-muted">No sales data.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Orders</th><th>Cash</th><th>MOMO</th><th>Discounts</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach($daily_sales as $day): ?>
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
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-crown me-2"></i>Top Products</h5></div>
        <div class="card-body p-0">
            <?php if(empty($top_products)): ?>
            <div class="text-center py-4 text-muted">No products sold.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th>Revenue</th><th>Profit</th><th>Margin</th></tr></thead>
                    <tbody>
                        <?php $rank = 1; foreach($top_products as $p): 
                            $margin = $p['revenue'] > 0 ? ($p['profit'] / $p['revenue']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo $rank++; ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo $p['quantity_sold']; ?></td>
                            <td><?php echo number_format($p['revenue'], 0); ?></td>
                            <td class="text-success"><?php echo number_format($p['profit'], 0); ?></td>
                            <td><span class="badge bg-<?php echo $margin > 30 ? 'success' : ($margin > 10 ? 'warning' : 'danger'); ?>"><?php echo number_format($margin, 1); ?>%</span></td>
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
    <?php if($report_tab == 'profit' && isset($pl_data)): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card"><p class="stat-label">Revenue</p><h4 class="text-success"><?php echo number_format($pl_data['total_revenue'], 0); ?></h4></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><p class="stat-label">COGS</p><h4 class="text-danger"><?php echo number_format($pl_data['total_cogs'], 0); ?></h4></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><p class="stat-label">Gross Profit</p><h4 class="text-primary"><?php echo number_format($pl_data['gross_profit'], 0); ?></h4></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><p class="stat-label">Net Profit</p><h4 class="<?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($net_profit, 0); ?></h4></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <table class="table">
                <tr><td><strong>Total Revenue</strong></td><td class="text-end"><?php echo number_format($pl_data['total_revenue'], 0); ?> RWF</td></tr>
                <tr><td><strong>Cost of Goods Sold</strong></td><td class="text-end text-danger"><?php echo number_format($pl_data['total_cogs'], 0); ?> RWF</td></tr>
                <tr class="table-success"><td><strong>Gross Profit</strong></td><td class="text-end"><strong><?php echo number_format($pl_data['gross_profit'], 0); ?> RWF</strong></td></tr>
                <tr><td><strong>Total Expenses</strong></td><td class="text-end text-danger"><?php echo number_format($total_expenses, 0); ?> RWF</td></tr>
                <tr class="table-<?php echo $net_profit >= 0 ? 'success' : 'danger'; ?>"><td><strong>Net Profit</strong></td><td class="text-end"><strong><?php echo number_format($net_profit, 0); ?> RWF</strong></td></tr>
                <tr><td><strong>Transactions</strong></td><td class="text-end"><?php echo $pl_data['transactions']; ?></td></tr>
                <tr><td><strong>Total Discounts</strong></td><td class="text-end"><?php echo number_format($pl_data['total_discounts'], 0); ?> RWF</td></tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    CUSTOMER REPORT
    ============================================ -->
    <?php if($report_tab == 'customers'): ?>
    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Top Customers</h5></div>
        <div class="card-body p-0">
            <?php if(empty($top_customers)): ?>
            <div class="text-center py-4 text-muted">No customer data.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Name</th><th>Orders</th><th>Total Spent</th></tr></thead>
                    <tbody>
                        <?php $rank = 1; foreach($top_customers as $c): if($c['total'] > 0): ?>
                        <tr>
                            <td><?php echo $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : ($rank == 3 ? '🥉' : $rank)); ?></td>
                            <td><?php echo htmlspecialchars($c['name']); ?></td>
                            <td><?php echo $c['transactions']; ?></td>
                            <td><strong><?php echo number_format($c['total'], 0); ?></strong></td>
                        </tr>
                        <?php endif; $rank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    END OF DAY REPORT
    ============================================ -->
    <?php if($report_tab == 'eod'): ?>
    <!-- FIX: this used to duplicate end_of_day.php's full history table
         verbatim. Now shows just the last 5 days as a quick glance, with
         a clear link to the real EOD page for the full history and to
         file a new one — one source of truth instead of two identical
         tables living on two different pages. -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>End of Day — Last 5 Days</h5>
            <a href="../end_of_day.php" class="btn btn-sm btn-primary">
                <i class="fas fa-external-link-alt me-1"></i> Open End of Day
            </a>
        </div>
        <div class="card-body p-0">
            <?php if(empty($eod_history)): ?>
            <div class="text-center py-4 text-muted">
                No End of Day records yet.
                <a href="../end_of_day.php" class="alert-link">Complete today's EOD</a>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Status</th><th>Sales</th><th>Net</th></tr></thead>
                    <tbody>
                        <?php foreach(array_slice($eod_history, 0, 5) as $eod):
                            $net = $eod['total_sales'] - $eod['total_expenses'];
                        ?>
                        <tr>
                            <td><strong><?php echo $eod['report_date']; ?></strong></td>
                            <td><span class="badge bg-<?php echo $eod['status'] == 'alert' ? 'danger' : 'success'; ?>"><?php echo strtoupper($eod['status']); ?></span></td>
                            <td><?php echo number_format($eod['total_sales'], 0); ?></td>
                            <td><?php echo number_format($net, 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center py-2">
                <a href="../end_of_day.php" class="small">View full End of Day history &rarr;</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

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
.stat-card h4 { margin: 0; font-weight: 700; }
.stat-card .stat-label { color: #6c757d; font-size: 13px; margin-bottom: 5px; }

/* FIX: no print rules existed before — printing this page printed the
   sidebar, navbar, tab bar, and buttons along with the actual report. */
@media print {
    .sidebar, .navbar-mystock, .nav-tabs, .btn, .no-print {
        display: none !important;
    }
    .main-content {
        padding: 0 !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
        break-inside: avoid;
    }
    .stat-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    body {
        background: white !important;
    }
}
</style>

<?php include '../includes/footer.php'; ?>