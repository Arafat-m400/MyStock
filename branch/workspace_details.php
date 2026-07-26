<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$workspace_id = $_GET['id'] ?? 0;
$message = '';
$active_tab = $_GET['tab'] ?? 'overview';
$is_create = isset($_GET['action']) && $_GET['action'] == 'create';

// ============================================
// CREATE NEW WORKSPACE (Separate page)
// ============================================

if ($is_create) {
    include '../includes/header.php';
    include '../includes/sidebar.php';
    ?>
    <div class="col-md-10 main-content">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-plus-circle me-2 text-primary"></i>Create New Workspace</h2>
                <p class="text-muted">Start a new production workflow</p>
            </div>
            <a href="workspaces.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
        
        <?php echo $message; ?>
        
        <div class="card shadow-sm">
            <div class="card-body">
                <!-- ============================================
                IMPORTANT: Form submits to workspaces.php (NOT itself)
                NO hidden id field - this is a pure INSERT
                ============================================ -->
                <form method="POST" action="workspaces.php">
                    <div class="mb-3">
                        <label class="form-label">Workspace Name *</label>
                        <input type="text" name="name" class="form-control" required placeholder="Enter workspace name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the production process..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected End Date</label>
                            <input type="date" name="expected_end_date" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" name="create_workspace" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Create Workspace
                        </button>
                        <a href="workspaces.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    include '../includes/footer.php';
    exit();
}

// ============================================
// GET WORKSPACE DATA
// ============================================

$stmt = $pdo->prepare("
    SELECT w.*, u.full_name as created_by_name
    FROM workspaces w
    LEFT JOIN users u ON w.created_by = u.id
    WHERE w.id = ? AND w.branch_id = ?
");
$stmt->execute([$workspace_id, $branch_id]);
$workspace = $stmt->fetch();

if (!$workspace) {
    die("Workspace not found.");
}

// ============================================
// GET INPUTS
// ============================================

$inputs = $pdo->prepare("
    SELECT * FROM workspace_inputs
    WHERE workspace_id = ?
    ORDER BY created_at DESC
");
$inputs->execute([$workspace_id]);
$inputs = $inputs->fetchAll();
$total_input_cost = array_sum(array_column($inputs, 'total_cost'));

// ============================================
// GET OUTPUTS
// ============================================

$outputs = $pdo->prepare("
    SELECT * FROM workspace_outputs
    WHERE workspace_id = ?
    ORDER BY created_at DESC
");
$outputs->execute([$workspace_id]);
$outputs = $outputs->fetchAll();
$total_output_value = array_sum(array_column($outputs, 'total_value'));

// Calculate profit/loss
$profit_loss = $total_output_value - $total_input_cost;

// ============================================
// ADD INPUT (Raw Material OR Expense)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_input'])) {
    $input_type = $_POST['input_type'];
    $name = sanitize($_POST['name']);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit = sanitize($_POST['unit'] ?? '');
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $total_cost = floatval($_POST['total_cost'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    
    try {
        if ($input_type == 'expense') {
            $total_cost = floatval($_POST['expense_amount'] ?? 0);
            $quantity = null;
            $unit = null;
            $unit_cost = null;
            if ($total_cost <= 0) {
                throw new Exception("Expense amount must be greater than 0.");
            }
        } else {
            if ($quantity <= 0) {
                throw new Exception("Quantity must be greater than 0.");
            }
            if ($unit_cost < 0) {
                throw new Exception("Unit cost cannot be negative.");
            }
            $total_cost = $quantity * $unit_cost;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO workspace_inputs (workspace_id, input_type, name, quantity, unit, unit_cost, total_cost, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workspace_id, $input_type, $name, $quantity, $unit, $unit_cost, $total_cost, $notes]);
        
        $type_label = $input_type == 'expense' ? 'Expense' : 'Raw Material';
        $message = '<div class="alert alert-success">✅ ' . $type_label . ' added: ' . htmlspecialchars($name) . ' (' . number_format($total_cost, 0) . ' RWF)</div>';
        logAction($pdo, 'Workspace Add Input', "Added $type_label to workspace #$workspace_id");
        
        // Refresh data
        $inputs = $pdo->prepare("SELECT * FROM workspace_inputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $inputs->execute([$workspace_id]);
        $inputs = $inputs->fetchAll();
        $total_input_cost = array_sum(array_column($inputs, 'total_cost'));
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// ADD OUTPUT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_output'])) {
    $name = sanitize($_POST['name']);
    $quantity = floatval($_POST['quantity']);
    $unit = sanitize($_POST['unit']);
    $unit_value = floatval($_POST['unit_value']);
    $total_value = $quantity * $unit_value;
    $notes = sanitize($_POST['notes'] ?? '');
    
    try {
        if (empty($name)) {
            throw new Exception("Output/Yield name is required.");
        }
        if ($quantity <= 0) {
            throw new Exception("Quantity must be greater than 0.");
        }
        if ($unit_value <= 0) {
            throw new Exception("Unit value must be greater than 0.");
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO workspace_outputs (workspace_id, name, quantity, unit, unit_value, total_value, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workspace_id, $name, $quantity, $unit, $unit_value, $total_value, $notes]);
        
        $message = '<div class="alert alert-success">✅ Output/Yield added: ' . $quantity . ' ' . $unit . ' of ' . htmlspecialchars($name) . ' (Value: ' . number_format($total_value, 0) . ' RWF)</div>';
        logAction($pdo, 'Workspace Add Output', "Added output to workspace #$workspace_id");
        
        // Refresh data
        $outputs = $pdo->prepare("SELECT * FROM workspace_outputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $outputs->execute([$workspace_id]);
        $outputs = $outputs->fetchAll();
        $total_output_value = array_sum(array_column($outputs, 'total_value'));
        $profit_loss = $total_output_value - $total_input_cost;
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// DELETE WORKSPACE
// ============================================

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    try {
        $check = $pdo->prepare("SELECT id, name FROM workspaces WHERE id = ? AND branch_id = ?");
        $check->execute([$workspace_id, $branch_id]);
        $workspace_check = $check->fetch();
        
        if (!$workspace_check) {
            throw new Exception("Workspace not found.");
        }
        
        $count_inputs = $pdo->prepare("SELECT COUNT(*) FROM workspace_inputs WHERE workspace_id = ?");
        $count_inputs->execute([$workspace_id]);
        $inputs_count = $count_inputs->fetchColumn();
        
        $count_outputs = $pdo->prepare("SELECT COUNT(*) FROM workspace_outputs WHERE workspace_id = ?");
        $count_outputs->execute([$workspace_id]);
        $outputs_count = $count_outputs->fetchColumn();
        
        $stmt = $pdo->prepare("DELETE FROM workspaces WHERE id = ? AND branch_id = ?");
        $stmt->execute([$workspace_id, $branch_id]);
        
        logAction($pdo, 'Workspace Delete', "Deleted workspace: {$workspace_check['name']}");
        
        $_SESSION['workspace_message'] = '<div class="alert alert-success">✅ Workspace "' . htmlspecialchars($workspace_check['name']) . '" deleted successfully!</div>';
        redirect('workspaces.php');
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// UPDATE WORKSPACE STATUS
// ============================================

if (isset($_GET['action']) && $_GET['action'] == 'complete') {
    $pdo->prepare("UPDATE workspaces SET status = 'completed', actual_end_date = CURDATE() WHERE id = ?")
        ->execute([$workspace_id]);
    $message = '<div class="alert alert-success">✅ Workspace marked as completed!</div>';
    $workspace['status'] = 'completed';
    logAction($pdo, 'Workspace Complete', "Completed workspace #$workspace_id");
}

if (isset($_GET['action']) && $_GET['action'] == 'pause') {
    $pdo->prepare("UPDATE workspaces SET status = 'paused' WHERE id = ?")
        ->execute([$workspace_id]);
    $message = '<div class="alert alert-warning">⏸️ Workspace paused.</div>';
    $workspace['status'] = 'paused';
    logAction($pdo, 'Workspace Pause', "Paused workspace #$workspace_id");
}

if (isset($_GET['action']) && $_GET['action'] == 'resume') {
    $pdo->prepare("UPDATE workspaces SET status = 'active' WHERE id = ?")
        ->execute([$workspace_id]);
    $message = '<div class="alert alert-success">▶️ Workspace resumed.</div>';
    $workspace['status'] = 'active';
    logAction($pdo, 'Workspace Resume', "Resumed workspace #$workspace_id");
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-industry me-2 text-primary"></i><?php echo htmlspecialchars($workspace['name']); ?></h2>
            <p class="text-muted">
                <?php echo htmlspecialchars($workspace['description'] ?: 'No description'); ?>
                <span class="mx-2">|</span>
                Status: <span class="badge bg-<?php echo ['active'=>'success','paused'=>'warning','completed'=>'info'][$workspace['status']] ?? 'secondary'; ?>">
                    <?php echo strtoupper($workspace['status']); ?>
                </span>
            </p>
        </div>
        <div>
            <a href="workspaces.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <?php if($workspace['status'] == 'active'): ?>
            <a href="?id=<?php echo $workspace_id; ?>&action=pause" class="btn btn-warning" onclick="return confirm('Pause this workspace?')">
                <i class="fas fa-pause me-1"></i> Pause
            </a>
            <a href="?id=<?php echo $workspace_id; ?>&action=complete" class="btn btn-success" onclick="return confirm('Mark this workspace as completed?')">
                <i class="fas fa-check me-1"></i> Complete
            </a>
            <?php elseif($workspace['status'] == 'paused'): ?>
            <a href="?id=<?php echo $workspace_id; ?>&action=resume" class="btn btn-primary">
                <i class="fas fa-play me-1"></i> Resume
            </a>
            <?php endif; ?>
            <a href="?id=<?php echo $workspace_id; ?>&action=delete" class="btn btn-danger" 
               onclick="return confirm('⚠️ Delete this workspace? All inputs and outputs will be lost.')">
                <i class="fas fa-trash me-1"></i> Delete
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Financial Summary - 3 BAR CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-4 col-6">
            <div class="stat-card text-center">
                <p class="stat-label">Total Inputs</p>
                <h4 class="text-danger"><?php echo number_format($total_input_cost, 0); ?> RWF</h4>
                <small><?php echo count($inputs); ?> items</small>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="stat-card text-center">
                <p class="stat-label">Output/Yield Value</p>
                <h4 class="text-success"><?php echo number_format($total_output_value, 0); ?> RWF</h4>
                <small><?php echo count($outputs); ?> outputs</small>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="stat-card text-center <?php echo $profit_loss >= 0 ? 'border-success' : 'border-danger'; ?>">
                <p class="stat-label"><?php echo $profit_loss >= 0 ? '✅ Profit' : '❌ Loss'; ?></p>
                <h4 class="<?php echo $profit_loss >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($profit_loss, 0); ?> RWF
                </h4>
                <small><?php echo $profit_loss >= 0 ? 'Gain' : 'Loss'; ?></small>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'overview' ? 'active' : ''; ?>" href="?id=<?php echo $workspace_id; ?>&tab=overview">
                <i class="fas fa-chart-pie me-1"></i> Overview
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'inputs' ? 'active' : ''; ?>" href="?id=<?php echo $workspace_id; ?>&tab=inputs">
                <i class="fas fa-arrow-down me-1"></i> Inputs
                <span class="badge bg-secondary ms-1"><?php echo count($inputs); ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'outputs' ? 'active' : ''; ?>" href="?id=<?php echo $workspace_id; ?>&tab=outputs">
                <i class="fas fa-arrow-up me-1"></i> Output/Yield
                <span class="badge bg-secondary ms-1"><?php echo count($outputs); ?></span>
            </a>
        </li>
    </ul>

    <!-- ============================================
    OVERVIEW TAB
    ============================================ -->
    <?php if($active_tab == 'overview'): ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Profit/Loss Summary</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr>
                            <td><strong>Total Inputs Cost</strong></td>
                            <td class="text-end text-danger"><?php echo number_format($total_input_cost, 0); ?> RWF</td>
                        </tr>
                        <tr>
                            <td><strong>Total Output/Yield Value</strong></td>
                            <td class="text-end text-success"><?php echo number_format($total_output_value, 0); ?> RWF</td>
                        </tr>
                        <tr class="table-<?php echo $profit_loss >= 0 ? 'success' : 'danger'; ?>">
                            <td><strong><?php echo $profit_loss >= 0 ? '✅ Profit' : '❌ Loss'; ?></strong></td>
                            <td class="text-end"><strong><?php echo number_format($profit_loss, 0); ?> RWF</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Workspace Details</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><td><strong>Created By</strong></td><td><?php echo htmlspecialchars($workspace['created_by_name'] ?? 'System'); ?></td></tr>
                        <tr><td><strong>Start Date</strong></td><td><?php echo $workspace['start_date'] ?? 'N/A'; ?></td></tr>
                        <tr><td><strong>Expected End Date</strong></td><td><?php echo $workspace['expected_end_date'] ?? 'Not set'; ?></td></tr>
                        <tr><td><strong>Actual End Date</strong></td><td><?php echo $workspace['actual_end_date'] ?? 'Not completed'; ?></td></tr>
                        <tr><td><strong>Input Items</strong></td><td><?php echo count($inputs); ?></td></tr>
                        <tr><td><strong>Output Items</strong></td><td><?php echo count($outputs); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    INPUTS TAB
    ============================================ -->
    <?php if($active_tab == 'inputs'): ?>
    <!-- Add Input Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Input</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Type *</label>
                        <select name="input_type" id="input_type" class="form-select" required onchange="toggleInputFields()">
                            <option value="raw_material">Raw Material</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g., Maize, Electricity, Labor...">
                    </div>
                    
                    <!-- Raw Material Fields -->
                    <div id="raw_material_fields">
                        <div class="col-md-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" placeholder="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit</label>
                            <input type="text" name="unit" class="form-control" placeholder="kg, pcs, liters...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Cost (RWF)</label>
                            <input type="number" name="unit_cost" class="form-control" placeholder="8500">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total Cost</label>
                            <input type="text" class="form-control" readonly id="raw_total_preview" placeholder="Auto-calculated">
                        </div>
                    </div>
                    
                    <!-- Expense Fields -->
                    <div id="expense_fields" style="display:none;">
                        <div class="col-md-6">
                            <label class="form-label">Amount (RWF) *</label>
                            <input type="number" name="expense_amount" class="form-control" placeholder="50000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                        </div>
                    </div>
                    
                    <div class="col-12" id="notes_field">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_input" class="btn btn-success">
                            <i class="fas fa-save me-1"></i> Add Input
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleInputFields() {
        const type = document.getElementById('input_type').value;
        const rawFields = document.getElementById('raw_material_fields');
        const expenseFields = document.getElementById('expense_fields');
        const notesField = document.getElementById('notes_field');
        
        if (type === 'raw_material') {
            rawFields.style.display = 'flex';
            expenseFields.style.display = 'none';
            notesField.style.display = 'block';
            document.querySelectorAll('#raw_material_fields input').forEach(el => el.setAttribute('required', 'required'));
            document.querySelectorAll('#expense_fields input').forEach(el => el.removeAttribute('required'));
        } else {
            rawFields.style.display = 'none';
            expenseFields.style.display = 'flex';
            notesField.style.display = 'none';
            document.querySelectorAll('#raw_material_fields input').forEach(el => el.removeAttribute('required'));
            document.querySelectorAll('#expense_fields input').forEach(el => el.setAttribute('required', 'required'));
        }
    }
    
    document.querySelector('input[name="quantity"]').addEventListener('input', calcRawTotal);
    document.querySelector('input[name="unit_cost"]').addEventListener('input', calcRawTotal);
    
    function calcRawTotal() {
        const qty = parseFloat(document.querySelector('input[name="quantity"]').value) || 0;
        const cost = parseFloat(document.querySelector('input[name="unit_cost"]').value) || 0;
        const total = qty * cost;
        document.getElementById('raw_total_preview').value = total > 0 ? total.toFixed(0) + ' RWF' : '—';
    }
    </script>

    <!-- Inputs List -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Inputs</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($inputs)): ?>
            <div class="text-center py-4 text-muted">No inputs added yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Qty</th>
                        <th>Unit</th>
                        <th>Unit Cost</th>
                        <th style="text-align:right;">Total</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($inputs as $in): 
                            $type_badge = $in['input_type'] == 'expense' ? 'warning' : 'info';
                            $type_label = $in['input_type'] == 'expense' ? '💰 Expense' : '📦 Raw';
                        ?>
                        <tr>
                            <td><span class="badge bg-<?php echo $type_badge; ?>"><?php echo $type_label; ?></span></td>
                            <td><strong><?php echo htmlspecialchars($in['name']); ?></strong></td>
                            <td><?php echo $in['quantity'] ?? '—'; ?></td>
                            <td><?php echo htmlspecialchars($in['unit'] ?? '—'); ?></td>
                            <td><?php echo $in['unit_cost'] !== null ? number_format($in['unit_cost'], 0) : '—'; ?></td>
                            <td style="text-align:right;"><strong><?php echo number_format($in['total_cost'], 0); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-danger">
                            <th colspan="5" class="text-end">Total Input Cost:</th>
                            <th style="text-align:right;"><strong><?php echo number_format($total_input_cost, 0); ?> RWF</strong></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    OUTPUTS TAB
    ============================================ -->
    <?php if($active_tab == 'outputs'): ?>
    <!-- Add Output Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Output/Yield</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Output/Yield Name *</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g., Maize Flour, Eggs...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required placeholder="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" class="form-control" placeholder="kg, pcs, boxes...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unit Value (RWF) *</label>
                        <input type="number" name="unit_value" class="form-control" required placeholder="15000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Total Value</label>
                        <input type="text" class="form-control" readonly id="output_total_preview" placeholder="Auto-calculated">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_output" class="btn btn-info text-white">
                            <i class="fas fa-save me-1"></i> Add Output/Yield
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.querySelector('input[name="quantity"]').addEventListener('input', calcOutputTotal);
    document.querySelector('input[name="unit_value"]').addEventListener('input', calcOutputTotal);
    
    function calcOutputTotal() {
        const qty = parseFloat(document.querySelector('input[name="quantity"]').value) || 0;
        const val = parseFloat(document.querySelector('input[name="unit_value"]').value) || 0;
        const total = qty * val;
        document.getElementById('output_total_preview').value = total > 0 ? total.toFixed(0) + ' RWF' : '—';
    }
    </script>

    <!-- Outputs List -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Output/Yield</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($outputs)): ?>
            <div class="text-center py-4 text-muted">No outputs added yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th>Name</th>
                        <th>Qty</th>
                        <th>Unit</th>
                        <th>Unit Value</th>
                        <th style="text-align:right;">Total Value</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($outputs as $out): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($out['name']); ?></strong></td>
                            <td><?php echo $out['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($out['unit'] ?? '—'); ?></td>
                            <td><?php echo number_format($out['unit_value'], 0); ?></td>
                            <td style="text-align:right;"><strong><?php echo number_format($out['total_value'], 0); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <th colspan="4" class="text-end">Total Output Value:</th>
                            <th style="text-align:right;"><strong><?php echo number_format($total_output_value, 0); ?> RWF</strong></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
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
.border-success {
    border: 2px solid #198754 !important;
}
.border-danger {
    border: 2px solid #dc3545 !important;
}
</style>

<?php include '../includes/footer.php'; ?>