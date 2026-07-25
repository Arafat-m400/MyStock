<?php
require_once 'config/db.php';
requireLogin();

$branch_id = $_GET['id'] ?? 0;

// Admin can access any branch
if (isAdmin()) {
    // Verify branch exists
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    if (!$stmt->fetch()) {
        redirect('index.php');
    }
} else {
    // Non-admin must have access
    if (!hasBranchAccess($branch_id)) {
        redirect('index.php');
    }
}

// Set branch in session
switchBranch($branch_id);

// Log branch selection
logAction($pdo, 'Branch Selected', "Selected branch: " . getBranchName($pdo, $branch_id));

// Redirect to dashboard
redirect('branch/dashboard.php');
?>