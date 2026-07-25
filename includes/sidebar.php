<?php if(!isset($_SESSION)) session_start(); ?>

<div class="col-md-2 sidebar p-3">
    <nav class="nav flex-column">
        <div class="nav-section">Main</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="/MyStock/branch/dashboard.php">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>" href="/MyStock/branch/products.php">
            <i class="fas fa-box me-2"></i>Products
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="/MyStock/branch/categories.php">
            <i class="fas fa-tags me-2"></i>Categories
        </a>
        
        <div class="nav-section">Sales & Stock</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>" href="/MyStock/branch/sales.php">
            <i class="fas fa-cash-register me-2"></i>Sales
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'stock_in.php' ? 'active' : ''; ?>" href="/MyStock/branch/stock_in.php">
            <i class="fas fa-truck me-2"></i>Stock In
        </a>
        
        <div class="nav-section">Production</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'workspaces.php' ? 'active' : ''; ?>" href="/MyStock/branch/workspaces.php">
            <i class="fas fa-industry me-2"></i>Workspace
        </a>
        
        <div class="nav-section">Financial</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>" href="/MyStock/branch/expenses.php">
            <i class="fas fa-money-bill-wave me-2"></i>Expenses
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'debts.php' ? 'active' : ''; ?>" href="/MyStock/branch/debts.php">
            <i class="fas fa-hand-holding-usd me-2"></i>Debts
        </a>
        
        <div class="nav-section">Reports</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="/MyStock/branch/reports.php">
            <i class="fas fa-chart-line me-2"></i>Reports
        </a>
        
        <div class="nav-section">Account</div>
        <a class="nav-link text-danger" href="/MyStock/logout.php">
            <i class="fas fa-sign-out-alt me-2"></i>Logout
        </a>
    </nav>
</div>