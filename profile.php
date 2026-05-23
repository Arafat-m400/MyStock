<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$msg = '';
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];
    $user = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $user->execute([$_SESSION['user_id']]);
    $hash = $user->fetchColumn();
    if(password_verify($old, $hash)) {
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, $_SESSION['user_id']]);
        $msg = "<div class='alert alert-success'>Password changed!</div>";
    } else {
        $msg = "<div class='alert alert-danger'>Incorrect current password.</div>";
    }
}
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>My Profile</h2>
    <?php echo $msg; ?>
    <div class="card"><div class="card-body">
        <form method="POST">
            <div class="mb-3"><label>Current Password</label><input type="password" name="old_password" class="form-control" required></div>
            <div class="mb-3"><label>New Password</label><input type="password" name="new_password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div></div>
</div>
<?php include 'includes/footer.php'; ?>