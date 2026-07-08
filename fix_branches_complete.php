<?php
require_once 'config/db.php';

echo "<h2>🔧 MyStock - Complete Branch Fix</h2>";

try {
    // 1. Check/Create Branches
    echo "<h3>1. Checking Branches...</h3>";
    $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
    
    if (empty($branches)) {
        echo "⚠️ No branches found. Creating default branches...<br>";
        $pdo->exec("INSERT INTO branches (name, code, location, phone) VALUES 
            ('Kigali Central', 'KGL-001', 'Kigali, Rwanda', '+250788000001'),
            ('Bugarama', 'BGM-001', 'Bugarama, Rwanda', '+250788000002')");
        echo "✅ Default branches created.<br>";
        $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
    }
    
    foreach ($branches as $b) {
        echo "  - " . $b['name'] . " (ID: " . $b['id'] . ")<br>";
    }
    
    // 2. Check Admin User
    echo "<h3>2. Checking Admin User...</h3>";
    $admin = $pdo->query("SELECT id, username FROM users WHERE username = 'admin'")->fetch();
    
    if (!$admin) {
        echo "❌ Admin user not found. Creating...<br>";
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password_hash, full_name, role) VALUES 
            ('admin', '$hash', 'System Administrator', 'admin')");
        $admin = $pdo->query("SELECT id, username FROM users WHERE username = 'admin'")->fetch();
        echo "✅ Admin created.<br>";
    }
    
    echo "✅ Admin found: " . $admin['username'] . " (ID: " . $admin['id'] . ")<br>";
    
    // 3. Assign Admin to Branches
    echo "<h3>3. Assigning Admin to Branches...</h3>";
    $count = 0;
    
    foreach ($branches as $branch) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM user_branches WHERE user_id = ? AND branch_id = ?");
        $check->execute([$admin['id'], $branch['id']]);
        
        if ($check->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$admin['id'], $branch['id']]);
            $count++;
            echo "✅ Assigned to: " . $branch['name'] . "<br>";
        } else {
            echo "⏭️ Already assigned to: " . $branch['name'] . "<br>";
        }
    }
    
    // 4. Verify
    echo "<h3>4. Verification...</h3>";
    $verify = $pdo->query("
        SELECT u.username, b.name, ub.role 
        FROM user_branches ub
        JOIN users u ON ub.user_id = u.id
        JOIN branches b ON ub.branch_id = b.id
        WHERE u.username = 'admin'
    ")->fetchAll();
    
    if (!empty($verify)) {
        echo "✅ Admin has access to:<br>";
        foreach ($verify as $v) {
            echo "  - " . $v['name'] . " (as " . $v['role'] . ")<br>";
        }
    } else {
        echo "❌ Still no access. Please check manually.<br>";
    }
    
    echo "<br><strong>✅ Complete!</strong> $count new assignments made.<br>";
    echo "<a href='index.php' class='btn btn-primary'>Go to Branch Selection</a>";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>