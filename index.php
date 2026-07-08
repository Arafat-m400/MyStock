<?php
require_once 'config/db.php';

// If not logged in, redirect to login
if (!isLoggedIn()) {
    redirect('login.php');
}

// If user only has one branch, redirect directly
if (count($_SESSION['user_branches'] ?? []) == 1) {
    $branch_id = $_SESSION['user_branches'][0];
    switchBranch($branch_id);
    redirect('branch/dashboard.php');
}

// Get user's branches
$branches = getUserBranches($pdo, $_SESSION['user_id']);

// ... rest of the branch selection code ...

// If no branches, show error
if (empty($branches)) {
    $error = 'You do not have access to any branches. Please contact your administrator.';
}

include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Header -->
            <div class="text-center mb-5">
                <h1 class="display-4">
                    <i class="fas fa-store-alt text-primary"></i> MyStock
                </h1>
                <p class="lead text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
                <p class="text-muted">Select your branch to continue</p>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger text-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error; ?>
            </div>
            <?php else: ?>
            
            <!-- Branch Grid -->
            <div class="row g-4">
                <?php foreach($branches as $branch): ?>
                <div class="col-md-4 col-sm-6">
                    <a href="select_branch.php?id=<?php echo $branch['id']; ?>" class="text-decoration-none">
                        <div class="card branch-card shadow-sm h-100">
                            <div class="card-body text-center p-4">
                                <div class="branch-icon mb-3">
                                    <i class="fas fa-store-alt"></i>
                                </div>
                                <h5 class="card-title"><?php echo htmlspecialchars($branch['name']); ?></h5>
                                <p class="card-text text-muted small">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    <?php echo htmlspecialchars($branch['location'] ?? 'Location not set'); ?>
                                </p>
                                <?php if($branch['phone']): ?>
                                <p class="card-text text-muted small">
                                    <i class="fas fa-phone me-1"></i>
                                    <?php echo htmlspecialchars($branch['phone']); ?>
                                </p>
                                <?php endif; ?>
                                <div class="mt-2">
                                    <span class="badge bg-<?php echo $branch['role'] == 'admin' ? 'danger' : ($branch['role'] == 'manager' ? 'warning' : 'info'); ?>">
                                        <i class="fas fa-<?php echo $branch['role'] == 'admin' ? 'crown' : ($branch['role'] == 'manager' ? 'user-tie' : 'user'); ?> me-1"></i>
                                        <?php echo ucfirst($branch['role']); ?>
                                    </span>
                                    <?php if($branch['status'] == 'active'): ?>
                                    <span class="badge bg-success"><i class="fas fa-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-circle me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="text-center mt-5">
                <a href="logout.php" class="text-muted text-decoration-none">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
                <?php if(isAdmin()): ?>
                <span class="text-muted mx-2">|</span>
                <a href="admin/branches.php" class="text-primary text-decoration-none">
                    <i class="fas fa-cog me-1"></i>Manage Branches
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.branch-card {
    transition: all 0.3s ease;
    border: 2px solid transparent;
    border-radius: 15px;
    cursor: pointer;
}
.branch-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15) !important;
    border-color: #0d6efd;
}
.branch-icon {
    width: 70px;
    height: 70px;
    background: #e7f1ff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 30px;
    color: #0d6efd;
}
.branch-icon i {
    color: #0d6efd;
}
</style>

<?php include 'includes/footer.php'; ?>