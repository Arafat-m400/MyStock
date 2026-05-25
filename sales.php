<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$message = '';
$products = $pdo->query("SELECT id, name, selling_price, quantity FROM products WHERE quantity > 0 ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();

// ─── Process sale ────────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_sale'])) {
    try {
        $pdo->beginTransaction();

        $customer_id = $_POST['customer_id'] ?: null;
        $discount    = floatval($_POST['discount'] ?? 0);
        $items       = json_decode($_POST['items_json'], true);

        if(empty($items)) throw new Exception("No items added to the sale.");

        $subtotal        = 0;
        $sale_items_data = [];

        foreach($items as $item) {
            $product = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $product->execute([$item['product_id']]);
            $prod = $product->fetch();

            if(!$prod) throw new Exception("Product not found (ID: {$item['product_id']}).");
            if($prod['quantity'] < $item['quantity'])
                throw new Exception("Insufficient stock for \"{$prod['name']}\". Available: {$prod['quantity']}.");

            $unit_price    = floatval($item['price']);
            $item_subtotal = $item['quantity'] * $unit_price;
            $subtotal     += $item_subtotal;

            $sale_items_data[] = [
                'product_id'   => $prod['id'],
                'quantity'     => $item['quantity'],
                'selling_price'=> $unit_price,
                'cost_price'   => $prod['cost_price'],
                'subtotal'     => $item_subtotal
            ];
        }

        $grand_total = $subtotal - $discount;
        if($grand_total < 0) $grand_total = 0;

        $invoice_no = generateInvoiceNo('INV');
        $sale_date  = date('Y-m-d');

        $stmt = $pdo->prepare(
            "INSERT INTO sales (customer_id, invoice_no, sale_date, subtotal, discount, grand_total, created_by)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$customer_id, $invoice_no, $sale_date, $subtotal, $discount, $grand_total, $_SESSION['user_id']]);
        $sale_id = $pdo->lastInsertId();

        foreach($sale_items_data as $item) {
            $stmt = $pdo->prepare(
                "INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, cost_price_at_sale, subtotal)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['selling_price'],
                $item['cost_price'],
                $item['subtotal']
            ]);
            // Deduct stock
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")
                ->execute([$item['quantity'], $item['product_id']]);
        }

        // Update customer total_spent
        if($customer_id) {
            $pdo->prepare("UPDATE customers SET total_spent = total_spent + ? WHERE id = ?")
                ->execute([$grand_total, $customer_id]);
        }

        $pdo->commit();
        
        // Log the sale
        if(function_exists('logAction')) {
            logAction($pdo, 'Sale Completed', "Invoice: $invoice_no, Total: $grand_total RWF");
        }
        
        $message = '<div class="alert alert-success">✅ Sale completed!
                    Invoice: <strong>' . $invoice_no . '</strong> &nbsp;
                    <a href="view_invoice.php?id=' . $sale_id . '" target="_blank"
                       class="btn btn-sm btn-outline-success">🖨 View Invoice</a></div>';

        // Refresh product list after sale
        $products = $pdo->query("SELECT id, name, selling_price, quantity FROM products WHERE quantity > 0 ORDER BY name")->fetchAll();

    } catch(Exception $e) {
        // Only rollback if a transaction is active
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Sales history with role-based filtering
if(isAdmin()) {
    $sales_list = $pdo->query(
        "SELECT s.*, c.name as customer_name, u.full_name as cashier
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         LEFT JOIN users u ON s.created_by = u.id
         ORDER BY s.created_at DESC LIMIT 50"
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT s.*, c.name as customer_name, u.full_name as cashier
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         LEFT JOIN users u ON s.created_by = u.id
         WHERE s.created_by = ?
         ORDER BY s.created_at DESC LIMIT 50"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $sales_list = $stmt->fetchAll();
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="col-md-10 p-4">
    <h2 class="mb-4"><i class="fas fa-cash-register me-2"></i>Sales</h2>
    <?php echo $message; ?>

    <!-- New Sale Form -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>New Sale</h5>
        </div>
        <div class="card-body">
            <form id="saleForm" method="POST" onsubmit="return prepareSubmit()">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control" id="customer_id">
                            <option value="">Walk-in Customer</option>
                            <?php foreach($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Discount (RWF)</label>
                        <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="0" oninput="updateTotals()">
                    </div>
                </div>

                <!-- Add product row with SEARCH feature - FIXED VERSION -->
                <div class="mb-3">
                    <label class="form-label">Add Product to Cart</label>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <input type="text" id="product_search" class="form-control" placeholder="🔍 Type product name to search..." autocomplete="off">
                            <div id="product_dropdown" class="dropdown-menu show" style="position: absolute; width: 100%; max-height: 300px; overflow-y: auto; display: none;">
                                <?php foreach($products as $p): ?>
                                <a href="#" class="dropdown-item" data-id="<?php echo $p['id']; ?>" 
                                   data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                   data-price="<?php echo $p['selling_price']; ?>"
                                   data-stock="<?php echo $p['quantity']; ?>"
                                   onclick="selectProduct(this); return false;">
                                    <?php echo htmlspecialchars($p['name']); ?> - <?php echo number_format($p['selling_price'], 0); ?> RWF (Stock: <?php echo $p['quantity']; ?>)
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <input type="number" id="qty_input" class="form-control" placeholder="Qty" min="1" value="1">
                        </div>
                        <div class="col-md-3">
                            <input type="number" id="custom_price_input" class="form-control" placeholder="Custom price (optional)" step="0.01" min="0">
                            <small class="text-muted">Leave blank to use default price</small>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-secondary w-100" onclick="addItem()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    <div id="selected_product_display" class="mt-2 text-muted small"></div>
                </div>

                <!-- Cart table -->
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th style="width:90px">Qty</th>
                                <th style="width:140px">Unit Price</th>
                                <th style="width:130px">Subtotal</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody id="cart_body">
                            <tr id="empty_row"><td colspan="5" class="text-center text-muted py-3">No items yet — add a product above.</td></tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-light"><th colspan="3" class="text-end">Subtotal:</th><th id="subtotal_display">0 RWF</th><th></th></tr>
                            <tr class="table-warning"><th colspan="3" class="text-end">Discount:</th><th id="discount_display">0 RWF</th><th></th></tr>
                            <tr class="table-success"><th colspan="3" class="text-end">Grand Total:</th><th id="grand_total_display">0 RWF</th><th></th></tr>
                        </tfoot>
                    </table>
                </div>

                <input type="hidden" name="items_json" id="items_json">
                <input type="hidden" name="selected_product_id" id="selected_product_id">
                <input type="hidden" name="selected_product_price" id="selected_product_price">
                
                <button type="submit" name="process_sale" class="btn btn-success btn-lg">
                    <i class="fas fa-check-circle me-2"></i>Complete Sale
                </button>
            </form>
        </div>
    </div>

    <!-- Sales History -->
    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Sales History</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Subtotal</th><th>Discount</th><th>Grand Total</th><th>Cashier</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($sales_list)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No sales yet.<?php else: ?>
                        <?php foreach($sales_list as $sale): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($sale['invoice_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                            <td><?php echo $sale['sale_date']; ?></td>
                            <td><?php echo number_format($sale['subtotal'], 0); ?> RWF</td>
                            <td class="text-danger"><?php echo number_format($sale['discount'], 0); ?> RWF</td>
                            <td><strong><?php echo number_format($sale['grand_total'], 0); ?> RWF</strong></td>
                            <td><?php echo htmlspecialchars($sale['cashier']); ?></td>
                            <td><a href="view_invoice.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-info" target="_blank"><i class="fas fa-file-invoice"></i> Invoice</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
let selectedProduct = null;

// Product search with dropdown
document.getElementById('product_search').addEventListener('input', function() {
    let search = this.value.toLowerCase();
    let dropdown = document.getElementById('product_dropdown');
    let items = dropdown.querySelectorAll('.dropdown-item');
    let hasMatch = false;
    
    items.forEach(item => {
        let text = item.textContent.toLowerCase();
        if(text.includes(search) || search === '') {
            item.style.display = 'block';
            hasMatch = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    dropdown.style.display = hasMatch && search !== '' ? 'block' : 'none';
});

// Hide dropdown when clicking outside
document.addEventListener('click', function(e) {
    let dropdown = document.getElementById('product_dropdown');
    let searchBox = document.getElementById('product_search');
    if(!dropdown.contains(e.target) && e.target !== searchBox) {
        dropdown.style.display = 'none';
    }
});

function selectProduct(element) {
    let id = element.dataset.id;
    let name = element.dataset.name;
    let price = parseFloat(element.dataset.price);
    let stock = parseInt(element.dataset.stock);
    
    selectedProduct = {
        id: id,
        name: name,
        price: price,
        stock: stock
    };
    
    document.getElementById('product_search').value = name;
    document.getElementById('selected_product_display').innerHTML = '<span class="badge bg-success">✓ Selected: ' + name + ' (Price: ' + formatNumber(price) + ' RWF, Stock: ' + stock + ')</span>';
    document.getElementById('product_dropdown').style.display = 'none';
}

function addItem() {
    if(!selectedProduct) {
        alert('Please select a product from the search dropdown first.');
        document.getElementById('product_search').focus();
        return;
    }
    
    const qty = parseInt(document.getElementById('qty_input').value);
    if(isNaN(qty) || qty < 1) {
        alert('Enter a valid quantity (at least 1).');
        return;
    }
    
    if(qty > selectedProduct.stock) {
        alert(`Not enough stock for "${selectedProduct.name}". Available: ${selectedProduct.stock}`);
        return;
    }
    
    const customPrice = parseFloat(document.getElementById('custom_price_input').value);
    const price = (!isNaN(customPrice) && customPrice > 0) ? customPrice : selectedProduct.price;
    
    const existing = cart.find(i => i.product_id == selectedProduct.id);
    if(existing) {
        if(existing.quantity + qty > selectedProduct.stock) {
            alert(`Cannot add ${qty}. Only ${selectedProduct.stock - existing.quantity} more available.`);
            return;
        }
        existing.quantity += qty;
        if(!isNaN(customPrice) && customPrice > 0) existing.price = price;
    } else {
        cart.push({
            product_id: selectedProduct.id,
            name: selectedProduct.name,
            quantity: qty,
            price: price
        });
    }
    
    // Update stock in the selected product object
    selectedProduct.stock -= qty;
    
    renderCart();
    
    // Reset form
    document.getElementById('qty_input').value = 1;
    document.getElementById('custom_price_input').value = '';
    document.getElementById('product_search').value = '';
    document.getElementById('selected_product_display').innerHTML = '';
    selectedProduct = null;
}

function removeItem(index) {
    cart.splice(index, 1);
    renderCart();
}

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    
    if(cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No items yet — add a product above.</td></tr>';
        updateTotals();
        return;
    }
    
    cart.forEach((item, index) => {
        const subtotal = item.quantity * item.price;
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(item.name)}</td>
            <td><input type="number" min="1" value="${item.quantity}" class="form-control form-control-sm" onchange="updateQty(${index}, this.value)"></td>
            <td><input type="number" min="0" step="0.01" value="${item.price.toFixed(2)}" class="form-control form-control-sm" onchange="updatePrice(${index}, this.value)"></td>
            <td>${formatNumber(subtotal)} RWF</td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})"><i class="fas fa-trash"></i></button></td>
        `;
        tbody.appendChild(row);
    });
    
    updateTotals();
}

function updateQty(index, val) {
    let qty = parseInt(val);
    if(!isNaN(qty) && qty > 0) {
        cart[index].quantity = qty;
        renderCart();
    }
}

function updatePrice(index, val) {
    let p = parseFloat(val);
    if(!isNaN(p) && p >= 0) {
        cart[index].price = p;
        renderCart();
    }
}

function updateTotals() {
    const subtotal = cart.reduce((sum, i) => sum + i.quantity * i.price, 0);
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const grandTotal = Math.max(0, subtotal - discount);
    
    document.getElementById('subtotal_display').textContent = formatNumber(subtotal) + ' RWF';
    document.getElementById('discount_display').textContent = formatNumber(discount) + ' RWF';
    document.getElementById('grand_total_display').textContent = formatNumber(grandTotal) + ' RWF';
}

function prepareSubmit() {
    if(cart.length === 0) {
        alert('Please add at least one product to the sale.');
        return false;
    }
    document.getElementById('items_json').value = JSON.stringify(cart);
    return true;
}

function formatNumber(n) {
    return new Intl.NumberFormat('en-RW').format(Math.round(n));
}

function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php include 'includes/footer.php'; ?>