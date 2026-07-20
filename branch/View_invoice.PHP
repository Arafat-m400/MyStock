<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$sale_id = $_GET['id'] ?? 0;

// FIX: view_invoice.php never existed at all - it was linked from
// sales.php, dashboard.php, customers.php, and products.php, but 404'd
// every time. Built here from scratch, using s.* so it stays tolerant
// of any columns added to `sales` since this was written.
$stmt = $pdo->prepare("
    SELECT s.*, 
           c.name as customer_name, c.phone as customer_phone,
           u.full_name as cashier_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.id = ? AND s.branch_id = ?
");
$stmt->execute([$sale_id, $branch_id]);
$sale = $stmt->fetch();

if (!$sale) {
    die("Invoice not found.");
}

$items = $pdo->prepare("
    SELECT si.*, p.name as product_name, p.unit
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
");
$items->execute([$sale_id]);
$items = $items->fetchAll();

// Company/branch info for the invoice header
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
    <div>
        <h2><i class="fas fa-file-invoice me-2 text-primary"></i>Invoice <?php echo htmlspecialchars($sale['invoice_no']); ?></h2>
        <p class="text-muted"><?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch</p>
    </div>
    <div>
        <a href="sales.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Sales
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print me-1"></i> Print
        </button>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-4">

        <!-- Invoice header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h4 class="mb-1"><?php echo htmlspecialchars($settings['company_name'] ?? 'MyStock'); ?></h4>
                <?php if(!empty($settings['company_address'])): ?>
                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></p>
                <?php endif; ?>
                <?php if(!empty($settings['company_phone'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($settings['company_phone']); ?></p>
                <?php endif; ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch</p>
            </div>
            <div class="col-md-6 text-md-end">
                <h3 class="text-primary">INVOICE</h3>
                <p class="mb-0"><strong>Invoice #:</strong> <?php echo htmlspecialchars($sale['invoice_no']); ?></p>
                <p class="mb-0"><strong>Date:</strong> <?php echo $sale['sale_date']; ?></p>
                <p class="mb-0"><strong>Cashier:</strong> <?php echo htmlspecialchars($sale['cashier_name'] ?? 'System'); ?></p>
            </div>
        </div>

        <!-- Customer info -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h6 class="text-muted">Billed To</h6>
                <p class="mb-0"><strong><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?></strong></p>
                <?php if(!empty($sale['customer_phone'])): ?>
                <p class="mb-0"><?php echo htmlspecialchars($sale['customer_phone']); ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <h6 class="text-muted">Payment</h6>
                <p class="mb-0">
                    Cash: <?php echo number_format($sale['cash_amount'] ?? 0, 0); ?> RWF
                    <?php if(($sale['mobile_money_amount'] ?? 0) > 0): ?>
                    <br>Mobile Money: <?php echo number_format($sale['mobile_money_amount'], 0); ?> RWF
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Items table -->
        <div class="table-container">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $i => $item): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            <?php if(!empty($item['unit'])): ?>
                            <small class="text-muted">(<?php echo htmlspecialchars($item['unit']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo number_format($item['selling_price'], 0); ?></td>
                        <td class="text-end"><strong><?php echo number_format($item['subtotal'], 0); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" class="text-end">Subtotal:</th>
                        <th class="text-end"><?php echo number_format($sale['subtotal'], 0); ?> RWF</th>
                    </tr>
                    <?php if(($sale['discount'] ?? 0) > 0): ?>
                    <tr>
                        <th colspan="4" class="text-end text-danger">Discount:</th>
                        <th class="text-end text-danger">-<?php echo number_format($sale['discount'], 0); ?> RWF</th>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-success">
                        <th colspan="4" class="text-end">Grand Total:</th>
                        <th class="text-end"><?php echo number_format($sale['grand_total'], 0); ?> RWF</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="text-center text-muted mt-4">
            <small>Thank you for your business!</small>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar-mystock, .no-print { display: none !important; }
    .main-content { padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    body { background: white !important; }
}
</style>

</div>
<?php include '../includes/footer.php'; ?>