<?php
require_once 'config/db.php';

echo "<h2>🔍 Database Connection Test</h2>";

if ($pdo) {
    echo "<p style='color: green;'>✅ Connection successful!</p>";
    echo "<p><strong>Host:</strong> " . $host . "</p>";
    echo "<p><strong>Database:</strong> " . $dbname . "</p>";
    echo "<p><strong>Username:</strong> " . $username . "</p>";
    
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    echo "<p><strong>Users in database:</strong> " . $result['count'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Connection failed!</p>";
}
?>