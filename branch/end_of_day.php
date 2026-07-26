<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$is_admin = isAdmin();
$message = '';

// ============================================
// GET DATA FOR TODAY
// ============================================

$today = date('Y-m-d');

// Accept ?date=YYYY-MM-DD, capped so you can't file a future day
$selected_date = $_GET['date'] ?? $today;
if ($selected_date > $today) $selected_date = $today;

// ============================================
// SALES SUMMARY
// ============================================

$sales_summary = $pdo->prepare("
    SELECT 
        COALESCE(SUM(grand_total), 0) as total_sales,
        COALESCE(SUM(cash_amount), 0) as total_cash,
        COALESCE(SUM(mobile_money_amount), 0) as total_momo,
        COUNT(*) as transaction_count,
        COALESCE(SUM(discount), 0) as total_discount
    FROM sales 
    WHERE branch_id = ? AND sale_date = ?
");
$sales_summary->execute([$branch_id, $selected_date]);
$sales_summary = $sales_summary->fetch();

// ============================================
// EXPENSES (Regular Branch Expenses)
// ============================================

$expenses_total = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM expenses 
    WHERE branch_id = ? AND expense_date = ?
");
$expenses_total->execute([$branch_id, $selected_date]);
$expenses_total = $expenses_total->fetchColumn();

// Only CASH expenses reduce physical cash in the drawer
$cash_expenses = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM expenses
    WHERE branch_id = ? AND expense_date = ? AND payment_method = 'cash'
");
$cash_expenses->execute([$branch_id, $selected_date]);
$cash_expenses = $cash_expenses->fetchColumn();

// ============================================
// REGULAR PURCHASES (Stock In - Cash paid to suppliers)
// ONLY include regular purchases (purchase_order_id IS NULL)
// NOT advance PO deliveries (already paid via advance)
// NOT formal PO deliveries (paid later via supplier debt)
// ============================================

$purchases_cash = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM purchases
    WHERE branch_id = ? 
    AND purchase_date = ?
    AND purchase_order_id IS NULL
");
$purchases_cash->execute([$branch_id, $selected_date]);
$purchases_cash = $purchases_cash->fetchColumn();

// ============================================
// CASH ADVANCES GIVEN TO SUPPLIERS (Initial + Top-ups)
// ============================================

$advances_given = $pdo->prepare("
    SELECT COALESCE(SUM(advance_amount), 0) as total
    FROM purchase_orders
    WHERE branch_id = ? AND po_type = 'advance' AND order_date = ? AND status != 'cancelled'
");
$advances_given->execute([$branch_id, $selected_date]);
$advances_given = $advances_given->fetchColumn();

$topups_given = $pdo->prepare("
    SELECT COALESCE(SUM(t.amount), 0) as total
    FROM po_topups t
    JOIN purchase_orders po ON t.po_id = po.id
    WHERE po.branch_id = ? AND DATE(t.created_at) = ?
");
$topups_given->execute([$branch_id, $selected_date]);
$topups_given = $topups_given->fetchColumn();

$cash_advances_given = $advances_given + $topups_given;

// ============================================
// CUSTOMER DEBT PAYMENTS RECEIVED
// ============================================

$payments_received_cash = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE branch_id = ? AND payment_date = ? AND debt_type = 'customer' AND payment_method = 'cash'
");
$payments_received_cash->execute([$branch_id, $selected_date]);
$payments_received_cash = $payments_received_cash->fetchColumn();

$payments_received_momo = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE branch_id = ? AND payment_date = ? AND debt_type = 'customer' AND payment_method = 'mobile_money'
");
$payments_received_momo->execute([$branch_id, $selected_date]);
$payments_received_momo = $payments_received_momo->fetchColumn();

// ============================================
// SUPPLIER DEBT PAYMENTS MADE (Cash leaving the drawer)
// ============================================

$payments_made_cash = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE branch_id = ? AND payment_date = ? AND debt_type = 'supplier' AND payment_method = 'cash'
");
$payments_made_cash->execute([$branch_id, $selected_date]);
$payments_made_cash = $payments_made_cash->fetchColumn();

// ============================================
// CHECK IF EOD ALREADY RECORDED
// ============================================

$existing_eod = $pdo->prepare("
    SELECT * FROM end_of_day 
    WHERE branch_id = ? AND report_date = ?
");
$existing_eod->execute([$branch_id, $selected_date]);
$existing_eod = $existing_eod->fetch();

$edit_mode = $is_admin && isset($_GET['edit']) && $existing_eod;

// ============================================
// SAVE END OF DAY
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_eod'])) {
    $actual_cash = floatval($_POST['actual_cash'] ?? 0);
    $actual_momo = floatval($_POST['actual_momo'] ?? 0);
    $notes = sanitize($_POST['notes']);
    $stock_discrepancies = sanitize($_POST['stock_discrepancies'] ?? '');
    $save_date = $_POST['report_date'] ?? $selected_date;
    $is_edit_save = $is_admin && isset($_POST['is_edit']) && $_POST['is_edit'] == '1';

    // Expected Cash:
    // Sales cash + customer cash payments 
    // - cash expenses 
    // - supplier cash payments 
    // - cash advances (initial + top-ups) 
    // - regular purchases (NOT advance PO deliveries)
    $expected_cash = $sales_summary['total_cash']
                   + $payments_received_cash
                   - $cash_expenses
                   - $payments_made_cash
                   - $cash_advances_given
                   - $purchases_cash;
    
    // Expected MOMO:
    // Sales MOMO + customer MOMO payments
    $expected_momo = $sales_summary['total_momo'] + $payments_received_momo;
    
    // Total expenses for display (regular expenses only - workspace is standalone)
    $total_expenses_for_eod = $expenses_total;
    
    $cash_diff = $actual_cash - $expected_cash;
    $momo_diff = $actual_momo - $expected_momo;
    
    $status = 'confirmed';
    if (abs($cash_diff) > 1000 || abs($momo_diff) > 1000) {
        $status = 'alert';
    }
    
    try {
        $discrepancies = [];
        if (!empty($stock_discrepancies)) {
            $discrepancies = json_decode($stock_discrepancies, true) ?? [];
        }

        if ($is_edit_save) {
            // Admin correcting an existing record
            $stmt = $pdo->prepare("
                UPDATE end_of_day SET
                    expected_cash=?, actual_cash=?, expected_momo=?, actual_momo=?,
                    total_expenses=?, total_sales=?, stock_discrepancies=?, notes=?, status=?
                WHERE branch_id=? AND report_date=?
            ");
            $stmt->execute([
                $expected_cash, $actual_cash, $expected_momo, $actual_momo,
                $total_expenses_for_eod, $sales_summary['total_sales'],
                json_encode($discrepancies), $notes, $status,
                $branch_id, $save_date
            ]);
            logAction($pdo, 'End of Day Edited', "EOD for $save_date corrected by admin (Status: $status)");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO end_of_day (
                    branch_id, report_date,
                    expected_cash, actual_cash,
                    expected_momo, actual_momo,
                    total_expenses, total_sales,
                    stock_discrepancies, notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $branch_id,
                $save_date,
                $expected_cash,
                $actual_cash,
                $expected_momo,
                $actual_momo,
                $total_expenses_for_eod,
                $sales_summary['total_sales'],
                json_encode($discrepancies),
                $notes,
                $status,
                $_SESSION['user_id']
            ]);
            logAction($pdo, 'End of Day', "EOD recorded for $save_date (Status: $status)");
        }
        
        $message = '<div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <strong>✅ End of Day ' . ($is_edit_save ? 'Updated' : 'Recorded') . '!</strong>
            <br>Date: ' . $save_date . '
            <br>Status: <span class="badge bg-' . ($status == 'alert' ? 'danger' : 'success') . '">' . strtoupper($status) . '</span>
            ' . ($status == 'alert' ? '<br><span class="text-danger">⚠️ Discrepancy detected! Please check your cash/MOMO counts.</span>' : '') . '
        </div>';
        
        $existing_eod = $pdo->prepare("
            SELECT * FROM end_of_day 
            WHERE branch_id = ? AND report_date = ?
        ");
        $existing_eod->execute([$branch_id, $save_date]);
        $existing_eod = $existing_eod->fetch();
        $selected_date = $save_date;
        $edit_mode = false;
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// GET EOD HISTORY
// ============================================

$eod_history = $pdo->prepare("
    SELECT e.*, u.full_name as created_by_name
    FROM end_of_day e
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.branch_id = ?
    ORDER BY e.report_date DESC
    LIMIT 30
");
$eod_history->execute([$branch_id]);
$eod_history = $eod_history->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="col-md-10 main-content">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2><i class="fas fa-calendar-check me-2 text-primary"></i>End of Day</h2>
        <p class="text-muted">
            <?php echo date('l, F j, Y', strtotime($selected_date)); ?> 
            <span class="mx-2">|</span>
            <?php echo htmlspecialchars(getCurrentBranchName()); ?> Branch
            <?php if($selected_date !== $today): ?>
            <span class="badge bg-secondary ms-2">Viewing a past day</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <form method="GET" class="d-flex gap-2">
            <input type="date" name="date" class="form-control form-control-sm"
                   value="<?php echo $selected_date; ?>" max="<?php echo $today; ?>"
                   onchange="this.form.submit()">
        </form>
        <span class="badge bg-<?php echo $existing_eod ? ($existing_eod['status']=='alert' ? 'danger' : 'success') : 'warning'; ?> fs-6">
            <?php echo $existing_eod ? ($existing_eod['status']=='alert' ? '⚠️ Alert' : '✅ Recorded') : '⏳ Pending'; ?>
        </span>
    </div>
</div>

<?php echo $message; ?>

<!-- ============================================
TODAY'S SUMMARY
============================================ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Total Sales</p>
            <h4 class="text-success"><?php echo number_format($sales_summary['total_sales'], 0); ?></h4>
            <small><?php echo $sales_summary['transaction_count']; ?> transactions</small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Cash Received</p>
            <h4 class="text-primary"><?php echo number_format($sales_summary['total_cash'] + $payments_received_cash, 0); ?></h4>
            <small>Sales: <?php echo number_format($sales_summary['total_cash'], 0); ?> + Payments: <?php echo number_format($payments_received_cash, 0); ?></small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Mobile Money</p>
            <h4 class="text-info"><?php echo number_format($sales_summary['total_momo'] + $payments_received_momo, 0); ?></h4>
            <small>Sales: <?php echo number_format($sales_summary['total_momo'], 0); ?> + MOMO Payments: <?php echo number_format($payments_received_momo, 0); ?></small>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card text-center">
            <p class="stat-label">Cash Outflows</p>
            <h4 class="text-danger">
                <?php 
                $total_outflows = $cash_expenses 
                                + $payments_made_cash 
                                + $cash_advances_given 
                                + $purchases_cash;
                echo number_format($total_outflows, 0); 
                ?>
            </h4>
            <small>
                Expenses: <?php echo number_format($cash_expenses, 0); ?>
                <?php if($purchases_cash > 0): ?>
                | Stock In: <?php echo number_format($purchases_cash, 0); ?>
                <?php endif; ?>
                <?php if($cash_advances_given > 0): ?>
                | Advances: <?php echo number_format($cash_advances_given, 0); ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
</div>

<!-- ============================================
EOD FORM
============================================ -->
<?php if(!$existing_eod || $edit_mode): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>
            <?php echo $edit_mode ? 'Correcting End of Day — ' . $selected_date : 'End of Day Report'; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php
        $calc_expected_cash = $sales_summary['total_cash']
                            + $payments_received_cash
                            - $cash_expenses
                            - $payments_made_cash
                            - $cash_advances_given
                            - $purchases_cash;
        
        $calc_expected_momo = $sales_summary['total_momo'] + $payments_received_momo;
        
        $prefill_cash = $edit_mode ? $existing_eod['actual_cash'] : $calc_expected_cash;
        $prefill_momo = $edit_mode ? $existing_eod['actual_momo'] : $calc_expected_momo;
        ?>
        <form method="POST">
            <input type="hidden" name="report_date" value="<?php echo $selected_date; ?>">
            <?php if($edit_mode): ?><input type="hidden" name="is_edit" value="1"><?php endif; ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <strong>Expected Cash:</strong> <?php echo number_format($calc_expected_cash, 0); ?> RWF
                        <br>
                        <small>
                            + Sales cash (<?php echo number_format($sales_summary['total_cash'],0); ?>)
                            <br>+ Customer cash payments (<?php echo number_format($payments_received_cash,0); ?>)
                            <br>− Cash expenses (<?php echo number_format($cash_expenses,0); ?>)
                            <br>− Supplier cash payments (<?php echo number_format($payments_made_cash,0); ?>)
                            <br>− Advances/top-ups (<?php echo number_format($cash_advances_given,0); ?>)
                            <?php if($purchases_cash > 0): ?>
                            <br>− Stock In (<?php echo number_format($purchases_cash,0); ?>)
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Cash Count (RWF) *</label>
                        <input type="number" name="actual_cash" class="form-control" required min="0" step="any" 
                               placeholder="Count actual cash in drawer"
                               value="<?php echo $prefill_cash; ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <strong>Expected Mobile Money:</strong> <?php echo number_format($calc_expected_momo, 0); ?> RWF
                        <br>
                        <small>
                            + MOMO from sales (<?php echo number_format($sales_summary['total_momo'],0); ?>)
                            <br>+ Customer MOMO payments (<?php echo number_format($payments_received_momo,0); ?>)
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Mobile Money (RWF) *</label>
                        <input type="number" name="actual_momo" class="form-control" required min="0" step="any" 
                               placeholder="Count actual MOMO received"
                               value="<?php echo $prefill_momo; ?>">
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Stock Discrepancies (if any)</label>
                <textarea name="stock_discrepancies" class="form-control" rows="2" 
                          placeholder="e.g., Missing 5 bags of cement, 2 boxes of nails..."><?php echo $edit_mode ? htmlspecialchars(is_array(json_decode($existing_eod['stock_discrepancies'],true)) ? implode(', ', json_decode($existing_eod['stock_discrepancies'],true)) : $existing_eod['stock_discrepancies']) : ''; ?></textarea>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" 
                          placeholder="Any issues, incidents, or notes for today..."><?php echo $edit_mode ? htmlspecialchars($existing_eod['notes']) : ''; ?></textarea>
            </div>
            
            <button type="submit" name="save_eod" class="btn btn-success btn-lg w-100" 
                    onclick="return confirm('<?php echo $edit_mode ? "Save corrections to this End of Day report?" : "Confirm End of Day report? This will lock this day\'s data."; ?>')">
                <i class="fas fa-save me-2"></i> <?php echo $edit_mode ? 'Save Corrections' : 'Save End of Day'; ?>
            </button>
            <?php if($edit_mode): ?>
            <a href="end_of_day.php?date=<?php echo $selected_date; ?>" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php else: ?>
<!-- Already recorded -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>End of Day Already Recorded</h5>
        <?php if($is_admin): ?>
        <a href="?date=<?php echo $selected_date; ?>&edit=1" class="btn btn-sm btn-light">
            <i class="fas fa-edit me-1"></i> Correct This Entry
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>Date</strong></td>
                        <td><?php echo $existing_eod['report_date']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td>
                            <span class="badge bg-<?php echo $existing_eod['status'] == 'alert' ? 'danger' : 'success'; ?>">
                                <?php echo strtoupper($existing_eod['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Expected Cash</strong></td>
                        <td><?php echo number_format($existing_eod['expected_cash'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>Actual Cash</strong></td>
                        <td><?php echo number_format($existing_eod['actual_cash'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>Cash Difference</strong></td>
                        <td class="<?php echo abs($existing_eod['actual_cash'] - $existing_eod['expected_cash']) > 1000 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($existing_eod['actual_cash'] - $existing_eod['expected_cash'], 0); ?> RWF
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>Expected MOMO</strong></td>
                        <td><?php echo number_format($existing_eod['expected_momo'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>Actual MOMO</strong></td>
                        <td><?php echo number_format($existing_eod['actual_momo'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>MOMO Difference</strong></td>
                        <td class="<?php echo abs($existing_eod['actual_momo'] - $existing_eod['expected_momo']) > 1000 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($existing_eod['actual_momo'] - $existing_eod['expected_momo'], 0); ?> RWF
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Total Expenses</strong></td>
                        <td><?php echo number_format($existing_eod['total_expenses'], 0); ?> RWF</td>
                    </tr>
                    <tr>
                        <td><strong>Net Sales</strong></td>
                        <td><?php echo number_format($existing_eod['total_sales'] - $existing_eod['total_expenses'], 0); ?> RWF</td>
                    </tr>
                </table>
            </div>
        </div>
        <?php if($existing_eod['notes']): ?>
        <div class="alert alert-info mt-2">
            <strong><i class="fas fa-sticky-note me-1"></i> Notes:</strong>
            <?php echo nl2br(htmlspecialchars($existing_eod['notes'])); ?>
        </div>
        <?php endif; ?>
        <?php if($existing_eod['stock_discrepancies']): ?>
        <div class="alert alert-warning mt-2">
            <strong><i class="fas fa-exclamation-triangle me-1"></i> Stock Discrepancies:</strong>
            <?php 
            $discrepancies = json_decode($existing_eod['stock_discrepancies'], true);
            if (is_array($discrepancies) && !empty($discrepancies)) {
                echo '<ul>';
                foreach ($discrepancies as $item) {
                    echo '<li>' . htmlspecialchars($item) . '</li>';
                }
                echo '</ul>';
            } else {
                echo nl2br(htmlspecialchars($existing_eod['stock_discrepancies']));
            }
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
EOD HISTORY
============================================ -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>End of Day History</h5>
        <span class="badge bg-secondary"><?php echo count($eod_history); ?> records</span>
    </div>
    <div class="card-body p-0">
        <?php if(empty($eod_history)): ?>
        <div class="text-center py-4 text-muted">
            No End of Day records yet. Complete your first EOD today!
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Sales</th>
                        <th>Cash Diff</th>
                        <th>MOMO Diff</th>
                        <th>Net</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($eod_history as $eod): 
                        $cash_diff = $eod['actual_cash'] - $eod['expected_cash'];
                        $momo_diff = $eod['actual_momo'] - $eod['expected_momo'];
                        $net = $eod['total_sales'] - $eod['total_expenses'];
                    ?>
                    <tr>
                        <td><strong><?php echo $eod['report_date']; ?></strong></td>
                        <td>
                            <span class="badge bg-<?php echo $eod['status'] == 'alert' ? 'danger' : 'success'; ?>">
                                <?php echo strtoupper($eod['status']); ?>
                            </span>
                        </td>
                        <td><?php echo number_format($eod['total_sales'], 0); ?></td>
                        <td class="<?php echo abs($cash_diff) > 1000 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($cash_diff, 0); ?>
                        </td>
                        <td class="<?php echo abs($momo_diff) > 1000 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($momo_diff, 0); ?>
                        </td>
                        <td><?php echo number_format($net, 0); ?></td>
                        <td><small><?php echo htmlspecialchars($eod['created_by_name'] ?? 'System'); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

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