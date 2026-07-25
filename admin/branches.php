<?php
require_once '../config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('../index.php');

$message = '';

// ============================================
// HANDLE BRANCH OPERATIONS
// ============================================

// Add Branch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_branch'])) {
    $name = sanitize($_POST['name']);
    $code = sanitize($_POST['code']);
    $location = sanitize($_POST['location']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $whatsapp = sanitize($_POST['whatsapp']);
    $status = $_POST['status'] ?? 'active';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO branches (name, code, location, phone, email, whatsapp, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $code, $location, $phone, $email, $whatsapp, $status]);
        $message = '<div class="alert alert-success">✅ Branch added successfully!</div>';
        logAction($pdo, 'Add Branch', "Added branch: $name");
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Edit Branch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_branch'])) {
    $id = $_POST['id'];
    $name = sanitize($_POST['name']);
    $code = sanitize($_POST['code']);
    $location = sanitize($_POST['location']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $whatsapp = sanitize($_POST['whatsapp']);
    $status = $_POST['status'] ?? 'active';
    
    try {
        $stmt = $pdo->prepare("
            UPDATE branches 
            SET name=?, code=?, location=?, phone=?, email=?, whatsapp=?, status=?
            WHERE id=?
        ");
        $stmt->execute([$name, $code, $location, $phone, $email, $whatsapp, $status, $id]);
        $message = '<div class="alert alert-success">✅ Branch updated successfully!</div>';
        logAction($pdo, 'Edit Branch', "Updated branch: $name");
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Branch - Updated with user cleanup
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if branch has data
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ?");
    $check->execute([$id]);
    $product_count = $check->fetchColumn();
    
    $check2 = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE branch_id = ?");
    $check2->execute([$id]);
    $sale_count = $check2->fetchColumn();
    
    if ($product_count > 0 || $sale_count > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete: Branch has ' . $product_count . ' products and ' . $sale_count . ' sales records.</div>';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Remove user-branch assignments first
            $pdo->prepare("DELETE FROM user_branches WHERE branch_id = ?")->execute([$id]);
            
            // Delete expenses
            $pdo->prepare("DELETE FROM expenses WHERE branch_id = ?")->execute([$id]);
            
            // Delete the branch
            $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$id]);
            
            $pdo->commit();
            $message = '<div class="alert alert-success">✅ Branch deleted successfully!</div>';
            logAction($pdo, 'Delete Branch', "Deleted branch ID: $id");
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
        }
    }
}

// ============================================
// GET DATA
// ============================================

$branches = $pdo->query("SELECT * FROM branches ORDER BY name")->fetchAll();

// Get edit data
$edit_branch = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_branch = $stmt->fetch();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-building me-2 text-primary"></i>Manage Branches</h2>
        <p class="text-muted">Add, edit, or remove branches</p>
    </div>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Branch
        </a>
    </div>
</div>

<?php echo $message; ?>

<!-- ============================================
ADD/EDIT BRANCH FORM
============================================ -->
<?php if(isset($_GET['add']) || $edit_branch): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $edit_branch ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $edit_branch ? 'Edit Branch' : 'Add New Branch'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $edit_branch['id'] ?? 0; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Branch Name *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?php echo htmlspecialchars($edit_branch['name'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Code *</label>
                    <input type="text" name="code" class="form-control" required
                           value="<?php echo htmlspecialchars($edit_branch['code'] ?? ''); ?>"
                           placeholder="e.g., KGL-001">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control"
                           value="<?php echo htmlspecialchars($edit_branch['location'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($edit_branch['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_branch['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo htmlspecialchars($edit_branch['phone'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">WhatsApp</label>
                    <input type="text" name="whatsapp" class="form-control"
                           value="<?php echo htmlspecialchars($edit_branch['whatsapp'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($edit_branch['email'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" name="<?php echo $edit_branch ? 'edit_branch' : 'add_branch'; ?>" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>
                    <?php echo $edit_branch ? 'Update Branch' : 'Add Branch'; ?>
                </button>
                <a href="branches.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
BRANCHES LIST
============================================ -->
<?php if(!isset($_GET['add']) && !$edit_branch): ?>
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Branches</h5>
        <span class="badge bg-primary"><?php echo count($branches); ?> branches</span>
    </div>
    <div class="card-body p-0">
        <?php if(empty($branches)): ?>
        <div class="text-center py-5">
            <i class="fas fa-building fa-4x text-muted mb-3 d-block"></i>
            <h5>No Branches</h5>
            <p class="text-muted">Add your first branch to get started.</p>
            <a href="?add" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Add Branch
            </a>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Location</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($branches as $index => $b): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($b['name']); ?></strong>
                        </td>
                        <td><code><?php echo htmlspecialchars($b['code']); ?></code></td>
                        <td><?php echo htmlspecialchars($b['location'] ?? '—'); ?></td>
                        <td>
                            <?php if($b['phone']): ?>
                            <div><small><?php echo $b['phone']; ?></small></div>
                            <?php endif; ?>
                            <?php if($b['email']): ?>
                            <div><small><?php echo $b['email']; ?></small></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $b['status'] == 'active' ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($b['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="?edit=<?php echo $b['id']; ?>" class="btn btn-outline-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $b['id']; ?>" class="btn btn-outline-danger" 
                                   onclick="return confirm('Delete branch \'<?php echo addslashes($b['name']); ?>\'? All data will be lost.')">
                                    <i class="fas fa-trash"></i>
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
<?php include '../includes/footer.php'; ?>