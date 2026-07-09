<?php
require_once '../config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('../index.php');

$message = '';

// ============================================
// HANDLE USER OPERATIONS
// ============================================

// Add User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = sanitize($_POST['username']);
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $role = $_POST['role'];
    $branch_id = $_POST['branch_id'];
    $password = $_POST['password'] ?? 'password123';
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, full_name, email, phone, role)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$username, $password_hash, $full_name, $email, $phone, $role]);
        $user_id = $pdo->lastInsertId();
        
        // Assign to branch
        if ($branch_id) {
            $stmt = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $branch_id, $role]);
        }
        
        $pdo->commit();
        $message = '<div class="alert alert-success">✅ User added successfully! Password: ' . $password . '</div>';
        logAction($pdo, 'Add User', "Added user: $username");
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $id = $_POST['id'];
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $branch_id = $_POST['branch_id'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET full_name=?, email=?, phone=?, role=?, status=?
            WHERE id=?
        ");
        $stmt->execute([$full_name, $email, $phone, $role, $status, $id]);
        
        // Update branch assignment
        $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?")->execute([$id]);
        if ($branch_id) {
            $stmt = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$id, $branch_id, $role]);
        }
        
        $pdo->commit();
        $message = '<div class="alert alert-success">✅ User updated successfully!</div>';
        logAction($pdo, 'Edit User', "Updated user ID: $id");
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Reset Password
if (isset($_GET['reset']) && is_numeric($_GET['reset'])) {
    $id = $_GET['reset'];
    $new_password = 'password123';
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $id]);
    $message = '<div class="alert alert-success">✅ Password reset to: password123</div>';
    logAction($pdo, 'Reset Password', "Reset password for user ID: $id");
}

// Delete User
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Don't delete admin
    $check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetchColumn();
    
    if ($role == 'admin') {
        $message = '<div class="alert alert-danger">❌ Cannot delete the main admin account.</div>';
    } else {
        $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">✅ User deleted!</div>';
        logAction($pdo, 'Delete User', "Deleted user ID: $id");
    }
}

// ============================================
// GET DATA
// ============================================

$users = $pdo->query("
    SELECT u.*, 
           GROUP_CONCAT(b.name SEPARATOR ', ') as branch_names
    FROM users u
    LEFT JOIN user_branches ub ON ub.user_id = u.id
    LEFT JOIN branches b ON ub.branch_id = b.id
    GROUP BY u.id
    ORDER BY u.role = 'admin' DESC, u.username
")->fetchAll();

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->fetchAll();

// Get edit data
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_user = $stmt->fetch();
    
    if ($edit_user) {
        $branch_stmt = $pdo->prepare("SELECT branch_id FROM user_branches WHERE user_id = ?");
        $branch_stmt->execute([$_GET['edit']]);
        $edit_user['branch_id'] = $branch_stmt->fetchColumn();
    }
}

include 'includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-users-cog me-2 text-primary"></i>Manage Users</h2>
        <p class="text-muted">Add, edit, or remove system users</p>
    </div>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add User
        </a>
    </div>
</div>

<?php echo $message; ?>

<!-- ============================================
ADD/EDIT USER FORM
============================================ -->
<?php if(isset($_GET['add']) || $edit_user): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $edit_user ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $edit_user ? 'Edit User' : 'Add New User'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $edit_user['id'] ?? 0; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required
                           value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>"
                           <?php echo $edit_user ? 'readonly' : ''; ?>>
                    <?php if($edit_user): ?>
                    <small class="text-muted">Username cannot be changed</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Role *</label>
                    <select name="role" class="form-select" required>
                        <option value="admin" <?php echo ($edit_user['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="manager" <?php echo ($edit_user['role'] ?? '') == 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="worker" <?php echo ($edit_user['role'] ?? '') == 'worker' ? 'selected' : ''; ?>>Worker</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Assignment *</label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">-- Select Branch --</option>
                        <?php foreach($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>"
                            <?php echo ($edit_user['branch_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if(!$edit_user): ?>
                <div class="col-md-6">
                    <label class="form-label">Temporary Password</label>
                    <input type="text" name="password" class="form-control" value="password123">
                    <small class="text-muted">Default: password123</small>
                </div>
                <?php endif; ?>
                <?php if($edit_user): ?>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($edit_user['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_user['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="mt-3">
                <button type="submit" name="<?php echo $edit_user ? 'edit_user' : 'add_user'; ?>" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>
                    <?php echo $edit_user ? 'Update User' : 'Add User'; ?>
                </button>
                <a href="users.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
USERS LIST
============================================ -->
<?php if(!isset($_GET['add']) && !$edit_user): ?>
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Users</h5>
        <span class="badge bg-primary"><?php echo count($users); ?> users</span>
    </div>
    <div class="card-body p-0">
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $u): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $u['role'] == 'admin' ? 'danger' : ($u['role'] == 'manager' ? 'warning' : 'info'); ?>">
                                <?php echo strtoupper($u['role']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($u['branch_names'] ?: '—'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $u['status'] == 'active' ? 'success' : 'danger'; ?>">
                                <?php echo strtoupper($u['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="?edit=<?php echo $u['id']; ?>" class="btn btn-outline-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?reset=<?php echo $u['id']; ?>" class="btn btn-outline-info" onclick="return confirm('Reset password to \'password123\'?')">
                                    <i class="fas fa-key"></i>
                                </a>
                                <?php if($u['role'] != 'admin'): ?>
                                <a href="?delete=<?php echo $u['id']; ?>" class="btn btn-outline-danger" 
                                   onclick="return confirm('Delete user \'<?php echo addslashes($u['username']); ?>\'?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>