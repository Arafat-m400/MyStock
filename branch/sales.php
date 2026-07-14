<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';

// ============================================
// GET DATA
// ============================================

$products = $pdo->prepare("
    SELECT id, name, sku, quantity, selling_price, unit 
    FROM products 
    WHERE branch_id = ? 
    ORDER BY name
");
$products->execute([$branch_id]);
$products = $products->fetchAll();

$customers = $pdo->prepare("
    SELECT id, name, phone 
    FROM customers 
    WHERE branch_id = ? 
    ORDER BY name
");
$customers->execute([$branch_id]);
$customers = $customers->fetchAll();

$sales_history = $pdo->prepare("
    SELECT s.*, c.name as customer_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.branch_id = ? AND s.sale_date = CURDATE()
    ORDER BY s.created_at DESC
    LIMIT 20
");
$sales_history->execute([$branch_id]);
$sales_history = $sales_history->fetchAll();

// ============================================
// PROCESS SALE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_sale'])) {
    try {
        $pdo->beginTransaction();
        
        $customer_id = $_POST['customer_id'] ?: null;
        $discount = floatval($_POST['discount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $items = json_decode($_POST['items_json'], true);
        
        if (empty($items)) {
            throw new Exception("No items added to the sale.");
        }
        
        $subtotal = 0;
        $sale_items = [];
        
        foreach ($items as $item) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
            $stmt->execute([$item['product_id'], $branch_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                throw new Exception("Product not found: " . $item['product_id']);
            }
            
            if ($product['quantity'] < $item['quantity']) {
                throw new Exception("Insufficient stock for \"{$product['name']}\". Available: {$product['quantity']}");
            }
            
            $unit_price = floatval($item['price'] ?? $product['selling_price']);
            $item_subtotal = $item['quantity'] * $unit_price;
            $subtotal += $item_subtotal;
            
            $sale_items[] = [
                'product_id' => $product['id'],
                'quantity' => $item['quantity'],
                'selling_price' => $unit_price,
                'cost_price' => $product['cost_price'],
                'subtotal' => $item_subtotal
            ];
        }
        
        $grand_total = $subtotal - $discount;
        if ($grand_total < 0) $grand_total = 0;
        
        // Set payment amounts based on method
        $cash_amount = 0;
        $momo_amount = 0;
        $payment_status = 'paid';
        
        if ($payment_method == 'cash') {
            $cash_amount = $grand_total;
            $payment_status = 'paid';
        } elseif ($payment_method == 'momo') {
            $momo_amount = $grand_total;
            $payment_status = 'paid';
        } elseif ($payment_method == 'debt') {
            $payment_status = 'unpaid';
            // Create debt record
        }
        
        $invoice_no = generateInvoiceNo('INV');
        $sale_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            INSERT INTO sales (
                branch_id, customer_id, invoice_no, sale_date, 
                subtotal, discount, grand_total, 
                cash_amount, mobile_money_amount, payment_status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $branch_id,
            $customer_id,
            $invoice_no,
            $sale_date,
            $subtotal,
            $discount,
            $grand_total,
            $cash_amount,
            $momo_amount,
            $payment_status,
            $_SESSION['user_id']
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // If debt, create debt record
        if ($payment_method == 'debt' && $customer_id) {
            $stmt = $pdo->prepare("
                INSERT INTO customer_debts (
                    customer_id, branch_id, sale_id, amount, paid_amount, remaining, status
                ) VALUES (?, ?, ?, ?, 0, ?, 'pending')
            ");
            $stmt->execute([$customer_id, $branch_id, $sale_id, $grand_total, $grand_total]);
            
            // Update customer total debt
            $pdo->prepare("
                UPDATE customers 
                SET total_debt = (
                    SELECT COALESCE(SUM(remaining), 0) 
                    FROM customer_debts 
                    WHERE customer_id = ? AND status IN ('pending', 'partial')
                )
                WHERE id = ?
            ")->execute([$customer_id, $customer_id]);
        }
        
        foreach ($sale_items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO sale_items (
                    sale_id, product_id, quantity, 
                    selling_price, cost_price_at_sale, subtotal
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['selling_price'],
                $item['cost_price'],
                $item['subtotal']
            ]);
            
            $pdo->prepare("
                UPDATE products 
                SET quantity = quantity - ?
                WHERE id = ?
            ")->execute([$item['quantity'], $item['product_id']]);
        }
        
        if ($customer_id && $payment_method != 'debt') {
            $pdo->prepare("
                UPDATE customers 
                SET total_spent = total_spent + ?
                WHERE id = ?
            ")->execute([$grand_total, $customer_id]);
        }
        
        $pdo->commit();
        
        logAction($pdo, 'Sale Completed', "Invoice: $invoice_no, Total: $grand_total RWF, Method: $payment_method");
        
        $method_text = [
            'cash' => '💵 Cash',
            'momo' => '📱 Mobile Money',
            'debt' => '📝 Debt'
        ][$payment_method] ?? $payment_method;
        
        $message = '<div class="alert alert-success alert-permanent">
            <i class="fas fa-check-circle me-2"></i>
            <strong>✅ Sale Completed!</strong>
            Invoice: <strong>' . $invoice_no . '</strong>
            <br><small>Total: ' . number_format($grand_total, 0) . ' RWF</small>
            <br><small>Payment: ' . $method_text . '</small>
            ' . ($payment_method == 'debt' ? '<br><span class="text-warning">⚠️ This is a debt sale</span>' : '') . '
            <br>
            <a href="view_invoice.php?id=' . $sale_id . '" target="_blank" class="btn btn-sm btn-success mt-2">
                <i class="fas fa-print me-1"></i> View/Print Invoice
            </a>
            <button class="btn btn-sm btn-secondary mt-2" onclick="location.reload()">
                <i class="fas fa-plus me-1"></i> New Sale
            </button>
        </div>';
        
        // Refresh data
        $products = $pdo->prepare("SELECT id, name, quantity, selling_price, unit FROM products WHERE branch_id = ? ORDER BY name");
        $products->execute([$branch_id]);
        $products = $products->fetchAll();
        
        $sales_history = $pdo->prepare("
            SELECT s.*, c.name as customer_name
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            WHERE s.branch_id = ? AND s.sale_date = CURDATE()
            ORDER BY s.created_at DESC
            LIMIT 20
        ");
        $sales_history->execute([$branch_id]);
        $sales_history = $sales_history->fetchAll();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-cash-register me-2 text-primary"></i>Point of Sale</h2>
            <p class="text-muted">
                <?php echo date('l, F j, Y'); ?>
                <span class="mx-2">|</span>
                <?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch
            </p>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Sale Cart</h5>
                </div>
                <div class="card-body">
                    <form id="saleForm" method="POST" onsubmit="return prepareSubmit()">
                        <div class="row g-2 mb-3">
                            <div class="col-md-5">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" id="customer_id" class="form-select">
                                    <option value="">Walk-in Customer</option>
                                    <?php foreach($customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Discount (RWF)</label>
                                <input type="number" name="discount" id="discount" class="form-control" 
                                       value="0" min="0" step="100" oninput="updateTotals()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" id="payment_method" class="form-select" onchange="togglePaymentFields()">
                                    <option value="cash">💵 Cash</option>
                                    <option value="momo">📱 Mobile Money</option>
                                    <option value="debt">📝 Debt (Credit)</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Product Selection -->
                        <div class="mb-3">
                            <label class="form-label">Add Product</label>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <select id="product_select" class="form-select" onchange="onProductSelect(this)">
                                        <option value="">-- Select Product --</option>
                                        <?php foreach($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                                data-price="<?php echo $p['selling_price']; ?>"
                                                data-stock="<?php echo $p['quantity']; ?>"
                                                data-unit="<?php echo $p['unit']; ?>">
                                            <?php echo htmlspecialchars($p['name']); ?> - 
                                            <?php echo number_format($p['selling_price'], 0); ?> RWF 
                                            (Stock: <?php echo $p['quantity']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="qty_input" class="form-control qty-input" 
                                           placeholder="Qty" min="1" value="1">
                                </div>
                                <div class="col-md-3">
                                    <input type="number" id="custom_price_input" class="form-control" 
                                           placeholder="Custom price" step="100" min="0">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-secondary w-100" onclick="addItem()">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                            <div id="selected_display" class="mt-2 small"></div>
                        </div>
                        
                        <!-- Cart Table -->
                        <div class="table-container mb-3">
                            <table class="table table-bordered table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th style="width:80px">Qty</th>
                                        <th style="width:120px">Price</th>
                                        <th style="width:120px">Subtotal</th>
                                        <th style="width:50px"></th>
                                    </tr>
                                </thead>
                                <tbody id="cart_body">
                                    <tr id="empty_row">
                                        <td colspan="5" class="text-center text-muted py-3">
                                            <i class="fas fa-cart-plus me-2"></i> Select a product and click Add
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="3" class="text-end">Subtotal:</th>
                                        <th id="subtotal_display">0 RWF</th>
                                        <th></th>
                                    </tr>
                                    <tr class="table-warning">
                                        <th colspan="3" class="text-end">Discount:</th>
                                        <th id="discount_display">0 RWF</th>
                                        <th></th>
                                    </tr>
                                    <tr class="table-success">
                                        <th colspan="3" class="text-end"><strong>Grand Total:</strong></th>
                                        <th id="grand_total_display"><strong>0 RWF</strong></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- Payment Amounts -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Cash Amount (RWF)</label>
                                <input type="number" name="cash_amount" id="cash_amount" class="form-control" 
                                       value="0" min="0" step="100" oninput="updateTotals()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mobile Money (RWF)</label>
                                <input type="number" name="momo_amount" id="momo_amount" class="form-control" 
                                       value="0" min="0" step="100" oninput="updateTotals()">
                            </div>
                        </div>
                        
                        <input type="hidden" name="items_json" id="items_json">
                        
                        <button type="submit" name="process_sale" class="btn btn-success btn-lg w-100">
                            <i class="fas fa-check-circle me-2"></i> Complete Sale
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Today's Sales</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-container" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Total</th>
                                    <th>Method</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($sales_history)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No sales today</td></tr>
                                <?php else: ?>
                                <?php foreach($sales_history as $sale): ?>
                                <tr>
                                    <td><small><?php echo htmlspecialchars($sale['invoice_no']); ?></small></td>
                                    <td><strong><?php echo number_format($sale['grand_total'], 0); ?></strong></td>
                                    <td>
                                        <?php if($sale['payment_status'] == 'unpaid'): ?>
                                        <span class="badge bg-danger">Debt</span>
                                        <?php elseif($sale['cash_amount'] > 0): ?>
                                        <span class="badge bg-success">Cash</span>
                                        <?php elseif($sale['mobile_money_amount'] > 0): ?>
                                        <span class="badge bg-info">MOMO</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="view_invoice.php?id=<?php echo $sale['id']; ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-file-invoice"></i>
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
            
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted">Today's Sales</small>
                            <h5 class="text-success">
                                <?php 
                                $total = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE branch_id = ? AND sale_date = CURDATE()");
                                $total->execute([$branch_id]);
                                echo number_format($total->fetchColumn(), 0);
                                ?>
                            </h5>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Transactions</small>
                            <h5 class="text-primary">
                                <?php 
                                $count = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE branch_id = ? AND sale_date = CURDATE()");
                                $count->execute([$branch_id]);
                                echo $count->fetchColumn();
                                ?>
                            </h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
let selectedProduct = null;

function onProductSelect(select) {
    const option = select.options[select.selectedIndex];
    if (!option.value) {
        selectedProduct = null;
        document.getElementById('selected_display').innerHTML = '';
        return;
    }
    
    selectedProduct = {
        id: option.value,
        name: option.dataset.name,
        price: parseFloat(option.dataset.price),
        stock: parseInt(option.dataset.stock),
        unit: option.dataset.unit
    };
    
    document.getElementById('selected_display').innerHTML = 
        '<span class="badge bg-success">✓ Selected: ' + selectedProduct.name + 
        ' (Price: ' + formatNumber(selectedProduct.price) + ' RWF, Stock: ' + selectedProduct.stock + ')</span>';
    document.getElementById('qty_input').focus();
}

function addItem() {
    if (!selectedProduct) {
        alert('Please select a product from the dropdown first.');
        return;
    }
    
    const qty = parseInt(document.getElementById('qty_input').value);
    if (isNaN(qty) || qty < 1) {
        alert('Enter a valid quantity (at least 1).');
        return;
    }
    
    if (qty > selectedProduct.stock) {
        alert('Not enough stock! Available: ' + selectedProduct.stock + ' ' + selectedProduct.unit);
        return;
    }
    
    const customPrice = parseFloat(document.getElementById('custom_price_input').value);
    const price = (!isNaN(customPrice) && customPrice > 0) ? customPrice : selectedProduct.price;
    
    const existing = cart.find(item => item.product_id === selectedProduct.id);
    if (existing) {
        if (existing.quantity + qty > selectedProduct.stock) {
            alert('Cannot add. Only ' + (selectedProduct.stock - existing.quantity) + ' more available.');
            return;
        }
        existing.quantity += qty;
        if (!isNaN(customPrice) && customPrice > 0) existing.price = price;
    } else {
        cart.push({
            product_id: selectedProduct.id,
            name: selectedProduct.name,
            quantity: qty,
            price: price
        });
    }
    
    selectedProduct.stock -= qty;
    renderCart();
    
    document.getElementById('qty_input').value = 1;
    document.getElementById('custom_price_input').value = '';
    document.getElementById('product_select').value = '';
    document.getElementById('selected_display').innerHTML = '';
    selectedProduct = null;
}

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    
    if (cart.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-cart-plus me-2"></i>Select a product and click Add</td></tr>`;
        updateTotals();
        return;
    }
    
    cart.forEach((item, index) => {
        const subtotal = item.quantity * item.price;
        tbody.innerHTML += `
            <tr>
                <td><strong>${escapeHtml(item.name)}</strong></td>
                <td>
                    <input type="number" min="1" value="${item.quantity}" 
                           class="form-control form-control-sm" 
                           style="font-weight:600; text-align:center;"
                           onchange="updateQty(${index}, this.value)">
                </td>
                <td>
                    <input type="number" min="0" step="100" value="${item.price.toFixed(0)}" 
                           class="form-control form-control-sm" 
                           style="font-weight:600; text-align:center;"
                           onchange="updatePrice(${index}, this.value)">
                </td>
                <td class="text-end"><strong>${formatNumber(subtotal)}</strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    updateTotals();
}

function updateQty(index, value) {
    const qty = parseInt(value);
    if (!isNaN(qty) && qty > 0) {
        cart[index].quantity = qty;
        renderCart();
    }
}

function updatePrice(index, value) {
    const price = parseFloat(value);
    if (!isNaN(price) && price >= 0) {
        cart[index].price = price;
        renderCart();
    }
}

function removeItem(index) {
    cart.splice(index, 1);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    if (confirm('Clear all items from cart?')) {
        cart = [];
        renderCart();
        document.getElementById('product_select').value = '';
        document.getElementById('selected_display').innerHTML = '';
        selectedProduct = null;
    }
}

function updateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.quantity * item.price), 0);
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const grandTotal = Math.max(0, subtotal - discount);
    
    document.getElementById('subtotal_display').textContent = formatNumber(subtotal) + ' RWF';
    document.getElementById('discount_display').textContent = formatNumber(discount) + ' RWF';
    document.getElementById('grand_total_display').textContent = formatNumber(grandTotal) + ' RWF';
    
    // Auto-fill payment based on method
    togglePaymentFields();
}

function togglePaymentFields() {
    const method = document.getElementById('payment_method').value;
    const cashField = document.getElementById('cash_amount');
    const momoField = document.getElementById('momo_amount');
    const grandTotal = parseFloat(document.getElementById('grand_total_display').textContent.replace(/[^0-9]/g, '')) || 0;
    
    if (method === 'cash') {
        cashField.value = grandTotal;
        momoField.value = 0;
        cashField.disabled = false;
        momoField.disabled = true;
    } else if (method === 'momo') {
        cashField.value = 0;
        momoField.value = grandTotal;
        cashField.disabled = true;
        momoField.disabled = false;
    } else if (method === 'debt') {
        cashField.value = 0;
        momoField.value = 0;
        cashField.disabled = true;
        momoField.disabled = true;
    }
}

function prepareSubmit() {
    if (cart.length === 0) {
        alert('Please add at least one product to the sale.');
        return false;
    }
    
    const grandTotal = parseFloat(document.getElementById('grand_total_display').textContent.replace(/[^0-9]/g, '')) || 0;
    const paymentMethod = document.getElementById('payment_method').value;
    
    if (paymentMethod !== 'debt') {
        const cash = parseFloat(document.getElementById('cash_amount').value) || 0;
        const momo = parseFloat(document.getElementById('momo_amount').value) || 0;
        const totalPaid = cash + momo;
        
        if (totalPaid < grandTotal) {
            alert('⚠️ Total payment is less than grand total.');
            return false;
        }
    } else {
        // For debt, ensure customer is selected
        const customerId = document.getElementById('customer_id').value;
        if (!customerId) {
            alert('⚠️ Please select a customer for debt sale.');
            return false;
        }
        
        if (!confirm('This will be recorded as debt. Customer will owe ' + formatNumber(grandTotal) + ' RWF. Continue?')) {
            return false;
        }
    }
    
    document.getElementById('items_json').value = JSON.stringify(cart);
    return true;
}

function formatNumber(n) {
    return new Intl.NumberFormat('en-RW').format(Math.round(n));
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Enter key to add item
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const active = document.activeElement;
        if (active.id === 'qty_input' || active.id === 'custom_price_input') {
            e.preventDefault();
            addItem();
        }
    }
});
</script>

<style>
.qty-input {
    font-size: 16px !important;
    font-weight: 600 !important;
    text-align: center !important;
    background: #f8f9fa !important;
    border: 2px solid #0d6efd !important;
    color: #0d6efd !important;
}

.qty-input:focus {
    background: #ffffff !important;
    border-color: #0d6efd !important;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25) !important;
}

#cart_body input[type="number"] {
    font-size: 15px !important;
    font-weight: 600 !important;
    text-align: center !important;
    background: #f8f9fa !important;
    border: 2px solid #dee2e6 !important;
}

#cart_body input[type="number"]:focus {
    background: #ffffff !important;
    border-color: #0d6efd !important;
}
</style>

<?php include '../includes/footer.php'; ?>