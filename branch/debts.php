<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';
$active_tab = $_GET['tab'] ?? 'customer';

// ============================================
// RECORD CUSTOMER DEBT PAYMENT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_customer_debt'])) {
    $debt_id = $_POST['debt_id'];
    $amount = floatval($_POST['payment_amount']);
    $payment_method = $_POST['payment_method'];
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = sanitize($_POST['payment_notes'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Get current debt
        $stmt = $pdo->prepare("SELECT * FROM customer_debts WHERE id = ? AND branch_id = ?");
        $stmt->execute([$debt_id, $branch_id]);
        $debt = $stmt->fetch();
        
        if (!$debt) throw new Exception("Debt not found.");
        if ($amount > $debt['remaining']) throw new Exception("Payment exceeds remaining debt.");
        
        // Update debt
        $new_paid = $debt['paid_amount'] + $amount;
        $new_remaining = $debt['remaining'] - $amount;
        $status = $new_remaining <= 0 ? 'paid' : 'partial';
        
        $stmt = $pdo->prepare("UPDATE customer_debts SET paid_amount = ?, remaining = ?, status = ? WHERE id = ?");
        $stmt->execute([$new_paid, $new_remaining, $status, $debt_id]);
        
        // Record payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (branch_id, debt_type, debt_id, amount, payment_date, payment_method, notes, created_by)
            VALUES (?, 'customer', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branch_id, $debt_id, $amount, $payment_date, $payment_method, $notes, $_SESSION['user_id']]);
        
        // Update customer total debt
        $stmt = $pdo->prepare("
            UPDATE customers 
            SET total_debt = (
                SELECT COALESCE(SUM(remaining), 0) 
                FROM customer_debts 
                WHERE customer_id = ? AND status IN ('pending', 'partial')
            )
            WHERE id = ?
        ");
        $stmt->execute([$debt['customer_id'], $debt['customer_id']]);
        
        $pdo->commit();
        
        $message = '<div class="alert alert-success">✅ Payment recorded! Remaining debt: ' . number_format($new_remaining, 0) . ' RWF</div>';
        logAction($pdo, 'Customer Debt Payment', "Paid $amount RWF on debt #$debt_id");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// RECORD SUPPLIER DEBT PAYMENT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_supplier_debt'])) {
    $debt_id = $_POST['debt_id'];
    $amount = floatval($_POST['payment_amount']);
    $payment_method = $_POST['payment_method'];
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = sanitize($_POST['payment_notes'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM supplier_debts WHERE id = ? AND branch_id = ?");
        $stmt->execute([$debt_id, $branch_id]);
        $debt = $stmt->fetch();
        
        if (!$debt) throw new Exception("Debt not found.");
        if ($amount > $debt['remaining']) throw new Exception("Payment exceeds remaining debt.");
        
        $new_paid = $debt['paid_amount'] + $amount;
        $new_remaining = $debt['remaining'] - $amount;
        $status = $new_remaining <= 0 ? 'paid' : 'partial';
        
        $stmt = $pdo->prepare("UPDATE supplier_debts SET paid_amount = ?, remaining = ?, status = ? WHERE id = ?");
        $stmt->execute([$new_paid, $new_remaining, $status, $debt_id]);
        
        $stmt = $pdo->prepare("
            INSERT INTO payments (branch_id, debt_type, debt_id, amount, payment_date, payment_method, notes, created_by)
            VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branch_id, $debt_id, $amount, $payment_date, $payment_method, $notes, $_SESSION['user_id']]);
        
        $stmt = $pdo->prepare("
            UPDATE suppliers 
            SET total_debt = (
                SELECT COALESCE(SUM(remaining), 0) 
                FROM supplier_debts 
                WHERE supplier_id = ? AND status IN ('pending', 'partial')
            )
            WHERE id = ?
        ");
        $stmt->execute([$debt['supplier_id'], $debt['supplier_id']]);
        
        $pdo->commit();
        
        $message = '<div class="alert alert-success">✅ Payment recorded! Remaining debt: ' . number_format($new_remaining, 0) . ' RWF</div>';
        logAction($pdo, 'Supplier Debt Payment', "Paid $amount RWF on debt #$debt_id");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// GET DATA
// ============================================

// Customer Debts with customer info
$customer_debts = $pdo->prepare("
    SELECT cd.*, 
           c.name as customer_name,
           c.phone as customer_phone,
           s.invoice_no as sale_invoice,
           s.sale_date
    FROM customer_debts cd
    JOIN customers c ON cd.customer_id = c.id
    LEFT JOIN sales s ON cd.sale_id = s.id
    WHERE cd.branch_id = ?
    ORDER BY cd.created_at DESC
");
$customer_debts->execute([$branch_id]);
$customer_debts = $customer_debts->fetchAll();

// Supplier Debts with supplier info
$supplier_debts = $pdo->prepare("
    SELECT sd.*, 
           s.name as supplier_name,
           s.phone as supplier_phone,
           p.invoice_no as purchase_invoice,
           p.purchase_date
    FROM supplier_debts sd
    JOIN suppliers s ON sd.supplier_id = s.id
    LEFT JOIN purchases p ON sd.purchase_id = p.id
    WHERE sd.branch_id = ?
    ORDER BY sd.created_at DESC
");
$supplier_debts->execute([$branch_id]);
$supplier_debts = $supplier_debts->fetchAll();

// Payment history for a specific debt
$debt_payments = [];
if (isset($_GET['view_payments']) && is_numeric($_GET['view_payments'])) {
    $debt_id = $_GET['view_payments'];
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as created_by_name
        FROM payments p
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.debt_id = ? AND p.branch_id = ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$debt_id, $branch_id]);
    $debt_payments = $stmt->fetchAll();
}

// Summary stats
$total_customer_debt = array_sum(array_column($customer_debts, 'remaining'));
$total_supplier_debt = array_sum(array_column($supplier_debts, 'remaining'));

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Debt Management</h2>
            <p class="text-muted">Track and manage customer and supplier debts</p>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card bg-danger text-white">
                <p class="stat-label text-white-50">Customer Debt</p>
                <h3><?php echo number_format($total_customer_debt, 0); ?> RWF</h3>
                <small><?php echo count($customer_debts); ?> debts</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card bg-warning text-white">
                <p class="stat-label text-white-50">Supplier Debt</p>
                <h3><?php echo number_format($total_supplier_debt, 0); ?> RWF</h3>
                <small><?php echo count($supplier_debts); ?> debts</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card bg-info text-white">
                <p class="stat-label text-white-50">Total Payments</p>
                <h3>
                    <?php 
                    $total_payments = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE branch_id = ?");
                    $total_payments->execute([$branch_id]);
                    echo $total_payments->fetchColumn();
                    ?>
                </h3>
                <small>All time</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card bg-success text-white">
                <p class="stat-label text-white-50">Settled Debts</p>
                <h3>
                    <?php 
                    $settled = $pdo->prepare("SELECT COUNT(*) FROM customer_debts WHERE branch_id = ? AND status = 'paid'");
                    $settled->execute([$branch_id]);
                    $settled_count = $settled->fetchColumn();
                    $settled2 = $pdo->prepare("SELECT COUNT(*) FROM supplier_debts WHERE branch_id = ? AND status = 'paid'");
                    $settled2->execute([$branch_id]);
                    echo $settled_count + $settled2->fetchColumn();
                    ?>
                </h3>
                <small>Paid in full</small>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'customer' ? 'active' : ''; ?>" href="?tab=customer">
                <i class="fas fa-users me-1"></i> Customer Debts
                <span class="badge bg-danger ms-1"><?php echo count($customer_debts); ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'supplier' ? 'active' : ''; ?>" href="?tab=supplier">
                <i class="fas fa-building me-1"></i> Supplier Debts
                <span class="badge bg-warning ms-1"><?php echo count($supplier_debts); ?></span>
            </a>
        </li>
    </ul>

    <!-- ============================================
    CUSTOMER DEBTS TAB
    ============================================ -->
    <?php if($active_tab == 'customer'): ?>
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Customer Debts</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($customer_debts)): ?>
            <div class="text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3 d-block"></i>
                <h5>No Customer Debts</h5>
                <p class="text-muted">All customers are up to date with payments.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Remaining</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customer_debts as $debt): 
                            $status_class = ['pending' => 'warning', 'partial' => 'info', 'paid' => 'success', 'overdue' => 'danger'][$debt['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($debt['customer_name']); ?></strong>
                                <br><small class="text-muted"><?php echo $debt['customer_phone'] ?? ''; ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($debt['sale_invoice'] ?? '—'); ?></td>
                            <td><?php echo $debt['sale_date'] ?? '—'; ?></td>
                            <td><?php echo number_format($debt['amount'], 0); ?></td>
                            <td><?php echo number_format($debt['paid_amount'], 0); ?></td>
                            <td><strong class="text-danger"><?php echo number_format($debt['remaining'], 0); ?></strong></td>
                            <td>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo strtoupper($debt['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($debt['remaining'] > 0): ?>
                                    <button class="btn btn-success" onclick="showPaymentModal('customer', <?php echo $debt['id']; ?>, '<?php echo htmlspecialchars($debt['customer_name']); ?>', <?php echo $debt['remaining']; ?>)">
                                        <i class="fas fa-hand-holding-usd"></i> Pay
                                    </button>
                                    <?php endif; ?>
                                    <a href="?tab=customer&view_payments=<?php echo $debt['id']; ?>" class="btn btn-outline-info">
                                        <i class="fas fa-history"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-danger">
                            <th colspan="5" class="text-end"><strong>Total Debt:</strong></th>
                            <th><strong><?php echo number_format($total_customer_debt, 0); ?> RWF</strong></th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    SUPPLIER DEBTS TAB
    ============================================ -->
    <?php if($active_tab == 'supplier'): ?>
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Supplier Debts</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($supplier_debts)): ?>
            <div class="text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3 d-block"></i>
                <h5>No Supplier Debts</h5>
                <p class="text-muted">All suppliers are paid up to date.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier</th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Remaining</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($supplier_debts as $debt): 
                            $status_class = ['pending' => 'warning', 'partial' => 'info', 'paid' => 'success', 'overdue' => 'danger'][$debt['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($debt['supplier_name']); ?></strong>
                                <br><small class="text-muted"><?php echo $debt['supplier_phone'] ?? ''; ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($debt['purchase_invoice'] ?? '—'); ?></td>
                            <td><?php echo $debt['purchase_date'] ?? '—'; ?></td>
                            <td><?php echo number_format($debt['amount'], 0); ?></td>
                            <td><?php echo number_format($debt['paid_amount'], 0); ?></td>
                            <td><strong class="text-warning"><?php echo number_format($debt['remaining'], 0); ?></strong></td>
                            <td>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo strtoupper($debt['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if($debt['remaining'] > 0): ?>
                                    <button class="btn btn-success" onclick="showPaymentModal('supplier', <?php echo $debt['id']; ?>, '<?php echo htmlspecialchars($debt['supplier_name']); ?>', <?php echo $debt['remaining']; ?>)">
                                        <i class="fas fa-hand-holding-usd"></i> Pay
                                    </button>
                                    <?php endif; ?>
                                    <a href="?tab=supplier&view_payments=<?php echo $debt['id']; ?>" class="btn btn-outline-info">
                                        <i class="fas fa-history"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning">
                            <th colspan="5" class="text-end"><strong>Total Debt:</strong></th>
                            <th><strong><?php echo number_format($total_supplier_debt, 0); ?> RWF</strong></th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    PAYMENT HISTORY MODAL
    ============================================ -->
    <?php if(!empty($debt_payments)): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Payment History</h5>
            <a href="debts.php?tab=<?php echo $active_tab; ?>" class="btn btn-sm btn-secondary">Close</a>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($debt_payments as $p): ?>
                        <tr>
                            <td><?php echo $p['payment_date']; ?></td>
                            <td><strong><?php echo number_format($p['amount'], 0); ?></strong></td>
                            <td>
                                <span class="badge bg-<?php echo $p['payment_method'] == 'cash' ? 'success' : ($p['payment_method'] == 'mobile_money' ? 'info' : 'primary'); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($p['reference'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['notes'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['created_by_name'] ?? 'System'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    PAYMENT MODAL
    ============================================ -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-hand-holding-usd me-2"></i> Record Payment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="paymentForm">
                    <div class="modal-body">
                        <p><strong>Customer/Supplier:</strong> <span id="payment_name"></span></p>
                        <p><strong>Remaining Debt:</strong> <span id="payment_remaining" class="text-danger"></span></p>
                        
                        <input type="hidden" name="debt_id" id="payment_debt_id">
                        <input type="hidden" name="debt_type" id="payment_debt_type">
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Amount (RWF) *</label>
                            <input type="number" name="payment_amount" class="form-control" required min="1" step="any" id="payment_amount">
                            <small class="text-muted">Cannot exceed remaining debt</small>
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" class="form-select">
                                    <option value="cash">💵 Cash</option>
                                    <option value="mobile_money">📱 Mobile Money</option>
                                    <option value="bank_transfer">🏦 Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-2">
                            <label class="form-label">Notes</label>
                            <input type="text" name="payment_notes" class="form-control" placeholder="Optional notes">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="submit_payment">
                            <i class="fas fa-check me-1"></i> Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
function showPaymentModal(type, debtId, name, remaining) {
    document.getElementById('payment_debt_type').value = type;
    document.getElementById('payment_debt_id').value = debtId;
    document.getElementById('payment_name').textContent = name;
    document.getElementById('payment_remaining').textContent = new Intl.NumberFormat('en-RW').format(remaining) + ' RWF';
    document.getElementById('payment_amount').max = remaining;
    document.getElementById('payment_amount').placeholder = 'Max: ' + new Intl.NumberFormat('en-RW').format(remaining);
    
    // Set form action
    const form = document.getElementById('paymentForm');
    if (type === 'customer') {
        form.action = 'debts.php?tab=customer';
        document.getElementById('submit_payment').name = 'pay_customer_debt';
        document.getElementById('submit_payment').innerHTML = '<i class="fas fa-check me-1"></i> Record Customer Payment';
    } else {
        form.action = 'debts.php?tab=supplier';
        document.getElementById('submit_payment').name = 'pay_supplier_debt';
        document.getElementById('submit_payment').innerHTML = '<i class="fas fa-check me-1"></i> Record Supplier Payment';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// Auto-set max amount
document.getElementById('payment_amount').addEventListener('input', function() {
    const max = parseFloat(this.max);
    const value = parseFloat(this.value);
    if (value > max) {
        this.value = max;
    }
});
</script>

<style>
.stat-card {
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.stat-card .stat-label {
    font-size: 13px;
    opacity: 0.8;
    margin-bottom: 5px;
}
.stat-card h3 {
    margin: 0;
    font-weight: 700;
}
</style>

<?php include '../includes/footer.php'; ?>