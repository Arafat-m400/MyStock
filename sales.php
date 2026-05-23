<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$message = '';
$products = $pdo->query("SELECT id, name, selling_price, quantity FROM products WHERE quantity > 0 ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();

// Process sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_sale'])) {
    try {
        $pdo->beginTransaction();
        
        $customer_id = $_POST['customer_id'] ?: null;
        $discount = $_POST['discount'] ?? 0;
        $items = json_decode($_POST['items_json'], true);
        
        if(empty($items)) throw new Exception("No items in sale");
        
        $subtotal = 0;
        $sale_items_data = [];
        
        foreach($items as $item) {
            $product = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $product->execute([$item['product_id']]);
            $prod = $product->fetch();
            if(!$prod) throw new Exception("Product not found");
            if($prod['quantity'] < $item['quantity']) throw new Exception("Insufficient stock for " . $prod['name']);
            
            $item_subtotal = $item['quantity'] * $prod['selling_price'];
            $subtotal += $item_subtotal;
            $sale_items_data[] = [
                'product_id' => $prod['id'],
                'quantity' => $item['quantity'],
                'selling_price' => $prod['selling_price'],
                'cost_price' => $prod['cost_price'],
                'subtotal' => $item_subtotal
            ];
        }
        
        $grand_total = $subtotal - $discount;
        $invoice_no = generateInvoiceNo('INV');
        $sale_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("INSERT INTO sales (customer_id, invoice_no, sale_date, subtotal, discount, grand_total, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$customer_id, $invoice_no, $sale_date, $subtotal, $discount, $grand_total, $_SESSION['user_id']]);
        $sale_id = $pdo->lastInsertId();
        
        foreach($sale_items_data as $item) {
            $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, cost_price_at_sale, subtotal) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$sale_id, $item['product_id'], $item['quantity'], $item['selling_price'], $item['cost_price'], $item['subtotal']]);
            
            // Update stock
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
        }
        
        // Update customer total spent
        if($customer_id) {
            $pdo->prepare("UPDATE customers SET total_spent = total_spent + ? WHERE id = ?")->execute([$grand_total, $customer_id]);
        }
        
        $pdo->commit();
        $message = '<div class="alert alert-success">Sale completed! Invoice: ' . $invoice_no . ' <a href="view_invoice.php?id=' . $sale_id . '" target="_blank">View Invoice</a></div>';
    } catch(Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

// Fetch sales list
$sales_list = $pdo->query("SELECT s.*, c.name as customer_name, u.full_name as cashier FROM sales s LEFT JOIN customers c ON s.customer_id = c.id LEFT JOIN users u ON s.created_by = u.id ORDER BY s.created_at DESC")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2 class="mb-4">Sales</h2>
    <?php echo $message; ?>
    
    <!-- Sale Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">New Sale</h5>
        </div>
        <div class="card-body">
            <form id="saleForm" method="POST">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control" id="customer_id">
                            <option value="">Walk-in Customer</option>
                            <?php foreach($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>Discount</label>
                        <input type="number" step="0.01" name="discount" id="discount" class="form-control" value="0">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label>Add Product</label>
                    <div class="input-group">
                        <select id="product_select" class="form-control">
                            <option value="">Select Product</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['selling_price']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-stock="<?php echo $p['quantity']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> - <?php echo number_format($p['selling_price'], 2); ?> (Stock: <?php echo $p['quantity']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="quantity" class="form-control" placeholder="Qty" style="width:100px"><br>
                        <input type="number" id="custom_price" class="form-control" placeholder="Price (optional)" step="0.01" style="width:120px">
                        <button type="button" class="btn btn-secondary" onclick="addItem()">Add</button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered" id="items_table">
                        <thead>
                            <tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th><th></th></tr>
                        </thead>
                        <tbody id="cart_items"></tbody>
                        <tfoot>
                            <tr><th colspan="3" class="text-end">Subtotal:</th><th id="subtotal_display">0.00</th><th></th></tr>
                            <tr><th colspan="3" class="text-end">Discount:</th><th id="discount_display">0.00</th><th></th></tr>
                            <tr><th colspan="3" class="text-end">Grand Total:</th><th id="grand_total_display">0.00</th><th></th></tr>
                        </tfoot>
                    </table>
                </div>
                
                <input type="hidden" name="items_json" id="items_json">
                <button type="submit" name="process_sale" class="btn btn-success btn-lg">Complete Sale</button>
            </form>
        </div>
    </div>
    
    <!-- Sales History -->
    <div class="card">
        <div class="card-header">
            <h5>Sales History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Total</th><th>Cashier</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($sales_list as $sale): ?>
                        <tr>
                            <td><?php echo $sale['invoice_no']; ?></td>
                            <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                            <td><?php echo $sale['sale_date']; ?></td>
                            <td><?php echo number_format($sale['grand_total'], 2); ?></td>
                            <td><?php echo htmlspecialchars($sale['cashier']); ?></td>
                            <td><a href="view_invoice.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-info" target="_blank">View Invoice</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
function addItem() {
    let select = document.getElementById('product_select');
    let productId = select.value;
    if(!productId) return alert('Select product');
    let productName = select.options[select.selectedIndex].dataset.name;
    let defaultPrice = parseFloat(select.options[select.selectedIndex].dataset.price);
    let quantity = parseInt(document.getElementById('quantity').value);
    let customPrice = parseFloat(document.getElementById('custom_price').value);
    
    // Use custom price if valid, else default price
    let price = (!isNaN(customPrice) && customPrice > 0) ? customPrice : defaultPrice;
    
    if(isNaN(quantity) || quantity < 1) return alert('Enter valid quantity');
    
    let existing = cart.find(i => i.product_id == productId);
    if(existing) {
        existing.quantity += quantity;
    } else {
        cart.push({ product_id: productId, name: productName, quantity: quantity, price: price });
    }
    renderCart();
    document.getElementById('quantity').value = '';
    document.getElementById('custom_price').value = '';
    select.value = '';
}
function renderCart() { ... same as before ... }
</script>
<?php include 'includes/footer.php'; ?>