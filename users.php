<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$message = '';
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $full_name = $_POST['full_name'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = 'worker';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)");
        $stmt->execute([$username, $password, $full_name, $role]);
        logAction($pdo, 'Create Worker', "Created worker: $username ($full_name)");
        $message = '<div class="alert alert-success">Worker account created! Username: ' . htmlspecialchars($username) . '</div>';
    } catch(PDOException $e) {
        logAction($pdo, 'Create Worker', "Failed to create worker: $username ($full_name)");
        $message = '<div class="alert alert-danger">Username already exists!</div>';
    }
}

$workers = $pdo->query("SELECT id, username, full_name, created_at FROM users WHERE role = 'worker' ORDER BY created_at DESC")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>User Management (Workers)</h2>
    <?php echo $message; ?>
    
    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><h5>Create New Worker Account</h5></div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-4 mb-2"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                    <div class="col-md-4 mb-2"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div>
                    <div class="col-md-4 mb-2"><label>Temporary Password</label><input type="text" name="password" class="form-control" required></div>
                </div>
                <button type="submit" class="btn btn-success">Create Worker</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h5>Existing Workers</h5></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Username</th><th>Full Name</th><th>Created Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach($workers as $w): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($w['username']); ?></td>
                        <td><?php echo htmlspecialchars($w['full_name']); ?></td>
                        <td><?php echo $w['created_at']; ?></td>
                        <td><a href="reset_password.php?id=<?php echo $w['id']; ?>" class="btn btn-sm btn-warning">Reset Password</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>