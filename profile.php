<?php
require_once 'config/db.php';
requireLogin();

$message = '';

// ============================================
// UPDATE PROFILE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?");
        $stmt->execute([$full_name, $email, $phone, $_SESSION['user_id']]);
        
        $_SESSION['full_name'] = $full_name;
        
        $message = '<div class="alert alert-success">✅ Profile updated successfully!</div>';
        logAction($pdo, 'Update Profile', "Updated profile for user ID: {$_SESSION['user_id']}");
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// CHANGE PASSWORD
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    if ($new !== $confirm) {
        $message = '<div class="alert alert-danger">❌ Passwords do not match.</div>';
    } else {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $hash = $stmt->fetchColumn();
        
        if (password_verify($current, $hash)) {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $_SESSION['user_id']]);
            $message = '<div class="alert alert-success">✅ Password changed successfully!</div>';
            logAction($pdo, 'Change Password', "Changed password for user ID: {$_SESSION['user_id']}");
        } else {
            $message = '<div class="alert alert-danger">❌ Current password is incorrect.</div>';
        }
    }
}

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

include 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-user-circle me-2 text-primary"></i>My Profile</h2>
        <p class="text-muted">Manage your personal information</p>
    </div>
</div>

<?php echo $message; ?>

<div class="row">
    <!-- Profile Info -->
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        <small class="text-muted">Username cannot be changed</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required
                               value="<?php echo htmlspecialchars($user['full_name']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?php echo ucfirst($user['role']); ?>" readonly>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Update Profile
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Change Password -->
    <div class="col-lg-6 mt-3 mt-lg-0">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-white">
                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Current Password *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning text-white">
                        <i class="fas fa-save me-1"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Branch Access -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="fas fa-store me-2"></i>My Branch Access</h5>
    </div>
    <div class="card-body">
        <?php
        $branches = $pdo->prepare("
            SELECT b.*, ub.role 
            FROM branches b
            JOIN user_branches ub ON ub.branch_id = b.id
            WHERE ub.user_id = ?
        ");
        $branches->execute([$_SESSION['user_id']]);
        $user_branches = $branches->fetchAll();
        ?>
        
        <?php if(empty($user_branches)): ?>
        <p class="text-muted">You don't have access to any branches.</p>
        <?php else: ?>
        <div class="row">
            <?php foreach($user_branches as $b): ?>
            <div class="col-md-4">
                <div class="card border-0 bg-light p-3 mb-2">
                    <strong><?php echo htmlspecialchars($b['name']); ?></strong>
                    <small class="text-muted">
                        <i class="fas fa-user-tag me-1"></i>
                        <?php echo ucfirst($b['role']); ?>
                    </small>
                    <?php if($b['location']): ?>
                    <small class="text-muted">
                        <i class="fas fa-map-marker-alt me-1"></i>
                        <?php echo htmlspecialchars($b['location']); ?>
                    </small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>