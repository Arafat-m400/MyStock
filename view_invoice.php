<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT s.*, c.name as customer_name, c.phone, u.full_name as cashier FROM sales s LEFT JOIN customers c ON s.customer_id = c.id LEFT JOIN users u ON s.created_by = u.id WHERE s.id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();
if(!$sale) die("Invoice not found");

$items = $pdo->prepare("SELECT si.*, p.name as product_name FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Invoice <?php echo $sale['invoice_no']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print { .no-print { display: none; } }
        .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="invoice-box">
        <div class="text-center mb-4">
            <h2><?php echo htmlspecialchars($settings['company_name']); ?></h2>
            <p><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?><br>Tel: <?php echo $settings['company_phone']; ?></p>
            <h4>TAX INVOICE</h4>
        </div>
        <div class="row mb-3">
            <div class="col-6">
                <strong>Invoice #:</strong> <?php echo $sale['invoice_no']; ?><br>
                <strong>Date:</strong> <?php echo $sale['sale_date']; ?><br>
                <strong>Cashier:</strong> <?php echo htmlspecialchars($sale['cashier']); ?>
            </div>
            <div class="col-6 text-end">
                <strong>Customer:</strong> <?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?><br>
                <strong>Phone:</strong> <?php echo $sale['phone'] ?? '-'; ?>
            </div>
        </div>
        <table class="table table-bordered">
            <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td><?php echo number_format($item['selling_price'], 2); ?></td>
                    <td><?php echo number_format($item['subtotal'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="3" class="text-end">Subtotal:</th><th><?php echo number_format($sale['subtotal'], 2); ?></th></tr>
                <tr><th colspan="3" class="text-end">Discount:</th><th><?php echo number_format($sale['discount'], 2); ?></th></tr>
                <tr><th colspan="3" class="text-end">Grand Total:</th><th><?php echo number_format($sale['grand_total'], 2); ?></th></tr>
            </tfoot>
        </table>
        <div class="text-center mt-4">
            <p>Thank you for your business!</p>
            <button class="btn btn-primary no-print" onclick="window.print()">Print Invoice</button>
            <a href="sales.php" class="btn btn-secondary no-print">Back to Sales</a>
        </div>
    </div>
</div>
</body>
</html>