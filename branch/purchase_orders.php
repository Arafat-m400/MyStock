<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';
$active_tab = $_GET['tab'] ?? 'create';

// ============================================
// GET DATA
// ============================================

$products = $pdo->prepare("SELECT id, name, sku, quantity, cost_price, unit FROM products WHERE branch_id = ? ORDER BY name");
$products->execute([$branch_id]);
$products = $products->fetchAll();

$suppliers = $pdo->prepare("SELECT id, name, phone, whatsapp FROM suppliers WHERE branch_id = ? ORDER BY name");
$suppliers->execute([$branch_id]);
$suppliers = $suppliers->fetchAll();

// ============================================
// CREATE PURCHASE ORDER - DUAL FLOW
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_po'])) {
    try {
        $pdo->beginTransaction();
        
        $po_type = $_POST['po_type'];
        $supplier_id = $_POST['supplier_id'];
        $order_date = $_POST['order_date'];
        $expected_delivery = $_POST['expected_delivery'] ?: null;
        $notes = $_POST['notes'];
        
        if ($po_type == 'formal') {
            // Formal Order - products selected upfront
            $items = json_decode($_POST['items_json'], true);
            if (empty($items)) throw new Exception("No items added to the purchase order.");
            
            $total_amount = 0;
            foreach ($items as $item) {
                $total_amount += $item['quantity'] * $item['unit_price'];
            }
            
            $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    branch_id, po_number, po_type, supplier_id, order_date, 
                    expected_delivery, total_amount, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$branch_id, $po_number, $po_type, $supplier_id, $order_date, 
                           $expected_delivery, $total_amount, $notes, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();
            
            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_order_items (
                        po_id, product_id, quantity_ordered, unit_price, subtotal
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $po_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['quantity'] * $item['unit_price']
                ]);
            }
            
            $success_msg = "✅ Formal Purchase Order Created!";
            
        } else {
            // Cash Advance - no products selected upfront
            $advance_amount = floatval($_POST['advance_amount']);
            if ($advance_amount <= 0) throw new Exception("Advance amount must be greater than 0.");
            
            $po_number = 'ADV-' . date('Ymd') . '-' . rand(1000, 9999);
            
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    branch_id, po_number, po_type, supplier_id, order_date, 
                    expected_delivery, advance_amount, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$branch_id, $po_number, $po_type, $supplier_id, $order_date, 
                           $expected_delivery, $advance_amount, $notes, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();
            
            // Log the advance in topups
            $stmt = $pdo->prepare("
                INSERT INTO po_topups (po_id, amount, notes, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$po_id, $advance_amount, "Initial advance", $_SESSION['user_id']]);
            
            // Update supplier total traded
            $pdo->prepare("UPDATE suppliers SET total_traded = total_traded + ? WHERE id = ?")
                ->execute([$advance_amount, $supplier_id]);
            
            $success_msg = "✅ Cash Advance Created! Supplier now owes you " . number_format($advance_amount, 0) . " RWF in goods.";
        }
        
        $pdo->commit();
        
        // Get supplier for WhatsApp
        $supplier = $pdo->prepare("SELECT name, whatsapp FROM suppliers WHERE id = ?");
        $supplier->execute([$supplier_id]);
        $supp = $supplier->fetch();
        
        logAction($pdo, 'PO Created', "PO: $po_number, Type: $po_type, Total: " . ($total_amount ?? $advance_amount));
        
        $message = '<div class="alert alert-success alert-permanent">
            <i class="fas fa-check-circle me-2"></i>
            <strong>' . $success_msg . '</strong>
            <br>PO Number: <strong>' . $po_number . '</strong>
            <br>Total: ' . number_format($total_amount ?? $advance_amount, 0) . ' RWF
            <br>
            <div class="mt-2">
                <a href="view_po.php?id=' . $po_id . '" target="_blank" class="btn btn-sm btn-info">
                    <i class="fas fa-eye me-1"></i> View PO
                </a>
                ' . (!empty($supp['whatsapp']) && $po_type == 'formal' ? '
                <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $supp['whatsapp']) . '?text=' . urlencode("PO: $po_number\nSupplier: {$supp['name']}\nTotal: " . number_format($total_amount, 0) . " RWF\n\nPlease confirm receipt.") . '" 
                   target="_blank" class="btn btn-sm btn-success">
                    <i class="fab fa-whatsapp me-1"></i> Send to Supplier
                </a>' : '') . '
            </div>
        </div>';
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// TOP UP ADVANCE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['topup_advance'])) {
    $po_id = $_POST['po_id'];
    $amount = floatval($_POST['topup_amount']);
    $notes = sanitize($_POST['topup_notes']);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO po_topups (po_id, amount, notes, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$po_id, $amount, $notes, $_SESSION['user_id']]);
        
        // Update advance_amount in purchase_orders
        $pdo->prepare("UPDATE purchase_orders SET advance_amount = advance_amount + ? WHERE id = ?")
            ->execute([$amount, $po_id]);
        
        // Update supplier total traded
        $supplier = $pdo->prepare("SELECT supplier_id FROM purchase_orders WHERE id = ?");
        $supplier->execute([$po_id]);
        $supplier_id = $supplier->fetchColumn();
        $pdo->prepare("UPDATE suppliers SET total_traded = total_traded + ? WHERE id = ?")
            ->execute([$amount, $supplier_id]);
        
        $pdo->commit();
        
        $message = '<div class="alert alert-success">✅ Top-up added! Advance increased by ' . number_format($amount, 0) . ' RWF.</div>';
        logAction($pdo, 'Advance Top-up', "Added top-up of $amount to PO #$po_id");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// RECEIVE ITEMS (for both formal and advance)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_items'])) {
    try {
        $pdo->beginTransaction();
        
        $po_id = $_POST['po_id'];
        $items_received = json_decode($_POST['received_json'], true);
        
        // Get PO type
        $po_info = $pdo->prepare("SELECT po_type, supplier_id FROM purchase_orders WHERE id = ?");
        $po_info->execute([$po_id]);
        $po = $po_info->fetch();
        
        $total_goods_value = 0;
        $all_received = true;
        $any_received = false;
        
        foreach ($items_received as $item) {
            $received_qty = intval($item['received_qty']);
            $unit_price = floatval($item['unit_price']);
            
            if ($received_qty > 0) {
                $any_received = true;
                $subtotal = $received_qty * $unit_price;
                $total_goods_value += $subtotal;
                
                // Update PO item
                if ($po['po_type'] == 'formal') {
                    $pdo->prepare("
                        UPDATE purchase_order_items 
                        SET quantity_received = quantity_received + ?
                        WHERE id = ?
                    ")->execute([$received_qty, $item['item_id']]);
                } else {
                    // Advance: insert as new item (no quantity_ordered)
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_order_items (
                            po_id, product_id, quantity_ordered, quantity_received, 
                            unit_price, subtotal, is_advance_delivery
                        ) VALUES (?, ?, NULL, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $po_id,
                        $item['product_id'],
                        $received_qty,
                        $unit_price,
                        $subtotal
                    ]);
                }
                
                // Update product stock
                $prod = $pdo->prepare("SELECT quantity, cost_price FROM products WHERE id = ? AND branch_id = ?");
                $prod->execute([$item['product_id'], $branch_id]);
                $current = $prod->fetch();
                
                $new_qty = $current['quantity'] + $received_qty;
                $new_cost = (($current['quantity'] * $current['cost_price']) + ($received_qty * $unit_price)) / $new_qty;
                
                $pdo->prepare("UPDATE products SET quantity = ?, cost_price = ? WHERE id = ? AND branch_id = ?")
                    ->execute([$new_qty, round($new_cost, 2), $item['product_id'], $branch_id]);
                
                // Record in purchases table
                $pdo->prepare("
                    INSERT INTO purchases (branch_id, supplier_id, invoice_no, purchase_date, total_amount, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $branch_id,
                    $po['supplier_id'],
                    'PO-' . $po_id,
                    date('Y-m-d'),
                    $subtotal,
                    $_SESSION['user_id']
                ]);
                
                $purchase_id = $pdo->lastInsertId();
                $pdo->prepare("
                    INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$purchase_id, $item['product_id'], $received_qty, $unit_price, $subtotal]);
                
                if ($po['po_type'] == 'formal' && $received_qty < intval($item['ordered_qty'])) {
                    $all_received = false;
                }
            }
        }
        
        // Update PO status
        if ($po['po_type'] == 'formal') {
            $status = 'pending';
            if ($any_received) {
                $status = $all_received ? 'completed' : 'partial';
            }
            $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$status, $po_id]);
        } else {
            // Advance: calculate balance
            $advance = $pdo->prepare("SELECT advance_amount FROM purchase_orders WHERE id = ?");
            $advance->execute([$po_id]);
            $advance_amount = $advance->fetchColumn();
            
            $balance = $advance_amount - $total_goods_value;
            $direction = $balance >= 0 ? 'supplier_owes_shop' : 'shop_owes_supplier';
            
            $pdo->prepare("
                UPDATE purchase_orders 
                SET status = ?, balance_direction = ?
                WHERE id = ?
            ")->execute([
                $balance == 0 ? 'completed' : ($balance > 0 ? 'partial' : 'completed'),
                $direction,
                $po_id
            ]);
            
            // Update supplier debt
            $pdo->prepare("
                UPDATE suppliers 
                SET total_debt = ?
                WHERE id = ?
            ")->execute([abs($balance), $po['supplier_id']]);
        }
        
        $pdo->commit();
        
        logAction($pdo, 'PO Received', "PO #$po_id received items");
        $message = '<div class="alert alert-success">✅ Items received and stock updated!</div>';
        
        header("Location: purchase_orders.php?tab=list&msg=received");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// CANCEL PO
// ============================================

if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $po_id = $_GET['cancel'];
    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ? AND branch_id = ?")
        ->execute([$po_id, $branch_id]);
    logAction($pdo, 'PO Cancelled', "PO #$po_id cancelled");
    $message = '<div class="alert alert-warning">⚠️ Purchase Order cancelled.</div>';
}

// ============================================
// GET POs FOR LISTING
// ============================================

$pos = $pdo->prepare("
    SELECT po.*, 
           s.name as supplier_name,
           s.whatsapp,
           COUNT(poi.id) as item_count,
           COALESCE(SUM(poi.subtotal), 0) as received_value,
           (SELECT COUNT(*) FROM po_topups WHERE po_id = po.id) as topup_count
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
    WHERE po.branch_id = ?
    GROUP BY po.id
    ORDER BY po.created_at DESC
");
$pos->execute([$branch_id]);
$pos = $pos->fetchAll();

// ============================================
// GET PO FOR RECEIVING
// ============================================

$po_to_receive = null;
$po_items_to_receive = null;

if (isset($_GET['receive']) && is_numeric($_GET['receive'])) {
    $po_id = $_GET['receive'];
    
    $po = $pdo->prepare("
        SELECT po.*, s.name as supplier_name, s.id as supplier_id
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = ? AND po.branch_id = ? AND po.status IN ('pending', 'partial')
    ");
    $po->execute([$po_id, $branch_id]);
    $po_to_receive = $po->fetch();
    
    if ($po_to_receive) {
        if ($po_to_receive['po_type'] == 'formal') {
            $items = $pdo->prepare("
                SELECT poi.*, p.name as product_name, p.unit
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                WHERE poi.po_id = ? AND poi.is_advance_delivery = 0
            ");
            $items->execute([$po_id]);
        } else {
            // FIX: previously dumped every single product in the branch
            // into this list (all showing 0/0), which is what made the
            // advance "Receive" form look like a giant empty checklist.
            // The modal now uses a proper add-product cart instead (like
            // the Formal Order create form), so nothing needs prefetching
            // here - $products is already loaded at the top of the page.
            $items = null;
        }
        $po_items_to_receive = $items ? $items->fetchAll() : [];
    }
}

// ============================================
// GET PO FOR TOP-UP
// ============================================
// FIX: this fetch never existed before. The "Top-up" button linked to
// ?topup=<id> but nothing on the page ever read that parameter, so
// clicking it visibly did nothing at all.
$po_to_topup = null;
if (isset($_GET['topup']) && is_numeric($_GET['topup'])) {
    $stmt = $pdo->prepare("
        SELECT po.*, s.name as supplier_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = ? AND po.branch_id = ? AND po.po_type = 'advance'
    ");
    $stmt->execute([$_GET['topup'], $branch_id]);
    $po_to_topup = $stmt->fetch();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-file-purchase me-2 text-primary"></i>Purchase Orders</h2>
            <p class="text-muted">Create formal orders or cash advances for suppliers</p>
        </div>
        <div>
            <a href="?tab=create" class="btn btn-primary <?php echo $active_tab == 'create' ? 'active' : ''; ?>">
                <i class="fas fa-plus me-1"></i> New PO
            </a>
            <a href="?tab=list" class="btn btn-outline-secondary <?php echo $active_tab == 'list' ? 'active' : ''; ?>">
                <i class="fas fa-list me-1"></i> All POs
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- ============================================
    CREATE PO TAB
    ============================================ -->
    <?php if($active_tab == 'create'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Create Purchase Order</h5>
        </div>
        <div class="card-body">
            <form method="POST" id="poForm">
                <!-- PO Type Selection -->
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">PO Type *</label>
                        <select name="po_type" id="po_type" class="form-select" onchange="togglePOType()" required>
                            <option value="formal">📋 Formal Order</option>
                            <option value="advance">💰 Cash Advance</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Supplier *</label>
                        <select name="supplier_id" id="supplier_id" class="form-select" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                                <?php if($s['whatsapp']): ?> (WA: <?php echo $s['whatsapp']; ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Order Date</label>
                        <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Expected Delivery</label>
                        <input type="date" name="expected_delivery" class="form-control">
                    </div>
                </div>
                
                <!-- Formal Order Section -->
                <div id="formal_section">
                    <div class="mb-3">
                        <label class="form-label">Add Product</label>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select id="po_product_select" class="form-select" onchange="onPOProductSelect(this)">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                            data-price="<?php echo $p['cost_price']; ?>"
                                            data-unit="<?php echo $p['unit']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> 
                                        (Cost: <?php echo number_format($p['cost_price'], 0); ?> RWF)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" id="po_qty" class="form-control" placeholder="Qty" min="1" value="1">
                            </div>
                            <div class="col-md-3">
                                <input type="number" id="po_unit_price" class="form-control" placeholder="Unit Price" step="100" min="0">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-secondary w-100" onclick="addPOItem()">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        <div id="po_selected_display" class="mt-2 small"></div>
                    </div>
                    
                    <!-- PO Items Table -->
                    <div class="table-container mb-3">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th style="width:100px">Qty</th>
                                    <th style="width:140px">Unit Price</th>
                                    <th style="width:140px">Subtotal</th>
                                    <th style="width:50px"></th>
                                </tr>
                            </thead>
                            <tbody id="po_cart_body">
                                <tr id="po_empty_row">
                                    <td colspan="5" class="text-center text-muted py-3">
                                        <i class="fas fa-plus-circle me-2"></i> Add products to the purchase order
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-success">
                                    <th colspan="3" class="text-end"><strong>Total:</strong></th>
                                    <th id="po_total_display"><strong>0 RWF</strong></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                
                <!-- Cash Advance Section -->
                <div id="advance_section" style="display:none;">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Cash Advance:</strong> Give money to supplier, they will bring goods later. 
                        Record what they bring back when received.
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Advance Amount (RWF) *</label>
                            <input type="number" name="advance_amount" id="advance_amount" class="form-control" 
                                   min="0" step="100" placeholder="e.g., 50000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purpose / Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="What is this advance for?">
                        </div>
                    </div>
                </div>
                
                <input type="hidden" name="items_json" id="po_items_json">
                
                <button type="submit" name="create_po" class="btn btn-success btn-lg w-100 mt-3">
                    <i class="fas fa-save me-2"></i> Create Purchase Order
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    LIST POS TAB
    ============================================ -->
    <?php if($active_tab == 'list'): ?>
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Purchase Orders</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>PO #</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Received</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pos)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No purchase orders created yet.</td></tr>
                        <?php else: ?>
                        <?php foreach($pos as $po): 
                            $status_class = [
                                'pending' => 'warning',
                                'partial' => 'info',
                                'completed' => 'success',
                                'cancelled' => 'danger'
                            ][$po['status']];
                            
                            $type_label = $po['po_type'] == 'formal' ? '📋 Formal' : '💰 Advance';
                            $balance_text = '';
                            if ($po['po_type'] == 'advance' && $po['status'] != 'completed' && $po['status'] != 'cancelled') {
                                $balance = $po['advance_amount'] - $po['received_value'];
                                if ($balance > 0) {
                                    $balance_text = ' | Balance: ' . number_format($balance, 0) . ' RWF';
                                }
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                            <td><?php echo $type_label; ?></td>
                            <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                            <td><?php echo $po['order_date']; ?></td>
                            <td>
                                <?php 
                                $total = $po['po_type'] == 'formal' ? $po['total_amount'] : $po['advance_amount'];
                                echo number_format($total, 0);
                                ?>
                                <?php echo $balance_text; ?>
                            </td>
                            <td><?php echo number_format($po['received_value'], 0); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo strtoupper($po['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($po['status'] != 'completed' && $po['status'] != 'cancelled'): ?>
                                <a href="?receive=<?php echo $po['id']; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-box"></i> Receive
                                </a>
                                <?php if($po['po_type'] == 'advance'): ?>
                                <a href="?topup=<?php echo $po['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-plus"></i> Top-up
                                </a>
                                <?php endif; ?>
                                <a href="?cancel=<?php echo $po['id']; ?>" class="btn btn-sm btn-danger" 
                                   onclick="return confirm('Cancel this PO?')">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                                <a href="view_po.php?id=<?php echo $po['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    RECEIVE MODAL — FIX: this modal never existed before.
    The ?receive= link correctly fetched $po_to_receive in PHP, but
    nothing ever rendered it, so clicking "Receive" visibly did nothing
    for formal orders, and the old advance version (further below,
    now removed) dumped every product in the catalog into a static list.
    ============================================ -->
    <?php if($po_to_receive): ?>
    <div class="modal show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-box me-2"></i>
                        Receive Items — <?php echo htmlspecialchars($po_to_receive['po_number']); ?>
                    </h5>
                    <a href="purchase_orders.php?tab=list" class="btn-close btn-close-white"></a>
                </div>

                <?php if($po_to_receive['po_type'] == 'formal'): ?>
                <!-- FORMAL: receive against the pre-ordered item list -->
                <form method="POST">
                    <div class="modal-body">
                        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po_to_receive['supplier_name']); ?></p>
                        <input type="hidden" name="po_id" value="<?php echo $po_to_receive['id']; ?>">
                        <input type="hidden" name="received_json" id="received_json">
                        <div class="table-container">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr><th>Product</th><th>Ordered</th><th>Received</th><th>To Receive</th><th>Unit Price</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($po_items_to_receive as $item):
                                        $remaining = $item['quantity_ordered'] - $item['quantity_received'];
                                        if($remaining <= 0) continue;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td><?php echo $item['quantity_ordered']; ?></td>
                                        <td><?php echo $item['quantity_received']; ?></td>
                                        <td>
                                            <input type="number" class="form-control receive-qty"
                                                   data-item-id="<?php echo $item['id']; ?>"
                                                   data-product-id="<?php echo $item['product_id']; ?>"
                                                   data-ordered="<?php echo $item['quantity_ordered']; ?>"
                                                   data-price="<?php echo $item['unit_price']; ?>"
                                                   min="0" max="<?php echo $remaining; ?>"
                                                   value="<?php echo $remaining; ?>" style="width:100px;">
                                        </td>
                                        <td><?php echo number_format($item['unit_price'], 0); ?> RWF</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="purchase_orders.php?tab=list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="receive_items" class="btn btn-success" onclick="return prepareFormalReceiveSubmit()">
                            <i class="fas fa-check me-1"></i> Confirm Receipt & Update Stock
                        </button>
                    </div>
                </form>

                <?php else: ?>
                <!-- ADVANCE: build a fresh cart of whatever actually came back,
                     same add-product UX as the Formal create form -->
                <form method="POST" onsubmit="return prepareAdvanceReceiveSubmit()">
                    <div class="modal-body">
                        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po_to_receive['supplier_name']); ?></p>
                        <p><strong>Advance Given:</strong> <?php echo number_format($po_to_receive['advance_amount'], 0); ?> RWF</p>
                        <input type="hidden" name="po_id" value="<?php echo $po_to_receive['id']; ?>">
                        <input type="hidden" name="received_json" id="advance_received_json">

                        <div class="row g-2 mb-2">
                            <div class="col-md-5">
                                <select id="deliver_product_select" class="form-select">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                            data-cost="<?php echo $p['cost_price']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" id="deliver_qty" class="form-control" placeholder="Qty" min="1">
                            </div>
                            <div class="col-md-3">
                                <input type="number" id="deliver_price" class="form-control" placeholder="Unit Value" step="100" min="0">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-secondary w-100" onclick="addDeliveryItem()"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>

                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr><th>Product</th><th>Qty</th><th>Unit Value</th><th>Subtotal</th><th></th></tr>
                            </thead>
                            <tbody id="delivery_cart_body">
                                <tr id="delivery_empty_row"><td colspan="5" class="text-center text-muted py-2">No items added yet — add whatever they actually brought back</td></tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-success">
                                    <th colspan="3" class="text-end">Total value brought back:</th>
                                    <th id="delivery_total_display">0 RWF</th><th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <a href="purchase_orders.php?tab=list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="receive_items" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Confirm & Update Stock
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    TOP-UP MODAL — FIX: this modal never existed before either.
    The POST handler for topup_advance was already there and worked
    fine, but there was no GET-triggered fetch and no modal to show
    the form in, so clicking "Top-up" also did nothing.
    ============================================ -->
    <?php if($po_to_topup): ?>
    <div class="modal show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Top Up Advance</h5>
                    <a href="purchase_orders.php?tab=list" class="btn-close btn-close-white"></a>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po_to_topup['supplier_name']); ?></p>
                        <p><strong>Advance so far:</strong> <?php echo number_format($po_to_topup['advance_amount'], 0); ?> RWF</p>
                        <input type="hidden" name="po_id" value="<?php echo $po_to_topup['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Additional Amount (RWF) *</label>
                            <input type="number" name="topup_amount" class="form-control" min="1" step="any" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" name="topup_notes" class="form-control" placeholder="e.g. So they can keep sourcing more">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="purchase_orders.php?tab=list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="topup_advance" class="btn btn-info text-white">
                            <i class="fas fa-check me-1"></i> Add Top-up
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let poCart = [];
let poSelectedProduct = null;

function togglePOType() {
    const type = document.getElementById('po_type').value;
    document.getElementById('formal_section').style.display = type == 'formal' ? 'block' : 'none';
    document.getElementById('advance_section').style.display = type == 'advance' ? 'block' : 'none';
}

function onPOProductSelect(select) {
    const option = select.options[select.selectedIndex];
    if (!option.value) {
        poSelectedProduct = null;
        document.getElementById('po_selected_display').innerHTML = '';
        return;
    }
    
    poSelectedProduct = {
        id: option.value,
        name: option.dataset.name,
        price: parseFloat(option.dataset.price),
        unit: option.dataset.unit
    };
    
    document.getElementById('po_selected_display').innerHTML = 
        '<span class="badge bg-info">✓ Selected: ' + poSelectedProduct.name + 
        ' (Cost: ' + formatNumber(poSelectedProduct.price) + ' RWF)</span>';
    document.getElementById('po_qty').focus();
}

function addPOItem() {
    if (!poSelectedProduct) {
        alert('Please select a product first.');
        return;
    }
    
    const qty = parseInt(document.getElementById('po_qty').value);
    if (isNaN(qty) || qty < 1) {
        alert('Enter a valid quantity.');
        return;
    }
    
    const unitPrice = parseFloat(document.getElementById('po_unit_price').value);
    if (isNaN(unitPrice) || unitPrice < 0) {
        alert('Enter a valid unit price.');
        return;
    }
    
    const existing = poCart.find(item => item.product_id === poSelectedProduct.id);
    if (existing) {
        existing.quantity += qty;
        existing.unit_price = unitPrice;
    } else {
        poCart.push({
            product_id: poSelectedProduct.id,
            name: poSelectedProduct.name,
            quantity: qty,
            unit_price: unitPrice
        });
    }
    
    renderPOCart();
    document.getElementById('po_qty').value = 1;
    document.getElementById('po_unit_price').value = '';
    document.getElementById('po_product_select').value = '';
    document.getElementById('po_selected_display').innerHTML = '';
    poSelectedProduct = null;
}

function renderPOCart() {
    const tbody = document.getElementById('po_cart_body');
    tbody.innerHTML = '';
    
    if (poCart.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">
            <i class="fas fa-plus-circle me-2"></i> Add products to the purchase order
        </td></tr>`;
        updatePOTotals();
        return;
    }
    
    let total = 0;
    poCart.forEach((item, index) => {
        const subtotal = item.quantity * item.unit_price;
        total += subtotal;
        tbody.innerHTML += `
            <tr>
                <td><strong>${escapeHtml(item.name)}</strong></td>
                <td>
                    <input type="number" min="1" value="${item.quantity}" 
                           class="form-control form-control-sm" style="width:80px;"
                           onchange="updatePOQty(${index}, this.value)">
                </td>
                <td>
                    <input type="number" min="0" step="100" value="${item.unit_price}" 
                           class="form-control form-control-sm" style="width:120px;"
                           onchange="updatePOPrice(${index}, this.value)">
                </td>
                <td><strong>${formatNumber(subtotal)}</strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removePOItem(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    updatePOTotals();
}

function updatePOQty(index, value) {
    const qty = parseInt(value);
    if (!isNaN(qty) && qty > 0) {
        poCart[index].quantity = qty;
        renderPOCart();
    }
}

function updatePOPrice(index, value) {
    const price = parseFloat(value);
    if (!isNaN(price) && price >= 0) {
        poCart[index].unit_price = price;
        renderPOCart();
    }
}

function removePOItem(index) {
    poCart.splice(index, 1);
    renderPOCart();
}

function updatePOTotals() {
    const total = poCart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
    document.getElementById('po_total_display').textContent = formatNumber(total) + ' RWF';
    document.getElementById('po_items_json').value = JSON.stringify(poCart);
}

function formatNumber(n) {
    return new Intl.NumberFormat('en-RW').format(Math.round(n));
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ============================================
// FORMAL RECEIVE — submit handler
// FIX: this modal + its submit logic never existed before.
// ============================================
function prepareFormalReceiveSubmit() {
    const items = [];
    document.querySelectorAll('.receive-qty').forEach(input => {
        const received = parseInt(input.value) || 0;
        if (received > 0) {
            items.push({
                item_id: input.dataset.itemId,
                product_id: input.dataset.productId,
                ordered_qty: input.dataset.ordered,
                received_qty: received,
                unit_price: input.dataset.price
            });
        }
    });
    if (items.length === 0) {
        alert('Please enter quantity for at least one item.');
        return false;
    }
    document.getElementById('received_json').value = JSON.stringify(items);
    return confirm('Confirm receipt of these items? Stock will be updated automatically.');
}

// ============================================
// ADVANCE DELIVERY CART — new, replaces the old full-catalog dump
// ============================================
let deliveryCart = [];

function addDeliveryItem() {
    const sel = document.getElementById('deliver_product_select');
    if (!sel.value) { alert('Select a product first.'); return; }
    const name = sel.options[sel.selectedIndex].dataset.name;
    const cost = parseFloat(sel.options[sel.selectedIndex].dataset.cost) || 0;
    const qty = parseInt(document.getElementById('deliver_qty').value);
    const priceInput = document.getElementById('deliver_price');
    const price = priceInput.value === '' ? cost : parseFloat(priceInput.value);

    if (isNaN(qty) || qty < 1) { alert('Enter a valid quantity.'); return; }
    if (isNaN(price) || price < 0) { alert('Enter a valid unit value.'); return; }

    const existing = deliveryCart.find(i => i.product_id === sel.value);
    if (existing) {
        existing.quantity += qty;
        existing.unit_price = price;
    } else {
        deliveryCart.push({ product_id: sel.value, name, quantity: qty, unit_price: price });
    }

    renderDeliveryCart();
    document.getElementById('deliver_qty').value = '';
    document.getElementById('deliver_price').value = '';
    sel.value = '';
}

function removeDeliveryItem(idx) {
    deliveryCart.splice(idx, 1);
    renderDeliveryCart();
}

function renderDeliveryCart() {
    const tbody = document.getElementById('delivery_cart_body');
    tbody.innerHTML = '';
    if (deliveryCart.length === 0) {
        tbody.innerHTML = `<tr id="delivery_empty_row"><td colspan="5" class="text-center text-muted py-2">No items added yet — add whatever they actually brought back</td></tr>`;
        document.getElementById('delivery_total_display').textContent = '0 RWF';
        return;
    }
    let total = 0;
    deliveryCart.forEach((item, idx) => {
        const sub = item.quantity * item.unit_price;
        total += sub;
        tbody.innerHTML += `<tr>
            <td>${escapeHtml(item.name)}</td>
            <td>
                <input type="number" min="1" value="${item.quantity}" class="form-control form-control-sm"
                       style="width:80px" onchange="updateDeliveryQty(${idx}, this.value)">
            </td>
            <td>
                <input type="number" min="0" step="100" value="${item.unit_price}" class="form-control form-control-sm"
                       style="width:120px" onchange="updateDeliveryPrice(${idx}, this.value)">
            </td>
            <td>${formatNumber(sub)}</td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeDeliveryItem(${idx})"><i class="fas fa-times"></i></button></td>
        </tr>`;
    });
    document.getElementById('delivery_total_display').textContent = formatNumber(total) + ' RWF';
}

function updateDeliveryQty(idx, val)   { const q = parseInt(val);   if (q > 0)  { deliveryCart[idx].quantity = q; renderDeliveryCart(); } }
function updateDeliveryPrice(idx, val) { const p = parseFloat(val); if (p >= 0) { deliveryCart[idx].unit_price = p; renderDeliveryCart(); } }

function prepareAdvanceReceiveSubmit() {
    if (deliveryCart.length === 0) {
        alert('Add at least one product that was brought back.');
        return false;
    }
    // Map to the shape the existing receive_items PHP handler expects
    // for advance-type items: product_id, received_qty, unit_price.
    const payload = deliveryCart.map(item => ({
        product_id: item.product_id,
        received_qty: item.quantity,
        unit_price: item.unit_price
    }));
    document.getElementById('advance_received_json').value = JSON.stringify(payload);
    return true;
}
</script>

<?php include '../includes/footer.php'; ?>