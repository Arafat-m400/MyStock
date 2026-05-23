<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isWorker()) redirect('index.php');

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Sales summary (no profit)
$sales_total = $pdo->prepare("SELECT SUM(grand_total) as total_sales, COUNT(*) as invoice_count FROM sales WHERE sale_date BETWEEN ? AND ?");
$sales_total->execute([$start, $end]);
$sales_data = $sales_total->fetch();

// Low stock
$low_stock = $pdo->query("SELECT * FROM products WHERE quantity <= reorder_level")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Worker Reports</h2>
    <form method="GET" class="row g-2 mb-4">
        <div class="col-auto"><input type="date" name="start" value="<?php echo $start; ?>" class="form-control"></div>
        <div class="col-auto"><input type="date" name="end" value="<?php echo $end; ?>" class="form-control"></div>
        <div class="col-auto"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
    
    <div class="row mb-4">
        <div class="col-md-6"><div class="card text-white bg-info"><div class="card-body"><h5>Total Sales (Period)</h5><h2><?php echo number_format($sales_data['total_sales'],2); ?></h2><small><?php echo $sales_data['invoice_count']; ?> invoices</small></div></div></div>
        <div class="col-md-6"><div class="card text-white bg-warning"><div class="card-body"><h5>Low Stock Products</h5><h2><?php echo count($low_stock); ?></h2><small>Need reorder</small></div></div></div>
    </div>
    
    <div class="card"><div class="card-header">Low Stock List</div><div class="card-body"><table class="table"><thead><tr><th>Product</th><th>Stock</th><th>Reorder Level</th></tr></thead><tbody><?php foreach($low_stock as $p): ?><tr class="text-danger"><td><?php echo htmlspecialchars($p['name']); ?></td><td><?php echo $p['quantity']; ?></td><td><?php echo $p['reorder_level']; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php include 'includes/footer.php'; ?>