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

// If no branches, show error
if (empty($branches)) {
    $error = 'You do not have access to any branches. Please contact your administrator.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyStock - Select Branch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a58ca 0%, #0d6efd 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .branch-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .branch-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .branch-card .logo {
            font-size: 60px;
            color: #0d6efd;
            background: #e7f1ff;
            padding: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-bottom: 20px;
        }
        .branch-card h1 {
            font-weight: 700;
            color: #1a2a3a;
            margin-bottom: 5px;
        }
        .branch-card .subtitle {
            color: #6c757d;
            margin-bottom: 30px;
        }
        .branch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .branch-item {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px 20px;
            text-decoration: none;
            color: #2c3e50;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            display: block;
        }
        .branch-item:hover {
            transform: translateY(-5px);
            border-color: #0d6efd;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            text-decoration: none;
            color: #2c3e50;
        }
        .branch-item .icon {
            font-size: 40px;
            color: #0d6efd;
            margin-bottom: 10px;
        }
        .branch-item h5 {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .branch-item .location {
            font-size: 13px;
            color: #6c757d;
        }
        .branch-item .badge {
            margin-top: 10px;
        }
        .footer-links {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        .footer-links a {
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
        }
        .footer-links a:hover {
            color: #0d6efd;
        }
        .footer-links .divider {
            color: #dee2e6;
            margin: 0 10px;
        }
        @media (max-width: 576px) {
            .branch-card {
                padding: 25px;
            }
            .branch-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="branch-container">
            <div class="branch-card">
                <div class="logo">
                    <i class="fas fa-store-alt"></i>
                </div>
                <h1>MyStock</h1>
                <p class="subtitle">
                    Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                    <br><small>Select your branch to continue</small>
                </p>
                
                <?php if(isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error; ?>
                </div>
                <?php else: ?>
                
                <div class="branch-grid">
                    <?php foreach($branches as $branch): ?>
                    <a href="select_branch.php?id=<?php echo $branch['id']; ?>" class="branch-item">
                        <div class="icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <h5><?php echo htmlspecialchars($branch['name']); ?></h5>
                        <div class="location">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            <?php echo htmlspecialchars($branch['location'] ?? 'Location not set'); ?>
                        </div>
                        <div>
                            <span class="badge bg-<?php echo $branch['role'] == 'admin' ? 'danger' : ($branch['role'] == 'manager' ? 'warning' : 'info'); ?>">
                                <?php echo ucfirst($branch['role']); ?>
                            </span>
                            <?php if($branch['status'] == 'active'): ?>
                            <span class="badge bg-success">Active</span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="footer-links">
                    <a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
                    <?php if(isAdmin()): ?>
                    <span class="divider">|</span>
                    <a href="admin/branches.php"><i class="fas fa-building me-1"></i>Manage Branches</a>
                    <span class="divider">|</span>
                    <a href="admin/users.php"><i class="fas fa-users-cog me-1"></i>Manage Users</a>
                    <span class="divider">|</span>
                    <a href="admin/settings.php"><i class="fas fa-cog me-1"></i>Settings</a>
                    <?php endif; ?>
                    <span class="divider">|</span>
                    <a href="profile.php"><i class="fas fa-user me-1"></i>Profile</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>