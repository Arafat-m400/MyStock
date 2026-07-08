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

// Products for dropdown
$products = $pdo->prepare("
    SELECT id, name, sku, quantity, cost_price, selling_price, unit 
    FROM products 
    WHERE branch_id = ? 
    ORDER BY name
");
$products->execute([$branch_id]);
$products = $products->fetchAll();

// Suppliers for dropdown
$suppliers = $pdo->prepare("
    SELECT id, name, phone, whatsapp 
    FROM suppliers 
    WHERE branch_id = ? 
    ORDER BY name
");
$suppliers->execute([$branch_id]);
$suppliers = $suppliers->fetchAll();

// ============================================
// CREATE PURCHASE ORDER
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_po'])) {
    try {
        $pdo->beginTransaction();
        
        $supplier_id = $_POST['supplier_id'];
        $order_date = $_POST['order_date'];
        $expected_delivery = $_POST['expected_delivery'] ?: null;
        $notes = $_POST['notes'];
        $items = json_decode($_POST['items_json'], true);
        
        if (empty($items)) {
            throw new Exception("No items added to the purchase order.");
        }
        
        // Generate PO number
        $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Calculate total
        $total_amount = 0;
        foreach ($items as $item) {
            $total_amount += $item['quantity'] * $item['unit_price'];
        }
        
        // Insert PO
        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders (
                branch_id, po_number, supplier_id, order_date, 
                expected_delivery, total_amount, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $branch_id,
            $po_number,
            $supplier_id,
            $order_date,
            $expected_delivery,
            $total_amount,
            $notes,
            $_SESSION['user_id']
        ]);
        $po_id = $pdo->lastInsertId();
        
        // Insert PO items
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
        
        $pdo->commit();
        
        logAction($pdo, 'PO Created', "PO: $po_number, Total: $total_amount RWF");
        
       // Get supplier for WhatsApp
$supplier = $pdo->prepare("SELECT name, whatsapp FROM suppliers WHERE id = ?");
$supplier->execute([$supplier_id]);
$supp = $supplier->fetch();

// Build detailed PO message
$po_items_text = "";
foreach ($items as $item) {
    // Get product name
    $prod = $pdo->prepare("SELECT name, unit FROM products WHERE id = ?");
    $prod->execute([$item['product_id']]);
    $product = $prod->fetch();
    $po_items_text .= "• " . $product['name'] . " - " . $item['quantity'] . " " . $product['unit'] . " x " . number_format($item['unit_price'], 0) . " RWF = " . number_format($item['quantity'] * $item['unit_price'], 0) . " RWF\n";
}

$whatsapp_message = "📋 *PURCHASE ORDER* 📋\n\n";
$whatsapp_message .= "*PO #:* " . $po_number . "\n";
$whatsapp_message .= "*Supplier:* " . $supp['name'] . "\n";
$whatsapp_message .= "*Date:* " . date('Y-m-d') . "\n";
if ($expected_delivery) {
    $whatsapp_message .= "*Expected Delivery:* " . $expected_delivery . "\n";
}
$whatsapp_message .= "\n*Items Ordered:*\n";
$whatsapp_message .= "------------------------\n";
$whatsapp_message .= $po_items_text;
$whatsapp_message .= "------------------------\n";
$whatsapp_message .= "*TOTAL: " . number_format($total_amount, 0) . " RWF*\n\n";
if (!empty($notes)) {
    $whatsapp_message .= "*Notes:* " . $notes . "\n\n";
}
$whatsapp_message .= "Please confirm receipt of this order.\n";
$whatsapp_message .= "Reply 'CONFIRM' to acknowledge.";

$message = '<div class="alert alert-success alert-permanent">
    <i class="fas fa-check-circle me-2"></i>
    <strong>✅ Purchase Order Created!</strong>
    <br>PO Number: <strong>' . $po_number . '</strong>
    <br>Total: ' . number_format($total_amount, 0) . ' RWF
    <br>
    <div class="mt-2">
        <a href="purchase_orders.php?tab=list" class="btn btn-sm btn-primary">
            <i class="fas fa-list me-1"></i> View All POs
        </a>
        ' . (!empty($supp['whatsapp']) ? '
        <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $supp['whatsapp']) . '?text=' . urlencode($whatsapp_message) . '" 
           target="_blank" class="btn btn-sm btn-success">
            <i class="fab fa-whatsapp me-1"></i> Send to Supplier
        </a>' : '') . '
    </div>
</div>';

// After inserting PO items
$pdo->prepare("UPDATE purchase_orders SET whatsapp_message = ? WHERE id = ?")
    ->execute([$whatsapp_message, $po_id]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// RECEIVE PO ITEMS
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_items'])) {
    try {
        $pdo->beginTransaction();
        
        $po_id = $_POST['po_id'];
        $items_received = json_decode($_POST['received_json'], true);
        
        $all_received = true;
        $any_received = false;
        
        foreach ($items_received as $item) {
            $received_qty = intval($item['received_qty']);
            
            if ($received_qty > 0) {
                $any_received = true;
                
                // Update PO item
                $pdo->prepare("
                    UPDATE purchase_order_items 
                    SET quantity_received = quantity_received + ?
                    WHERE id = ?
                ")->execute([$received_qty, $item['item_id']]);
                
                // Update product stock (with weighted average cost)
                $prod = $pdo->prepare("SELECT quantity, cost_price FROM products WHERE id = ? AND branch_id = ?");
                $prod->execute([$item['product_id'], $branch_id]);
                $current = $prod->fetch();
                
                $new_qty = $current['quantity'] + $received_qty;
                $unit_price = floatval($item['unit_price']);
                $new_cost = (($current['quantity'] * $current['cost_price']) + ($received_qty * $unit_price)) / $new_qty;
                
                $pdo->prepare("
                    UPDATE products 
                    SET quantity = ?, 
                        cost_price = ?,
                        last_purchase_date = CURDATE()
                    WHERE id = ? AND branch_id = ?
                ")->execute([$new_qty, round($new_cost, 2), $item['product_id'], $branch_id]);
                
                // Record in purchases table
                $pdo->prepare("
                    INSERT INTO purchases (branch_id, supplier_id, invoice_no, purchase_date, total_amount, created_by)
                    SELECT ?, supplier_id, po_number, CURDATE(), ?, created_by
                    FROM purchase_orders
                    WHERE id = ?
                ")->execute([$branch_id, $received_qty * $unit_price, $po_id]);
                
                $purchase_id = $pdo->lastInsertId();
                
                $pdo->prepare("
                    INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$purchase_id, $item['product_id'], $received_qty, $unit_price, $received_qty * $unit_price]);
                
                if ($received_qty < intval($item['ordered_qty'])) {
                    $all_received = false;
                }
            } else {
                if (intval($item['ordered_qty']) > 0) {
                    $all_received = false;
                }
            }
        }
        
        // Update PO status
        $status = 'pending';
        if ($any_received) {
            $status = $all_received ? 'completed' : 'partial';
        }
        $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$status, $po_id]);
        
        $pdo->commit();
        
        logAction($pdo, 'PO Received', "PO #$po_id received: $status");
        $message = '<div class="alert alert-success">✅ Stock updated! PO status: ' . strtoupper($status) . '</div>';
        
        // Redirect to list
        header("Location: purchase_orders.php?tab=list&msg=received");
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        $items = $pdo->prepare("
            SELECT poi.*, p.name as product_name, p.unit
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.po_id = ?
        ");
        $items->execute([$po_id]);
        $po_items_to_receive = $items->fetchAll();
    }
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-file-purchase me-2 text-primary"></i>Purchase Orders</h2>
        <p class="text-muted">
            Create, track, and receive purchase orders from suppliers
        </p>
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
            <div class="row g-3 mb-3">
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
                <div class="col-md-3">
                    <label class="form-label">Expected Delivery</label>
                    <input type="date" name="expected_delivery" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-outline-danger w-100" onclick="clearPOCart()">
                        <i class="fas fa-trash"></i> Clear
                    </button>
                </div>
            </div>
            
            <!-- Add Product to PO -->
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
            
            <div class="mb-3">
                <label class="form-label">Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Delivery instructions, special requests..."></textarea>
            </div>
            
            <input type="hidden" name="items_json" id="po_items_json">
            
            <button type="submit" name="create_po" class="btn btn-success btn-lg w-100">
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
                        <th>Supplier</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($pos)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            No purchase orders created yet.
                            <a href="?tab=create" class="alert-link">Create one now</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($pos as $po): 
                        $status_class = [
                            'pending' => 'warning',
                            'partial' => 'info',
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ][$po['status']];
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                        <td><?php echo $po['order_date']; ?></td>
                        <td><?php echo $po['item_count']; ?></td>
                        <td><strong><?php echo number_format($po['total_amount'], 0); ?></strong></td>
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
RECEIVE PO MODAL
============================================ -->
<?php if($po_to_receive && $po_items_to_receive): ?>
<div class="modal show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-box me-2"></i>
                    Receive Items - <?php echo $po_to_receive['po_number']; ?>
                </h5>
                <a href="purchase_orders.php?tab=list" class="btn-close btn-close-white"></a>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po_to_receive['supplier_name']); ?></p>
                    <p><strong>Order Date:</strong> <?php echo $po_to_receive['order_date']; ?></p>
                    <p><strong>Status:</strong> <span class="badge bg-warning"><?php echo strtoupper($po_to_receive['status']); ?></span></p>
                    
                    <input type="hidden" name="po_id" value="<?php echo $po_to_receive['id']; ?>">
                    <input type="hidden" name="received_json" id="received_json">
                    
                    <div class="table-container">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>Ordered</th>
                                    <th>Received</th>
                                    <th>To Receive</th>
                                    <th>Unit Price</th>
                                </tr>
                            </thead>
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
                                               min="0" max="<?php echo $remaining; ?>"
                                               value="<?php echo $remaining; ?>"
                                               style="width:100px;">
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
                    <button type="submit" name="receive_items" class="btn btn-success" onclick="prepareReceiveSubmit()">
                        <i class="fas fa-check me-1"></i> Confirm Receipt & Update Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ============================================
// PO CART
// ============================================

let poCart = [];
let poSelectedProduct = null;

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
    
    // Check if already in cart
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
        tbody.innerHTML = `<tr id="po_empty_row">
            <td colspan="5" class="text-center text-muted py-3">
                <i class="fas fa-plus-circle me-2"></i> Add products to the purchase order
            </td>
        </tr>`;
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

function clearPOCart() {
    if (poCart.length === 0) return;
    if (confirm('Clear all items from PO?')) {
        poCart = [];
        renderPOCart();
    }
}

function updatePOTotals() {
    const total = poCart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
    document.getElementById('po_total_display').textContent = formatNumber(total) + ' RWF';
    document.getElementById('po_items_json').value = JSON.stringify(poCart);
}

// ============================================
// RECEIVE HANDLING
// ============================================

function prepareReceiveSubmit() {
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
// UTILITY
// ============================================

function formatNumber(n) {
    return new Intl.NumberFormat('en-RW').format(Math.round(n));
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Enter key for PO
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const active = document.activeElement;
        if (active.id === 'po_qty' || active.id === 'po_unit_price') {
            e.preventDefault();
            addPOItem();
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>