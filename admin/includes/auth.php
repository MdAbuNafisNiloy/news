<?php
/**
 * Authentication functions
 */

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if session is expired
function isSessionExpired() {
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
        return true;
    }
    return false;
}

// Process login
function processLogin($username, $password) {
    global $db;
    
    try {
        // Find user by username or email
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        // Verify credentials
        if ($user) {
            // Check if the password is correct
            if (password_verify($password, $user['password'])) {
                if ($user['status'] != 'active') {
                    return [
                        'success' => false,
                        'message' => 'Your account is not active. Please contact an administrator.'
                    ];
                }
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['last_activity'] = time();
                
                // Update last login time
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Log activity
                logActivity($user['id'], 'login', 'users', $user['id'], 'User logged in');
                
                return [
                    'success' => true,
                    'message' => 'Login successful'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Process logout
function processLogout() {
    // Log activity before destroying session
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id'], 'User logged out');
    }
    
    // Destroy the session
    session_unset();
    session_destroy();
    
    return [
        'success' => true,
        'message' => 'Logged out successfully'
    ];
}

// Update session activity
function updateSessionActivity() {
    $_SESSION['last_activity'] = time();
}

// Session expiry check - call this function at the beginning of each page
if (isLoggedIn() && isSessionExpired()) {
    processLogout();
    header("Location: login.php?expired=1");
    exit();
} else if (isLoggedIn()) {
    updateSessionActivity();
}