<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';
$active_tab = $_GET['tab'] ?? 'customers';

// ============================================
// CUSTOMER CRUD
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer'])) {
    $id = $_POST['id'] ?? 0;
    $name = sanitize($_POST['name']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address']);
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=? AND branch_id=?");
            $stmt->execute([$name, $phone, $email, $address, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Customer updated!</div>';
            logAction($pdo, 'Update Customer', "Updated customer: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO customers (branch_id, name, phone, email, address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $name, $phone, $email, $address]);
            $message = '<div class="alert alert-success">✅ Customer added!</div>';
            logAction($pdo, 'Add Customer', "Added customer: $name");
        }
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete_customer']) && is_numeric($_GET['delete_customer']) && $is_admin) {
    $id = $_GET['delete_customer'];
    $check = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = ? AND branch_id = ?");
    $check->execute([$id, $branch_id]);
    if ($check->fetchColumn() > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete: Customer has sales records.</div>';
    } else {
        $pdo->prepare("DELETE FROM customers WHERE id = ? AND branch_id = ?")->execute([$id, $branch_id]);
        $message = '<div class="alert alert-success">✅ Customer deleted!</div>';
    }
}

// ============================================
// SUPPLIER CRUD
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_supplier'])) {
    $id = $_POST['id'] ?? 0;
    $name = sanitize($_POST['name']);
    $contact_person = sanitize($_POST['contact_person']);
    $phone = sanitize($_POST['phone']);
    $whatsapp = sanitize($_POST['whatsapp']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address']);
    $notes = sanitize($_POST['notes']);
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, whatsapp=?, email=?, address=?, notes=? WHERE id=? AND branch_id=?");
            $stmt->execute([$name, $contact_person, $phone, $whatsapp, $email, $address, $notes, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Supplier updated!</div>';
            logAction($pdo, 'Update Supplier', "Updated supplier: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (branch_id, name, contact_person, phone, whatsapp, email, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $name, $contact_person, $phone, $whatsapp, $email, $address, $notes]);
            $message = '<div class="alert alert-success">✅ Supplier added!</div>';
            logAction($pdo, 'Add Supplier', "Added supplier: $name");
        }
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete_supplier']) && is_numeric($_GET['delete_supplier']) && $is_admin) {
    $id = $_GET['delete_supplier'];
    $check = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ? AND branch_id = ?");
    $check->execute([$id, $branch_id]);
    if ($check->fetchColumn() > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete: Supplier has purchase records.</div>';
    } else {
        $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND branch_id = ?")->execute([$id, $branch_id]);
        $message = '<div class="alert alert-success">✅ Supplier deleted!</div>';
    }
}

// ============================================
// GET DATA
// ============================================

// Customers with stats
$customers = $pdo->prepare("
    SELECT c.*, 
           COUNT(s.id) as order_count, 
           COALESCE(SUM(s.grand_total), 0) as total_spent,
           COALESCE(SUM(cd.remaining), 0) as total_debt
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id AND s.branch_id = ?
    LEFT JOIN customer_debts cd ON cd.customer_id = c.id AND cd.branch_id = ? AND cd.status IN ('pending', 'partial')
    WHERE c.branch_id = ?
    GROUP BY c.id
    ORDER BY c.name
");
$customers->execute([$branch_id, $branch_id, $branch_id]);
$customers = $customers->fetchAll();

// Suppliers with stats
$suppliers = $pdo->prepare("
    SELECT s.*, 
           COUNT(p.id) as purchase_count, 
           COALESCE(SUM(p.total_amount), 0) as total_purchased,
           COALESCE(SUM(sd.remaining), 0) as total_debt
    FROM suppliers s
    LEFT JOIN purchases p ON p.supplier_id = s.id AND p.branch_id = ?
    LEFT JOIN supplier_debts sd ON sd.supplier_id = s.id AND sd.branch_id = ? AND sd.status IN ('pending', 'partial')
    WHERE s.branch_id = ?
    GROUP BY s.id
    ORDER BY s.name
");
$suppliers->execute([$branch_id, $branch_id, $branch_id]);
$suppliers = $suppliers->fetchAll();

// Get edit data
$edit_customer = null;
if (isset($_GET['edit_customer']) && is_numeric($_GET['edit_customer']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit_customer'], $branch_id]);
    $edit_customer = $stmt->fetch();
}

$edit_supplier = null;
if (isset($_GET['edit_supplier']) && is_numeric($_GET['edit_supplier']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit_supplier'], $branch_id]);
    $edit_supplier = $stmt->fetch();
}

// ============================================
// ADD/EDIT FORMS
// ============================================

// Check if we're showing a form
$show_customer_form = isset($_GET['add_customer']) || $edit_customer;
$show_supplier_form = isset($_GET['add_supplier']) || $edit_supplier;

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-users me-2 text-primary"></i>People</h2>
            <p class="text-muted">Manage customers and suppliers</p>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'customers' ? 'active' : ''; ?>" href="?tab=customers">
                <i class="fas fa-user me-1"></i> Customers
                <span class="badge bg-secondary ms-1"><?php echo count($customers); ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'suppliers' ? 'active' : ''; ?>" href="?tab=suppliers">
                <i class="fas fa-building me-1"></i> Suppliers
                <span class="badge bg-secondary ms-1"><?php echo count($suppliers); ?></span>
            </a>
        </li>
    </ul>

    <!-- ============================================
    ADD/EDIT CUSTOMER FORM
    ============================================ -->
    <?php if($show_customer_form): ?>
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
                        <label class="form-label">Name *</label>
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
                        <i class="fas fa-save me-1"></i> <?php echo $edit_customer ? 'Update' : 'Save'; ?>
                    </button>
                    <a href="?tab=customers" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    ADD/EDIT SUPPLIER FORM
    ============================================ -->
    <?php if($show_supplier_form): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-<?php echo $edit_supplier ? 'edit' : 'plus'; ?> me-2"></i>
                <?php echo $edit_supplier ? 'Edit Supplier' : 'Add New Supplier'; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $edit_supplier['id'] ?? 0; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?php echo htmlspecialchars($edit_supplier['name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['whatsapp'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['email'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['address'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($edit_supplier['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="save_supplier" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> <?php echo $edit_supplier ? 'Update' : 'Save'; ?>
                    </button>
                    <a href="?tab=suppliers" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    CUSTOMERS TAB
    ============================================ -->
    <?php if($active_tab == 'customers' && !$show_customer_form): ?>
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Customers</h5>
            <?php if($is_admin): ?>
            <a href="?tab=customers&add_customer" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Add Customer
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if(empty($customers)): ?>
            <div class="text-center py-5">
                <i class="fas fa-user fa-4x text-muted mb-3 d-block"></i>
                <h5>No Customers</h5>
                <p class="text-muted">Add customers to track their purchases and debts.</p>
                <?php if($is_admin): ?>
                <a href="?tab=customers&add_customer" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Add First Customer
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Orders</th>
                            <th>Total Spent</th>
                            <th>Debt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers as $c): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
                            <td>
                                <?php if($c['phone']): ?>
                                <div><small><?php echo $c['phone']; ?></small></div>
                                <?php endif; ?>
                                <?php if($c['email']): ?>
                                <div><small><?php echo $c['email']; ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $c['order_count']; ?></td>
                            <td><?php echo number_format($c['total_spent'], 0); ?></td>
                            <td>
                                <?php if($c['total_debt'] > 0): ?>
                                <span class="badge bg-danger"><?php echo number_format($c['total_debt'], 0); ?></span>
                                <?php else: ?>
                                <span class="badge bg-success">✅</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($is_admin): ?>
                                    <a href="?tab=customers&edit_customer=<?php echo $c['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?tab=customers&delete_customer=<?php echo $c['id']; ?>" class="btn btn-outline-danger" 
                                       onclick="return confirm('Delete this customer?')" title="Delete">
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
    <?php endif; ?>

    <!-- ============================================
    SUPPLIERS TAB
    ============================================ -->
    <?php if($active_tab == 'suppliers' && !$show_supplier_form): ?>
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Suppliers</h5>
            <?php if($is_admin): ?>
            <a href="?tab=suppliers&add_supplier" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Add Supplier
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if(empty($suppliers)): ?>
            <div class="text-center py-5">
                <i class="fas fa-building fa-4x text-muted mb-3 d-block"></i>
                <h5>No Suppliers</h5>
                <p class="text-muted">Add suppliers to create purchase orders.</p>
                <?php if($is_admin): ?>
                <a href="?tab=suppliers&add_supplier" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Add First Supplier
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>WhatsApp</th>
                            <th>Orders</th>
                            <th>Total Purchased</th>
                            <th>Debt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($suppliers as $s): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                            <td>
                                <?php if($s['contact_person']): ?>
                                <div><small><?php echo $s['contact_person']; ?></small></div>
                                <?php endif; ?>
                                <?php if($s['phone']): ?>
                                <div><small><?php echo $s['phone']; ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($s['whatsapp']): ?>
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $s['whatsapp']); ?>" target="_blank" class="btn btn-sm btn-success">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $s['purchase_count']; ?></td>
                            <td><?php echo number_format($s['total_purchased'], 0); ?></td>
                            <td>
                                <?php if($s['total_debt'] > 0): ?>
                                <span class="badge bg-warning"><?php echo number_format($s['total_debt'], 0); ?></span>
                                <?php else: ?>
                                <span class="badge bg-success">✅</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($is_admin): ?>
                                    <a href="?tab=suppliers&edit_supplier=<?php echo $s['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?tab=suppliers&delete_supplier=<?php echo $s['id']; ?>" class="btn btn-outline-danger" 
                                       onclick="return confirm('Delete this supplier?')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="../stock_in.php?supplier=<?php echo $s['id']; ?>" class="btn btn-outline-primary" title="View PO">
                                        <i class="fas fa-file-purchase"></i>
                                    </a>
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
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>