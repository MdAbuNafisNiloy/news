<?php
// This is a password reset tool for admin use
// IMPORTANT: Delete this file after use!
require_once 'config/database.php';
require_once 'config/config.php';

$message = '';
$messageType = 'info';
$users = [];

// Get all users
try {
    $stmt = $db->query("SELECT id, username, email FROM users ORDER BY username");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $message = "Database error: " . $e->getMessage();
    $messageType = "danger";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Validate input
    if (empty($userId)) {
        $message = "Please select a user.";
        $messageType = "warning";
    } else if (empty($newPassword)) {
        $message = "Password is required.";
        $messageType = "warning";
    } else if (strlen($newPassword) < 8) {
        $message = "Password must be at least 8 characters long.";
        $messageType = "warning";
    } else if ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "warning";
    } else {
        try {
            // Hash the new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update the password in the database
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $result = $stmt->execute([$hashedPassword, $userId]);
            
            if ($result) {
                $message = "Password has been reset successfully!";
                $messageType = "success";
            } else {
                $message = "Failed to reset password.";
                $messageType = "danger";
            }
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Tool - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fc;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        .card {
            border-radius: 5px;
            border: none;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #4e73df;
            color: white;
            font-weight: bold;
            border-bottom: none;
            padding: 15px;
        }
        .card-body {
            padding: 20px;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .warning-banner {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="warning-banner">
            <h4><i class="fas fa-exclamation-triangle"></i> SECURITY WARNING</h4>
            <p>This tool should be used only for emergency password resets. Delete this file immediately after use!</p>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-key"></i> Password Reset Tool
            </div>
            <div class="card-body">
                <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                    <?php echo $message; ?>
                </div>
                <?php endif; ?>
                
                <form method="post">
                    <div class="mb-3">
                        <label for="user_id" class="form-label">Select User</label>
                        <select class="form-select" id="user_id" name="user_id" required>
                            <option value="">-- Select User --</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required 
                               minlength="8" placeholder="Enter new password">
                        <div class="form-text">Password must be at least 8 characters long.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                               placeholder="Confirm new password">
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Reset Password
                        </button>
                        <a href="login.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Login
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>