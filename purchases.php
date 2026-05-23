<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$message = '';
$products = $pdo->query("SELECT id, name, cost_price, quantity FROM products ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();

// Process purchase
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_purchase'])) {
    try {
        $pdo->beginTransaction();
        
        $supplier_id = $_POST['supplier_id'] ?: null;
        $invoice_no = $_POST['invoice_no'] ?: generateInvoiceNo('PO');
        $purchase_date = $_POST['purchase_date'];
        $items = json_decode($_POST['items_json'], true);
        
        if(empty($items)) throw new Exception("No items in purchase");
        
        $total_amount = 0;
        $purchase_items = [];
        
        foreach($items as $item) {
            $product = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $product->execute([$item['product_id']]);
            $prod = $product->fetch();
            if(!$prod) throw new Exception("Product not found");
            
            $unit_price = $item['unit_price'];
            $quantity = $item['quantity'];
            $subtotal = $unit_price * $quantity;
            $total_amount += $subtotal;
            
            $purchase_items[] = [
                'product_id' => $prod['id'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'subtotal' => $subtotal
            ];
        }
        
        // Insert purchase record
        $stmt = $pdo->prepare("INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total_amount, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$supplier_id, $invoice_no, $purchase_date, $total_amount, $_SESSION['user_id']]);
        $purchase_id = $pdo->lastInsertId();
        
        foreach($purchase_items as $item) {
            $stmt = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)");
            $stmt->execute([$purchase_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            
            // Update product stock and cost price (weighted average)
            $prod = $pdo->prepare("SELECT quantity, cost_price FROM products WHERE id = ?");
            $prod->execute([$item['product_id']]);
            $current = $prod->fetch();
            $new_qty = $current['quantity'] + $item['quantity'];
            $new_cost = (($current['quantity'] * $current['cost_price']) + ($item['quantity'] * $item['unit_price'])) / $new_qty;
            
            $update = $pdo->prepare("UPDATE products SET quantity = ?, cost_price = ? WHERE id = ?");
            $update->execute([$new_qty, round($new_cost, 2), $item['product_id']]);
        }
        
        $pdo->commit();
        $message = '<div class="alert alert-success">Purchase recorded! Stock updated with new average cost.</div>';
    } catch(Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

// Fetch purchase history
$purchases = $pdo->query("SELECT p.*, s.name as supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.created_at DESC")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Stock-In / Purchases</h2>
    <?php echo $message; ?>
    
    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><h5>New Stock-In</h5></div>
        <div class="card-body">
            <form method="POST" id="purchaseForm">
                <div class="row mb-3">
                    <div class="col-md-3"><label>Supplier</label><select name="supplier_id" class="form-control"><option value="">-- None --</option><?php foreach($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label>Invoice #</label><input type="text" name="invoice_no" class="form-control" placeholder="Auto if empty"></div>
                    <div class="col-md-3"><label>Purchase Date</label><input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                </div>
                
                <div class="mb-3">
                    <label>Add Product</label>
                    <div class="input-group">
                        <select id="product_select" class="form-control">
                            <option value="">Select Product</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-cost="<?php echo $p['cost_price']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> (Current cost: <?php echo number_format($p['cost_price'],2); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="qty" class="form-control" placeholder="Quantity" style="width:100px">
                        <input type="number" id="unit_price" class="form-control" placeholder="Unit Price" step="0.01" style="width:120px">
                        <button type="button" class="btn btn-secondary" onclick="addPurchaseItem()">Add</button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered" id="purchase_table">
                        <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th><th></th></tr></thead>
                        <tbody id="purchase_items"></tbody>
                        <tfoot><tr><th colspan="3" class="text-end">Total:</th><th id="purchase_total">0.00</th><th></th></tr></tfoot>
                    </table>
                </div>
                
                <input type="hidden" name="items_json" id="purchase_items_json">
                <button type="submit" name="save_purchase" class="btn btn-success">Save Purchase</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h5>Purchase History</h5></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Total</th><th>By</th></tr></thead>
                <tbody>
                    <?php foreach($purchases as $po): ?>
                    <tr>
                        <td><?php echo $po['invoice_no']; ?></td>
                        <td><?php echo htmlspecialchars($po['supplier_name'] ?? 'N/A'); ?></td>
                        <td><?php echo $po['purchase_date']; ?></td>
                        <td><?php echo number_format($po['total_amount'],2); ?></td>
                        <td><?php echo $po['created_by']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let purchaseCart = [];
function addPurchaseItem() {
    let select = document.getElementById('product_select');
    let prodId = select.value;
    if(!prodId) return alert('Select product');
    let prodName = select.options[select.selectedIndex].dataset.name;
    let qty = parseInt(document.getElementById('qty').value);
    let price = parseFloat(document.getElementById('unit_price').value);
    if(isNaN(qty) || qty<1) return alert('Valid quantity');
    if(isNaN(price) || price<0) return alert('Valid price');
    
    purchaseCart.push({ product_id: prodId, name: prodName, quantity: qty, unit_price: price });
    renderPurchaseCart();
    document.getElementById('qty').value = '';
    document.getElementById('unit_price').value = '';
    select.value = '';
}
function removePurchaseItem(idx) { purchaseCart.splice(idx,1); renderPurchaseCart(); }
function renderPurchaseCart() {
    let tbody = document.getElementById('purchase_items');
    tbody.innerHTML = '';
    let total = 0;
    purchaseCart.forEach((item,idx) => {
        let sub = item.quantity * item.unit_price;
        total += sub;
        tbody.innerHTML += `<tr><td>${item.name}</td><td>${item.quantity}</td><td>${item.unit_price.toFixed(2)}</td><td>${sub.toFixed(2)}</td><td><button class="btn btn-sm btn-danger" onclick="removePurchaseItem(${idx})">X</button></td></tr>`;
    });
    document.getElementById('purchase_total').innerText = total.toFixed(2);
    document.getElementById('purchase_items_json').value = JSON.stringify(purchaseCart.map(i => ({ product_id: i.product_id, quantity: i.quantity, unit_price: i.unit_price })));
}
</script>
<?php include 'includes/footer.php'; ?>e