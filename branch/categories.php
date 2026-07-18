<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = true;
$message = '';


// FORCE ADMIN MODE FOR TESTING - REMOVE AFTER
if ($_SESSION['username'] == 'admin') {
    $is_admin = true;
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

// Add/Edit Category (Admin only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_category']) && $is_admin) {
    $id = $_POST['id'] ?? 0;
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    
    try {
        if ($id > 0) {
            // Update
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$name, $description, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Category updated successfully!</div>';
            logAction($pdo, 'Update Category', "Updated category: $name (Branch: $branch_id)");
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO categories (branch_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$branch_id, $name, $description]);
            $message = '<div class="alert alert-success">✅ Category added successfully!</div>';
            logAction($pdo, 'Add Category', "Added category: $name (Branch: $branch_id)");
        }
    } catch(PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Category (Admin only)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    
    // Check if category is used by products
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ? AND branch_id = ?");
    $check->execute([$id, $branch_id]);
    $product_count = $check->fetchColumn();
    
    if ($product_count > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete category: ' . $product_count . ' product(s) are using it. Reassign products first.</div>';
    } else {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND branch_id = ?");
        $stmt->execute([$id, $branch_id]);
        $message = '<div class="alert alert-success">✅ Category deleted successfully!</div>';
        logAction($pdo, 'Delete Category', "Deleted category ID: $id (Branch: $branch_id)");
    }
}

// ============================================
// GET DATA
// ============================================

// Get all categories with product count
$categories = $pdo->prepare("
    SELECT c.*, 
           COUNT(p.id) as product_count 
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.branch_id = ?
    WHERE c.branch_id = ?
    GROUP BY c.id
    ORDER BY c.name
");
$categories->execute([$branch_id, $branch_id]);
$categories = $categories->fetchAll();

// Get edit data (Admin only)
$edit_category = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit'], $branch_id]);
    $edit_category = $stmt->fetch();
}

include '../includes/header.php';
include '../includes/sidebar.php';

?>

<!-- ============================================
CATEGORIES PAGE
============================================ -->
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-tags me-2 text-primary"></i>Categories</h2>
        <p class="text-muted">
            Organize your products into categories for better management
        </p>
    </div>
    <?php if($is_admin): ?>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Category
        </a>
    </div>
    <?php endif; ?>
</div>

<?php echo $message; ?>

<!-- ============================================
CATEGORY STATS
============================================ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h3 class="text-primary"><?php echo count($categories); ?></h3>
            <p class="stat-label mb-0">Total Categories</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h3 class="text-success"><?php 
                $total_products = 0;
                foreach($categories as $cat) {
                    $total_products += $cat['product_count'];
                }
                echo $total_products;
            ?></h3>
            <p class="stat-label mb-0">Products Categorized</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h3 class="text-warning"><?php 
                $uncategorized = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ? AND category_id IS NULL");
                $uncategorized->execute([$branch_id]);
                echo $uncategorized->fetchColumn();
            ?></h3>
            <p class="stat-label mb-0">Uncategorized Products</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h3 class="text-info"><?php
                $avg_products = count($categories) > 0 ? round($total_products / count($categories), 1) : 0;
                echo $avg_products;
            ?></h3>
            <p class="stat-label mb-0">Avg. Products/Category</p>
        </div>
    </div>
</div>

<!-- ============================================
ADD/EDIT CATEGORY FORM (Admin Only)
============================================ -->
<?php if($is_admin && (isset($_GET['add']) || $edit_category)): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $edit_category ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $edit_category ? 'Edit Category' : 'Add New Category'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $edit_category['id'] ?? 0; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="name" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_category['name'] ?? ''); ?>" 
                               required placeholder="e.g., Electronics, Furniture, Food...">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Product Count</label>
                        <input type="text" class="form-control" readonly 
                               value="<?php echo $edit_category['product_count'] ?? 0; ?> products">
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2" 
                          placeholder="Optional category description"><?php echo htmlspecialchars($edit_category['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" name="save_category" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> 
                    <?php echo $edit_category ? 'Update Category' : 'Create Category'; ?>
                </button>
                <a href="categories.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
CATEGORIES LIST
============================================ -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Categories</h5>
        <span class="badge bg-primary"><?php echo count($categories); ?> categories</span>
    </div>
    <div class="card-body p-0">
        <?php if(empty($categories)): ?>
        <div class="text-center py-5">
            <i class="fas fa-tags fa-4x text-muted mb-3 d-block"></i>
            <h5>No Categories Yet</h5>
            <p class="text-muted">Create categories to organize your products.</p>
            <?php if($is_admin): ?>
            <a href="?add" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Add First Category
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th style="width: 120px;">Products</th>
                        <th style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($categories as $index => $cat): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                            <br>
                            <small class="text-muted">ID: <?php echo $cat['id']; ?></small>
                        </td>
                        <td><?php echo nl2br(htmlspecialchars($cat['description'] ?: '—')); ?></td>
                        <td>
                            <a href="products.php?category=<?php echo $cat['id']; ?>" class="text-decoration-none">
                                <span class="badge bg-info">
                                    <?php echo $cat['product_count']; ?> products
                                </span>
                            </a>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if($is_admin): ?>
                                <a href="?edit=<?php echo $cat['id']; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $cat['id']; ?>" class="btn btn-outline-danger" 
                                   onclick="return confirm('Delete this category? Products using it will become uncategorized.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                                <a href="products.php?category_id=<?php echo $cat['id']; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-box"></i>
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

<!-- ============================================
QUICK STATS - Category Distribution
============================================ -->
<?php if(!empty($categories)): ?>
<div class="row mt-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Category Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Top Categories</h5>
            </div>
            <div class="card-body">
                <?php 
                $sorted = $categories;
                usort($sorted, function($a, $b) {
                    return $b['product_count'] - $a['product_count'];
                });
                $top = array_slice($sorted, 0, 5);
                ?>
                <?php foreach($top as $cat): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="badge bg-primary"><?php echo $cat['product_count']; ?> products</span>
                    </div>
                    <?php 
                    $max_products = $top[0]['product_count'] ?? 1;
                    $percent = ($cat['product_count'] / $max_products) * 100;
                    ?>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $percent; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
SCRIPTS
============================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Category Distribution Chart
    const ctx = document.getElementById('categoryChart');
    if (ctx) {
        <?php
        $labels = [];
        $data = [];
        $colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6f42c1', '#fd7e14', '#20c997'];
        foreach($categories as $i => $cat) {
            $labels[] = $cat['name'];
            $data[] = $cat['product_count'];
        }
        ?>
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($data); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($colors, 0, count($categories))); ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }
});
</script>

<style>
.stat-card {
    background: white;
    padding: 15px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.stat-card h3 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
}
.stat-card .stat-label {
    color: #6c757d;
    font-size: 13px;
    margin-top: 5px;
}
.table-container {
    overflow-x: auto;
}
</style>
</div>
<?php include '../includes/footer.php'; ?>