<?php if(!isset($_SESSION)) session_start(); ?>

<div class="col-md-2 sidebar p-2" id="mainSidebar">
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

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile sidebar toggle
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    // Create toggle button if it doesn't exist
    let toggleBtn = document.getElementById('sidebarToggle');
    if (!toggleBtn) {
        toggleBtn = document.createElement('button');
        toggleBtn.id = 'sidebarToggle';
        toggleBtn.className = 'btn btn-sm btn-light d-md-none position-fixed';
        toggleBtn.style.cssText = 'bottom:20px; left:20px; z-index:1060; border-radius:50%; width:50px; height:50px; box-shadow:0 4px 15px rgba(0,0,0,0.2);';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(toggleBtn);
    }
    
    // Toggle sidebar
    toggleBtn.addEventListener('click', function() {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
        toggleBtn.innerHTML = sidebar.classList.contains('show') ? 
            '<i class="fas fa-times"></i>' : 
            '<i class="fas fa-bars"></i>';
    });
    
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
    });
});
</script>