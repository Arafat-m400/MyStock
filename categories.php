<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$message = '';
// Add/Edit category
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_category'])) {
    $id = $_POST['id'] ?? 0;
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if($id > 0) {
        $stmt = $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
        $stmt->execute([$name, $description, $id]);
        $message = '<div class="alert alert-success">Category updated!</div>';
    } else {
        $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?,?)");
        $stmt->execute([$name, $description]);
        $message = '<div class="alert alert-success">Category added!</div>';
    }
}

// Delete category
if(isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    // Check if any product uses this category
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $check->execute([$id]);
    if($check->fetchColumn() > 0) {
        $message = '<div class="alert alert-warning">Cannot delete: Some products use this category. Reassign them first.</div>';
    } else {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">Category deleted!</div>';
    }
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Product Categories</h2>
    <?php echo $message; ?>
    
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
                <div class="mb-3">
                    <label>Category Name *</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_cat['name'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($edit_cat['description'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="save_category" class="btn btn-primary">Save Category</button>
                <?php if(isset($_GET['edit'])): ?>
                <a href="categories.php" class="btn btn-secondary">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5>Existing Categories</h5>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach($categories as $cat): ?>
                    <tr>
                        <td><?php echo $cat['id']; ?></td>
                        <td><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td><?php echo htmlspecialchars($cat['description']); ?></td>
                        <td>
                            <a href="?edit=<?php echo $cat['id']; ?>" class="btn btn-sm btn-info">Edit</a>
                            <a href="?delete=<?php echo $cat['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete category? Products using it will lose category.')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(count($categories) == 0): ?>
                    <tr><td colspan="4" class="text-center">No categories yet. Add one above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>