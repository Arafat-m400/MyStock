<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$is_admin = isAdmin();
$message = '';

// Handle delete (admin only)
if($is_admin && isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $message = '<div class="alert alert-success">Product deleted successfully!</div>';
}

// Handle add/edit (admin only)
if($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_product'])) {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'];
    $sku = $_POST['sku'];
    $category_id = $_POST['category_id'] ?: null;
    $reorder_level = $_POST['reorder_level'];
    $cost_price = $_POST['cost_price'];
    $selling_price = $_POST['selling_price'];
    $unit = $_POST['unit'];
    $description = $_POST['description'];
    
    if($id > 0) {
        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, reorder_level=?, cost_price=?, selling_price=?, unit=?, description=? WHERE id=?");
        $stmt->execute([$name, $sku, $category_id, $reorder_level, $cost_price, $selling_price, $unit, $description, $id]);
        $message = '<div class="alert alert-success">Product updated!</div>';
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (name, sku, category_id, quantity, reorder_level, cost_price, selling_price, unit, description) VALUES (?,?,?,0,?,?,?,?,?)");
        $stmt->execute([$name, $sku, $category_id, $reorder_level, $cost_price, $selling_price, $unit, $description]);
        $message = '<div class="alert alert-success">Product added!</div>';
    }
}

$products = $pdo->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.name")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Products</h2>
        <?php if($is_admin): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="clearForm()">
            <i class="fas fa-plus"></i> Add Product
        </button>
        <?php endif; ?>
    </div>
    
    <?php echo $message; ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr><th>SKU</th><th>Name</th><th>Category</th><th>Stock</th><th>Cost Price</th><th>Selling Price</th><th>Status</th><?php if($is_admin): ?><th>Actions</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach($products as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['sku']); ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo htmlspecialchars($p['category_name'] ?? 'Uncategorized'); ?></td>
                            <td class="<?php echo $p['quantity'] <= $p['reorder_level'] ? 'text-danger fw-bold' : ''; ?>">
                                <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>
                            </td>
                            <td><?php echo number_format($p['cost_price'], 2); ?></td>
                            <td><?php echo number_format($p['selling_price'], 2); ?></td>
                            <td>
                                <?php if($p['quantity'] <= $p['reorder_level']): ?>
                                <span class="badge bg-warning">Low Stock</span>
                                <?php else: ?>
                                <span class="badge bg-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <?php if($is_admin): ?>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?delete=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this product?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Product Modal (Admin only) -->
<?php if($is_admin): ?>
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" id="product_id">
                    <div class="mb-2">
                        <label>Name *</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label>SKU</label>
                        <input type="text" name="sku" id="sku" class="form-control">
                    </div>
                    <div class="mb-2">
    <label>Category</label>
    <select name="category_id" id="category_id" class="form-control">
        <option value="">-- No Category --</option>
        <?php 
        $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
        foreach($categories as $cat): ?>
        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
        <?php endforeach; ?>
    </select>
</div>
                    <div class="mb-2">
                        <label>Reorder Level</label>
                        <input type="number" name="reorder_level" id="reorder_level" class="form-control" value="5">
                    </div>
                    <div class="mb-2">
                        <label>Cost Price</label>
                        <input type="number" step="0.01" name="cost_price" id="cost_price" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label>Selling Price</label>
                        <input type="number" step="0.01" name="selling_price" id="selling_price" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label>Unit</label>
                        <input type="text" name="unit" id="unit" class="form-control" value="pcs">
                    </div>
                    <div class="mb-2">
                        <label>Description</label>
                        <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function clearForm() {
    document.getElementById('product_id').value = '';
    document.getElementById('name').value = '';
    document.getElementById('sku').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('reorder_level').value = '5';
    document.getElementById('cost_price').value = '';
    document.getElementById('selling_price').value = '';
    document.getElementById('unit').value = 'pcs';
    document.getElementById('description').value = '';
}
function editProduct(p) {
    document.getElementById('product_id').value = p.id;
    document.getElementById('name').value = p.name;
    document.getElementById('sku').value = p.sku;
    document.getElementById('category_id').value = p.category_id || '';
    document.getElementById('reorder_level').value = p.reorder_level;
    document.getElementById('cost_price').value = p.cost_price;
    document.getElementById('selling_price').value = p.selling_price;
    document.getElementById('unit').value = p.unit;
    document.getElementById('description').value = p.description;
    new bootstrap.Modal(document.getElementById('productModal')).show();
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>