<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isWorker()) redirect('index.php');

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Worker's own sales summary (not all sales)
$stmt = $pdo->prepare(
    "SELECT SUM(grand_total) as total_sales, COUNT(*) as invoice_count 
     FROM sales 
     WHERE sale_date BETWEEN ? AND ? AND created_by = ?"
);
$stmt->execute([$start, $end, $_SESSION['user_id']]);
$sales_data = $stmt->fetch();

// Worker's own daily sales chart data
$daily = $pdo->prepare(
    "SELECT sale_date, COUNT(*) as count, SUM(grand_total) as total 
     FROM sales 
     WHERE sale_date BETWEEN ? AND ? AND created_by = ? 
     GROUP BY sale_date 
     ORDER BY sale_date"
);
$daily->execute([$start, $end, $_SESSION['user_id']]);
$daily_sales = $daily->fetchAll();

// Worker's top products sold
$top_products = $pdo->prepare(
    "SELECT p.name, SUM(si.quantity) as qty_sold, SUM(si.subtotal) as revenue
     FROM sale_items si
     JOIN sales s ON si.sale_id = s.id
     JOIN products p ON si.product_id = p.id
     WHERE s.sale_date BETWEEN ? AND ? AND s.created_by = ?
     GROUP BY p.id
     ORDER BY revenue DESC
     LIMIT 10"
);
$top_products->execute([$start, $end, $_SESSION['user_id']]);
$top_products = $top_products->fetchAll();

// Low stock (same for everyone - this is just inventory status)
$low_stock = $pdo->query("SELECT * FROM products WHERE quantity <= reorder_level")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>My Performance Reports</h2>
    <p class="text-muted">Showing data for: <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong></p>
    
    <form method="GET" class="row g-2 mb-4">
        <div class="col-auto"><input type="date" name="start" value="<?php echo $start; ?>" class="form-control"></div>
        <div class="col-auto"><input type="date" name="end" value="<?php echo $end; ?>" class="form-control"></div>
        <div class="col-auto"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5>My Total Sales</h5>
                    <h2><?php echo number_format($sales_data['total_sales'], 2); ?> RWF</h2>
                    <small><?php echo $sales_data['invoice_count']; ?> invoices</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5>Avg. Daily Sale</h5>
                    <?php 
                    $days = max(1, (strtotime($end) - strtotime($start)) / 86400 + 1);
                    $avg = ($sales_data['total_sales'] ?? 0) / $days;
                    ?>
                    <h2><?php echo number_format($avg, 2); ?> RWF</h2>
                    <small>per day</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5>Low Stock Alerts</h5>
                    <h2><?php echo count($low_stock); ?></h2>
                    <small>Products need reorder</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Daily Sales Chart (simple table) -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5>My Daily Sales</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Date</th><th>Invoices</th><th>Total Sales</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($daily_sales as $day): ?>
                        <tr>
                            <td><?php echo $day['sale_date']; ?></td>
                            <td><?php echo $day['count']; ?></td>
                            <td><?php echo number_format($day['total'], 2); ?> RWF</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($daily_sales)): ?>
                        <tr><td colspan="3" class="text-center">No sales in this period</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Top Products I Sold -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5>Top Products I Sold</h5>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr><th>Product</th><th>Quantity Sold</th><th>Revenue Generated</th></tr>
                </thead>
                <tbody>
                    <?php foreach($top_products as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['name']); ?></td>
                        <td><?php echo $p['qty_sold']; ?></td>
                        <td><?php echo number_format($p['revenue'], 2); ?> RWF</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Low Stock List -->
    <div class="card">
        <div class="card-header bg-warning">
            <h5>Low Stock Products (Need Reorder)</h5>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr><th>Product</th><th>Current Stock</th><th>Reorder Level</th></tr>
                </thead>
                <tbody>
                    <?php foreach($low_stock as $p): ?>
                    <tr class="text-danger">
                        <td><?php echo htmlspecialchars($p['name']); ?></td>
                        <td><?php echo $p['quantity']; ?></td>
                        <td><?php echo $p['reorder_level']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($low_stock)): ?>
                    <tr><td colspan="3" class="text-center text-success">All stock levels are healthy!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>