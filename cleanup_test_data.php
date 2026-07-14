<?php
require_once 'config/db.php';

// Only admin can run this
if (!isLoggedIn() || !isAdmin()) {
    die("Access denied. Admin only.");
}

echo "<h2>🧹 MyStock - Cleanup Test Data</h2>";
echo "<div class='container'>";

// Get branch ID for Kigali
$stmt = $pdo->prepare("SELECT id, name FROM branches WHERE name LIKE '%Kigali%'");
$stmt->execute();
$branch = $stmt->fetch();

if (!$branch) {
    echo "<p class='text-danger'>❌ Kigali branch not found!</p>";
    echo "<a href='index.php' class='btn btn-secondary'>Go Back</a>";
    exit;
}

$branch_id = $branch['id'];
$branch_name = $branch['name'];

echo "<p><strong>Branch:</strong> $branch_name (ID: $branch_id)</p>";
echo "<hr>";

// List all data to be deleted
$tables = [
    'products' => "SELECT COUNT(*) FROM products WHERE branch_id = $branch_id",
    'categories' => "SELECT COUNT(*) FROM categories WHERE branch_id = $branch_id",
    'customers' => "SELECT COUNT(*) FROM customers WHERE branch_id = $branch_id",
    'suppliers' => "SELECT COUNT(*) FROM suppliers WHERE branch_id = $branch_id",
    'sales' => "SELECT COUNT(*) FROM sales WHERE branch_id = $branch_id",
    'purchases' => "SELECT COUNT(*) FROM purchases WHERE branch_id = $branch_id",
    'purchase_orders' => "SELECT COUNT(*) FROM purchase_orders WHERE branch_id = $branch_id",
    'expenses' => "SELECT COUNT(*) FROM expenses WHERE branch_id = $branch_id",
    'customer_debts' => "SELECT COUNT(*) FROM customer_debts WHERE branch_id = $branch_id",
    'supplier_debts' => "SELECT COUNT(*) FROM supplier_debts WHERE branch_id = $branch_id",
    'end_of_day' => "SELECT COUNT(*) FROM end_of_day WHERE branch_id = $branch_id"
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Table</th><th>Records to Delete</th></tr>";

$total_records = 0;
foreach ($tables as $table => $query) {
    $count = $pdo->query($query)->fetchColumn();
    $total_records += $count;
    echo "<tr><td>$table</td><td>" . number_format($count) . "</td></tr>";
}
echo "</table>";

echo "<p><strong>Total records to delete:</strong> " . number_format($total_records) . "</p>";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    try {
        $pdo->beginTransaction();
        
        // Delete in correct order (child tables first)
        $pdo->exec("DELETE FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE branch_id = $branch_id)");
        $pdo->exec("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM purchases WHERE branch_id = $branch_id)");
        $pdo->exec("DELETE FROM purchase_order_items WHERE po_id IN (SELECT id FROM purchase_orders WHERE branch_id = $branch_id)");
        
        $pdo->exec("DELETE FROM customer_debts WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM supplier_debts WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM end_of_day WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM sales WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM purchases WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM purchase_orders WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM expenses WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM products WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM categories WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM customers WHERE branch_id = $branch_id");
        $pdo->exec("DELETE FROM suppliers WHERE branch_id = $branch_id");
        
        $pdo->commit();
        
        echo "<div class='alert alert-success'>✅ All test data deleted successfully from <strong>$branch_name</strong>!</div>";
        echo "<a href='index.php' class='btn btn-primary'>Go to Dashboard</a>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>❌ Error: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<form method='POST'>";
    echo "<button type='submit' name='confirm' class='btn btn-danger' onclick=\"return confirm('⚠️ Are you sure you want to delete ALL test data from $branch_name? This cannot be undone!')\">";
    echo "<i class='fas fa-trash'></i> Delete All Test Data from $branch_name";
    echo "</button>";
    echo " <a href='index.php' class='btn btn-secondary'>Cancel</a>";
    echo "</form>";
}

echo "</div>";

?>