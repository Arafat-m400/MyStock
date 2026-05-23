<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

$id = $_GET['id'] ?? 0;
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'worker'")->execute([$new_pass, $id]);
    $msg = "Password reset successfully!";
}
$user = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'worker'");
$user->execute([$id]);
$worker = $user->fetch();
if(!$worker) die("Worker not found.");
?>
<!DOCTYPE html><html><head><title>Reset Password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="container mt-5"><div class="card"><div class="card-header">Reset Password for <?php echo htmlspecialchars($worker['full_name']); ?></div><div class="card-body">
<?php if(isset($msg)) echo "<div class='alert alert-success'>$msg</div>"; ?>
<form method="POST"><label>New Password</label><input type="text" name="new_password" class="form-control" required><button type="submit" class="btn btn-primary mt-3">Reset</button> <a href="users.php" class="btn btn-secondary mt-3">Back</a></form>
</div></div></body></html>