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
// CREATE NEW WORKSPACE
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
                <form method="POST" action="workspaces.php">
                    <div class="mb-3">
                        <label class="form-label">Workspace Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
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
// UPDATE WORKSPACE DETAILS (EDIT)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_workspace'])) {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    $start_date = $_POST['start_date'];
    $expected_end_date = $_POST['expected_end_date'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE workspaces 
            SET name=?, description=?, start_date=?, expected_end_date=?
            WHERE id=? AND branch_id=?
        ");
        $stmt->execute([$name, $description, $start_date, $expected_end_date, $workspace_id, $branch_id]);
        
        $message = '<div class="alert alert-success">✅ Workspace updated successfully!</div>';
        logAction($pdo, 'Workspace Updated', "Updated workspace: $name");
        
        // Refresh workspace data
        $workspace['name'] = $name;
        $workspace['description'] = $description;
        $workspace['start_date'] = $start_date;
        $workspace['expected_end_date'] = $expected_end_date;
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
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

$profit_loss = $total_output_value - $total_input_cost;

// ============================================
// GET SINGLE INPUT FOR EDIT
// ============================================

$edit_input = null;
if (isset($_GET['edit_input']) && is_numeric($_GET['edit_input'])) {
    $stmt = $pdo->prepare("SELECT * FROM workspace_inputs WHERE id = ? AND workspace_id = ?");
    $stmt->execute([$_GET['edit_input'], $workspace_id]);
    $edit_input = $stmt->fetch();
}

// ============================================
// GET SINGLE OUTPUT FOR EDIT
// ============================================

$edit_output = null;
if (isset($_GET['edit_output']) && is_numeric($_GET['edit_output'])) {
    $stmt = $pdo->prepare("SELECT * FROM workspace_outputs WHERE id = ? AND workspace_id = ?");
    $stmt->execute([$_GET['edit_output'], $workspace_id]);
    $edit_output = $stmt->fetch();
}

// ============================================
// ADD INPUT
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
        
        // Refresh
        $inputs = $pdo->prepare("SELECT * FROM workspace_inputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $inputs->execute([$workspace_id]);
        $inputs = $inputs->fetchAll();
        $total_input_cost = array_sum(array_column($inputs, 'total_cost'));
        $profit_loss = $total_output_value - $total_input_cost;
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// EDIT INPUT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_input'])) {
    $input_id = $_POST['input_id'];
    $name = sanitize($_POST['name']);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit = sanitize($_POST['unit'] ?? '');
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $total_cost = floatval($_POST['total_cost'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    $input_type = $_POST['input_type'];
    
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
            UPDATE workspace_inputs 
            SET name=?, quantity=?, unit=?, unit_cost=?, total_cost=?, notes=?
            WHERE id=? AND workspace_id=?
        ");
        $stmt->execute([$name, $quantity, $unit, $unit_cost, $total_cost, $notes, $input_id, $workspace_id]);
        
        $message = '<div class="alert alert-success">✅ Input updated successfully!</div>';
        logAction($pdo, 'Workspace Update Input', "Updated input #$input_id");
        
        // Refresh
        $inputs = $pdo->prepare("SELECT * FROM workspace_inputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $inputs->execute([$workspace_id]);
        $inputs = $inputs->fetchAll();
        $total_input_cost = array_sum(array_column($inputs, 'total_cost'));
        $profit_loss = $total_output_value - $total_input_cost;
        $edit_input = null;
        
        // Redirect to remove GET parameters
        header("Location: workspace_details.php?id=$workspace_id&tab=inputs");
        exit();
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// DELETE INPUT
// ============================================

if (isset($_GET['delete_input']) && is_numeric($_GET['delete_input'])) {
    $input_id = $_GET['delete_input'];
    try {
        $stmt = $pdo->prepare("DELETE FROM workspace_inputs WHERE id = ? AND workspace_id = ?");
        $stmt->execute([$input_id, $workspace_id]);
        
        $message = '<div class="alert alert-success">✅ Input deleted!</div>';
        logAction($pdo, 'Workspace Delete Input', "Deleted input #$input_id");
        
        // Refresh
        $inputs = $pdo->prepare("SELECT * FROM workspace_inputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $inputs->execute([$workspace_id]);
        $inputs = $inputs->fetchAll();
        $total_input_cost = array_sum(array_column($inputs, 'total_cost'));
        $profit_loss = $total_output_value - $total_input_cost;
        
        header("Location: workspace_details.php?id=$workspace_id&tab=inputs");
        exit();
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
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
        
        // Refresh
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
// EDIT OUTPUT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_output'])) {
    $output_id = $_POST['output_id'];
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
            UPDATE workspace_outputs 
            SET name=?, quantity=?, unit=?, unit_value=?, total_value=?, notes=?
            WHERE id=? AND workspace_id=?
        ");
        $stmt->execute([$name, $quantity, $unit, $unit_value, $total_value, $notes, $output_id, $workspace_id]);
        
        $message = '<div class="alert alert-success">✅ Output updated successfully!</div>';
        logAction($pdo, 'Workspace Update Output', "Updated output #$output_id");
        
        // Refresh
        $outputs = $pdo->prepare("SELECT * FROM workspace_outputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $outputs->execute([$workspace_id]);
        $outputs = $outputs->fetchAll();
        $total_output_value = array_sum(array_column($outputs, 'total_value'));
        $profit_loss = $total_output_value - $total_input_cost;
        $edit_output = null;
        
        header("Location: workspace_details.php?id=$workspace_id&tab=outputs");
        exit();
        
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// DELETE OUTPUT
// ============================================

if (isset($_GET['delete_output']) && is_numeric($_GET['delete_output'])) {
    $output_id = $_GET['delete_output'];
    try {
        $stmt = $pdo->prepare("DELETE FROM workspace_outputs WHERE id = ? AND workspace_id = ?");
        $stmt->execute([$output_id, $workspace_id]);
        
        $message = '<div class="alert alert-success">✅ Output deleted!</div>';
        logAction($pdo, 'Workspace Delete Output', "Deleted output #$output_id");
        
        // Refresh
        $outputs = $pdo->prepare("SELECT * FROM workspace_outputs WHERE workspace_id = ? ORDER BY created_at DESC");
        $outputs->execute([$workspace_id]);
        $outputs = $outputs->fetchAll();
        $total_output_value = array_sum(array_column($outputs, 'total_value'));
        $profit_loss = $total_output_value - $total_input_cost;
        
        header("Location: workspace_details.php?id=$workspace_id&tab=outputs");
        exit();
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
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
            
            <!-- Edit Workspace Button -->
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editWorkspaceModal">
                <i class="fas fa-edit me-1"></i> Edit
            </button>
            
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

    <!-- Edit Workspace Modal -->
    <div class="modal fade" id="editWorkspaceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Workspace</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_workspace" value="1">
                        <div class="mb-3">
                            <label class="form-label">Workspace Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($workspace['name']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($workspace['description']); ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo $workspace['start_date']; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expected End Date</label>
                                <input type="date" name="expected_end_date" class="form-control" value="<?php echo $workspace['expected_end_date']; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save me-1"></i> Update Workspace
                        </button>
                    </div>
                </form>
            </div>
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
    
    <!-- Edit Input Form -->
    <?php if($edit_input): ?>
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Input</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="update_input" value="1">
                <input type="hidden" name="input_id" value="<?php echo $edit_input['id']; ?>">
                <input type="hidden" name="input_type" value="<?php echo $edit_input['input_type']; ?>">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_input['name']); ?>">
                    </div>
                    
                    <?php if($edit_input['input_type'] == 'raw_material'): ?>
                    <div class="col-md-4">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required value="<?php echo $edit_input['quantity']; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" class="form-control" value="<?php echo htmlspecialchars($edit_input['unit'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit Cost</label>
                        <input type="number" name="unit_cost" class="form-control" value="<?php echo $edit_input['unit_cost']; ?>">
                    </div>
                    <?php else: ?>
                    <div class="col-md-12">
                        <label class="form-label">Amount (RWF) *</label>
                        <input type="number" name="expense_amount" class="form-control" required value="<?php echo $edit_input['total_cost']; ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($edit_input['notes'] ?? ''); ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i> Update Input
                    </button>
                    <a href="workspace_details.php?id=<?php echo $workspace_id; ?>&tab=inputs" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    
                    <div id="raw_material_fields">
                        <div class="col-md-4">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <input type="text" name="unit" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" name="unit_cost" class="form-control">
                        </div>
                    </div>
                    
                    <div id="expense_fields" style="display:none;">
                        <div class="col-md-12">
                            <label class="form-label">Amount (RWF) *</label>
                            <input type="number" name="expense_amount" class="form-control">
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control">
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
        
        if (type === 'raw_material') {
            rawFields.style.display = 'flex';
            expenseFields.style.display = 'none';
            document.querySelectorAll('#raw_material_fields input').forEach(el => el.setAttribute('required', 'required'));
            document.querySelectorAll('#expense_fields input').forEach(el => el.removeAttribute('required'));
        } else {
            rawFields.style.display = 'none';
            expenseFields.style.display = 'flex';
            document.querySelectorAll('#raw_material_fields input').forEach(el => el.removeAttribute('required'));
            document.querySelectorAll('#expense_fields input').forEach(el => el.setAttribute('required', 'required'));
        }
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
                        <th style="width:120px;">Actions</th>
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
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?id=<?php echo $workspace_id; ?>&tab=inputs&edit_input=<?php echo $in['id']; ?>" class="btn btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?id=<?php echo $workspace_id; ?>&tab=inputs&delete_input=<?php echo $in['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this input?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-danger">
                            <th colspan="5" class="text-end">Total Input Cost:</th>
                            <th style="text-align:right;"><strong><?php echo number_format($total_input_cost, 0); ?> RWF</strong></th>
                            <th></th>
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
    
    <!-- Edit Output Form -->
    <?php if($edit_output): ?>
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Output/Yield</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="update_output" value="1">
                <input type="hidden" name="output_id" value="<?php echo $edit_output['id']; ?>">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Output/Yield Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_output['name']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required value="<?php echo $edit_output['quantity']; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" class="form-control" value="<?php echo htmlspecialchars($edit_output['unit'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit Value (RWF) *</label>
                        <input type="number" name="unit_value" class="form-control" required value="<?php echo $edit_output['unit_value']; ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($edit_output['notes'] ?? ''); ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i> Update Output
                    </button>
                    <a href="workspace_details.php?id=<?php echo $workspace_id; ?>&tab=outputs" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add Output Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Output/Yield</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Output/Yield Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit Value (RWF) *</label>
                        <input type="number" name="unit_value" class="form-control" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control">
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
                        <th style="width:120px;">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($outputs as $out): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($out['name']); ?></strong></td>
                            <td><?php echo $out['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($out['unit'] ?? '—'); ?></td>
                            <td><?php echo number_format($out['unit_value'], 0); ?></td>
                            <td style="text-align:right;"><strong><?php echo number_format($out['total_value'], 0); ?></strong></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?id=<?php echo $workspace_id; ?>&tab=outputs&edit_output=<?php echo $out['id']; ?>" class="btn btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?id=<?php echo $workspace_id; ?>&tab=outputs&delete_output=<?php echo $out['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this output?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <th colspan="4" class="text-end">Total Output Value:</th>
                            <th style="text-align:right;"><strong><?php echo number_format($total_output_value, 0); ?> RWF</strong></th>
                            <th></th>
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