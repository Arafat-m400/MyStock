<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$po_id = $_GET['id'] ?? 0;

// Get PO details
$stmt = $pdo->prepare("
    SELECT po.*, 
           s.name as supplier_name,
           s.phone as supplier_phone,
           s.whatsapp as supplier_whatsapp,
           s.email as supplier_email,
           s.address as supplier_address,
           u.full_name as created_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = ? AND po.branch_id = ?
");
$stmt->execute([$po_id, $branch_id]);
$po = $stmt->fetch();

if (!$po) {
    die("Purchase Order not found.");
}

// Get PO items
$items = $pdo->prepare("
    SELECT poi.*, p.name as product_name, p.unit
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.po_id = ?
");
$items->execute([$po_id]);
$items = $items->fetchAll();

// Calculate totals
$total_ordered = 0;
$total_received = 0;
foreach ($items as $item) {
    $total_ordered += $item['quantity_ordered'];
    $total_received += $item['quantity_received'];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-file-purchase me-2 text-primary"></i>Purchase Order Details</h2>
        <p class="text-muted">
            <?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch
        </p>
    </div>
    <div>
        <a href="purchase_orders.php?tab=list" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to POs
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <?php if($po['status'] != 'completed' && $po['status'] != 'cancelled'): ?>
            <?php if($po['po_type'] === 'advance'): ?>
            <!-- FIX: advances route to ?deliver=, not ?receive= — the old
                 link went nowhere for advance-type POs because that
                 endpoint only ever built a modal for formal orders. -->
            <a href="purchase_orders.php?deliver=<?php echo $po['id']; ?>" class="btn btn-success">
                <i class="fas fa-box me-1"></i> Record Delivery
            </a>
            <a href="purchase_orders.php?topup=<?php echo $po['id']; ?>" class="btn btn-outline-success">
                <i class="fas fa-plus me-1"></i> Top Up
            </a>
            <?php else: ?>
            <a href="purchase_orders.php?receive=<?php echo $po['id']; ?>" class="btn btn-success">
                <i class="fas fa-box me-1"></i> Receive Items
            </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if($po['supplier_whatsapp']):
            $wa_total = $po['po_type'] === 'advance' ? $po['advance_amount'] : $po['total_amount'];
            $wa_label = $po['po_type'] === 'advance' ? 'Advance Given' : 'Total';
        ?>
        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $po['supplier_whatsapp']); ?>?text=<?php echo urlencode("PO: {$po['po_number']}\nSupplier: {$po['supplier_name']}\n$wa_label: " . number_format($wa_total, 0) . " RWF\n\nPlease confirm receipt."); ?>" 
           target="_blank" class="btn btn-success">
            <i class="fab fa-whatsapp me-1"></i> Send WhatsApp
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- PO Details Card -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-file-invoice me-2"></i>
                <?php echo htmlspecialchars($po['po_number']); ?>
            </h5>
            <span class="badge bg-<?php echo [
                'pending' => 'warning',
                'partial' => 'info',
                'completed' => 'success',
                'cancelled' => 'danger'
            ][$po['status']]; ?>">
                <?php echo strtoupper($po['status']); ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td style="width: 120px;"><strong>PO Number</strong></td>
                        <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Order Date</strong></td>
                        <td><?php echo $po['order_date']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Expected Delivery</strong></td>
                        <td><?php echo $po['expected_delivery'] ?: 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Created By</strong></td>
                        <td><?php echo htmlspecialchars($po['created_by_name'] ?? 'System'); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td style="width: 120px;"><strong>Supplier</strong></td>
                        <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                    </tr>
                    <?php if($po['supplier_phone']): ?>
                    <tr>
                        <td><strong>Phone</strong></td>
                        <td><a href="tel:<?php echo $po['supplier_phone']; ?>"><?php echo $po['supplier_phone']; ?></a></td>
                    </tr>
                    <?php endif; ?>
                    <?php if($po['supplier_whatsapp']): ?>
                    <tr>
                        <td><strong>WhatsApp</strong></td>
                        <td>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $po['supplier_whatsapp']); ?>" target="_blank">
                                <?php echo $po['supplier_whatsapp']; ?>
                                <i class="fab fa-whatsapp text-success ms-1"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if($po['supplier_email']): ?>
                    <tr>
                        <td><strong>Email</strong></td>
                        <td><a href="mailto:<?php echo $po['supplier_email']; ?>"><?php echo $po['supplier_email']; ?></a></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <?php if($po['notes']): ?>
        <div class="row mt-2">
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <strong><i class="fas fa-sticky-note me-1"></i> Notes:</strong>
                    <?php echo nl2br(htmlspecialchars($po['notes'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if($po['po_type'] === 'advance'): ?>
<!-- Advance Summary — this PO is a cash advance, not a fixed product order -->
<div class="card shadow-sm mb-4 border-success">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Cash Advance Summary</h5>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-md-4">
                <p class="stat-label mb-1">Advance Given</p>
                <h4 class="text-primary"><?php echo number_format($po['advance_amount'], 0); ?> RWF</h4>
            </div>
            <div class="col-md-4">
                <p class="stat-label mb-1">Goods Value Received So Far</p>
                <h4 class="text-info"><?php echo number_format($po['total_amount'], 0); ?> RWF</h4>
            </div>
            <div class="col-md-4">
                <p class="stat-label mb-1">Balance</p>
                <?php if($po['balance_direction'] === 'settled'): ?>
                <h4 class="text-success">Settled</h4>
                <?php elseif($po['balance_direction'] === 'supplier_owes'): ?>
                <h4 class="text-danger">They owe <?php echo number_format($po['balance'], 0); ?> RWF</h4>
                <?php else: ?>
                <h4 class="text-warning">We owe <?php echo number_format(abs($po['balance']), 0); ?> RWF</h4>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- PO Items Table -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i><?php echo $po['po_type'] === 'advance' ? 'Goods Received' : 'Items Ordered'; ?></h5>
        <span class="badge bg-secondary"><?php echo count($items); ?> items</span>
    </div>
    <div class="card-body p-0">
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th style="text-align: center;">Ordered</th>
                        <th style="text-align: center;">Received</th>
                        <th style="text-align: right;">Unit Price</th>
                        <th style="text-align: right;">Subtotal</th>
                        <th style="text-align: center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grand_total = 0;
                    foreach($items as $index => $item):
                        $subtotal = $item['quantity_ordered'] * $item['unit_price'];
                        $grand_total += $subtotal;
                        $remaining = $item['quantity_ordered'] - $item['quantity_received'];
                        $status_class = $remaining == 0 ? 'success' : ($remaining > 0 ? 'warning' : 'danger');
                        $status_text = $remaining == 0 ? 'Complete' : ($remaining > 0 ? 'Pending' : 'Over');
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                            <br><small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                        </td>
                        <td style="text-align: center;"><?php echo $item['quantity_ordered']; ?></td>
                        <td style="text-align: center;"><?php echo $item['quantity_received']; ?></td>
                        <td style="text-align: right;"><?php echo number_format($item['unit_price'], 0); ?></td>
                        <td style="text-align: right;"><strong><?php echo number_format($subtotal, 0); ?></strong></td>
                        <td style="text-align: center;">
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-success">
                        <th colspan="5" class="text-end"><strong>Grand Total:</strong></th>
                        <th style="text-align: right;"><strong><?php echo number_format($grand_total, 0); ?> RWF</strong></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Summary Stats -->
<div class="row mt-4">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-primary"><?php echo count($items); ?></h4>
            <p class="stat-label">Total Items</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-success"><?php echo number_format($total_ordered); ?></h4>
            <p class="stat-label">Total Ordered</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-info"><?php echo number_format($total_received); ?></h4>
            <p class="stat-label">Total Received</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-warning"><?php echo number_format($total_ordered - $total_received); ?></h4>
            <p class="stat-label">Remaining</p>
        </div>
    </div>
</div>

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
</div>
<?php include '../includes/footer.php'; ?>