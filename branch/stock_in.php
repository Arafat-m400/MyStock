<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';
$active_tab = $_GET['tab'] ?? 'quick_add';

// ============================================
// GET DATA
// ============================================

$products = $pdo->prepare("SELECT id, name, sku, quantity, cost_price, selling_price, unit FROM products WHERE branch_id = ? ORDER BY name");
$products->execute([$branch_id]);
$products = $products->fetchAll();

$suppliers = $pdo->prepare("SELECT id, name, phone, whatsapp FROM suppliers WHERE branch_id = ? ORDER BY name");
$suppliers->execute([$branch_id]);
$suppliers = $suppliers->fetchAll();

// ============================================
// QUICK ADD STOCK (Direct Entry)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['quick_add'])) {
    $product_id = $_POST['product_id'];
    $supplier_id = $_POST['supplier_id'] ?: null;
    $quantity = intval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $invoice_no = $_POST['invoice_no'] ?: 'PO-' . date('Ymd') . '-' . rand(100, 999);
    
    try {
        $pdo->beginTransaction();
        
        if ($quantity <= 0) throw new Exception("Quantity must be greater than 0.");
        if ($unit_price < 0) throw new Exception("Unit price cannot be negative.");
        
        // Get current product details
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
        $stmt->execute([$product_id, $branch_id]);
        $product = $stmt->fetch();
        if (!$product) throw new Exception("Product not found.");
        
        // Calculate new cost price (weighted average)
        $current_qty = $product['quantity'];
        $current_cost = $product['cost_price'];
        $new_qty = $current_qty + $quantity;
        $new_cost = (($current_qty * $current_cost) + ($quantity * $unit_price)) / $new_qty;
        
        // Insert purchase record
        $stmt = $pdo->prepare("INSERT INTO purchases (branch_id, supplier_id, invoice_no, purchase_date, total_amount, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $total_amount = $quantity * $unit_price;
        $stmt->execute([$branch_id, $supplier_id, $invoice_no, $purchase_date, $total_amount, $_SESSION['user_id']]);
        $purchase_id = $pdo->lastInsertId();
        
        // Insert purchase items
        $stmt = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$purchase_id, $product_id, $quantity, $unit_price, $total_amount]);
        
        // Update product stock and cost
        $stmt = $pdo->prepare("UPDATE products SET quantity = ?, cost_price = ?, last_purchase_date = ? WHERE id = ?");
        $stmt->execute([$new_qty, round($new_cost, 2), $purchase_date, $product_id]);
        
        // Update supplier total
        if ($supplier_id) {
            $pdo->prepare("UPDATE suppliers SET total_purchased = total_purchased + ? WHERE id = ?")->execute([$total_amount, $supplier_id]);
        }
        
        $pdo->commit();
        
        logAction($pdo, 'Quick Add Stock', "Added $quantity of {$product['name']}");
        $message = '<div class="alert alert-success">✅ Stock added! ' . $quantity . ' ' . $product['unit'] . ' of "' . htmlspecialchars($product['name']) . '" added. New stock: ' . $new_qty . ' | Cost: ' . number_format($new_cost, 0) . ' RWF</div>';
        
        // Refresh products
        $products = $pdo->prepare("SELECT id, name, quantity, cost_price, unit FROM products WHERE branch_id = ? ORDER BY name");
        $products->execute([$branch_id]);
        $products = $products->fetchAll();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// CREATE PURCHASE ORDER (Formal + Advance)
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
            $items = json_decode($_POST['items_json'], true);
            if (empty($items)) throw new Exception("No items added.");
            
            $total_amount = 0;
            foreach ($items as $item) {
                $total_amount += $item['quantity'] * $item['unit_price'];
            }
            
            $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            
            $stmt = $pdo->prepare("INSERT INTO purchase_orders (branch_id, po_number, po_type, supplier_id, order_date, expected_delivery, total_amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $po_number, $po_type, $supplier_id, $order_date, $expected_delivery, $total_amount, $notes, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();
            
            foreach ($items as $item) {
                $stmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['quantity'] * $item['unit_price']]);
            }
            
            $success_msg = "✅ Formal Purchase Order Created!";
            
        } else {
            // Cash Advance
            $advance_amount = floatval($_POST['advance_amount']);
            if ($advance_amount <= 0) throw new Exception("Advance amount must be greater than 0.");
            
            $po_number = 'ADV-' . date('Ymd') . '-' . rand(1000, 9999);
            
            $stmt = $pdo->prepare("INSERT INTO purchase_orders (branch_id, po_number, po_type, supplier_id, order_date, expected_delivery, advance_amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $po_number, $po_type, $supplier_id, $order_date, $expected_delivery, $advance_amount, $notes, $_SESSION['user_id']]);
            $po_id = $pdo->lastInsertId();
            
            // Log the advance in topups
            $stmt = $pdo->prepare("INSERT INTO po_topups (po_id, amount, notes, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$po_id, $advance_amount, "Initial advance", $_SESSION['user_id']]);
            
            $success_msg = "✅ Cash Advance Created! Supplier owes " . number_format($advance_amount, 0) . " RWF in goods.";
        }
        
        $pdo->commit();
        
        // Get supplier for WhatsApp
        $supplier = $pdo->prepare("SELECT name, whatsapp FROM suppliers WHERE id = ?");
        $supplier->execute([$supplier_id]);
        $supp = $supplier->fetch();
        
        logAction($pdo, 'PO Created', "PO: $po_number, Type: $po_type");
        
        $message = '<div class="alert alert-success alert-permanent">
            <i class="fas fa-check-circle me-2"></i>
            <strong>' . $success_msg . '</strong>
            <br>PO Number: <strong>' . $po_number . '</strong>
            <br>
            <div class="mt-2">
                <a href="view_po.php?id=' . $po_id . '" target="_blank" class="btn btn-sm btn-info">
                    <i class="fas fa-eye me-1"></i> View PO
                </a>
                ' . (!empty($supp['whatsapp']) && $po_type == 'formal' ? '
                <a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $supp['whatsapp']) . '?text=' . urlencode("PO: $po_number\nSupplier: {$supp['name']}\nTotal: " . number_format($total_amount ?? $advance_amount, 0) . " RWF\n\nPlease confirm receipt.") . '" 
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
// GET POs FOR LISTING
// ============================================

$pos = $pdo->prepare("
    SELECT po.*, s.name as supplier_name, s.whatsapp,
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

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-truck me-2 text-primary"></i>Stock In</h2>
            <p class="text-muted">Add stock directly or create purchase orders</p>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'quick_add' ? 'active' : ''; ?>" href="?tab=quick_add">
                <i class="fas fa-plus-circle me-1"></i> Quick Add
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'purchase_orders' ? 'active' : ''; ?>" href="?tab=purchase_orders">
                <i class="fas fa-file-purchase me-1"></i> Purchase Orders
                <span class="badge bg-secondary ms-1"><?php echo count($pos); ?></span>
            </a>
        </li>
    </ul>

    <!-- ============================================
    QUICK ADD TAB
    ============================================ -->
    <?php if($active_tab == 'quick_add'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Quick Add Stock</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Product *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Select Product --</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> 
                                (Stock: <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required min="1" placeholder="10">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit Price (RWF) *</label>
                        <input type="number" name="unit_price" class="form-control" required min="0" step="100" placeholder="8500">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Invoice / Reference</label>
                        <input type="text" name="invoice_no" class="form-control" placeholder="Optional reference">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" name="quick_add" class="btn btn-success w-100">
                            <i class="fas fa-save me-2"></i> Add Stock
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row mt-4 g-3">
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <h4 class="text-primary"><?php echo count($products); ?></h4>
                <p class="stat-label">Total Products</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <h4 class="text-success">
                    <?php 
                    $value = $pdo->prepare("SELECT COALESCE(SUM(quantity * cost_price), 0) FROM products WHERE branch_id = ?");
                    $value->execute([$branch_id]);
                    echo number_format($value->fetchColumn(), 0);
                    ?>
                </h4>
                <p class="stat-label">Stock Value (RWF)</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <h4 class="text-warning">
                    <?php 
                    $low = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ? AND quantity <= reorder_level");
                    $low->execute([$branch_id]);
                    echo $low->fetchColumn();
                    ?>
                </h4>
                <p class="stat-label">Low Stock Items</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <h4 class="text-info">
                    <?php 
                    $purchases = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE branch_id = ?");
                    $purchases->execute([$branch_id]);
                    echo $purchases->fetchColumn();
                    ?>
                </h4>
                <p class="stat-label">Total Purchases</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    PURCHASE ORDERS TAB
    ============================================ -->
    <?php if($active_tab == 'purchase_orders'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Create Purchase Order</h5>
        </div>
        <div class="card-body">
            <form method="POST" id="poForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">PO Type</label>
                        <select name="po_type" id="po_type" class="form-select" onchange="togglePOType()">
                            <option value="formal">📋 Formal Order</option>
                            <option value="advance">💰 Cash Advance</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier *</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
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
                                <tr><td colspan="5" class="text-center text-muted py-3">No items added</td></tr>
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
                        Give money to supplier, they bring goods later. Record what they bring when received.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Advance Amount (RWF) *</label>
                            <input type="number" name="advance_amount" class="form-control" min="0" step="100" placeholder="50000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Purpose of advance">
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

    <!-- Purchase Orders List -->
    <div class="card shadow-sm mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Purchase Orders</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($pos)): ?>
            <div class="text-center py-4 text-muted">No purchase orders yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pos as $po): 
                            $status_class = ['pending' => 'warning', 'partial' => 'info', 'completed' => 'success', 'cancelled' => 'danger'][$po['status']] ?? 'secondary';
                            $type_label = $po['po_type'] == 'formal' ? '📋 Formal' : '💰 Advance';
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
                            </td>
                            <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo strtoupper($po['status']); ?></span></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($po['status'] != 'completed' && $po['status'] != 'cancelled'): ?>
                                    <a href="purchase_orders.php?receive=<?php echo $po['id']; ?>" class="btn btn-success">
                                        <i class="fas fa-box"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="view_po.php?id=<?php echo $po['id']; ?>" target="_blank" class="btn btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">No items added</td></tr>`;
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
                <td><input type="number" min="1" value="${item.quantity}" class="form-control form-control-sm" style="width:80px;" onchange="updatePOQty(${index}, this.value)"></td>
                <td><input type="number" min="0" step="100" value="${item.unit_price}" class="form-control form-control-sm" style="width:120px;" onchange="updatePOPrice(${index}, this.value)"></td>
                <td><strong>${formatNumber(subtotal)}</strong></td>
                <td><button type="button" class="btn btn-sm btn-danger" onclick="removePOItem(${index})"><i class="fas fa-times"></i></button></td>
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
</script>

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
</style>

<?php include '../includes/footer.php'; ?>