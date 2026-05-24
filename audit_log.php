<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

// Create audit log table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Fetch all logs
$logs = $pdo->query("
    SELECT a.*, u.full_name 
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC 
    LIMIT 200
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>System Audit Log</h2>
    <p class="text-muted">Every action logged by all users (admin can see everything)</p>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP Address</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td><?php echo $log['created_at']; ?></td>
                            <td><strong><?php echo htmlspecialchars($log['username']); ?></strong><br>
                                <small><?php echo htmlspecialchars($log['full_name']); ?></small>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($log['action']); ?></span></td>
                            <td><?php echo htmlspecialchars($log['details']); ?></td>
                            <td><?php echo $log['ip_address']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>