<?php
require_once 'config/db.php';
requireLogin();

$branch_id = $_GET['id'] ?? 0;

// Verify user has access to this branch
if (!hasBranchAccess($branch_id)) {
    redirect('index.php');
}

// Set branch in session
switchBranch($branch_id);

// Log branch selection
logAction($pdo, 'Branch Selected', "Selected branch: " . getBranchName($pdo, $branch_id));

// Redirect to dashboard
redirect('branch/dashboard.php');
?>