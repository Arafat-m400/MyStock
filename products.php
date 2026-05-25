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
    logAction($pdo, 'Delete Product', "Deleted product ID: $id");
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
        logAction($pdo, 'Edit Product', "Updated product: $name");
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (name, sku, category_id, quantity, reorder_level, cost_price, selling_price, unit, description) VALUES (?,?,?,0,?,?,?,?,?)");
        $stmt->execute([$name, $sku, $category_id, $reorder_level, $cost_price, $selling_price, $unit, $description]);
        $message = '<div class="alert alert-success">Product added!</div>';
        logAction($pdo, 'Add Product', "Added product: $name (SKU: $sku)");
    }
}

$products = $pdo->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.name")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-box me-2"></i>Products</h2>
        <?php if($is_admin): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="clearForm()">
            <i class="fas fa-plus"></i> Add Product
        </button>
        <?php endif; ?>
    </div>
    
    <?php echo $message; ?>
    
    <!-- Category Filter & Search -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-filter me-1"></i>Filter by Category</label>
                    <select id="category_filter" class="form-control" onchange="filterByCategory()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-search me-1"></i>Search Product</label>
                    <input type="text" id="product_name_filter" class="form-control" placeholder="Type product name..." onkeyup="filterProductsByName()">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-chart-line me-1"></i>Stock Status</label>
                    <select id="stock_filter" class="form-control" onchange="filterByStock()">
                        <option value="">All Products</option>
                        <option value="low">Low Stock Only</option>
                        <option value="normal">Normal Stock</option>
                        <option value="zero">Out of Stock (0)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="products_table">
                    <thead class="table-light">
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Cost Price</th>
                            <th>Selling Price</th>
                            <th>Status</th>
                            <?php if($is_admin): ?>
                            <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($products as $p): ?>
                        <tr data-stock="<?php echo $p['quantity']; ?>">
                            <td><?php echo htmlspecialchars($p['sku'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo htmlspecialchars($p['category_name'] ?? 'Uncategorized'); ?></td>
                            <td class="<?php echo $p['quantity'] <= $p['reorder_level'] ? 'text-danger fw-bold' : ''; ?>">
                                <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>
                            </td>
                            <td><?php echo number_format($p['cost_price'], 2); ?></td>
                            <td><?php echo number_format($p['selling_price'], 2); ?></td>
                            <td>
                                <?php if($p['quantity'] <= 0): ?>
                                <span class="badge bg-dark">Out of Stock</span>
                                <?php elseif($p['quantity'] <= $p['reorder_level']): ?>
                                <span class="badge bg-warning">Low Stock</span>
                                <?php else: ?>
                                <span class="badge bg-success">In Stock</span>
                                <?php endif; ?>
                             </td>
                            <?php if($is_admin): ?>
                            <td>
                                <button class="btn btn-sm btn-info" onclick='editProduct(<?php echo json_encode($p); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?delete=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this product?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                             </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($products)): ?>
                        <tr>
                            <td colspan="<?php echo $is_admin ? 9 : 8; ?>" class="text-center text-muted py-4">
                                No products found. <?php if($is_admin): ?>Click "Add Product" to create one.<?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
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
                <h5 class="modal-title"><i class="fas fa-box me-2"></i>Product Details</h5>
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
                        <label>SKU (Stock Keeping Unit)</label>
                        <input type="text" name="sku" id="sku" class="form-control" placeholder="Unique identifier">
                    </div>
                    <div class="mb-2">
                        <label>Category</label>
                        <select name="category_id" id="category_id" class="form-control">
                            <option value="">-- No Category --</option>
                            <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label>Cost Price</label>
                            <input type="number" step="0.01" name="cost_price" id="cost_price" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Selling Price</label>
                            <input type="number" step="0.01" name="selling_price" id="selling_price" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" id="reorder_level" class="form-control" value="5">
                            <small class="text-muted">Alert when stock ≤ this number</small>
                        </div>
                        <div class="col-md-6">
                            <label>Unit</label>
                            <input type="text" name="unit" id="unit" class="form-control" value="pcs" placeholder="pcs, kg, box...">
                        </div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label>Description</label>
                        <textarea name="description" id="description" class="form-control" rows="2" placeholder="Optional product description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary">Save Product</button>
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
    document.getElementById('sku').value = p.sku || '';
    document.getElementById('category_id').value = p.category_id || '';
    document.getElementById('reorder_level').value = p.reorder_level;
    document.getElementById('cost_price').value = p.cost_price;
    document.getElementById('selling_price').value = p.selling_price;
    document.getElementById('unit').value = p.unit;
    document.getElementById('description').value = p.description || '';
    new bootstrap.Modal(document.getElementById('productModal')).show();
}
</script>
<?php endif; ?>

<!-- Filter Scripts -->
<script>
function filterByCategory() {
    let category = document.getElementById('category_filter').value;
    let rows = document.querySelectorAll('#products_table tbody tr');
    rows.forEach(row => {
        let catCell = row.cells[2]?.innerText;
        if(category === '' || catCell === category) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterProductsByName() {
    let search = document.getElementById('product_name_filter').value.toLowerCase();
    let rows = document.querySelectorAll('#products_table tbody tr');
    rows.forEach(row => {
        let name = row.cells[1]?.innerText.toLowerCase();
        if(name.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterByStock() {
    let stockFilter = document.getElementById('stock_filter').value;
    let rows = document.querySelectorAll('#products_table tbody tr');
    rows.forEach(row => {
        let stock = parseInt(row.dataset.stock);
        if(stockFilter === '') {
            row.style.display = '';
        } else if(stockFilter === 'low' && stock > 0 && stock <= (row.querySelector('td:nth-child(4)')?.innerText.split(' ')[0] || 0)) {
            // Show low stock
            let reorderSpan = row.querySelector('td:nth-child(7) .badge');
            if(reorderSpan && reorderSpan.innerText === 'Low Stock') {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        } else if(stockFilter === 'zero' && stock === 0) {
            row.style.display = '';
        } else if(stockFilter === 'normal' && stock > 0) {
            let reorderSpan = row.querySelector('td:nth-child(7) .badge');
            if(reorderSpan && reorderSpan.innerText !== 'Low Stock' && reorderSpan.innerText !== 'Out of Stock') {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>