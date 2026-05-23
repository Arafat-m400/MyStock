<?php if(!isset($_SESSION)) session_start(); ?>
<div class="col-md-2 sidebar p-3">
    <div class="nav flex-column nav-pills">
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>" href="products.php">
            <i class="fas fa-box me-2"></i>Products
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="categories.php">
    <i class="fas fa-tags me-2"></i>Categories
</a>
        <?php if(isAdmin()): ?>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'purchases.php' ? 'active' : ''; ?>" href="purchases.php">
            <i class="fas fa-truck me-2"></i>Purchases (Stock In)
        </a>
        <?php endif; ?>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>" href="sales.php">
            <i class="fas fa-shopping-cart me-2"></i>Sales
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
            <i class="fas fa-users me-2"></i>Customers
        </a>
        <?php if(isAdmin()): ?>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''; ?>" href="suppliers.php">
            <i class="fas fa-building me-2"></i>Suppliers
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
            <i class="fas fa-chart-line me-2"></i>Reports
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="users.php">
            <i class="fas fa-user-cog me-2"></i>User Management
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="fas fa-cog me-2"></i>Settings
        </a>
        <?php endif; ?>
        <?php if(isWorker()): ?>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports_worker.php' ? 'active' : ''; ?>" href="reports_worker.php">
            <i class="fas fa-chart-simple me-2"></i>Reports
        </a>
        <?php endif; ?>
        <a class="nav-link" href="profile.php">
            <i class="fas fa-user-edit me-2"></i>Profile
        </a>
    </div>
</div>