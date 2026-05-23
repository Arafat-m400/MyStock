<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Profit & Loss
$stmt = $pdo->prepare("SELECT SUM(si.quantity * (si.selling_price - si.cost_price_at_sale)) as gross_profit, SUM(si.subtotal) as total_revenue, SUM(si.quantity * si.cost_price_at_sale) as total_cogs FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
$profit_data = $stmt->fetch();

// Most buyers (customers ranking)
$top_customers = $pdo->prepare("SELECT c.id, c.name, c.phone, SUM(s.grand_total) as total_spent, COUNT(s.id) as invoice_count FROM customers c JOIN sales s ON c.id = s.customer_id WHERE s.sale_date BETWEEN ? AND ? GROUP BY c.id ORDER BY total_spent DESC LIMIT 10");
$top_customers->execute([$start_date, $end_date]);
$top_customers = $top_customers->fetchAll();

// Product trading rate (quantity sold, avg price, turnover)
$product_trading = $pdo->prepare("SELECT p.id, p.name, p.unit, SUM(si.quantity) as total_sold, AVG(si.selling_price) as avg_selling_price, (SUM(si.quantity) / (SELECT AVG(quantity) FROM products WHERE id = p.id)) as turnover_rate FROM sale_items si JOIN products p ON si.product_id = p.id JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY p.id ORDER BY total_sold DESC LIMIT 20");
$product_trading->execute([$start_date, $end_date]);
$product_trading = $product_trading->fetchAll();

// Low stock report
$low_stock = $pdo->query("SELECT * FROM products WHERE quantity <= reorder_level ORDER BY quantity ASC")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Advanced Reports</h2>
    
    <form method="GET" class="row g-3 mb-4">
        <div class="col-auto"><label>From:</label><input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control"></div>
        <div class="col-auto"><label>To:</label><input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control"></div>
        <div class="col-auto align-self-end"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
    
    <!-- Profit & Loss -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><h5>Profit & Loss (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h5></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4"><strong>Total Revenue:</strong> <?php echo number_format($profit_data['total_revenue'], 2); ?></div>
                <div class="col-md-4"><strong>COGS:</strong> <?php echo number_format($profit_data['total_cogs'], 2); ?></div>
                <div class="col-md-4"><strong>Gross Profit:</strong> <?php echo number_format($profit_data['gross_profit'], 2); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Top Buyers Ranking -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><h5>Top Customers (Most Buyers)</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Rank</th><th>Name</th><th>Phone</th><th>Total Spent</th><th>Invoices</th></tr></thead>
                    <tbody>
                        <?php $rank=1; foreach($top_customers as $c): ?>
                        <tr><td><?php echo $rank++; ?></td><td><?php echo htmlspecialchars($c['name']); ?></td><td><?php echo $c['phone']; ?></td><td><?php echo number_format($c['total_spent'], 2); ?></td><td><?php echo $c['invoice_count']; ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Product Trading Rate -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><h5>Product Trading Rate (Sales Performance)</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Product</th><th>Total Sold</th><th>Avg Selling Price</th><th>Turnover Rate</th></tr></thead>
                    <tbody>
                        <?php foreach($product_trading as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo $p['total_sold']; ?> <?php echo $p['unit']; ?></td>
                            <td><?php echo number_format($p['avg_selling_price'], 2); ?></td>
                            <td><?php echo round($p['turnover_rate'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Report -->
    <div class="card">
        <div class="card-header bg-warning"><h5>Low Stock Products</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Product</th><th>Current Stock</th><th>Reorder Level</th></tr></thead>
                    <tbody>
                        <?php foreach($low_stock as $p): ?>
                        <tr class="text-danger"><td><?php echo htmlspecialchars($p['name']); ?></td><td><?php echo $p['quantity']; ?></td><td><?php echo $p['reorder_level']; ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>