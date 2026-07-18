<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';
$active_tab = $_GET['tab'] ?? 'create';

// ============================================
// GET DATA
// ============================================

$products = $pdo->prepare("
    SELECT id, name, sku, quantity, cost_price, selling_price, unit
    FROM products WHERE branch_id = ? ORDER BY name
");
$products->execute([$branch_id]);
$products = $products->fetchAll();

$suppliers = $pdo->prepare("
    SELECT id, name, phone, whatsapp
    FROM suppliers WHERE branch_id = ? ORDER BY name
");
$suppliers->execute([$branch_id]);
$suppliers = $suppliers->fetchAll();

// ── Helper: recalc balance + direction + supplier_debts row for a PO ────────
function refreshAdvanceBalance(PDO $pdo, int $po_id, int $branch_id) {
    $po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $po->execute([$po_id]);
    $po = $po->fetch();
    if (!$po || $po['po_type'] !== 'advance') return;

    $sum = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM purchase_order_items WHERE po_id = ?");
    $sum->execute([$po_id]);
    $goods_value = (float)$sum->fetchColumn();

    $balance = $po['advance_amount'] - $goods_value;
    // balance > 0  → supplier still owes us goods/cash
    // balance < 0  → they delivered more than we advanced, we owe them
    // balance == 0 → settled
    if ($balance > 0.009)      $direction = 'supplier_owes_us';
    elseif ($balance < -0.009) $direction = 'we_owe_supplier';
    else                       $direction = 'settled';

    $status = $direction === 'settled' ? 'completed' : ($goods_value > 0 ? 'partial' : 'pending');

    $pdo->prepare("
        UPDATE purchase_orders
        SET balance = ?, balance_direction = ?, total_amount = ?, status = ?
        WHERE id = ?
    ")->execute([abs($balance), $direction, $goods_value, $status, $po_id]);

    // Mirror into supplier_debts so it shows up in your debts page too
    $existing = $pdo->prepare("SELECT id FROM supplier_debts WHERE po_id = ?");
    $existing->execute([$po_id]);
    $debt_row = $existing->fetch();

    if ($direction === 'settled') {
        if ($debt_row) {
            $pdo->prepare("UPDATE supplier_debts SET status='paid', remaining=0 WHERE po_id=?")->execute([$po_id]);
        }
    } else {
        $debt_direction = $direction === 'supplier_owes_us' ? 'receivable' : 'payable';
        if ($debt_row) {
            $pdo->prepare("
                UPDATE supplier_debts
                SET amount=?, remaining=?, direction=?, status='partial'
                WHERE po_id=?
            ")->execute([abs($balance), abs($balance), $debt_direction, $po_id]);
        } else {
            $pdo->prepare("
                INSERT INTO supplier_debts (supplier_id, branch_id, po_id, direction, amount, remaining, status)
                VALUES (?,?,?,?,?,?,'partial')
            ")->execute([$po['supplier_id'], $branch_id, $po_id, $debt_direction, abs($balance), abs($balance)]);
        }
    }
}

// ============================================
// CREATE PO — handles both formal & advance
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_po'])) {
    try {
        $pdo->beginTransaction();

        $po_type       = $_POST['po_type'] === 'advance' ? 'advance' : 'formal';
        $supplier_id   = $_POST['supplier_id'];
        $order_date    = $_POST['order_date'];
        $expected_delivery = $_POST['expected_delivery'] ?: null;
        $notes         = $_POST['notes'];
        $po_number     = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);

        if ($po_type === 'formal') {
            $items = json_decode($_POST['items_json'], true);
            if (empty($items)) throw new Exception("No items added to the purchase order.");

            $total_amount = 0;
            foreach ($items as $item) $total_amount += $item['quantity'] * $item['unit_price'];

            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders
                    (branch_id, po_number, po_type, supplier_id, order_date, expected_delivery,
                     total_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$branch_id, $po_number, 'formal', $supplier_id, $order_date,
                             $expected_delivery, $total_amount, $notes, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $pdo->prepare("
                    INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price, subtotal)
                    VALUES (?,?,?,?,?)
                ")->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price'],
                             $item['quantity'] * $item['unit_price']]);
            }

            logAction($pdo, 'PO Created (Formal)', "PO: $po_number, Total: $total_amount RWF");

        } else {
            // ── Cash Advance ──────────────────────────────────────────────
            $advance_amount = floatval($_POST['advance_amount']);
            if ($advance_amount <= 0) throw new Exception("Enter a valid advance amount.");

            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders
                    (branch_id, po_number, po_type, supplier_id, order_date, expected_delivery,
                     advance_amount, balance, balance_direction, notes, created_by, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$branch_id, $po_number, 'advance', $supplier_id, $order_date,
                             $expected_delivery, $advance_amount, $advance_amount,
                             'supplier_owes_us', $notes, $_SESSION['user_id'], 'pending']);
            $po_id = $pdo->lastInsertId();

            refreshAdvanceBalance($pdo, $po_id, $branch_id);
            logAction($pdo, 'PO Created (Advance)', "PO: $po_number, Advance: $advance_amount RWF");
        }

        // ── Build WhatsApp message (both types) ─────────────────────────────
        $supplier = $pdo->prepare("SELECT name, whatsapp FROM suppliers WHERE id = ?");
        $supplier->execute([$supplier_id]);
        $supp = $supplier->fetch();

        if ($po_type === 'formal') {
            $po_items_text = "";
            foreach ($items as $item) {
                $prod = $pdo->prepare("SELECT name, unit FROM products WHERE id = ?");
                $prod->execute([$item['product_id']]);
                $product = $prod->fetch();
                $po_items_text .= "• " . $product['name'] . " - " . $item['quantity'] . " " . $product['unit']
                    . " x " . number_format($item['unit_price'], 0) . " RWF = "
                    . number_format($item['quantity'] * $item['unit_price'], 0) . " RWF\n";
            }
            $whatsapp_message = "📋 *PURCHASE ORDER* 📋\n\n";
            $whatsapp_message .= "*PO #:* $po_number\n*Supplier:* {$supp['name']}\n*Date:* " . date('Y-m-d') . "\n";
            if ($expected_delivery) $whatsapp_message .= "*Expected Delivery:* $expected_delivery\n";
            $whatsapp_message .= "\n*Items Ordered:*\n------------------------\n$po_items_text------------------------\n";
            $whatsapp_message .= "*TOTAL: " . number_format($total_amount, 0) . " RWF*\n\n";
            if (!empty($notes)) $whatsapp_message .= "*Notes:* $notes\n\n";
            $whatsapp_message .= "Please confirm receipt of this order.\nReply 'CONFIRM' to acknowledge.";
        } else {
            $whatsapp_message = "💵 *CASH ADVANCE* 💵\n\n";
            $whatsapp_message .= "*Ref #:* $po_number\n*Supplier:* {$supp['name']}\n*Date:* " . date('Y-m-d') . "\n\n";
            $whatsapp_message .= "*Amount given:* " . number_format($advance_amount, 0) . " RWF\n\n";
            if (!empty($notes)) $whatsapp_message .= "*Notes:* $notes\n\n";
            $whatsapp_message .= "Please bring back goods or confirm this amount. Thank you! 🙏";
        }

        $pdo->prepare("UPDATE purchase_orders SET whatsapp_message = ? WHERE id = ?")
            ->execute([$whatsapp_message, $po_id]);

        $pdo->commit();

        $label = $po_type === 'formal' ? 'Purchase Order' : 'Cash Advance';
        $message = '<div class="alert alert-success alert-permanent">
            <i class="fas fa-check-circle me-2"></i>
            <strong>✅ ' . $label . ' Created!</strong>
            <br>Ref: <strong>' . $po_number . '</strong>
            <div class="mt-2">
                <a href="purchase_orders.php?tab=list" class="btn btn-sm btn-primary">
                    <i class="fas fa-list me-1"></i> View All
                </a>
                ' . (!empty($supp['whatsapp']) ? '
                <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $supp['whatsapp'])
                    . '?text=' . urlencode($whatsapp_message) . '" target="_blank" class="btn btn-sm btn-success">
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
// TOP UP AN OPEN ADVANCE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_topup'])) {
    try {
        $pdo->beginTransaction();
        $po_id  = (int)$_POST['po_id'];
        $amount = floatval($_POST['topup_amount']);
        $notes  = trim($_POST['topup_notes'] ?? '');

        if ($amount <= 0) throw new Exception("Enter a valid top-up amount.");

        $pdo->prepare("INSERT INTO po_topups (po_id, amount, notes, created_by) VALUES (?,?,?,?)")
            ->execute([$po_id, $amount, $notes, $_SESSION['user_id']]);

        $pdo->prepare("UPDATE purchase_orders SET advance_amount = advance_amount + ? WHERE id = ?")
            ->execute([$amount, $po_id]);

        refreshAdvanceBalance($pdo, $po_id, $branch_id);
        $pdo->commit();

        logAction($pdo, 'PO Top-up', "PO #$po_id topped up with $amount RWF");
        header("Location: purchase_orders.php?tab=list&msg=topup");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// RECEIVE ITEMS — handles both formal & advance
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_items'])) {
    try {
        $pdo->beginTransaction();

        $po_id = $_POST['po_id'];
        $items_received = json_decode($_POST['received_json'], true);

        $po_check = $pdo->prepare("SELECT po_type FROM purchase_orders WHERE id = ?");
        $po_check->execute([$po_id]);
        $po_type = $po_check->fetchColumn();

        $all_received = true;
        $any_received = false;

        foreach ($items_received as $item) {
            $received_qty = intval($item['received_qty']);
            if ($received_qty <= 0) {
                if (!empty($item['ordered_qty']) && intval($item['ordered_qty']) > 0) $all_received = false;
                continue;
            }
            $any_received = true;
            $unit_price = floatval($item['unit_price']);

            if ($po_type === 'advance') {
                // No pre-existing line item — insert directly, quantity_ordered = quantity_received
                $pdo->prepare("
                    INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, quantity_received, unit_price, subtotal)
                    VALUES (?,?,?,?,?,?)
                ")->execute([$po_id, $item['product_id'], $received_qty, $received_qty, $unit_price,
                             $received_qty * $unit_price]);
            } else {
                // Formal: update existing line item's received count
                $pdo->prepare("
                    UPDATE purchase_order_items SET quantity_received = quantity_received + ?
                    WHERE id = ?
                ")->execute([$received_qty, $item['item_id']]);
                if ($received_qty < intval($item['ordered_qty'])) $all_received = false;
            }

            // Update product stock (weighted average cost) — same for both types
            $prod = $pdo->prepare("SELECT quantity, cost_price FROM products WHERE id = ? AND branch_id = ?");
            $prod->execute([$item['product_id'], $branch_id]);
            $current = $prod->fetch();
            $new_qty  = $current['quantity'] + $received_qty;
            $new_cost = (($current['quantity'] * $current['cost_price']) + ($received_qty * $unit_price)) / $new_qty;
            $pdo->prepare("
                UPDATE products SET quantity=?, cost_price=?, last_purchase_date=CURDATE()
                WHERE id=? AND branch_id=?
            ")->execute([$new_qty, round($new_cost, 2), $item['product_id'], $branch_id]);

            // Log in purchases / purchase_items (unified stock-in history)
            $pdo->prepare("
                INSERT INTO purchases (branch_id, supplier_id, purchase_order_id, invoice_no, purchase_date, total_amount, created_by)
                SELECT ?, supplier_id, ?, po_number, CURDATE(), ?, created_by
                FROM purchase_orders WHERE id = ?
            ")->execute([$branch_id, $po_id, $received_qty * $unit_price, $po_id]);
            $purchase_id = $pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, subtotal)
                VALUES (?,?,?,?,?)
            ")->execute([$purchase_id, $item['product_id'], $received_qty, $unit_price, $received_qty * $unit_price]);
        }

        if ($po_type === 'advance') {
            refreshAdvanceBalance($pdo, $po_id, $branch_id);
        } else {
            $status = $any_received ? ($all_received ? 'completed' : 'partial') : 'pending';
            $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$status, $po_id]);
        }

        $pdo->commit();
        logAction($pdo, 'PO Received', "PO #$po_id items received");
        header("Location: purchase_orders.php?tab=list&msg=received");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    $message = '<div class="alert alert-warning">⚠️ Cancelled.</div>';
}

// ============================================
// GET POs FOR LISTING
// ============================================

$pos = $pdo->prepare("
    SELECT po.*, s.name as supplier_name, s.whatsapp,
           COUNT(poi.id) as item_count
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
$po_topup_history = [];

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
        if ($po_to_receive['po_type'] === 'formal') {
            $items = $pdo->prepare("
                SELECT poi.*, p.name as product_name, p.unit
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                WHERE poi.po_id = ?
            ");
            $items->execute([$po_id]);
            $po_items_to_receive = $items->fetchAll();
        }
        $tu = $pdo->prepare("SELECT * FROM po_topups WHERE po_id = ? ORDER BY created_at");
        $tu->execute([$po_id]);
        $po_topup_history = $tu->fetchAll();
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-file-purchase me-2 text-primary"></i>Purchase Orders</h2>
        <p class="text-muted">Formal orders and cash advances to suppliers</p>
    </div>
    <div>
        <a href="?tab=create" class="btn btn-primary <?php echo $active_tab == 'create' ? 'active' : ''; ?>">
            <i class="fas fa-plus me-1"></i> New
        </a>
        <a href="?tab=list" class="btn btn-outline-secondary <?php echo $active_tab == 'list' ? 'active' : ''; ?>">
            <i class="fas fa-list me-1"></i> All
        </a>
    </div>
</div>

<?php echo $message; ?>

<!-- ============================================
CREATE TAB — type selector + both forms
============================================ -->
<?php if($active_tab == 'create'): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <label class="form-label fw-bold">What are you doing?</label>
        <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="po_type_toggle" id="type_formal" checked onchange="togglePoType('formal')">
            <label class="btn btn-outline-primary" for="type_formal">
                <i class="fas fa-file-invoice me-1"></i> Formal Order (I know exactly what I'm ordering)
            </label>
            <input type="radio" class="btn-check" name="po_type_toggle" id="type_advance" onchange="togglePoType('advance')">
            <label class="btn btn-outline-success" for="type_advance">
                <i class="fas fa-money-bill-wave me-1"></i> Cash Advance (give money, see what comes back)
            </label>
        </div>
    </div>
</div>

<form method="POST" id="poForm">
<input type="hidden" name="po_type" id="po_type_field" value="formal">

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0" id="form_title"><i class="fas fa-plus-circle me-2"></i>Create Purchase Order</h5>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">Supplier *</label>
                <select name="supplier_id" class="form-select" required>
                    <option value="">-- Select Supplier --</option>
                    <?php foreach($suppliers as $s): ?>
                    <option value="<?php echo $s['id']; ?>">
                        <?php echo htmlspecialchars($s['name']); ?>
                        <?php if($s['whatsapp']): ?> (WA: <?php echo $s['whatsapp']; ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Date</label>
                <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Expected Delivery</label>
                <input type="date" name="expected_delivery" class="form-control">
            </div>
        </div>

        <!-- ══════ FORMAL ORDER FIELDS ══════ -->
        <div id="formal_fields">
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

            <div class="table-container mb-3">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr><th>Product</th><th style="width:100px">Qty</th><th style="width:140px">Unit Price</th><th style="width:140px">Subtotal</th><th style="width:50px"></th></tr>
                    </thead>
                    <tbody id="po_cart_body">
                        <tr id="po_empty_row"><td colspan="5" class="text-center text-muted py-3">
                            <i class="fas fa-plus-circle me-2"></i> Add products to the purchase order
                        </td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <th colspan="3" class="text-end"><strong>Total:</strong></th>
                            <th id="po_total_display"><strong>0 RWF</strong></th><th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <input type="hidden" name="items_json" id="po_items_json">
        </div>

        <!-- ══════ CASH ADVANCE FIELDS ══════ -->
        <div id="advance_fields" style="display:none;">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Give the supplier cash to go source goods. You'll record what actually
                comes back later — the balance (either direction) is calculated automatically.
            </div>
            <div class="mb-3">
                <label class="form-label">Amount to Give *</label>
                <input type="number" name="advance_amount" id="advance_amount_input"
                       class="form-control form-control-lg" step="100" min="0" placeholder="e.g. 50000">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Delivery instructions, special requests..."></textarea>
        </div>

        <button type="submit" name="create_po" class="btn btn-success btn-lg w-100">
            <i class="fas fa-save me-2"></i> <span id="submit_label">Create Purchase Order</span>
        </button>
    </div>
</div>
</form>
<?php endif; ?>

<!-- ============================================
LIST TAB
============================================ -->
<?php if($active_tab == 'list'): ?>
<div class="card shadow-sm">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>All Purchase Orders & Advances</h5></div>
    <div class="card-body p-0">
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ref #</th><th>Type</th><th>Supplier</th><th>Date</th>
                        <th>Amount</th><th>Balance</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($pos)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">
                        None yet. <a href="?tab=create" class="alert-link">Create one now</a>
                    </td></tr>
                <?php else: foreach($pos as $po):
                    $status_class = ['pending'=>'warning','partial'=>'info','completed'=>'success','cancelled'=>'danger'][$po['status']];
                    $is_advance = $po['po_type'] === 'advance';
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td>
                            <?php if($is_advance): ?>
                                <span class="badge bg-success"><i class="fas fa-money-bill-wave"></i> Advance</span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="fas fa-file-invoice"></i> Formal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="supplier_profile.php?id=<?php echo $po['supplier_id']; ?>">
                                <?php echo htmlspecialchars($po['supplier_name']); ?>
                            </a>
                        </td>
                        <td><?php echo $po['order_date']; ?></td>
                        <td><strong><?php echo number_format($is_advance ? $po['advance_amount'] : $po['total_amount'], 0); ?></strong></td>
                        <td>
                            <?php if($is_advance && $po['balance'] > 0): ?>
                                <?php if($po['balance_direction']==='supplier_owes_us'): ?>
                                    <span class="text-danger"><?php echo number_format($po['balance'],0); ?> owed to us</span>
                                <?php elseif($po['balance_direction']==='we_owe_supplier'): ?>
                                    <span class="text-warning"><?php echo number_format($po['balance'],0); ?> we owe them</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo strtoupper($po['status']); ?></span></td>
                        <td>
                            <?php if($po['status'] != 'completed' && $po['status'] != 'cancelled'): ?>
                            <a href="?receive=<?php echo $po['id']; ?>" class="btn btn-sm btn-success" title="Receive goods">
                                <i class="fas fa-box"></i>
                            </a>
                            <?php if($is_advance): ?>
                            <button class="btn btn-sm btn-outline-success" title="Top up"
                                    onclick="openTopup(<?php echo $po['id']; ?>)">
                                <i class="fas fa-plus-circle"></i>
                            </button>
                            <?php endif; ?>
                            <a href="?cancel=<?php echo $po['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this?')">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                            <a href="view_po.php?id=<?php echo $po['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Top-up modal -->
<div class="modal fade" id="topupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Top Up Advance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="po_id" id="topup_po_id">
                    <div class="mb-3">
                        <label class="form-label">Additional Amount *</label>
                        <input type="number" name="topup_amount" class="form-control" step="100" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Note (optional)</label>
                        <input type="text" name="topup_notes" class="form-control" placeholder="e.g. so they can keep sourcing">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_topup" class="btn btn-success">Confirm Top-up</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
RECEIVE MODAL — different body for advance vs formal
============================================ -->
<?php if($po_to_receive): ?>
<div class="modal show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-box me-2"></i>
                    Receive — <?php echo $po_to_receive['po_number']; ?>
                    <?php if($po_to_receive['po_type']==='advance'): ?>
                        <span class="badge bg-light text-dark ms-2">Cash Advance</span>
                    <?php endif; ?>
                </h5>
                <a href="purchase_orders.php?tab=list" class="btn-close btn-close-white"></a>
            </div>
            <form method="POST" id="receiveForm">
                <div class="modal-body">
                    <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po_to_receive['supplier_name']); ?></p>

                    <?php if($po_to_receive['po_type']==='advance'): ?>
                        <div class="alert alert-warning">
                            <strong>Advance given so far:</strong> <?php echo number_format($po_to_receive['advance_amount'],0); ?> RWF
                            <?php if(!empty($po_topup_history)): ?>
                                <br><small>Includes <?php echo count($po_topup_history); ?> top-up(s)</small>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted">Enter what the supplier actually brought back. Add as many products as needed.</p>

                        <div class="row g-2 mb-3">
                            <div class="col-md-5">
                                <select id="recv_product_select" class="form-select">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                            data-unit="<?php echo $p['unit']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" id="recv_qty" class="form-control" placeholder="Qty" min="1">
                            </div>
                            <div class="col-md-3">
                                <input type="number" id="recv_price" class="form-control" placeholder="Unit Price" step="100" min="0">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-secondary w-100" onclick="addAdvanceReceiveItem()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <table class="table table-bordered table-sm">
                            <thead class="table-light"><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th><th></th></tr></thead>
                            <tbody id="advance_recv_body">
                                <tr id="advance_recv_empty"><td colspan="5" class="text-center text-muted py-2">No items added yet</td></tr>
                            </tbody>
                            <tfoot><tr class="table-success"><th colspan="3" class="text-end">Total value:</th><th id="advance_recv_total">0 RWF</th><th></th></tr></tfoot>
                        </table>
                        <input type="hidden" name="received_json" id="received_json_advance">

                    <?php else: ?>
                        <p><strong>Order Date:</strong> <?php echo $po_to_receive['order_date']; ?></p>
                        <input type="hidden" name="po_id" value="<?php echo $po_to_receive['id']; ?>">
                        <input type="hidden" name="received_json" id="received_json">
                        <div class="table-container">
                            <table class="table table-bordered">
                                <thead class="table-light"><tr><th>Product</th><th>Ordered</th><th>Received</th><th>To Receive</th><th>Unit Price</th></tr></thead>
                                <tbody>
                                <?php foreach($po_items_to_receive as $item):
                                    $remaining = $item['quantity_ordered'] - $item['quantity_received'];
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
                                                   min="0" max="<?php echo $remaining; ?>" value="<?php echo $remaining; ?>" style="width:100px;">
                                        </td>
                                        <td><?php echo number_format($item['unit_price'], 0); ?> RWF</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="purchase_orders.php?tab=list" class="btn btn-secondary">Cancel</a>
                    <?php if($po_to_receive['po_type']==='advance'): ?>
                    <input type="hidden" name="po_id" value="<?php echo $po_to_receive['id']; ?>">
                    <button type="submit" name="receive_items" class="btn btn-success" onclick="return prepareAdvanceReceiveSubmit()">
                        <i class="fas fa-check me-1"></i> Confirm & Update Stock
                    </button>
                    <?php else: ?>
                    <button type="submit" name="receive_items" class="btn btn-success" onclick="return prepareReceiveSubmit()">
                        <i class="fas fa-check me-1"></i> Confirm Receipt & Update Stock
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Type toggle ──────────────────────────────────────────────────────────────
function togglePoType(type) {
    document.getElementById('po_type_field').value = type;
    if (type === 'advance') {
        document.getElementById('formal_fields').style.display = 'none';
        document.getElementById('advance_fields').style.display = 'block';
        document.getElementById('form_title').innerHTML = '<i class="fas fa-money-bill-wave me-2"></i>Give Cash Advance';
        document.getElementById('submit_label').textContent = 'Give Advance';
    } else {
        document.getElementById('formal_fields').style.display = 'block';
        document.getElementById('advance_fields').style.display = 'none';
        document.getElementById('form_title').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Create Purchase Order';
        document.getElementById('submit_label').textContent = 'Create Purchase Order';
    }
}

// ── Formal PO cart (unchanged from before) ───────────────────────────────────
let poCart = [];
let poSelectedProduct = null;

function onPOProductSelect(select) {
    const option = select.options[select.selectedIndex];
    if (!option.value) { poSelectedProduct = null; document.getElementById('po_selected_display').innerHTML=''; return; }
    poSelectedProduct = { id: option.value, name: option.dataset.name, price: parseFloat(option.dataset.price), unit: option.dataset.unit };
    document.getElementById('po_selected_display').innerHTML =
        '<span class="badge bg-info">✓ Selected: ' + poSelectedProduct.name + ' (Cost: ' + formatNumber(poSelectedProduct.price) + ' RWF)</span>';
    document.getElementById('po_qty').focus();
}

function addPOItem() {
    if (!poSelectedProduct) { alert('Please select a product first.'); return; }
    const qty = parseInt(document.getElementById('po_qty').value);
    if (isNaN(qty) || qty < 1) { alert('Enter a valid quantity.'); return; }
    const unitPrice = parseFloat(document.getElementById('po_unit_price').value);
    if (isNaN(unitPrice) || unitPrice < 0) { alert('Enter a valid unit price.'); return; }

    const existing = poCart.find(item => item.product_id === poSelectedProduct.id);
    if (existing) { existing.quantity += qty; existing.unit_price = unitPrice; }
    else poCart.push({ product_id: poSelectedProduct.id, name: poSelectedProduct.name, quantity: qty, unit_price: unitPrice });

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
        tbody.innerHTML = `<tr id="po_empty_row"><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-plus-circle me-2"></i> Add products to the purchase order</td></tr>`;
        updatePOTotals(); return;
    }
    let total = 0;
    poCart.forEach((item, index) => {
        const subtotal = item.quantity * item.unit_price;
        total += subtotal;
        tbody.innerHTML += `<tr>
            <td><strong>${escapeHtml(item.name)}</strong></td>
            <td><input type="number" min="1" value="${item.quantity}" class="form-control form-control-sm" style="width:80px;" onchange="updatePOQty(${index}, this.value)"></td>
            <td><input type="number" min="0" step="100" value="${item.unit_price}" class="form-control form-control-sm" style="width:120px;" onchange="updatePOPrice(${index}, this.value)"></td>
            <td><strong>${formatNumber(subtotal)}</strong></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="removePOItem(${index})"><i class="fas fa-times"></i></button></td>
        </tr>`;
    });
    updatePOTotals();
}

function updatePOQty(i,v){ const q=parseInt(v); if(!isNaN(q)&&q>0){poCart[i].quantity=q; renderPOCart();} }
function updatePOPrice(i,v){ const p=parseFloat(v); if(!isNaN(p)&&p>=0){poCart[i].unit_price=p; renderPOCart();} }
function removePOItem(i){ poCart.splice(i,1); renderPOCart(); }
function updatePOTotals(){
    const total = poCart.reduce((s,i)=>s+(i.quantity*i.unit_price),0);
    document.getElementById('po_total_display').textContent = formatNumber(total) + ' RWF';
    document.getElementById('po_items_json').value = JSON.stringify(poCart);
}

// ── Advance receive-items cart ────────────────────────────────────────────────
let advanceRecvCart = [];

function addAdvanceReceiveItem() {
    const sel = document.getElementById('recv_product_select');
    if (!sel.value) { alert('Select a product first.'); return; }
    const name = sel.options[sel.selectedIndex].dataset.name;
    const qty = parseInt(document.getElementById('recv_qty').value);
    const price = parseFloat(document.getElementById('recv_price').value);
    if (isNaN(qty) || qty < 1) { alert('Enter a valid quantity.'); return; }
    if (isNaN(price) || price < 0) { alert('Enter a valid price.'); return; }

    advanceRecvCart.push({ product_id: sel.value, name, received_qty: qty, unit_price: price });
    renderAdvanceRecvCart();
    document.getElementById('recv_qty').value = '';
    document.getElementById('recv_price').value = '';
    sel.value = '';
}

function renderAdvanceRecvCart() {
    const tbody = document.getElementById('advance_recv_body');
    tbody.innerHTML = '';
    if (advanceRecvCart.length === 0) {
        tbody.innerHTML = `<tr id="advance_recv_empty"><td colspan="5" class="text-center text-muted py-2">No items added yet</td></tr>`;
        document.getElementById('advance_recv_total').textContent = '0 RWF';
        return;
    }
    let total = 0;
    advanceRecvCart.forEach((item, idx) => {
        const sub = item.received_qty * item.unit_price;
        total += sub;
        tbody.innerHTML += `<tr>
            <td>${escapeHtml(item.name)}</td><td>${item.received_qty}</td>
            <td>${formatNumber(item.unit_price)}</td><td>${formatNumber(sub)}</td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeAdvanceRecvItem(${idx})"><i class="fas fa-times"></i></button></td>
        </tr>`;
    });
    document.getElementById('advance_recv_total').textContent = formatNumber(total) + ' RWF';
}
function removeAdvanceRecvItem(idx){ advanceRecvCart.splice(idx,1); renderAdvanceRecvCart(); }

function prepareAdvanceReceiveSubmit() {
    if (advanceRecvCart.length === 0) { alert('Add at least one item received.'); return false; }
    document.getElementById('received_json_advance').value = JSON.stringify(advanceRecvCart);
    return confirm('Confirm these items? Stock and balance will update automatically.');
}

// ── Formal receive (unchanged) ────────────────────────────────────────────────
function prepareReceiveSubmit() {
    const items = [];
    document.querySelectorAll('.receive-qty').forEach(input => {
        const received = parseInt(input.value) || 0;
        if (received > 0) items.push({ item_id: input.dataset.itemId, product_id: input.dataset.productId, ordered_qty: input.dataset.ordered, received_qty: received, unit_price: input.dataset.price });
    });
    if (items.length === 0) { alert('Please enter quantity for at least one item.'); return false; }
    document.getElementById('received_json').value = JSON.stringify(items);
    return confirm('Confirm receipt of these items? Stock will be updated automatically.');
}

// ── Top-up modal trigger ───────────────────────────────────────────────────────
function openTopup(poId) {
    document.getElementById('topup_po_id').value = poId;
    new bootstrap.Modal(document.getElementById('topupModal')).show();
}

function formatNumber(n){ return new Intl.NumberFormat('en-RW').format(Math.round(n)); }
function escapeHtml(str){ return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</div>
<?php include '../includes/footer.php'; ?>