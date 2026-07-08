<?php
require_once 'config/db.php';

// Password: admin123
$new_password = 'admin123';
$hash = password_hash($new_password, PASSWORD_DEFAULT);

// Update admin password
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
$result = $stmt->execute([$hash]);

if ($result) {
    echo "✅ Admin password reset successfully!\n";
    echo "Username: admin\n";
    echo "Password: admin123\n";
    echo "<br><br><a href='login.php'>Go to Login</a>";
} else {
    echo "❌ Failed to reset password. Please check database connection.";
}

// If no admin exists, create one
$check = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
if ($check == 0) {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->execute(['admin', $hash, 'System Administrator', 'admin']);
    echo "<br>✅ Admin user created!";
}
?>