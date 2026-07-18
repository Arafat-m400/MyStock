<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyStock v2.0 - <?php echo $_SESSION['branch_name'] ?? 'Enterprise'; ?></title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0a58ca;
            --primary-light: #e7f1ff;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            overflow-x: hidden;
        }

        .navbar-mystock {
            background: linear-gradient(135deg, #0a58ca 0%, #0d6efd 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 8px 0;
            position: sticky;
            top: 0;
            z-index: 1050;
        }
        .navbar-mystock .navbar-brand {
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .navbar-mystock .navbar-brand i {
            margin-right: 10px;
        }
        .navbar-mystock .nav-link {
            color: rgba(255,255,255,0.85) !important;
            transition: all 0.3s;
        }
        .navbar-mystock .nav-link:hover {
            color: white !important;
        }
        .navbar-mystock .dropdown-menu {
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .branch-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .branch-badge i {
            margin-right: 5px;
        }

        .sidebar {
            background: white;
            min-height: calc(100vh - 56px);
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            padding: 15px 0;
            position: sticky;
            top: 56px;
            height: calc(100vh - 56px);
            overflow-y: auto;
        }
        .sidebar .nav-link {
            color: #2c3e50;
            border-radius: 8px;
            margin: 2px 10px;
            padding: 8px 15px;
            transition: all 0.3s;
            font-size: 0.9rem;
        }
        .sidebar .nav-link:hover {
            background: #e7f1ff;
            color: #0d6efd;
        }
        .sidebar .nav-link.active {
            background: #0d6efd;
            color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
        }
        .sidebar .nav-section {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #6c757d;
            padding: 10px 20px 5px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .main-content {
            padding: 20px;
            background: #f0f2f5;
            min-height: calc(100vh - 56px);
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 5px;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                height: auto;
                position: relative;
                top: 0;
            }
            .navbar-mystock .navbar-brand {
                font-size: 1rem;
            }
            .branch-badge {
                font-size: 0.7rem;
                padding: 2px 10px;
            }
            .stat-card .stat-value {
                font-size: 20px;
            }
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .text-primary { color: #0d6efd !important; }
        .bg-primary { background: #0d6efd !important; }
        .cursor-pointer { cursor: pointer; }

        .back-btn {
            display: inline-block;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-mystock navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo $branch_path; ?>dashboard.php">
                <i class="fas fa-store-alt"></i> MyStock
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if(getCurrentBranch()): ?>
                    <li class="nav-item">
                        <span class="branch-badge">
                            <i class="fas fa-store"></i>
                            <?php echo htmlspecialchars(getCurrentBranchName()); ?>
                        </span>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?>
                            <small class="d-none d-sm-inline">(<?php echo ucfirst($_SESSION['role'] ?? ''); ?>)</small>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <!-- FIX: use $root_path / $admin_path so these links
                                 work correctly no matter which folder (root,
                                 branch/, admin/) the CURRENT page lives in -->
                            <li><a class="dropdown-item" href="<?php echo $root_path; ?>profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="<?php echo $root_path; ?>index.php"><i class="fas fa-store me-2"></i>Switch Branch</a></li>
                            <?php if(isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo $admin_path; ?>branches.php"><i class="fas fa-building me-2"></i>Manage Branches</a></li>
                            <li><a class="dropdown-item" href="<?php echo $admin_path; ?>users.php"><i class="fas fa-users-cog me-2"></i>Manage Users</a></li>
                            <li><a class="dropdown-item" href="<?php echo $admin_path; ?>settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo $root_path; ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-0">
        <div class="row g-0">