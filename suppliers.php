<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$message = '';

// ─── Delete supplier ──────────────────────────────────────────────────────────
if(isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Check if supplier is linked to any purchases
    $check = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ?");
    $check->execute([$id]);
    if($check->fetchColumn() > 0) {
        $message = '<div class="alert alert-warning">
                    ⚠️ Cannot delete: This supplier has purchase records linked to them.
                    Reassign or delete those purchases first.</div>';
    } else {
        $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">✅ Supplier deleted.</div>';
    }
}

// ─── Add / Edit supplier ──────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_supplier'])) {
    $id             = (int)($_POST['id'] ?? 0);
    $name           = trim($_POST['name']);
    $contact_person = trim($_POST['contact_person']);
    $phone          = trim($_POST['phone']);
    $email          = trim($_POST['email']);
    $address        = trim($_POST['address']);
    $notes          = trim($_POST['notes']);

    if(empty($name)) {
        $message = '<div class="alert alert-danger">❌ Supplier name is required.</div>';
    } else {
        if($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, notes=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $contact_person, $phone, $email, $address, $notes, $id]);
            $message = '<div class="alert alert-success">✅ Supplier updated successfully.</div>';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO suppliers (name, contact_person, phone, email, address, notes)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([$name, $contact_person, $phone, $email, $address, $notes]);
            $message = '<div class="alert alert-success">✅ Supplier added successfully.</div>';
        }
    }
}

// ─── Fetch edit target ────────────────────────────────────────────────────────
$edit_supplier = null;
if(isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_supplier = $stmt->fetch();
}

// ─── Fetch all suppliers with purchase stats ──────────────────────────────────
$suppliers = $pdo->query(
    "SELECT s.*,
            COUNT(p.id)      AS total_orders,
            COALESCE(SUM(p.total_cost), 0) AS total_purchased
     FROM suppliers s
     LEFT JOIN purchases p ON p.supplier_id = s.id
     GROUP BY s.id
     ORDER BY s.name"
)->fetchAll();

// ─── Fetch purchase history for a specific supplier (view mode) ───────────────
$view_supplier    = null;
$supplier_history = [];
if(isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([(int)$_GET['view']]);
    $view_supplier = $stmt->fetch();

    if($view_supplier) {
        $stmt2 = $pdo->prepare(
            "SELECT p.*, pr.name AS product_name
             FROM purchases p
             JOIN products pr ON p.product_id = pr.id
             WHERE p.supplier_id = ?
             ORDER BY p.purchase_date DESC"
        );
        $stmt2->execute([(int)$_GET['view']]);
        $supplier_history = $stmt2->fetchAll();
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="col-md-10 p-4">
    <h2 class="mb-4"><i class="fas fa-truck me-2"></i>Suppliers</h2>
    <?php echo $message; ?>

    <?php if($view_supplier): ?>
    <!-- ── Purchase history view ─────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-history me-2"></i>
                Purchase History — <strong><?php echo htmlspecialchars($view_supplier['name']); ?></strong>
            </h5>
            <a href="suppliers.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Suppliers
            </a>
        </div>
        <div class="card-body">
            <?php if(empty($supplier_history)): ?>
            <p class="text-muted text-center py-3">No purchases recorded from this supplier yet.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Ref #</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Unit Cost</th>
                            <th>Total Cost</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $grand_total_purchased = 0;
                        foreach($supplier_history as $ph):
                            $grand_total_purchased += $ph['total_cost'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ph['reference_no'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($ph['product_name']); ?></td>
                            <td><?php echo number_format($ph['quantity']); ?></td>
                            <td><?php echo number_format($ph['unit_cost'], 0); ?> RWF</td>
                            <td><?php echo number_format($ph['total_cost'], 0); ?> RWF</td>
                            <td><?php echo $ph['purchase_date']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <th colspan="4" class="text-end">Grand Total Purchased:</th>
                            <th><?php echo number_format($grand_total_purchased, 0); ?> RWF</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>

    <!-- ── Add / Edit form ───────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-<?php echo $edit_supplier ? 'edit' : 'plus-circle'; ?> me-2"></i>
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
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Address / Location</label>
                        <input type="text" name="address" class="form-control"
                               value="<?php echo htmlspecialchars($edit_supplier['address'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php
                            echo htmlspecialchars($edit_supplier['notes'] ?? '');
                        ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="save_supplier" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        <?php echo $edit_supplier ? 'Update Supplier' : 'Save Supplier'; ?>
                    </button>
                    <?php if($edit_supplier): ?>
                    <a href="suppliers.php" class="btn btn-secondary ms-2">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Suppliers list ────────────────────────────────────────────────── -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Suppliers
                <span class="badge bg-secondary ms-2"><?php echo count($suppliers); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if(empty($suppliers)): ?>
            <p class="text-center text-muted py-4">No suppliers yet. Add one above.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Orders</th>
                            <th>Total Purchased</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($suppliers as $i => $sup): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($sup['name']); ?></strong>
                                <?php if($sup['address']): ?>
                                <br><small class="text-muted">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($sup['address']); ?>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($sup['contact_person'] ?: '—'); ?></td>
                            <td>
                                <?php if($sup['phone']): ?>
                                <a href="tel:<?php echo $sup['phone']; ?>">
                                    <?php echo htmlspecialchars($sup['phone']); ?>
                                </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if($sup['email']): ?>
                                <a href="mailto:<?php echo $sup['email']; ?>">
                                    <?php echo htmlspecialchars($sup['email']); ?>
                                </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info text-dark">
                                    <?php echo $sup['total_orders']; ?> orders
                                </span>
                            </td>
                            <td>
                                <strong><?php echo number_format($sup['total_purchased'], 0); ?> RWF</strong>
                            </td>
                            <td>
                                <a href="?view=<?php echo $sup['id']; ?>"
                                   class="btn btn-sm btn-outline-primary"
                                   title="View purchase history">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?edit=<?php echo $sup['id']; ?>"
                                   class="btn btn-sm btn-warning"
                                   title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $sup['id']; ?>"
                                   class="btn btn-sm btn-danger"
                                   title="Delete"
                                   onclick="return confirm('Delete supplier \'<?php echo addslashes($sup['name']); ?>\'? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
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

    <?php endif; // end view/list toggle ?>
</div>

<?php include 'includes/footer.php'; ?>
