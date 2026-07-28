<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <?php
    // Get company name from settings
    $stmt = $pdo->prepare("SELECT company_name FROM settings WHERE id = 1");
    $stmt->execute();
    $settings_row = $stmt->fetch();
    $company_name = $settings_row['company_name'] ?? 'MyStock';
    ?>
    
    <title><?php echo htmlspecialchars($company_name); ?> - <?php echo $_SESSION['branch_name'] ?? 'Enterprise'; ?></title>
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
        
        /* ===== NAVBAR ===== */
        .navbar-mystock {
            background: linear-gradient(135deg, #0a58ca 0%, #0d6efd 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 6px 0;
            position: sticky;
            top: 0;
            z-index: 1050;
            min-height: 50px;
        }
        .navbar-mystock .navbar-brand {
            color: white;
            font-weight: 700;
            font-size: 1rem;
            padding: 0;
        }
        .navbar-mystock .navbar-brand i {
            margin-right: 6px;
        }
        .navbar-mystock .nav-link {
            color: rgba(255,255,255,0.85) !important;
            font-size: 0.9rem;
            padding: 6px 12px;
        }
        .navbar-mystock .nav-link:hover {
            color: white !important;
        }
        .navbar-mystock .dropdown-menu {
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .navbar-mystock .navbar-toggler {
            border-color: rgba(255,255,255,0.3);
            padding: 4px 8px;
        }
        .navbar-mystock .navbar-toggler-icon {
            filter: brightness(0) invert(1);
            width: 24px;
            height: 24px;
        }
        .branch-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        .branch-badge i {
            margin-right: 4px;
        }
        
        /* ===== SIDEBAR ===== */
        .sidebar {
            background: white;
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            padding: 10px 0;
            position: sticky;
            top: 50px;
            height: calc(100vh - 50px);
            overflow-y: auto;
        }
        .sidebar .nav-link {
            color: #2c3e50;
            border-radius: 8px;
            margin: 1px 8px;
            padding: 6px 12px;
            font-size: 0.85rem;
            transition: all 0.3s;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
            width: 18px;
            text-align: center;
            font-size: 0.85rem;
        }
        .sidebar .nav-section {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #6c757d;
            padding: 8px 16px 3px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            padding: 12px;
            background: #f0f2f5;
            min-height: calc(100vh - 50px);
        }
        
        /* ===== STAT CARDS ===== */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 12px 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            height: 100%;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 11px;
            margin-bottom: 3px;
            font-weight: 500;
        }
        .stat-card .stat-value {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }
        .stat-card .stat-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .stat-card .stat-icon i {
            font-size: 18px;
        }
        
        /* ===== TABLES ===== */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -4px;
        }
        .table {
            font-size: 0.85rem;
            margin-bottom: 0;
        }
        .table th, .table td {
            padding: 6px 8px;
            vertical-align: middle;
        }
        .table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background: #f8f9fa;
        }
        
        /* ===== FORMS ===== */
        .form-control, .form-select {
            font-size: 16px !important; /* Prevents iOS zoom */
            padding: 8px 12px;
            border-radius: 8px;
            min-height: 44px;
        }
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 3px;
            color: #2c3e50;
        }
        
        /* ===== BUTTONS ===== */
        .btn {
            font-size: 0.85rem;
            padding: 8px 16px;
            border-radius: 8px;
            min-height: 44px;
            white-space: nowrap;
        }
        .btn-sm {
            min-height: 32px;
            padding: 4px 10px;
            font-size: 0.75rem;
        }
        .btn-group {
            flex-wrap: wrap;
            gap: 4px;
        }
        .btn-group .btn {
            min-height: 32px;
        }
        
        /* ===== CARDS ===== */
        .card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-header {
            padding: 10px 15px;
            font-size: 0.9rem;
        }
        .card-body {
            padding: 12px 15px;
        }
        .card-footer {
            padding: 8px 15px;
            font-size: 0.8rem;
        }
        
        /* ===== MODALS ===== */
        .modal-dialog {
            margin: 10px;
        }
        .modal-content {
            border-radius: 12px;
        }
        .modal-header {
            padding: 12px 15px;
        }
        .modal-body {
            padding: 15px;
        }
        .modal-footer {
            padding: 12px 15px;
        }
        
        /* ===== UTILITIES ===== */
        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cursor-pointer { cursor: pointer; }
        
        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #c1c7cd;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a8b0b8;
        }
        
        /* ============================================
           MOBILE RESPONSIVE OVERRIDES
           ============================================ */
        
        /* Mobile First - Small screens */
        @media (max-width: 576px) {
            /* Typography */
            h1 { font-size: 1.4rem; }
            h2 { font-size: 1.2rem; }
            h3 { font-size: 1.1rem; }
            h4 { font-size: 1rem; }
            h5 { font-size: 0.9rem; }
            .text-muted { font-size: 0.8rem; }
            
            /* Layout */
            .container-fluid {
                padding: 0 6px;
            }
            .main-content {
                padding: 8px;
            }
            .p-4 { padding: 12px !important; }
            .p-3 { padding: 10px !important; }
            .m-4 { margin: 10px !important; }
            .g-3 { gap: 8px !important; }
            .row { margin: 0 -4px; }
            [class*="col-"] { padding: 0 4px; }
            
            /* Stats - 2 columns */
            .stat-card .stat-value {
                font-size: 18px;
            }
            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
            }
            .stat-card .stat-icon i {
                font-size: 14px;
            }
            .stat-card .stat-label {
                font-size: 10px;
            }
            
            /* Tables - Card View on Mobile */
            .table thead {
                display: none;
            }
            .table tbody tr {
                display: block;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                margin-bottom: 8px;
                padding: 10px 12px;
            }
            .table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 4px 0;
                border: none;
                font-size: 0.8rem;
            }
            .table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #6c757d;
                font-size: 0.7rem;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .table tbody td .badge {
                font-size: 0.7rem;
                padding: 2px 8px;
            }
            
            /* Buttons - Full width on mobile */
            .btn {
                width: 100%;
                margin-bottom: 4px;
                font-size: 0.8rem;
                min-height: 40px;
            }
            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
                width: 100%;
            }
            .btn-group .btn {
                border-radius: 8px !important;
                width: 100%;
            }
            .btn-group .btn:not(:last-child) {
                border-bottom: none;
            }
            .btn-sm {
                min-height: 34px;
                font-size: 0.7rem;
            }
            
            /* Forms */
            .form-control, .form-select {
                font-size: 16px !important;
                padding: 6px 10px;
                min-height: 38px;
            }
            
            /* Cards */
            .card-header h5, .card-header .h5 {
                font-size: 0.9rem;
            }
            .card-body {
                padding: 10px 12px;
            }
            
            /* Modal */
            .modal-dialog {
                margin: 8px;
            }
            .modal-header h5 {
                font-size: 1rem;
            }
            
            /* Sidebar - Hidden by default, shown via toggle */
            .sidebar {
                position: fixed;
                top: 50px;
                left: -280px;
                width: 280px;
                height: calc(100vh - 50px);
                background: white;
                z-index: 1040;
                transition: left 0.3s ease;
                overflow-y: auto;
                box-shadow: 2px 0 20px rgba(0,0,0,0.1);
            }
            .sidebar.show {
                left: 0;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1039;
            }
            .sidebar-overlay.show {
                display: block;
            }
            
            /* Navbar - smaller */
            .navbar-mystock .navbar-brand {
                font-size: 0.9rem;
            }
            .navbar-mystock .navbar-brand i {
                font-size: 0.9rem;
            }
            .navbar-mystock .nav-link {
                font-size: 0.8rem;
                padding: 4px 10px;
            }
            .branch-badge {
                font-size: 0.6rem;
                padding: 2px 8px;
            }
            
            /* Product cards - 2 columns */
            .product-card .card-title {
                font-size: 0.75rem;
            }
            .product-card .badge {
                font-size: 0.6rem;
                padding: 2px 6px;
            }
            
            /* Alert */
            .alert {
                font-size: 0.8rem;
                padding: 8px 12px;
            }
            
            /* Breadcrumbs / page title */
            .page-title {
                font-size: 1.1rem;
            }
            
            /* Hide labels on mobile tables */
            .table td .d-md-none {
                display: inline-block;
            }
            
            /* Workspace cards on mobile */
            .workspace-row .col-md-3, 
            .workspace-row .col-md-6, 
            .workspace-row .col-md-9 {
                padding: 4px 0;
            }
            .workspace-row .text-md-end {
                text-align: left !important;
            }
        }
        
        /* Tablet */
        @media (min-width: 577px) and (max-width: 992px) {
            .sidebar {
                position: fixed;
                top: 50px;
                left: -280px;
                width: 280px;
                height: calc(100vh - 50px);
                z-index: 1040;
                transition: left 0.3s ease;
                box-shadow: 2px 0 20px rgba(0,0,0,0.1);
            }
            .sidebar.show {
                left: 0;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1039;
            }
            .sidebar-overlay.show {
                display: block;
            }
            
            .table td, .table th {
                padding: 8px 10px;
                font-size: 0.8rem;
            }
            .stat-card .stat-value {
                font-size: 22px;
            }
            .btn {
                font-size: 0.8rem;
                padding: 6px 14px;
                min-height: 38px;
            }
        }
        
        /* Desktop */
        @media (min-width: 993px) {
            .sidebar {
                position: sticky;
                top: 50px;
                height: calc(100vh - 50px);
                overflow-y: auto;
            }
            .stat-card .stat-value {
                font-size: 26px;
            }
        }
        
        /* Print */
        @media print {
            .sidebar, .navbar, footer, .no-print {
                display: none !important;
            }
            body {
                background: white !important;
            }
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            .table td, .table th {
                padding: 4px !important;
            }
            .col-md-10 {
                width: 100% !important;
                max-width: 100% !important;
                flex: 0 0 100% !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-mystock navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="/MyStock/branch/dashboard.php">
                <i class="fas fa-store-alt"></i> 
                <?php echo htmlspecialchars($company_name); ?>
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
                            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                            <span class="d-sm-none"><i class="fas fa-user"></i></span>
                            <small class="d-none d-sm-inline">(<?php echo ucfirst($_SESSION['role'] ?? ''); ?>)</small>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/MyStock/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="/MyStock/index.php"><i class="fas fa-store me-2"></i>Switch Branch</a></li>
                            <?php if(isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header"><i class="fas fa-shield-alt me-1"></i> Admin</li>
                            <li><a class="dropdown-item" href="/MyStock/admin/branches.php"><i class="fas fa-building me-2"></i>Branches</a></li>
                            <li><a class="dropdown-item" href="/MyStock/admin/users.php"><i class="fas fa-users-cog me-2"></i>Users</a></li>
                            <li><a class="dropdown-item" href="/MyStock/admin/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/MyStock/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid p-0">
        <div class="row g-0">