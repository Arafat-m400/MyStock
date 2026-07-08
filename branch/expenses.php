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

// Add/Edit Expense
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_expense'])) {
    $id = $_POST['id'] ?? 0;
    $category = sanitize($_POST['category']);
    $description = sanitize($_POST['description']);
    $amount = floatval($_POST['amount']);
    $expense_date = $_POST['expense_date'];
    $payment_method = $_POST['payment_method'];
    
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE expenses 
                SET category=?, description=?, amount=?, expense_date=?, payment_method=?
                WHERE id=? AND branch_id=?
            ");
            $stmt->execute([$category, $description, $amount, $expense_date, $payment_method, $id, $branch_id]);
            $message = '<div class="alert alert-success">✅ Expense updated successfully!</div>';
            logAction($pdo, 'Update Expense', "Updated expense: $category - $amount RWF");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO expenses (branch_id, category, description, amount, expense_date, payment_method, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$branch_id, $category, $description, $amount, $expense_date, $payment_method, $_SESSION['user_id']]);
            $message = '<div class="alert alert-success">✅ Expense added successfully!</div>';
            logAction($pdo, 'Add Expense', "Added expense: $category - $amount RWF");
        }
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// Delete Expense
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $is_admin) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $branch_id]);
    $message = '<div class="alert alert-success">✅ Expense deleted!</div>';
    logAction($pdo, 'Delete Expense', "Deleted expense ID: $id");
}

// ============================================
// GET DATA
// ============================================

// Get date range filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get expenses with date filter
$expenses = $pdo->prepare("
    SELECT * FROM expenses 
    WHERE branch_id = ? 
    AND expense_date BETWEEN ? AND ?
    ORDER BY expense_date DESC, created_at DESC
");
$expenses->execute([$branch_id, $start_date, $end_date]);
$expenses = $expenses->fetchAll();

// Get summary stats
$summary = $pdo->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total,
        COUNT(*) as count,
        COALESCE(AVG(amount), 0) as average
    FROM expenses 
    WHERE branch_id = ? AND expense_date BETWEEN ? AND ?
");
$summary->execute([$branch_id, $start_date, $end_date]);
$summary = $summary->fetch();

// Get expenses by category
$by_category = $pdo->prepare("
    SELECT category, 
           COUNT(*) as count,
           COALESCE(SUM(amount), 0) as total
    FROM expenses 
    WHERE branch_id = ? AND expense_date BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total DESC
");
$by_category->execute([$branch_id, $start_date, $end_date]);
$by_category = $by_category->fetchAll();

// Get today's expenses
$today_total = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE branch_id = ? AND expense_date = CURDATE()
");
$today_total->execute([$branch_id]);
$today_total = $today_total->fetchColumn();

// Get this month's expenses
$month_total = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE branch_id = ? AND MONTH(expense_date) = MONTH(CURDATE()) 
    AND YEAR(expense_date) = YEAR(CURDATE())
");
$month_total->execute([$branch_id]);
$month_total = $month_total->fetchColumn();

// Get edit data
$edit_expense = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND branch_id = ?");
    $stmt->execute([$_GET['edit'], $branch_id]);
    $edit_expense = $stmt->fetch();
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-money-bill-wave me-2 text-primary"></i>Expenses</h2>
        <p class="text-muted">
            Track and manage business expenses
        </p>
    </div>
    <?php if($is_admin): ?>
    <div>
        <a href="?add" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add Expense
        </a>
    </div>
    <?php endif; ?>
</div>

<?php echo $message; ?>

<!-- ============================================
DATE FILTER
============================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(7)">7 Days</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(30)">30 Days</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(90)">90 Days</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(365)">1 Year</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
STATS CARDS
============================================ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <p class="stat-label">Total Expenses</p>
            <h4 class="text-danger"><?php echo number_format($summary['total'], 0); ?> RWF</h4>
            <small class="text-muted"><?php echo $summary['count']; ?> transactions</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <p class="stat-label">Average Per Day</p>
            <h4 class="text-primary">
                <?php 
                $days = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);
                echo number_format($summary['total'] / $days, 0); 
                ?> RWF
            </h4>
            <small class="text-muted">Over <?php echo round($days); ?> days</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <p class="stat-label">Today's Expenses</p>
            <h4 class="text-warning"><?php echo number_format($today_total, 0); ?> RWF</h4>
            <small class="text-muted"><?php echo date('Y-m-d'); ?></small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <p class="stat-label">This Month</p>
            <h4 class="text-info"><?php echo number_format($month_total, 0); ?> RWF</h4>
            <small class="text-muted"><?php echo date('F Y'); ?></small>
        </div>
    </div>
</div>

<!-- ============================================
ADD/EDIT EXPENSE FORM
============================================ -->
<?php if(($is_admin && isset($_GET['add'])) || $edit_expense): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $edit_expense ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $edit_expense ? 'Edit Expense' : 'Add New Expense'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $edit_expense['id'] ?? 0; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Category *</label>
                    <select name="category" class="form-select" required>
                        <option value="">-- Select Category --</option>
                        <?php 
                        $categories = ['Transport', 'Utilities', 'Rent', 'Salaries', 'Marketing', 'Supplies', 
                                       'Maintenance', 'Insurance', 'Taxes', 'Food', 'Stationery', 'Other'];
                        foreach($categories as $cat):
                        ?>
                        <option value="<?php echo $cat; ?>" 
                            <?php echo ($edit_expense['category'] ?? '') == $cat ? 'selected' : ''; ?>>
                            <?php echo $cat; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Amount (RWF) *</label>
                    <input type="number" name="amount" class="form-control" required min="0" step="100"
                           value="<?php echo $edit_expense['amount'] ?? ''; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date *</label>
                    <input type="date" name="expense_date" class="form-control" required
                           value="<?php echo $edit_expense['expense_date'] ?? date('Y-m-d'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="cash" <?php echo ($edit_expense['payment_method'] ?? '') == 'cash' ? 'selected' : ''; ?>>💵 Cash</option>
                        <option value="mobile_money" <?php echo ($edit_expense['payment_method'] ?? '') == 'mobile_money' ? 'selected' : ''; ?>>📱 Mobile Money</option>
                        <option value="bank_transfer" <?php echo ($edit_expense['payment_method'] ?? '') == 'bank_transfer' ? 'selected' : ''; ?>>🏦 Bank Transfer</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" 
                              placeholder="What was this expense for?"><?php echo htmlspecialchars($edit_expense['description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" name="save_expense" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>
                    <?php echo $edit_expense ? 'Update Expense' : 'Save Expense'; ?>
                </button>
                <a href="expenses.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
EXPENSES LIST
============================================ -->
<?php if(!isset($_GET['add']) && !$edit_expense): ?>
<div class="row">
    <!-- Expenses Table -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Expenses</h5>
                <span class="badge bg-secondary"><?php echo count($expenses); ?> records</span>
            </div>
            <div class="card-body p-0">
                <?php if(empty($expenses)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-receipt fa-4x text-muted mb-3 d-block"></i>
                    <h5>No Expenses Found</h5>
                    <p class="text-muted">
                        <?php if($start_date != date('Y-m-01') || $end_date != date('Y-m-d')): ?>
                        No expenses in this date range.
                        <a href="expenses.php" class="alert-link">Clear filter</a>
                        <?php else: ?>
                        Start tracking your business expenses.
                        <?php if($is_admin): ?>
                        <a href="?add" class="alert-link">Add first expense</a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Method</th>
                                <th style="text-align: right;">Amount</th>
                                <?php if($is_admin): ?>
                                <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($expenses as $exp): ?>
                            <tr>
                                <td><?php echo $exp['expense_date']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo [
                                        'Transport' => 'info',
                                        'Utilities' => 'warning',
                                        'Rent' => 'primary',
                                        'Salaries' => 'success',
                                        'Marketing' => 'danger',
                                        'Supplies' => 'secondary',
                                        'Maintenance' => 'dark',
                                        'Insurance' => 'info',
                                        'Taxes' => 'danger',
                                        'Food' => 'success',
                                        'Stationery' => 'primary',
                                        'Other' => 'secondary'
                                    ][$exp['category']] ?? 'secondary'; ?>">
                                        <?php echo htmlspecialchars($exp['category']); ?>
                                    </span>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($exp['description'] ?: '—')); ?></td>
                                <td>
                                    <?php 
                                    $method_icons = [
                                        'cash' => '💵',
                                        'mobile_money' => '📱',
                                        'bank_transfer' => '🏦'
                                    ];
                                    echo $method_icons[$exp['payment_method']] ?? '—';
                                    ?>
                                </td>
                                <td style="text-align: right;">
                                    <strong class="text-danger"><?php echo number_format($exp['amount'], 0); ?></strong>
                                </td>
                                <?php if($is_admin): ?>
                                <td style="text-align: center;">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?edit=<?php echo $exp['id']; ?>" class="btn btn-outline-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?delete=<?php echo $exp['id']; ?>" class="btn btn-outline-danger" 
                                           onclick="return confirm('Delete this expense?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-danger">
                                <th colspan="4" class="text-end"><strong>Total:</strong></th>
                                <th style="text-align: right;">
                                    <strong><?php echo number_format($summary['total'], 0); ?> RWF</strong>
                                </th>
                                <?php if($is_admin): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Category Breakdown -->
    <div class="col-lg-4 mt-3 mt-lg-0">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>By Category</h5>
            </div>
            <div class="card-body">
                <?php if(empty($by_category)): ?>
                <p class="text-muted text-center py-3">No data to display</p>
                <?php else: ?>
                <?php foreach($by_category as $cat): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span><?php echo htmlspecialchars($cat['category']); ?></span>
                        <span><strong><?php echo number_format($cat['total'], 0); ?></strong></span>
                    </div>
                    <?php 
                    $max = $by_category[0]['total'] ?? 1;
                    $percent = ($cat['total'] / $max) * 100;
                    ?>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-<?php echo [
                            'Transport' => 'info',
                            'Utilities' => 'warning',
                            'Rent' => 'primary',
                            'Salaries' => 'success',
                            'Marketing' => 'danger',
                            'Supplies' => 'secondary',
                            'Maintenance' => 'dark',
                            'Insurance' => 'info',
                            'Taxes' => 'danger',
                            'Food' => 'success',
                            'Stationery' => 'primary',
                            'Other' => 'secondary'
                        ][$cat['category']] ?? 'secondary'; ?>" 
                             style="width: <?php echo $percent; ?>%;">
                        </div>
                    </div>
                    <small class="text-muted"><?php echo $cat['count']; ?> transactions</small>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <small class="text-muted">Total Categories</small>
                        <h5 class="text-primary"><?php echo count($by_category); ?></h5>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Avg Expense</small>
                        <h5 class="text-warning"><?php echo number_format($summary['average'], 0); ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function setDateRange(days) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);
    
    document.querySelector('input[name="start_date"]').value = start.toISOString().split('T')[0];
    document.querySelector('input[name="end_date"]').value = end.toISOString().split('T')[0];
    document.querySelector('form').submit();
}
</script>

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