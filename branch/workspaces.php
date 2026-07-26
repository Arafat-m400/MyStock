<?php
require_once '../config/db.php';
requireLogin();
requireBranchAccess();

$branch_id = getCurrentBranch();
$message = '';

// ============================================
// DISPLAY SESSION MESSAGE
// ============================================
if (isset($_SESSION['workspace_message'])) {
    $message = $_SESSION['workspace_message'];
    unset($_SESSION['workspace_message']);
}

// ============================================
// CREATE WORKSPACE - FIXED (NO SESSION CONFLICT)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_workspace'])) {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $expected_end_date = $_POST['expected_end_date'] ?: null;
    
    try {
        // ALWAYS insert a NEW workspace - never update existing
        $stmt = $pdo->prepare("
            INSERT INTO workspaces (branch_id, name, description, start_date, expected_end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branch_id, $name, $description, $start_date, $expected_end_date, $_SESSION['user_id']]);
        $workspace_id = $pdo->lastInsertId();
        
        // Set success message with the NEW ID
        $_SESSION['workspace_message'] = '<div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <strong>✅ Workspace created!</strong>
            <a href="workspace_details.php?id=' . $workspace_id . '" class="alert-link">View Details</a>
        </div>';
        
        // Redirect to clear POST data
        header("Location: workspaces.php");
        exit();
        
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
    }
}

// ============================================
// GET WORKSPACES
// ============================================

$workspaces = $pdo->prepare("
    SELECT w.*, 
           u.full_name as created_by_name,
           COALESCE(SUM(wi.total_cost), 0) as total_input_cost,
           COALESCE(SUM(wo.total_value), 0) as total_output_value,
           COUNT(DISTINCT wi.id) as input_count,
           COUNT(DISTINCT wo.id) as output_count
    FROM workspaces w
    LEFT JOIN workspace_inputs wi ON wi.workspace_id = w.id
    LEFT JOIN workspace_outputs wo ON wo.workspace_id = w.id
    LEFT JOIN users u ON w.created_by = u.id
    WHERE w.branch_id = ?
    GROUP BY w.id
    ORDER BY w.created_at DESC
");
$workspaces->execute([$branch_id]);
$workspaces = $workspaces->fetchAll();

// Calculate profit/loss
foreach ($workspaces as &$ws) {
    $ws['profit_loss'] = $ws['total_output_value'] - $ws['total_input_cost'];
}
unset($ws); // Break the reference

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="col-md-10 main-content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h2><i class="fas fa-industry me-2 text-primary"></i>Workspace</h2>
            <p class="text-muted">Manage production workflows, track raw materials, expenses, outputs, and profitability</p>
        </div>
        <div>
            <!-- ===== MODAL BUTTON ===== -->
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createWorkspaceModal">
                <i class="fas fa-plus me-1"></i> New Workspace
            </button>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- ============================================
    CREATE WORKSPACE MODAL
    ============================================ -->
    <div class="modal fade" id="createWorkspaceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create New Workspace</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="workspaces.php">
                    <div class="modal-body">
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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_workspace" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Create Workspace
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================
    WORKSPACES LIST - ROW STYLE
    ============================================ -->
    <?php if(empty($workspaces)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-industry fa-4x text-muted mb-3 d-block"></i>
            <h5>No Workspaces</h5>
            <p class="text-muted">Create your first production workspace to track raw materials, expenses, and outputs.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createWorkspaceModal">
                <i class="fas fa-plus me-1"></i> Create Workspace
            </button>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Workspace Rows -->
    <?php foreach($workspaces as $ws): 
        $status_class = [
            'active' => 'success',
            'paused' => 'warning',
            'completed' => 'info'
        ][$ws['status']] ?? 'secondary';
        
        $profit_class = $ws['profit_loss'] >= 0 ? 'success' : 'danger';
    ?>
    <div class="card shadow-sm mb-3" data-workspace-id="<?php echo $ws['id']; ?>">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <!-- Workspace Name & Status -->
                <div class="col-md-3">
                    <h5 class="mb-0">
                        <i class="fas fa-industry text-primary me-2"></i>
                        <?php echo htmlspecialchars($ws['name']); ?>
                    </h5>
                    <span class="badge bg-<?php echo $status_class; ?> mt-1">
                        <?php echo strtoupper($ws['status']); ?>
                    </span>
                    <?php if($ws['description']): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($ws['description']); ?></small>
                    <?php endif; ?>
                </div>
                
                <!-- Financial Summary - 3 Bar Cards -->
                <div class="col-md-6">
                    <div class="row g-1">
                        <div class="col-4">
                            <div class="p-2 bg-light rounded text-center">
                                <small class="text-muted d-block">Inputs</small>
                                <strong><?php echo number_format($ws['total_input_cost'], 0); ?></strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-light rounded text-center">
                                <small class="text-muted d-block">Outputs</small>
                                <strong class="text-success"><?php echo number_format($ws['total_output_value'], 0); ?></strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-<?php echo $profit_class; ?> bg-opacity-10 rounded text-center">
                                <small class="text-muted d-block">P/L</small>
                                <strong class="text-<?php echo $profit_class; ?>">
                                    <?php echo number_format($ws['profit_loss'], 0); ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-calendar me-1"></i>
                        <?php echo $ws['start_date'] ?? 'N/A'; ?>
                        <?php if($ws['expected_end_date']): ?>
                        → <?php echo $ws['expected_end_date']; ?>
                        <?php endif; ?>
                    </small>
                </div>
                
                <!-- Actions -->
                <div class="col-md-3 text-md-end mt-2 mt-md-0">
                    <a href="workspace_details.php?id=<?php echo $ws['id']; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-eye me-1"></i> Manage
                    </a>
                    <a href="workspace_details.php?id=<?php echo $ws['id']; ?>&action=delete" 
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('⚠️ Delete this workspace? All inputs and outputs will be lost.')">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>
</div>

<!-- ============================================
AUTO-CLOSE MODAL ON SUCCESS
============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // If there's a success message, close the modal
    const successAlert = document.querySelector('.alert-success');
    if (successAlert && successAlert.innerHTML.includes('Workspace created')) {
        const modalElement = document.getElementById('createWorkspaceModal');
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
            // Remove backdrop
            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>