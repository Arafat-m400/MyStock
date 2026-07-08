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

// Add/Edit Supplier
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
            $stmt = $pdo->prepare("
                UPDATE suppliers 
                SET name=?, contact_person=?, phone=?, whatsapp=?, email=?, address=?, notes=?
                WHERE id=? AND branch_id=?
            ");
            $stmt->execute([$name, $contact_person, $phone, $whatsapp, $email, $address, $notes, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Supplier updated successfully!</div>';
            logAction($pdo, 'Update Supplier', "Updated supplier: $name");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (branch_id, name, contact_person, phone, whatsapp, email, address, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$branch_id, $name, $contact_person, $phone, $whatsapp, $email, $address, $notes]);
            $message = '<div class="alert alert-success">✅ Supplier added successfully!</div>';
            logAction($pdo, 'Add Supplier', "Added supplier: $name");
        }
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Supplier
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    
    // Check if supplier has purchases
    $check = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ? AND branch_id = ?");
    $check->execute([$id, $branch_id]);
    $purchase_count = $check->fetchColumn();
    
    if ($purchase_count > 0) {
        $message = '<div class="alert alert-warning">⚠️ Cannot delete: Supplier has ' . $purchase_count . ' purchase record(s).</div>';
    } else {
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND branch_id = ?");
        $stmt->execute([$id, $branch_id]);
        $message = '<div class="alert alert-success">✅ Supplier deleted!</div>';
        logAction($pdo, 'Delete Supplier', "Deleted supplier ID: $id");
    }
}

// ============================================
// GET DATA
// ============================================

// Get all suppliers with stats
$suppliers = $pdo->prepare("
    SELECT s.*,
           COUNT(DISTINCT p.id) as purchase_count,
           COALESCE(SUM(p.total_amount), 0) as total_purchased
    FROM suppliers s
    LEFT JOIN purchases p ON p.supplier_id = s.id AND p.branch_id = ?
    WHERE s.branch_id = ?
    GROUP BY s.id
    ORDER BY s.name
");
$suppliers->execute([$branch_id, $branch_id]);
$suppliers = $suppliers->fetchAll();

// Get edit data
$edit_supplier = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit'], $branch_id]);
    $edit_supplier = $stmt->fetch();
}

// Get supplier for view (purchase history)
$view_supplier = null;
$supplier_purchases = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['view'], $branch_id]);
    $view_supplier = $stmt->fetch();
    
    if ($view_supplier) {
        $purchases = $pdo->prepare("
            SELECT p.*, 
                   pi.quantity, pi.unit_price, pi.subtotal,
                   pr.name as product_name
            FROM purchases p
            JOIN purchase_items pi ON pi.purchase_id = p.id
            JOIN products pr ON pi.product_id = pr.id
            WHERE p.supplier_id = ? AND p.branch_id = ?
            ORDER BY p.purchase_date DESC
            LIMIT 20
        ");
        $purchases->execute([$_GET['view'], $branch_id]);
        $supplier_purchases = $purchases->fetchAll();
    }
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-building me-2 text-primary"></i>Suppliers</h2>
        <p class="text-muted">
            Manage your suppliers and track purchases
        </p>
    </div>
    <?php if($is_admin): ?>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Supplier
        </a>
    </div>
    <?php endif; ?>
</div>

<?php echo $message; ?>

<!-- ============================================
VIEW SUPPLIER PURCHASE HISTORY
============================================ -->
<?php if($view_supplier): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-history me-2"></i>
            Purchase History - <?php echo htmlspecialchars($view_supplier['name']); ?>
        </h5>
        <a href="suppliers.php" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
    <div class="card-body p-0">
        <?php if(empty($supplier_purchases)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
            No purchases from this supplier yet.
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grand_total = 0;
                    foreach($supplier_purchases as $p): 
                        $grand_total += $p['subtotal'];
                    ?>
                    <tr>
                        <td><?php echo $p['purchase_date']; ?></td>
                        <td><?php echo htmlspecialchars($p['invoice_no'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                        <td><?php echo $p['quantity']; ?></td>
                        <td><?php echo number_format($p['unit_price'], 0); ?></td>
                        <td><strong><?php echo number_format($p['subtotal'], 0); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-success">
                        <th colspan="5" class="text-end">Grand Total Purchased:</th>
                        <th><strong><?php echo number_format($grand_total, 0); ?> RWF</strong></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
ADD/EDIT SUPPLIER FORM
============================================ -->
<?php if(($is_admin && isset($_GET['add'])) || $edit_supplier): ?>
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
                    <label class="form-label">Supplier / Company Name *</label>
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
                    <label class="form-label">WhatsApp Number</label>
                    <input type="text" name="whatsapp" class="form-control"
                           value="<?php echo htmlspecialchars($edit_supplier['whatsapp'] ?? ''); ?>"
                           placeholder="e.g., 250788123456">
                    <small class="text-muted">Include country code (e.g., 250...)</small>
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
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Payment terms, delivery preferences..."><?php echo htmlspecialchars($edit_supplier['notes'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" name="save_supplier" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>
                    <?php echo $edit_supplier ? 'Update Supplier' : 'Save Supplier'; ?>
                </button>
                <a href="suppliers.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
SUPPLIERS LIST
============================================ -->
<?php if(!isset($_GET['view']) && !isset($_GET['add']) && !$edit_supplier): ?>
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Suppliers</h5>
        <span class="badge bg-primary"><?php echo count($suppliers); ?> suppliers</span>
    </div>
    <div class="card-body p-0">
        <?php if(empty($suppliers)): ?>
        <div class="text-center py-5">
            <i class="fas fa-building fa-4x text-muted mb-3 d-block"></i>
            <h5>No Suppliers Yet</h5>
            <p class="text-muted">Add suppliers to start creating purchase orders.</p>
            <?php if($is_admin): ?>
            <a href="?add" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Add First Supplier
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>WhatsApp</th>
                        <th>Purchases</th>
                        <th>Total Spent</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($suppliers as $index => $s): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                            <?php if($s['address']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($s['address']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['contact_person']): ?>
                            <div><small><?php echo htmlspecialchars($s['contact_person']); ?></small></div>
                            <?php endif; ?>
                            <?php if($s['phone']): ?>
                            <div><small><a href="tel:<?php echo $s['phone']; ?>"><?php echo $s['phone']; ?></a></small></div>
                            <?php endif; ?>
                            <?php if($s['email']): ?>
                            <div><small><a href="mailto:<?php echo $s['email']; ?>"><?php echo $s['email']; ?></a></small></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['whatsapp']): ?>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $s['whatsapp']); ?>" 
                               target="_blank" class="btn btn-sm btn-success">
                                <i class="fab fa-whatsapp"></i> Chat
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $s['purchase_count']; ?></td>
                        <td><strong><?php echo number_format($s['total_purchased'], 0); ?></strong></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="?view=<?php echo $s['id']; ?>" class="btn btn-outline-primary" title="View Purchases">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if($is_admin): ?>
                                <a href="?edit=<?php echo $s['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-outline-danger" 
                                   onclick="return confirm('Delete supplier \'<?php echo addslashes($s['name']); ?>\'?')" title="Delete">
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
            <h4 class="text-primary"><?php echo count($suppliers); ?></h4>
            <p class="stat-label">Total Suppliers</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-success">
                <?php 
                $with_whatsapp = array_filter($suppliers, function($s) { return !empty($s['whatsapp']); });
                echo count($with_whatsapp);
                ?>
            </h4>
            <p class="stat-label">With WhatsApp</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-info">
                <?php 
                $total_purchases = array_sum(array_column($suppliers, 'purchase_count'));
                echo $total_purchases;
                ?>
            </h4>
            <p class="stat-label">Total Purchases</p>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <h4 class="text-warning">
                <?php 
                $total_spent = array_sum(array_column($suppliers, 'total_purchased'));
                echo number_format($total_spent, 0);
                ?>
            </h4>
            <p class="stat-label">Total Spent (RWF)</p>
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

<?php include '../includes/footer.php'; ?>