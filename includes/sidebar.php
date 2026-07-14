<?php if(!isset($_SESSION)) session_start(); ?>

<?php
// Detect current directory
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$is_admin = ($current_dir == 'admin');

// Set base paths
if ($is_admin) {
    $branch_path = '../branch/';
    $admin_path = '';
    $root_path = '../';
    $logout_path = '../';  // From admin folder, logout is one level up
} else {
    $branch_path = '';
    $admin_path = '../admin/';
    $root_path = '';
    $logout_path = '../';  // From branch folder, logout is one level up (to root)
}
?>

<div class="col-md-2 sidebar p-3">
    <nav class="nav flex-column">
        <div class="nav-section">Main</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>dashboard.php">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>products.php">
            <i class="fas fa-box me-2"></i>Products
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>categories.php">
            <i class="fas fa-tags me-2"></i>Categories
        </a>
        
        <div class="nav-section">Sales & Stock</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>sales.php">
            <i class="fas fa-cash-register me-2"></i>Sales
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'purchases.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>purchases.php">
            <i class="fas fa-truck me-2"></i>Stock In
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'purchase_orders.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>purchase_orders.php">
            <i class="fas fa-file-purchase me-2"></i>Purchase Orders
        </a>
        
        <div class="nav-section">Financial</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>expenses.php">
            <i class="fas fa-money-bill-wave me-2"></i>Expenses
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'debts.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>debts.php">
            <i class="fas fa-hand-holding-usd me-2"></i>Debts
        </a>
        
        <div class="nav-section">People</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>suppliers.php">
            <i class="fas fa-building me-2"></i>Suppliers
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>customers.php">
            <i class="fas fa-users me-2"></i>Customers
        </a>
        
        <div class="nav-section">Reports</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>reports.php">
            <i class="fas fa-chart-line me-2"></i>Reports
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'end_of_day.php' ? 'active' : ''; ?>" href="<?php echo $branch_path; ?>end_of_day.php">
            <i class="fas fa-calendar-check me-2"></i>End of Day
        </a>
        
        <?php if(isAdmin()): ?>
        <div class="nav-section">Admin</div>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'branches.php' ? 'active' : ''; ?>" href="<?php echo $admin_path; ?>branches.php">
            <i class="fas fa-building me-2"></i>Branches
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="<?php echo $admin_path; ?>users.php">
            <i class="fas fa-users-cog me-2"></i>Users
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="<?php echo $admin_path; ?>settings.php">
            <i class="fas fa-cog me-2"></i>Settings
        </a>
        <?php endif; ?>
        
        <div class="nav-section">Account</div>
        <a class="nav-link text-danger" href="<?php echo $logout_path; ?>logout.php">
            <i class="fas fa-sign-out-alt me-2"></i>Logout
        </a>
        
        <?php if($is_admin): ?>
        <div class="nav-section">Branch</div>
        <a class="nav-link" href="<?php echo $root_path; ?>index.php">
            <i class="fas fa-store me-2"></i>Switch Branch
        </a>
        <?php endif; ?>
    </nav>
</div>