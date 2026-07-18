<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$supplier_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND branch_id = ?");
$stmt->execute([$supplier_id, $branch_id]);
$supplier = $stmt->fetch();

if (!$supplier) { die("Supplier not found."); }

// ── All POs / advances with this supplier ────────────────────────────────────
$pos = $pdo->prepare("
    SELECT * FROM purchase_orders
    WHERE supplier_id = ? AND branch_id = ?
    ORDER BY created_at DESC
");
$pos->execute([$supplier_id, $branch_id]);
$pos = $pos->fetchAll();

// ── Totals ─────────────────────────────────────────────────────────────────
$total_formal   = 0;
$total_advanced = 0;
$total_goods_value = 0; // value of everything actually received from them
$outstanding_receivable = 0; // they owe us
$outstanding_payable    = 0; // we owe them

foreach ($pos as $po) {
    if ($po['po_type'] === 'formal') {
        $total_formal += $po['total_amount'];
        $total_goods_value += $po['total_amount'];
    } else {
        $total_advanced += $po['advance_amount'];
        $total_goods_value += $po['total_amount']; // goods value received against advance
        if ($po['balance_direction'] === 'supplier_owes_us') $outstanding_receivable += $po['balance'];
        if ($po['balance_direction'] === 'we_owe_supplier')  $outstanding_payable += $po['balance'];
    }
}

// ── Product IDs ever supplied by this supplier ───────────────────────────────
$product_ids_stmt = $pdo->prepare("
    SELECT DISTINCT poi.product_id
    FROM purchase_order_items poi
    JOIN purchase_orders po ON poi.po_id = po.id
    WHERE po.supplier_id = ? AND po.branch_id = ?
");
$product_ids_stmt->execute([$supplier_id, $branch_id]);
$product_ids = array_column($product_ids_stmt->fetchAll(), 'product_id');

// ── Revenue generated from products this supplier has ever supplied ─────────
// NOTE: this is an approximation — products.cost_price is a blended weighted
// average across ALL suppliers/purchases over time, not tracked per-supplier.
// So this shows revenue from THEIR products, not a mathematically pure
// "profit from this supplier" figure.
$revenue_from_their_products = 0;
$units_sold_of_their_products = 0;
if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $rev_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.subtotal),0) as revenue, COALESCE(SUM(si.quantity),0) as units
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE si.product_id IN ($placeholders) AND s.branch_id = ?
    ");
    $rev_stmt->execute([...$product_ids, $branch_id]);
    $rev = $rev_stmt->fetch();
    $revenue_from_their_products = $rev['revenue'];
    $units_sold_of_their_products = $rev['units'];
}

// ── Product-level breakdown ───────────────────────────────────────────────────
$product_breakdown = [];
if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $pb_stmt = $pdo->prepare("
        SELECT p.id, p.name, p.unit,
               COALESCE((SELECT SUM(poi.quantity_received) FROM purchase_order_items poi
                         JOIN purchase_orders po ON poi.po_id = po.id
                         WHERE poi.product_id = p.id AND po.supplier_id = ?), 0) as qty_supplied,
               COALESCE((SELECT SUM(si.quantity) FROM sale_items si
                         JOIN sales s ON si.sale_id = s.id
                         WHERE si.product_id = p.id AND s.branch_id = ?), 0) as qty_sold_overall
        FROM products p
        WHERE p.id IN ($placeholders)
    ");
    $pb_stmt->execute([$supplier_id, $branch_id, ...$product_ids]);
    $product_breakdown = $pb_stmt->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-building me-2 text-primary"></i><?php echo htmlspecialchars($supplier['name']); ?></h2>
        <p class="text-muted">
            <?php if($supplier['contact_person']): ?><?php echo htmlspecialchars($supplier['contact_person']); ?> · <?php endif; ?>
            <?php if($supplier['phone']): ?><a href="tel:<?php echo $supplier['phone']; ?>"><?php echo $supplier['phone']; ?></a><?php endif; ?>
        </p>
    </div>
    <div>
        <a href="suppliers.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Suppliers
        </a>
        <a href="purchase_orders.php?tab=create" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> New Order / Advance
        </a>
    </div>
</div>

<!-- ── Summary stats ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-primary"><?php echo number_format($total_formal + $total_advanced, 0); ?></h4>
            <p class="stat-label">Total Traded (RWF)</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-success"><?php echo number_format($total_goods_value, 0); ?></h4>
            <p class="stat-label">Goods Value Received</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="<?php echo $outstanding_receivable > 0 ? 'text-danger' : 'text-muted'; ?>">
                <?php echo number_format($outstanding_receivable, 0); ?>
            </h4>
            <p class="stat-label">They Owe Us</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="<?php echo $outstanding_payable > 0 ? 'text-warning' : 'text-muted'; ?>">
                <?php echo number_format($outstanding_payable, 0); ?>
            </h4>
            <p class="stat-label">We Owe Them</p>
        </div>
    </div>
</div>

<!-- ── Revenue from their products (with honesty caveat) ───────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Revenue From Products They've Supplied</h5>
    </div>
    <div class="card-body">
        <div class="row text-center mb-2">
            <div class="col-md-6">
                <h3 class="text-success"><?php echo number_format($revenue_from_their_products, 0); ?> RWF</h3>
                <p class="text-muted mb-0">Total revenue from these products</p>
            </div>
            <div class="col-md-6">
                <h3><?php echo number_format($units_sold_of_their_products); ?></h3>
                <p class="text-muted mb-0">Units sold</p>
            </div>
        </div>
        <div class="alert alert-secondary small mb-0">
            <i class="fas fa-info-circle me-1"></i>
            This shows revenue from products this supplier has ever supplied — not a pure
            "profit from this supplier" figure. Your product costs are tracked as one blended
            average price per product across all suppliers over time, not per-supplier, so if
            you've also restocked the same product from someone else, this number mixes both.
        </div>
    </div>
</div>

<!-- ── Product-level breakdown ───────────────────────────────────────────────── -->
<?php if(!empty($product_breakdown)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Products Supplied</h5></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Product</th><th>Qty Supplied by Them</th><th>Total Units Sold (all sources)</th></tr>
            </thead>
            <tbody>
                <?php foreach($product_breakdown as $pb): ?>
                <tr>
                    <td><?php echo htmlspecialchars($pb['name']); ?></td>
                    <td><?php echo number_format($pb['qty_supplied']); ?> <?php echo htmlspecialchars($pb['unit']); ?></td>
                    <td><?php echo number_format($pb['qty_sold_overall']); ?> <?php echo htmlspecialchars($pb['unit']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Full history ───────────────────────────────────────────────────────────── -->
<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Full History</h5></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Ref #</th><th>Type</th><th>Date</th><th>Amount</th><th>Balance</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php if(empty($pos)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No trade history with this supplier yet.</td></tr>
            <?php else: foreach($pos as $po):
                $status_class = ['pending'=>'warning','partial'=>'info','completed'=>'success','cancelled'=>'danger'][$po['status']];
                $is_advance = $po['po_type'] === 'advance';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                    <td>
                        <?php if($is_advance): ?>
                            <span class="badge bg-success">Advance</span>
                        <?php else: ?>
                            <span class="badge bg-primary">Formal</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $po['order_date']; ?></td>
                    <td><?php echo number_format($is_advance ? $po['advance_amount'] : $po['total_amount'], 0); ?></td>
                    <td>
                        <?php if($is_advance && $po['balance'] > 0): ?>
                            <?php echo $po['balance_direction']==='supplier_owes_us' ? 'They owe: ' : 'We owe: '; ?>
                            <?php echo number_format($po['balance'],0); ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo strtoupper($po['status']); ?></span></td>
                    <td><a href="view_po.php?id=<?php echo $po['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fas fa-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.stat-card{background:white;padding:15px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
.stat-card h4{margin:0;font-weight:700;}
.stat-card .stat-label{color:#6c757d;font-size:13px;margin-top:5px;}
</style>
</div>
<?php include '../includes/footer.php'; ?>