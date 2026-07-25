<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';
$active_tab = $_GET['tab'] ?? 'list';

// ============================================
// GET DATA FOR FORMS
// ============================================

$products = $pdo->prepare("SELECT id, name, unit, quantity, cost_price, selling_price FROM products WHERE branch_id = ? ORDER BY name");
$products->execute([$branch_id]);
$products = $products->fetchAll();

// ============================================
// CREATE WORKSPACE
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_workspace'])) {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $expected_end_date = $_POST['expected_end_date'] ?: null;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO workspaces (branch_id, name, description, start_date, expected_end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branch_id, $name, $description, $start_date, $expected_end_date, $_SESSION['user_id']]);
        $workspace_id = $pdo->lastInsertId();
        
        $message = '<div class="alert alert-success">✅ Workspace created! <a href="workspace_details.php?id=' . $workspace_id . '">View Details</a></div>';
        logAction($pdo, 'Create Workspace', "Created workspace: $name");
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// GET WORKSPACES WITH FINANCIAL SUMMARY
// ============================================

$workspaces = $pdo->prepare("
    SELECT w.*, 
           u.full_name as created_by_name,
           COALESCE(SUM(wi.total_cost), 0) as total_input_cost,
           COALESCE(SUM(wc.amount), 0) as total_production_cost,
           COALESCE(SUM(wo.total_value), 0) as total_output_value,
           COUNT(DISTINCT wi.id) as input_count,
           COUNT(DISTINCT wo.id) as output_count,
           COUNT(DISTINCT wc.id) as cost_count
    FROM workspaces w
    LEFT JOIN workspace_inputs wi ON wi.workspace_id = w.id
    LEFT JOIN workspace_outputs wo ON wo.workspace_id = w.id
    LEFT JOIN workspace_costs wc ON wc.workspace_id = w.id
    LEFT JOIN users u ON w.created_by = u.id
    WHERE w.branch_id = ?
    GROUP BY w.id
    ORDER BY w.created_at DESC
");
$workspaces->execute([$branch_id]);
$workspaces = $workspaces->fetchAll();

// Calculate profit/loss for each workspace
foreach ($workspaces as &$ws) {
    $ws['profit_loss'] = $ws['total_output_value'] - $ws['total_input_cost'] - $ws['total_production_cost'];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-industry me-2 text-primary"></i>Workspace</h2>
            <p class="text-muted">Manage production workflows, track inputs, costs, outputs, and profitability</p>
        </div>
        <div>
            <a href="?tab=create" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> New Workspace
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- ============================================
    CREATE WORKSPACE FORM
    ============================================ -->
    <?php if($active_tab == 'create'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Create New Workspace</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Workspace Name *</label>
                        <input type="text" name="name" class="form-control" required 
                               placeholder="e.g., Maize Flour Production, Chicken Farm">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Describe the production process..."></textarea>
                    </div>
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
    <?php else: ?>
    
    <!-- ============================================
    WORKSPACES LIST
    ============================================ -->
    <div class="row g-3">
        <?php if(empty($workspaces)): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-industry fa-4x text-muted mb-3 d-block"></i>
                    <h5>No Workspaces</h5>
                    <p class="text-muted">Create your first production workspace to track raw materials, costs, and outputs.</p>
                    <a href="?tab=create" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Create Workspace
                    </a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <?php foreach($workspaces as $ws): 
            $status_class = [
                'active' => 'success',
                'paused' => 'warning',
                'completed' => 'info'
            ][$ws['status']] ?? 'secondary';
            
            $profit_class = $ws['profit_loss'] >= 0 ? 'success' : 'danger';
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h5 class="card-title">
                            <i class="fas fa-industry text-primary me-2"></i>
                            <?php echo htmlspecialchars($ws['name']); ?>
                        </h5>
                        <span class="badge bg-<?php echo $status_class; ?>">
                            <?php echo strtoupper($ws['status']); ?>
                        </span>
                    </div>
                    
                    <?php if($ws['description']): ?>
                    <p class="card-text small text-muted"><?php echo htmlspecialchars($ws['description']); ?></p>
                    <?php endif; ?>
                    
                    <!-- Financial Summary -->
                    <div class="row mt-3 g-1">
                        <div class="col-6">
                            <div class="p-2 bg-light rounded text-center">
                                <small class="text-muted d-block">Inputs</small>
                                <strong><?php echo number_format($ws['total_input_cost'], 0); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-light rounded text-center">
                                <small class="text-muted d-block">Prod. Costs</small>
                                <strong><?php echo number_format($ws['total_production_cost'], 0); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-light rounded text-center">
                                <small class="text-muted d-block">Output Value</small>
                                <strong class="text-success"><?php echo number_format($ws['total_output_value'], 0); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-<?php echo $profit_class; ?> bg-opacity-10 rounded text-center">
                                <small class="text-muted d-block">Profit/Loss</small>
                                <strong class="text-<?php echo $profit_class; ?>">
                                    <?php echo number_format($ws['profit_loss'], 0); ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <a href="workspace_details.php?id=<?php echo $ws['id']; ?>" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-eye me-1"></i> Manage Workspace
                        </a>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <small class="text-muted">
                        <i class="fas fa-calendar me-1"></i>
                        <?php echo $ws['start_date'] ?? 'N/A'; ?>
                        <?php if($ws['expected_end_date']): ?>
                        → <?php echo $ws['expected_end_date']; ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>