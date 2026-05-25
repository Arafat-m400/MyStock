<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$is_admin = isAdmin();
$message = '';

// Only admin can add/edit/delete
if($is_admin) {
    // Add/Edit category
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_category'])) {
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        
        if($id > 0) {
            $stmt = $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
            $stmt->execute([$name, $description, $id]);
            $message = '<div class="alert alert-success">Category updated!</div>';
            logAction($pdo, 'Edit Category', "Updated category: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?,?)");
            $stmt->execute([$name, $description]);
            $message = '<div class="alert alert-success">Category added!</div>';
            logAction($pdo, 'Add Category', "Added category: $name");
        }
    }
    
    // Delete category
    if(isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $id = $_GET['delete'];
        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $check->execute([$id]);
        if($check->fetchColumn() > 0) {
            $message = '<div class="alert alert-warning">Cannot delete: Some products use this category. Reassign them first.</div>';
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $message = '<div class="alert alert-success">Category deleted!</div>';
            logAction($pdo, 'Delete Category', "Deleted category ID: $id");
        }
    }
}

$categories = $pdo->query("SELECT c.*, COUNT(p.id) as product_count FROM categories c LEFT JOIN products p ON c.id = p.category_id GROUP BY c.id ORDER BY c.name")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2><i class="fas fa-tags me-2"></i>Product Categories</h2>
    <?php echo $message; ?>
    
    <?php if($is_admin): ?>
    <!-- Admin: Show Add/Edit Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><?php echo isset($_GET['edit']) ? 'Edit Category' : 'Add New Category'; ?></h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php 
                $edit_cat = null;
                if(isset($_GET['edit'])) {
                    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
                    $stmt->execute([$_GET['edit']]);
                    $edit_cat = $stmt->fetch();
                }
                ?>
                <input type="hidden" name="id" value="<?php echo $edit_cat['id'] ?? 0; ?>">
                <div class="row">
                    <div class="col-md-6">
                        <label>Category Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_cat['name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="1"><?php echo htmlspecialchars($edit_cat['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="save_category" class="btn btn-primary">Save Category</button>
                    <?php if(isset($_GET['edit'])): ?>
                    <a href="categories.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Category List (visible to both admin and worker) -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Categories</h5>
        </div>
        <div class="card-body">
            <?php if(empty($categories)): ?>
            <p class="text-center text-muted py-4">No categories yet. 
            <?php if($is_admin): ?>Add one above.<?php else: ?>Contact admin to create categories.<?php endif; ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Products Count</th>
                            <?php if($is_admin): ?>
                            <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($categories as $index => $cat): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($cat['description'] ?: '—'); ?></td>
                            <td><span class="badge bg-info"><?php echo $cat['product_count']; ?> products</span></td>
                            <?php if($is_admin): ?>
                            <td>
                                <a href="?edit=<?php echo $cat['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="?delete=<?php echo $cat['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete category? Products using it will lose category.')">Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>