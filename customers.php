<?php
require_once 'config/db.php';
if(!isLoggedIn()) redirect('login.php');

$message = '';
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    
    if($id) {
        $stmt = $pdo->prepare("UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=?");
        $stmt->execute([$name, $phone, $email, $address, $id]);
        $message = "Customer updated!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?,?,?,?)");
        $stmt->execute([$name, $phone, $email, $address]);
        $message = "Customer added!";
    }
}

if(isset($_GET['delete']) && isAdmin()) {
    $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$_GET['delete']]);
    $message = "Customer deleted!";
}

$customers = $pdo->query("SELECT * FROM customers ORDER BY total_spent DESC")->fetchAll();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="col-md-10 p-4">
    <h2>Customers</h2>
    <?php if($message) echo "<div class='alert alert-info'>$message</div>"; ?>
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="clearCustomerForm()">+ New Customer</button>
    <div class="card">
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Total Spent</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($customers as $c): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['name']); ?></td>
                        <td><?php echo $c['phone']; ?></td>
                        <td><?php echo $c['email']; ?></td>
                        <td><?php echo number_format($c['total_spent'],2); ?></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editCustomer(<?php echo htmlspecialchars(json_encode($c)); ?>)">Edit</button>
                            <?php if(isAdmin()): ?>
                            <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete customer?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="customerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5>Customer Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="id" id="cust_id">
            <div class="mb-2"><label>Name *</label><input type="text" name="name" id="cust_name" class="form-control" required></div>
            <div class="mb-2"><label>Phone</label><input type="text" name="phone" id="cust_phone" class="form-control"></div>
            <div class="mb-2"><label>Email</label><input type="email" name="email" id="cust_email" class="form-control"></div>
            <div class="mb-2"><label>Address</label><textarea name="address" id="cust_address" class="form-control"></textarea></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
</div></div></div>

<script>
function clearCustomerForm() { document.getElementById('cust_id').value = ''; document.getElementById('cust_name').value = ''; document.getElementById('cust_phone').value = ''; document.getElementById('cust_email').value = ''; document.getElementById('cust_address').value = ''; }
function editCustomer(c) { document.getElementById('cust_id').value = c.id; document.getElementById('cust_name').value = c.name; document.getElementById('cust_phone').value = c.phone; document.getElementById('cust_email').value = c.email; document.getElementById('cust_address').value = c.address; new bootstrap.Modal(document.getElementById('customerModal')).show(); }
</script>
<?php include 'includes/footer.php'; ?>