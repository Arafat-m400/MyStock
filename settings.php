<?php
require_once 'config/db.php';
if(!isLoggedIn() || !isAdmin()) redirect('index.php');

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $stmt = $pdo->prepare("UPDATE settings SET company_name=?, company_address=?, company_phone=?, company_email=?, currency_symbol=? WHERE id=1");
    $stmt->execute([$_POST['company_name'], $_POST['company_address'], $_POST['company_phone'], $_POST['company_email'], $_POST['currency_symbol']]);
    $success = "Settings saved!";
}
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Shop Settings</h2>
    <?php if(isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>
    <div class="card"><div class="card-body">
        <form method="POST">
            <div class="mb-2"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name']); ?>" required></div>
            <div class="mb-2"><label>Address</label><textarea name="company_address" class="form-control"><?php echo htmlspecialchars($settings['company_address']); ?></textarea></div>
            <div class="mb-2"><label>Phone</label><input type="text" name="company_phone" class="form-control" value="<?php echo $settings['company_phone']; ?>"></div>
            <div class="mb-2"><label>Email</label><input type="email" name="company_email" class="form-control" value="<?php echo $settings['company_email']; ?>"></div>
            <div class="mb-2"><label>Currency Symbol</label><input type="text" name="currency_symbol" class="form-control" value="<?php echo $settings['currency_symbol']; ?>"></div>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div></div>
</div>
<?php include 'includes/footer.php'; ?>