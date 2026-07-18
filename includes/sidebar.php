<?php if(!isset($_SESSION)) session_start(); ?>

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

        <!--
        Admin section + Logout live in the header.php profile dropdown,
        not duplicated here.
        -->
    </nav>
</div>