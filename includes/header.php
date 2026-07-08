<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyStock v2.0 - <?php echo $_SESSION['branch_name'] ?? 'Enterprise'; ?></title>
    
    <!-- Bootstrap 5 (CDN with offline support) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            overflow-x: hidden;
        }
        
        /* Navbar */
        .navbar-mystock {
            background: linear-gradient(135deg, #0a58ca 0%, #0d6efd 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 10px 0;
        }
        .navbar-mystock .navbar-brand {
            color: white;
            font-weight: 700;
            font-size: 1.3rem;
        }
        .navbar-mystock .navbar-brand i {
            margin-right: 10px;
        }
        .navbar-mystock .nav-link {
            color: rgba(255,255,255,0.8) !important;
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
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .branch-badge i {
            margin-right: 5px;
        }
        
        /* Sidebar */
        .sidebar {
            background: white;
            min-height: calc(100vh - 70px);
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            padding: 20px 0;
            position: sticky;
            top: 70px;
            height: calc(100vh - 70px);
            overflow-y: auto;
        }
        .sidebar .nav-link {
            color: #2c3e50;
            border-radius: 8px;
            margin: 2px 10px;
            padding: 10px 15px;
            transition: all 0.3s;
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
            margin-right: 10px;
            text-align: center;
        }
        .sidebar .nav-section {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            padding: 10px 20px 5px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        /* Cards */
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .stat-card .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                height: auto;
                position: relative;
                top: 0;
            }
            .navbar-mystock .navbar-brand {
                font-size: 1.1rem;
            }
            .branch-badge {
                font-size: 0.7rem;
                padding: 3px 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-mystock navbar-expand-lg sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
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
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
                            <small class="text-muted d-none d-sm-inline">(<?php echo ucfirst($_SESSION['role'] ?? ''); ?>)</small>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="../index.php"><i class="fas fa-store me-2"></i>Switch Branch</a></li>
                            <?php if(isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../admin/branches.php"><i class="fas fa-building me-2"></i>Manage Branches</a></li>
                            <li><a class="dropdown-item" href="../admin/users.php"><i class="fas fa-users me-2"></i>Manage Users</a></li>
                            <li><a class="dropdown-item" href="../admin/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar d-none d-md-block">
                <nav class="nav flex-column">
                    <div class="nav-section">Main</div>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>" href="products.php">
                        <i class="fas fa-box"></i>Products
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="categories.php">
                        <i class="fas fa-tags"></i>Categories
                    </a>
                    
                    <div class="nav-section">Sales & Stock</div>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>" href="sales.php">
                        <i class="fas fa-cash-register"></i>Sales
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'purchases.php' ? 'active' : ''; ?>" href="purchases.php">
                        <i class="fas fa-truck"></i>Stock In
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'purchase_orders.php' ? 'active' : ''; ?>" href="purchase_orders.php">
                        <i class="fas fa-file-purchase"></i>Purchase Orders
                    </a>
                    
                    <div class="nav-section">Financial</div>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>" href="expenses.php">
                        <i class="fas fa-money-bill-wave"></i>Expenses
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'debts.php' ? 'active' : ''; ?>" href="debts.php">
                        <i class="fas fa-hand-holding-usd"></i>Debts
                    </a>
                    
                    <div class="nav-section">People</div>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
                        <i class="fas fa-users"></i>Customers
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''; ?>" href="suppliers.php">
                        <i class="fas fa-building"></i>Suppliers
                    </a>
                    
                    <div class="nav-section">Reports</div>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                        <i class="fas fa-chart-line"></i>Reports
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'end_of_day.php' ? 'active' : ''; ?>" href="end_of_day.php">
                        <i class="fas fa-calendar-check"></i>End of Day
                    </a>
                    <?php if(isAdmin() || isManager()): ?>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'whatsapp_alerts.php' ? 'active' : ''; ?>" href="whatsapp_alerts.php">
                        <i class="fab fa-whatsapp"></i>WhatsApp Alerts
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
            
            <!-- Main Content Area -->
            <div class="col-md-10 p-4">