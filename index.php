<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

// Get dashboard stats
$total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_sales = $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= reorder_level")->fetchColumn();

// Recent sales
$recent_sales = $pdo->query("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.created_at DESC LIMIT 5")->fetchAll();

// Low stock products
$low_stock_products = $pdo->query("SELECT * FROM products WHERE quantity <= reorder_level LIMIT 10")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2 class="mb-4">Dashboard</h2>
    
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card card-stats bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Products</h5>
                    <h2><?php echo $total_products; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card card-stats bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Sales</h5>
                    <h2><?php echo $total_sales; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card card-stats bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Customers</h5>
                    <h2><?php echo $total_customers; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card card-stats <?php echo $low_stock_count > 0 ? 'bg-warning' : 'bg-secondary'; ?> text-white">
                <div class="card-body">
                    <h5 class="card-title">Low Stock Alerts</h5>
                    <h2><?php echo $low_stock_count; ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <?php if($low_stock_count > 0): ?>
    <div class="alert alert-low-stock mb-4">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Low Stock Alert:</strong> You have <?php echo $low_stock_count; ?> product(s) that need reordering.
    </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white">
                    <h5>Recent Sales</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Total</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_sales as $sale): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sale['invoice_no']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td><?php echo $sale['sale_date']; ?></td>
                                    <td><?php echo number_format($sale['grand_total'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white">
                    <h5>Low Stock Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Product</th><th>Current Stock</th><th>Reorder Level</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($low_stock_products as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td class="text-danger"><?php echo $product['quantity']; ?></td>
                                    <td><?php echo $product['reorder_level']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>