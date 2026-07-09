<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

// Add/Edit Customer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer'])) {
    $id = $_POST['id'] ?? 0;
    $name = sanitize($_POST['name']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address']);
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET name=?, phone=?, email=?, address=?
                WHERE id=? AND branch_id=?
            ");
            $stmt->execute([$name, $phone, $email, $address, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Customer updated successfully!</div>';
            logAction($pdo, 'Update Customer', "Updated customer: $name");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO customers (branch_id, name, phone, email, address)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$branch_id, $name, $phone, $email, $address]);
            $message = '<div class="alert alert-success">✅ Customer added successfully!</div>';
            logAction($pdo, 'Add Customer', "Added customer: $name");
        }
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Customer
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    
    // Check if customer has sales or debts
    $check_sales = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = ? AND branch_id = ?");
    $check_sales->execute([$id, $branch_id]);
    $sales_count = $check_sales->fetchColumn();
    
    $check_debts = $pdo->prepare("SELECT COUNT(*) FROM customer_debts WHERE customer_id = ? AND branch_id = ?");
    $check_debts->execute([$id, $branch_id]);
    $debts_count = $check_debts->fetchColumn();
    
    if ($sales_count > 0 || $debts_count > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete: Customer has ' . $sales_count . ' sale(s) and ' . $debts_count . ' debt(s).</div>';
    } else {
        $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ? AND branch_id = ?");
        $stmt->execute([$id, $branch_id]);
        $message = '<div class="alert alert-success">✅ Customer deleted!</div>';
        logAction($pdo, 'Delete Customer', "Deleted customer ID: $id");
    }
}

// ============================================
// GET DATA
// ============================================

// Get search and filter
$search = $_GET['search'] ?? '';
$filter_debt = $_GET['filter_debt'] ?? '';

// Build query
$sql = "
    SELECT c.*,
           COUNT(DISTINCT s.id) as total_orders,
           COALESCE(SUM(s.grand_total), 0) as total_spent,
           COALESCE(SUM(cd.remaining), 0) as total_debt
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id AND s.branch_id = ?
    LEFT JOIN customer_debts cd ON cd.customer_id = c.id AND cd.branch_id = ? AND cd.status IN ('pending', 'partial')
    WHERE c.branch_id = ?
";

$params = [$branch_id, $branch_id, $branch_id];

if (!empty($search)) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_debt == 'has_debt') {
    $sql .= " AND EXISTS (SELECT 1 FROM customer_debts cd2 WHERE cd2.customer_id = c.id AND cd2.branch_id = ? AND cd2.status IN ('pending', 'partial'))";
    $params[] = $branch_id;
} elseif ($filter_debt == 'no_debt') {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM customer_debts cd2 WHERE cd2.customer_id = c.id AND cd2.branch_id = ? AND cd2.status IN ('pending', 'partial'))";
    $params[] = $branch_id;
}

$sql .= " GROUP BY c.id ORDER BY c.name";

$customers = $pdo->prepare($sql);
$customers->execute($params);
$customers = $customers->fetchAll();

// Get edit data
$edit_customer = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit'], $branch_id]);
    $edit_customer = $stmt->fetch();
}

// Get customer details for view
$view_customer = null;
$customer_sales = [];
$customer_debts = [];
$customer_payments = [];

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['view'], $branch_id]);
    $view_customer = $stmt->fetch();
    
    if ($view_customer) {
        // Customer sales
        $sales = $pdo->prepare("
            SELECT s.*, COUNT(si.id) as item_count
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE s.customer_id = ? AND s.branch_id = ?
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT 20
        ");
        $sales->execute([$_GET['view'], $branch_id]);
        $customer_sales = $sales->fetchAll();
        
        // Customer debts
        $debts = $pdo->prepare("
            SELECT * FROM customer_debts 
            WHERE customer_id = ? AND branch_id = ?
            ORDER BY created_at DESC
        ");
        $debts->execute([$_GET['view'], $branch_id]);
        $customer_debts = $debts->fetchAll();
        
        // Customer payments
        $payments = $pdo->prepare("
            SELECT p.*, u.full_name as created_by_name
            FROM payments p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.debt_type = 'customer' 
            AND p.debt_id IN (SELECT id FROM customer_debts WHERE customer_id = ?)
            ORDER BY p.payment_date DESC
            LIMIT 20
        ");
        $payments->execute([$_GET['view']]);
        $customer_payments = $payments->fetchAll();
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-users me-2 text-primary"></i>Customers</h2>
        <p class="text-muted">
            Manage your customers and track their purchases
        </p>
    </div>
    <?php if($is_admin): ?>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Customer
        </a>
    </div>
    <?php endif; ?>
</div>

<?php echo $message; ?>

<!-- ============================================
VIEW CUSTOMER DETAILS
============================================ -->
<?php if($view_customer): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-user me-2"></i>
                <?php echo htmlspecialchars($view_customer['name']); ?>
            </h5>
            <div>
                <a href="customers.php" class="btn btn-sm btn-light">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
                <?php if($is_admin): ?>
                <a href="?edit=<?php echo $view_customer['id']; ?>" class="btn btn-sm btn-warning">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td><strong>Phone</strong></td>
                        <td><?php echo htmlspecialchars($view_customer['phone'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Email</strong></td>
                        <td><?php echo htmlspecialchars($view_customer['email'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Address</strong></td>
                        <td><?php echo htmlspecialchars($view_customer['address'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Spent</strong></td>
                        <td><strong class="text-success"><?php echo number_format($view_customer['total_spent'], 0); ?> RWF</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Total Debt</strong></td>
                        <td>
                            <?php 
                            $total_debt = array_sum(array_column($customer_debts, 'remaining'));
                            echo '<strong class="text-danger">' . number_format($total_debt, 0) . ' RWF</strong>';
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-8">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="stat-card">
                            <h4><?php echo count($customer_sales); ?></h4>
                            <p class="stat-label">Transactions</p>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-card">
                            <h4><?php echo number_format($view_customer['total_spent'] / max(1, count($customer_sales)), 0); ?></h4>
                            <p class="stat-label">Avg. Transaction</p>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-card">
                            <h4><?php echo count($customer_debts); ?></h4>
                            <p class="stat-label">Active Debts</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Customer Sales History -->
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-shopping-bag me-2"></i>Purchase History</h5>
    </div>
    <div class="card-body p-0">
        <?php if(empty($customer_sales)): ?>
        <div class="text-center py-4 text-muted">
            No purchases from this customer yet.
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($customer_sales as $sale): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($sale['invoice_no']); ?></td>
                        <td><?php echo $sale['sale_date']; ?></td>
                        <td><?php echo $sale['item_count']; ?></td>
                        <td><strong><?php echo number_format($sale['grand_total'], 0); ?></strong></td>
                        <td>
                            <a href="../view_invoice.php?id=<?php echo $sale['id']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                <i class="fas fa-file-invoice"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Customer Debts -->
<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Debt History</h5>
    </div>
    <div class="card-body p-0">
        <?php if(empty($customer_debts)): ?>
        <div class="text-center py-4 text-success">
            <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
            No debts. Customer is up to date! 🎉
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($customer_debts as $debt): 
                        $status_class = [
                            'pending' => 'warning',
                            'partial' => 'info',
                            'paid' => 'success',
                            'overdue' => 'danger'
                        ][$debt['status']];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($debt['sale_invoice'] ?? '—'); ?></td>
                        <td><?php echo number_format($debt['amount'], 0); ?></td>
                        <td><?php echo number_format($debt['paid_amount'], 0); ?></td>
                        <td><strong class="text-danger"><?php echo number_format($debt['remaining'], 0); ?></strong></td>
                        <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo strtoupper($debt['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
ADD/EDIT CUSTOMER FORM
============================================ -->
<?php if(($is_admin && isset($_GET['add'])) || $edit_customer): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $edit_customer ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $edit_customer ? 'Edit Customer' : 'Add New Customer'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $edit_customer['id'] ?? 0; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Customer Name *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?php echo htmlspecialchars($edit_customer['name'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo htmlspecialchars($edit_customer['phone'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($edit_customer['email'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control"
                           value="<?php echo htmlspecialchars($edit_customer['address'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" name="save_customer" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>
                    <?php echo $edit_customer ? 'Update Customer' : 'Save Customer'; ?>
                </button>
                <a href="customers.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
CUSTOMERS LIST
============================================ -->
<?php if(!isset($_GET['view']) && !isset($_GET['add']) && !$edit_customer): ?>
<!-- Search & Filter -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="🔍 Name, phone, email..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Debt Status</label>
                <select name="filter_debt" class="form-select">
                    <option value="">All Customers</option>
                    <option value="has_debt" <?php echo $filter_debt == 'has_debt' ? 'selected' : ''; ?>>⚠️ Has Debt</option>
                    <option value="no_debt" <?php echo $filter_debt == 'no_debt' ? 'selected' : ''; ?>>✅ No Debt</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-3">
                <a href="customers.php" class="btn btn-secondary w-100">
                    <i class="fas fa-undo me-1"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Customers</h5>
        <span class="badge bg-primary"><?php echo count($customers); ?> customers</span>
    </div>
    <div class="card-body p-0">
        <?php if(empty($customers)): ?>
        <div class="text-center py-5">
            <i class="fas fa-users fa-4x text-muted mb-3 d-block"></i>
            <h5>No Customers Found</h5>
            <p class="text-muted">
                <?php if(!empty($search) || !empty($filter_debt)): ?>
                No customers match your filters.
                <a href="customers.php" class="alert-link">Clear filters</a>
                <?php else: ?>
                Start adding customers to your system.
                <?php if($is_admin): ?>
                <a href="?add" class="alert-link">Add first customer</a>
                <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Orders</th>
                        <th>Total Spent</th>
                        <th>Debt</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($customers as $index => $c): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                            <?php if($c['address']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['address']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($c['phone']): ?>
                            <div><small><a href="tel:<?php echo $c['phone']; ?>"><?php echo $c['phone']; ?></a></small></div>
                            <?php endif; ?>
                            <?php if($c['email']): ?>
                            <div><small><a href="mailto:<?php echo $c['email']; ?>"><?php echo $c['email']; ?></a></small></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $c['total_orders']; ?></td>
                        <td><strong><?php echo number_format($c['total_spent'], 0); ?></strong></td>
                        <td>
                            <?php if($c['total_debt'] > 0): ?>
                            <span class="badge bg-danger">
                                <?php echo number_format($c['total_debt'], 0); ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-success">✅</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="?view=<?php echo $c['id']; ?>" class="btn btn-outline-primary" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if($is_admin): ?>
                                <a href="?edit=<?php echo $c['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-outline-danger" 
                                   onclick="return confirm('Delete customer \'<?php echo addslashes($c['name']); ?>\'?')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats -->
<div class="row mt-4">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-primary"><?php echo count($customers); ?></h4>
            <p class="stat-label">Total Customers</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-success">
                <?php 
                $total_spent = array_sum(array_column($customers, 'total_spent'));
                echo number_format($total_spent, 0);
                ?>
            </h4>
            <p class="stat-label">Total Revenue</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-danger">
                <?php 
                $total_debt = array_sum(array_column($customers, 'total_debt'));
                echo number_format($total_debt, 0);
                ?>
            </h4>
            <p class="stat-label">Total Debt</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-info">
                <?php 
                $avg_spent = count($customers) > 0 ? $total_spent / count($customers) : 0;
                echo number_format($avg_spent, 0);
                ?>
            </h4>
            <p class="stat-label">Avg. Customer Spend</p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.stat-card {
    background: white;
    padding: 15px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.stat-card h4 {
    margin: 0;
    font-weight: 700;
}
.stat-card .stat-label {
    color: #6c757d;
    font-size: 13px;
    margin-top: 5px;
}
</style>
</div>
<?php include '../includes/footer.php'; ?>