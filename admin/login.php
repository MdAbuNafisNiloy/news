<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$username = '';
$password = '';
$error = '';
$success = '';
$debug = '';  // Added for debugging

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input
    $username = trim($_POST['username']);
    $password = $_POST['password']; // Don't sanitize password before verification
    
    // Validate input
    if (empty($username) || empty($password)) {
        $error = "Username and password are required.";
    } else {
        // Direct database query to check user (for debugging)
        global $db;
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        // Debug information (you can comment this out later)
        if ($user) {
            // For security, don't show the actual password hash in production
            $debug = "Verifying User Details:  ";
            
            // Try verifying the password
            $passwordMatch = password_verify($password, $user['password']);
            if ($passwordMatch) {
                $debug .= " Password matches!";
            } else {
                $debug .= "Failed. Try again.";
            }
        } else {
            $debug = "No user found with username or email: " . $username;
        }
        
        // Attempt login
        $loginResult = processLogin($username, $password);
        
        if ($loginResult['success']) {
            // Log successful login
            $currentUser = getCurrentUser();
            logActivity($currentUser['id'], 'login', 'users', $currentUser['id'], 'Successful login');
            
            // Redirect to dashboard
            header("Location: index.php");
            exit();
        } else {
            $error = $loginResult['message'];
        }
    }
}

// Check if session expired
if (isset($_GET['expired']) && $_GET['expired'] == 1) {
    $error = "Your session has expired. Please log in again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #1cc88a;
            --danger-color: #e74a3b;
            --dark-color: #5a5c69;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8f9fc 0%, #d1d3e0 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 0;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 0 15px;
        }
        
        .login-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .login-header {
            padding: 2rem 2rem 1rem;
            text-align: center;
        }
        
        .login-header h1 {
            margin-bottom: 0.5rem;
            color: var(--dark-color);
            font-weight: 700;
            font-size: 1.75rem;
        }
        
        .login-header p {
            color: #858796;
            margin-bottom: 0;
        }
        
        .login-body {
            padding: 1rem 2rem 2rem;
        }
        
        .login-form .form-control {
            padding: 1.2rem 1rem;
            font-size: 0.9rem;
            border-radius: 0.35rem;
            border: 1px solid #d1d3e2;
        }
        
        .login-form .form-label {
            color: var(--dark-color);
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .login-form .form-control:focus {
            border-color: #bac8f3;
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
        
        .login-btn {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            border-radius: 0.35rem;
            font-weight: 500;
            width: 100%;
            transition: all 0.2s;
        }
        
        .login-btn:hover {
            background-color: #4262c3;
            border-color: #3d5cbb;
        }
        
        .login-btn:focus {
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
        
        .forgot-password {
            text-align: center;
            margin-top: 1rem;
        }
        
        .forgot-password a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .forgot-password a:hover {
            color: #3d5cbb;
            text-decoration: underline;
        }
        
        .login-footer {
            padding: 1rem 2rem;
            background-color: #f8f9fc;
            border-top: 1px solid #e3e6f0;
            text-align: center;
        }
        
        .login-footer p {
            color: #858796;
            margin-bottom: 0;
            font-size: 0.85rem;
        }
        
        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .login-footer a:hover {
            color: #3d5cbb;
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 0.35rem;
            font-size: 0.9rem;
        }
        
        .logo-wrapper {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .logo {
            max-width: 100px;
            height: auto;
        }
        
        .site-name {
            margin-top: 0.5rem;
            margin-bottom: 0;
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.75rem;
        }
        
        .input-group-text {
            background-color: #e9ecef;
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        .show-password {
            cursor: pointer;
            color: #858796;
        }
        
        .debug-info {
            margin-top: 15px;
            padding: 10px;
            background-color: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card animate__animated animate__fadeIn">
            <div class="login-header">
                <div class="logo-wrapper">
                    <i class="fas fa-newspaper fa-3x" style="color: var(--primary-color);"></i>
                    <h2 class="site-name"><?php echo SITE_NAME; ?></h2>
                </div>
                <h1>Admin Login</h1>
                <p>Enter your credentials to access the dashboard</p>
                <!-- Current Date and Time -->
                <small class="text-muted"><?php echo date('Y-m-d H:i:s'); ?> UTC</small>
            </div>
            
            <div class="login-body">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($debug)): ?>
                <div class="debug-info">
                    <strong>Process Info:</strong><br>
                    <?php echo $debug; ?>
                </div>
                <?php endif; ?>
                
                <form class="login-form" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="mb-4">
                        <label for="username" class="form-label">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" placeholder="Enter username or email" required autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                            <span class="input-group-text show-password" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    
                    <button type="submit" class="btn login-btn">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                    
                    <div class="forgot-password">
                        <a href="forgot-password.php">Forgot your password?</a>
                    </div>
                </form>
            </div>
            
            <div class="login-footer">
                <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
                <p><a href="../index.php">Back to Website</a></p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show/hide password
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 1s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 1000);
                }, 5000);
            });
        });
    </script>
</body>
</html>