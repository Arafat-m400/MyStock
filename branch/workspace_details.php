<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$workspace_id = $_GET['id'] ?? 0;
$message = '';
$active_tab = $_GET['tab'] ?? 'overview';

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
    SELECT wi.*, p.name as product_name, p.unit as product_unit
    FROM workspace_inputs wi
    JOIN products p ON wi.product_id = p.id
    WHERE wi.workspace_id = ?
    ORDER BY wi.created_at DESC
");
$inputs->execute([$workspace_id]);
$inputs = $inputs->fetchAll();
$total_input_cost = array_sum(array_column($inputs, 'total_cost'));

// ============================================
// GET COSTS
// ============================================

$costs = $pdo->prepare("
    SELECT * FROM workspace_costs
    WHERE workspace_id = ?
    ORDER BY cost_date DESC
");
$costs->execute([$workspace_id]);
$costs = $costs->fetchAll();
$total_production_cost = array_sum(array_column($costs, 'amount'));

// ============================================
// GET OUTPUTS
// ============================================

$outputs = $pdo->prepare("
    SELECT wo.*, p.name as product_name, p.unit as product_unit
    FROM workspace_outputs wo
    JOIN products p ON wo.product_id = p.id
    WHERE wo.workspace_id = ?
    ORDER BY wo.created_at DESC
");
$outputs->execute([$workspace_id]);
$outputs = $outputs->fetchAll();
$total_output_value = array_sum(array_column($outputs, 'total_value'));

// Calculate totals for profit/loss
$profit_loss = $total_output_value - $total_input_cost - $total_production_cost;

// ============================================
// GET PRODUCTS FOR DROPDOWNS
// ============================================

$products = $pdo->prepare("SELECT id, name, unit, quantity, cost_price, selling_price FROM products WHERE branch_id = ? ORDER BY name");
$products->execute([$branch_id]);
$products = $products->fetchAll();

// ============================================
// ADD INPUT - UPDATED WITH STOCK REDUCTION
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_input'])) {
    $product_id = $_POST['product_id'];
    $quantity = floatval($_POST['quantity']);
    $unit_cost = floatval($_POST['unit_cost']);
    $source = $_POST['source'] ?? 'existing_stock';
    $source_reference = sanitize($_POST['source_reference'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Get product details
        $prod = $pdo->prepare("SELECT name, unit, quantity FROM products WHERE id = ? AND branch_id = ?");
        $prod->execute([$product_id, $branch_id]);
        $product = $prod->fetch();
        
        if (!$product) throw new Exception("Product not found.");
        
        // Reduce stock if source is 'existing_stock'
        if ($source == 'existing_stock') {
            if ($product['quantity'] < $quantity) {
                throw new Exception("Insufficient stock! Available: " . $product['quantity'] . " " . $product['unit']);
            }
            
            $new_quantity = $product['quantity'] - $quantity;
            $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ? AND branch_id = ?")
                ->execute([$new_quantity, $product_id, $branch_id]);
        }
        
        $total_cost = $quantity * $unit_cost;
        
        $stmt = $pdo->prepare("
            INSERT INTO workspace_inputs (workspace_id, product_id, quantity, unit, unit_cost, total_cost, source, source_reference, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workspace_id, $product_id, $quantity, $product['unit'], $unit_cost, $total_cost, $source, $source_reference, $notes]);
        
        $pdo->commit();
        
        $source_text = $source == 'existing_stock' ? ' (stock reduced)' : '';
        $message = '<div class="alert alert-success">✅ Input added: ' . $quantity . ' ' . $product['unit'] . ' of ' . $product['name'] . $source_text . '</div>';
        logAction($pdo, 'Workspace Add Input', "Added input to workspace #$workspace_id");
        
        // Refresh data
        $inputs = $pdo->prepare("SELECT wi.*, p.name as product_name, p.unit as product_unit FROM workspace_inputs wi JOIN products p ON wi.product_id = p.id WHERE wi.workspace_id = ? ORDER BY wi.created_at DESC");
        $inputs->execute([$workspace_id]);
        $inputs = $inputs->fetchAll();
        $total_input_cost = array_sum(array_column($inputs, 'total_cost'));
        
        // Refresh products list
        $products = $pdo->prepare("SELECT id, name, unit, quantity, cost_price, selling_price FROM products WHERE branch_id = ? ORDER BY name");
        $products->execute([$branch_id]);
        $products = $products->fetchAll();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ============================================
// ADD COST - UPDATED WITH COST SOURCE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_cost'])) {
    $category = $_POST['category'];
    $description = sanitize($_POST['description']);
    $amount = floatval($_POST['amount']);
    $cost_date = $_POST['cost_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $cost_source = $_POST['cost_source'] ?? 'branch';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO workspace_costs (workspace_id, category, description, amount, cost_date, payment_method, cost_source, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workspace_id, $category, $description, $amount, $cost_date, $payment_method, $cost_source, $_SESSION['user_id']]);
        
        $source_label = $cost_source == 'branch' ? '🏦 Branch Money' : '💼 External/Boss Money';
        $message = '<div class="alert alert-success">✅ Production cost added: ' . number_format($amount, 0) . ' RWF for ' . ucfirst($category) . ' (' . $source_label . ')</div>';
        logAction($pdo, 'Workspace Add Cost', "Added cost to workspace #$workspace_id");
        
        // Refresh data
        $costs = $pdo->prepare("SELECT * FROM workspace_costs WHERE workspace_id = ? ORDER BY cost_date DESC");
        $costs->execute([$workspace_id]);
        $costs = $costs->fetchAll();
        $total_production_cost = array_sum(array_column($costs, 'amount'));
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// ADD OUTPUT
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_output'])) {
    $product_id = $_POST['product_id'];
    $quantity_produced = floatval($_POST['quantity_produced']);
    $selling_price_per_unit = floatval($_POST['selling_price_per_unit']);
    $notes = sanitize($_POST['notes'] ?? '');
    
    try {
        // Get product unit
        $prod = $pdo->prepare("SELECT name, unit FROM products WHERE id = ?");
        $prod->execute([$product_id]);
        $product = $prod->fetch();
        
        // Calculate production cost per unit (total costs / total quantity)
        $total_output_qty = array_sum(array_column($outputs, 'quantity_produced')) + $quantity_produced;
        $production_cost_per_unit = $total_output_qty > 0 ? $total_production_cost / $total_output_qty : 0;
        $total_production_cost_for_output = $quantity_produced * $production_cost_per_unit;
        $total_value = $quantity_produced * $selling_price_per_unit;
        
        $stmt = $pdo->prepare("
            INSERT INTO workspace_outputs (workspace_id, product_id, quantity_produced, unit, production_cost_per_unit, total_production_cost, selling_price_per_unit, total_value, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$workspace_id, $product_id, $quantity_produced, $product['unit'], $production_cost_per_unit, $total_production_cost_for_output, $selling_price_per_unit, $total_value, $notes]);
        
        $message = '<div class="alert alert-success">✅ Output added: ' . $quantity_produced . ' ' . $product['unit'] . ' of ' . $product['name'] . ' (Value: ' . number_format($total_value, 0) . ' RWF)</div>';
        logAction($pdo, 'Workspace Add Output', "Added output to workspace #$workspace_id");
        
        // Refresh data
        $outputs = $pdo->prepare("SELECT wo.*, p.name as product_name, p.unit as product_unit FROM workspace_outputs wo JOIN products p ON wo.product_id = p.id WHERE wo.workspace_id = ? ORDER BY wo.created_at DESC");
        $outputs->execute([$workspace_id]);
        $outputs = $outputs->fetchAll();
        $total_output_value = array_sum(array_column($outputs, 'total_value'));
        $profit_loss = $total_output_value - $total_input_cost - $total_production_cost;
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// TRANSFER OUTPUT TO BRANCH STOCK
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['transfer_output'])) {
    $output_id = $_POST['output_id'];
    $quantity = floatval($_POST['transfer_quantity']);
    
    try {
        $pdo->beginTransaction();
        
        // Get output details
        $out = $pdo->prepare("SELECT * FROM workspace_outputs WHERE id = ? AND workspace_id = ?");
        $out->execute([$output_id, $workspace_id]);
        $output = $out->fetch();
        
        if (!$output) throw new Exception("Output not found.");
        if ($quantity > $output['quantity_produced'] - $output['transferred_to_branch']) {
            throw new Exception("Not enough quantity available to transfer.");
        }
        
        // Update output transferred quantity
        $new_transferred = $output['transferred_to_branch'] + $quantity;
        $pdo->prepare("UPDATE workspace_outputs SET transferred_to_branch = ? WHERE id = ?")
            ->execute([$new_transferred, $output_id]);
        
        // Update product stock in branch
        $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ? AND branch_id = ?")
            ->execute([$quantity, $output['product_id'], $branch_id]);
        
        // Record transfer
        $pdo->prepare("
            INSERT INTO workspace_batch_transfers (workspace_output_id, branch_id, quantity, transfer_date, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$output_id, $branch_id, $quantity, date('Y-m-d'), $_SESSION['user_id']]);
        
        $pdo->commit();
        
        $message = '<div class="alert alert-success">✅ ' . $quantity . ' units transferred to branch stock!</div>';
        logAction($pdo, 'Workspace Transfer', "Transferred $quantity from output #$output_id");
        
        // Refresh outputs
        $outputs = $pdo->prepare("SELECT wo.*, p.name as product_name, p.unit as product_unit FROM workspace_outputs wo JOIN products p ON wo.product_id = p.id WHERE wo.workspace_id = ? ORDER BY wo.created_at DESC");
        $outputs->execute([$workspace_id]);
        $outputs = $outputs->fetchAll();
        $total_output_value = array_sum(array_column($outputs, 'total_value'));
        $profit_loss = $total_output_value - $total_input_cost - $total_production_cost;
        
    } catch (Exception $e) {
        $pdo->rollBack();
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
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Financial Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <p class="stat-label">Total Inputs Cost</p>
                <h4 class="text-danger"><?php echo number_format($total_input_cost, 0); ?></h4>
                <small><?php echo count($inputs); ?> items</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <p class="stat-label">Production Costs</p>
                <h4 class="text-warning"><?php echo number_format($total_production_cost, 0); ?></h4>
                <small><?php echo count($costs); ?> expenses</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <p class="stat-label">Outputs Value</p>
                <h4 class="text-success"><?php echo number_format($total_output_value, 0); ?></h4>
                <small><?php echo count($outputs); ?> products</small>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center <?php echo $profit_loss >= 0 ? 'border-success' : 'border-danger'; ?>">
                <p class="stat-label"><?php echo $profit_loss >= 0 ? '✅ Profit' : '❌ Loss'; ?></p>
                <h4 class="<?php echo $profit_loss >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($profit_loss, 0); ?>
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
            <a class="nav-link <?php echo $active_tab == 'costs' ? 'active' : ''; ?>" href="?id=<?php echo $workspace_id; ?>&tab=costs">
                <i class="fas fa-coins me-1"></i> Production Costs
                <span class="badge bg-secondary ms-1"><?php echo count($costs); ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'outputs' ? 'active' : ''; ?>" href="?id=<?php echo $workspace_id; ?>&tab=outputs">
                <i class="fas fa-arrow-up me-1"></i> Outputs
                <span class="badge bg-secondary ms-1"><?php echo count($outputs); ?></span>
            </a>
        </li>
    </ul>

    <!-- ============================================
    OVERVIEW TAB
    ============================================ -->
    <?php if($active_tab == 'overview'): ?>
    <div class="row">
        <!-- Summary Table -->
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
                            <td><strong>Total Production Costs</strong></td>
                            <td class="text-end text-warning"><?php echo number_format($total_production_cost, 0); ?> RWF</td>
                        </tr>
                        <tr>
                            <td><strong>Total Outputs Value</strong></td>
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
                        <tr><td><strong>Cost Items</strong></td><td><?php echo count($costs); ?></td></tr>
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
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Raw Material Input</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Product *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Select Product --</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> 
                                (Stock: <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" required min="0.01" placeholder="10">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit Cost (RWF) *</label>
                        <input type="number" name="unit_cost" class="form-control" required min="0" placeholder="8500">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Source</label>
                        <select name="source" class="form-select">
                            <option value="existing_stock">📦 Existing Stock</option>
                            <option value="purchase">🛒 New Purchase</option>
                            <option value="transfer">📥 Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Reference (PO #, etc.)</label>
                        <input type="text" name="source_reference" class="form-control" placeholder="PO-2026-001">
                    </div>
                    <div class="col-md-4">
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

    <!-- Inputs List -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Inputs Used</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($inputs)): ?>
            <div class="text-center py-4 text-muted">No inputs added yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Unit Cost</th>
                        <th>Total</th>
                        <th>Source</th>
                        <th>Reference</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($inputs as $in): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($in['product_name']); ?></td>
                            <td><?php echo $in['quantity']; ?> <?php echo $in['product_unit']; ?></td>
                            <td><?php echo number_format($in['unit_cost'], 0); ?></td>
                            <td><strong><?php echo number_format($in['total_cost'], 0); ?></strong></td>
                            <td>
                                <?php if($in['source'] == 'existing_stock'): ?>
                                <span class="badge bg-info">📦 Stock</span>
                                <?php elseif($in['source'] == 'purchase'): ?>
                                <span class="badge bg-success">🛒 Purchase</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">📥 Transfer</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($in['source_reference'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-danger">
                            <th colspan="3" class="text-end">Total Input Cost:</th>
                            <th><strong><?php echo number_format($total_input_cost, 0); ?> RWF</strong></th>
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
    COSTS TAB - UPDATED WITH COST SOURCE
    ============================================ -->
    <?php if($active_tab == 'costs'): ?>
    <!-- Add Cost Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Production Cost</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-select" required>
                            <option value="labor">👷 Labor</option>
                            <option value="electricity">⚡ Electricity</option>
                            <option value="water">💧 Water</option>
                            <option value="equipment">🔧 Equipment</option>
                            <option value="maintenance">🛠️ Maintenance</option>
                            <option value="transport">🚚 Transport</option>
                            <option value="packaging">📦 Packaging</option>
                            <option value="veterinary">🏥 Veterinary</option>
                            <option value="feed">🌾 Feed</option>
                            <option value="rent">🏠 Rent</option>
                            <option value="other">📌 Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Monthly electricity bill">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount (RWF) *</label>
                        <input type="number" name="amount" class="form-control" required min="0" placeholder="50000">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date</label>
                        <input type="date" name="cost_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <!-- ============================================
                    NEW: Cost Source Field
                    ============================================ -->
                    <div class="col-md-6">
                        <label class="form-label">Payment Source *</label>
                        <select name="cost_source" class="form-select" required>
                            <option value="branch">🏦 Branch Money (affects EOD)</option>
                            <option value="external">💼 External/Boss Money</option>
                        </select>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Branch money will affect End of Day reports. External money won't.
                        </small>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">💵 Cash</option>
                            <option value="mobile_money">📱 Mobile Money</option>
                            <option value="bank_transfer">🏦 Bank Transfer</option>
                        </select>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" name="add_cost" class="btn btn-warning text-white">
                            <i class="fas fa-save me-1"></i> Add Cost
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Costs List -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Production Costs</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($costs)): ?>
            <div class="text-center py-4 text-muted">No production costs added yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Method</th>
                        <th style="text-align:right;">Amount</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($costs as $c): 
                            $source_badge = $c['cost_source'] == 'branch' ? 'success' : 'secondary';
                            $source_label = $c['cost_source'] == 'branch' ? '🏦 Branch' : '💼 External';
                        ?>
                        <tr>
                            <td><?php echo $c['cost_date']; ?></td>
                            <td><span class="badge bg-<?php echo ['labor'=>'primary','electricity'=>'warning','water'=>'info','equipment'=>'secondary','maintenance'=>'dark','transport'=>'success','packaging'=>'info','veterinary'=>'danger','feed'=>'success','rent'=>'primary','other'=>'secondary'][$c['category']] ?? 'secondary'; ?>"><?php echo ucfirst($c['category']); ?></span></td>
                            <td><?php echo htmlspecialchars($c['description'] ?: '—'); ?></td>
                            <td><span class="badge bg-<?php echo $source_badge; ?>"><?php echo $source_label; ?></span></td>
                            <td><?php echo ucfirst($c['payment_method']); ?></td>
                            <td style="text-align:right;"><strong><?php echo number_format($c['amount'], 0); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning">
                            <th colspan="5" class="text-end">Total Production Cost:</th>
                            <th style="text-align:right;"><strong><?php echo number_format($total_production_cost, 0); ?> RWF</strong></th>
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
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Finished Product Output</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Product *</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Select Product --</option>
                            <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> 
                                (Selling: <?php echo number_format($p['selling_price'], 0); ?> RWF)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity Produced *</label>
                        <input type="number" name="quantity_produced" class="form-control" required min="0.01" placeholder="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Selling Price/Unit (RWF) *</label>
                        <input type="number" name="selling_price_per_unit" class="form-control" required min="0" placeholder="15000">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Total Value</label>
                        <input type="text" class="form-control" readonly id="output_value_preview" placeholder="Auto-calculated">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes about this batch">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_output" class="btn btn-info text-white">
                            <i class="fas fa-save me-1"></i> Add Output
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Auto-calculate total value
    document.querySelector('input[name="quantity_produced"]').addEventListener('input', calculateOutputValue);
    document.querySelector('input[name="selling_price_per_unit"]').addEventListener('input', calculateOutputValue);
    
    function calculateOutputValue() {
        const qty = parseFloat(document.querySelector('input[name="quantity_produced"]').value) || 0;
        const price = parseFloat(document.querySelector('input[name="selling_price_per_unit"]').value) || 0;
        const total = qty * price;
        document.getElementById('output_value_preview').value = total > 0 ? new Intl.NumberFormat('en-RW').format(total) + ' RWF' : '—';
    }
    </script>

    <!-- Outputs List -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Finished Products</h5>
        </div>
        <div class="card-body p-0">
            <?php if(empty($outputs)): ?>
            <div class="text-center py-4 text-muted">No outputs added yet.</div>
            <?php else: ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Unit Value</th>
                        <th>Total Value</th>
                        <th>Transferred</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($outputs as $out): 
                            $available = $out['quantity_produced'] - $out['transferred_to_branch'];
                            $status_class = $out['status'] == 'completed' ? 'success' : ($available > 0 ? 'warning' : 'info');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($out['product_name']); ?></td>
                            <td><?php echo $out['quantity_produced']; ?> <?php echo $out['product_unit']; ?></td>
                            <td><?php echo number_format($out['selling_price_per_unit'], 0); ?></td>
                            <td><strong><?php echo number_format($out['total_value'], 0); ?></strong></td>
                            <td><?php echo number_format($out['transferred_to_branch'], 0); ?> <?php echo $out['product_unit']; ?></td>
                            <td>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo strtoupper($out['status']); ?>
                                </span>
                                <?php if($available > 0): ?>
                                <br><small class="text-muted">Available: <?php echo number_format($available, 0); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($available > 0): ?>
                                <button class="btn btn-sm btn-success" onclick="showTransferModal(<?php echo $out['id']; ?>, '<?php echo htmlspecialchars($out['product_name']); ?>', <?php echo $available; ?>)">
                                    <i class="fas fa-exchange-alt"></i> Transfer
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <th colspan="3" class="text-end">Total Output Value:</th>
                            <th><strong><?php echo number_format($total_output_value, 0); ?> RWF</strong></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transfer Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Transfer to Branch Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="output_id" id="transfer_output_id">
                        <p><strong>Product:</strong> <span id="transfer_product_name"></span></p>
                        <p><strong>Available:</strong> <span id="transfer_available"></span></p>
                        <div class="mb-3">
                            <label class="form-label">Quantity to Transfer *</label>
                            <input type="number" name="transfer_quantity" class="form-control" required min="0.01" id="transfer_quantity">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="transfer_output" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function showTransferModal(id, name, available) {
        document.getElementById('transfer_output_id').value = id;
        document.getElementById('transfer_product_name').textContent = name;
        document.getElementById('transfer_available').textContent = available;
        document.getElementById('transfer_quantity').max = available;
        const modal = new bootstrap.Modal(document.getElementById('transferModal'));
        modal.show();
    }
    </script>
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
</style>

<?php include '../includes/footer.php'; ?>