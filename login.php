<?php
// Prevent caching of login page
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'config/db.php';

// If already logged in, redirect to branch selection
if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
$success = '';

// Process login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
            // Get user's branches
            $branches = getUserBranches($pdo, $user['id']);
            $_SESSION['user_branches'] = array_column($branches, 'id');
            
            // Log login
            logAction($pdo, 'Login', "User logged in from " . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
            
            // Update last login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            // Redirect to branch selection
            redirect('index.php');
        } else {
            $error = 'Invalid username or password.';
            logAction($pdo, 'Login Failed', "Failed login attempt for: $username");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MyStock v2.0</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #0a58ca 0%, #0d6efd 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            max-width: 420px;
            margin: 0 auto;
            padding: 20px;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo i {
            font-size: 60px;
            color: #0d6efd;
            background: #e7f1ff;
            padding: 20px;
            border-radius: 50%;
        }
        .login-logo h2 {
            margin-top: 15px;
            font-weight: 700;
            color: #1a2a3a;
        }
        .login-logo p {
            color: #6c757d;
            font-size: 14px;
        }
        .form-floating {
            margin-bottom: 15px;
        }
        .btn-login {
            background: #0d6efd;
            color: white;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            border: none;
            transition: all 0.3s;
        }
        .btn-login:hover {
            background: #0a58ca;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
            font-size: 13px;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        .alert {
            border-radius: 10px;
        }
        @media (max-width: 576px) {
            .login-card {
                padding: 25px;
                margin: 10px;
            }
            .login-logo i {
                font-size: 40px;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <i class="fas fa-store-alt"></i>
                    <h2>MyStock</h2>
                    <p>Enterprise Stock Management</p>
                </div>
                
                <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                        <label for="username"><i class="fas fa-user me-2"></i>Username</label>
                    </div>
                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                        <label for="password"><i class="fas fa-lock me-2"></i>Password</label>
                    </div>
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                </form>
                
                <div class="login-footer">
                    <small>
                        <i class="fas fa-shield-alt me-1"></i>
                        Demo: admin / admin123
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>