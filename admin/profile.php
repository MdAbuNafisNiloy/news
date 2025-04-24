<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getUserRole etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, getCurrentUser etc.

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get current user information (assuming getCurrentUser fetches all necessary fields)
$currentUser = getCurrentUser();
if (!$currentUser) {
    // Handle case where user data couldn't be fetched (e.g., deleted user still has session)
    session_destroy();
    header("Location: login.php?message=User data not found. Please log in again.&type=danger");
    exit();
}

$message = '';
$messageType = '';

// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 05:32:09";

// Process profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check which form was submitted
        if (isset($_POST['update_profile'])) {
            // --- Personal information update ---
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $lastName = sanitizeInput($_POST['last_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $bio = sanitizeInput($_POST['bio'] ?? '');

            // Basic Validation
            if (empty($firstName) || empty($lastName)) throw new Exception("First and Last Name are required.");
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("A valid Email Address is required.");

            // Check if email already exists for another user
            if (strtolower($email) !== strtolower($currentUser['email'])) {
                $checkEmailStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
                $checkEmailStmt->execute([$email, $currentUser['id']]);
                if ($checkEmailStmt->fetchColumn() > 0) {
                    throw new Exception("Email address is already in use by another user.");
                }
            }

            // Handle profile picture upload
            $uploadPath = $currentUser['profile_picture']; // Keep current picture by default
            $newImageUploaded = false;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
                $fileName = $_FILES['profile_picture']['name'];
                $fileSize = $_FILES['profile_picture']['size'];
                $fileInfo = pathinfo($fileName);
                $extension = strtolower($fileInfo['extension']);
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (!in_array($extension, $allowedExtensions)) throw new Exception("Invalid image format. Allowed: JPG, JPEG, PNG, GIF, WEBP");
                if ($fileSize > 2 * 1024 * 1024) throw new Exception("Image size too large (Max 2MB).");

                $newFilename = 'profile_' . $currentUser['id'] . '_' . uniqid() . '.' . $extension;
                $uploadDir = 'uploads/profile/';
                 if (!is_dir($uploadDir)) { if (!mkdir($uploadDir, 0755, true)) throw new Exception("Failed to create upload directory."); }

                $newUploadPath = $uploadDir . $newFilename;
                if (!move_uploaded_file($fileTmpPath, $newUploadPath)) throw new Exception("Failed to move uploaded profile picture.");

                $uploadPath = $newUploadPath; // Set path for DB update to the NEW path
                $newImageUploaded = true;
            }

            // Update user information in DB
            $stmt = $db->prepare("
                UPDATE users
                SET first_name = :first_name, last_name = :last_name, email = :email,
                    bio = :bio, profile_picture = :profile_picture
                WHERE id = :id
            ");
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':bio' => $bio,
                ':profile_picture' => $uploadPath, // Use the determined path
                ':id' => $currentUser['id']
            ]);

             // Delete old profile picture *after* successful DB update if a new one was uploaded
            if ($newImageUploaded && !empty($currentUser['profile_picture']) && $currentUser['profile_picture'] !== $uploadPath && file_exists($currentUser['profile_picture'])) {
                 unlink($currentUser['profile_picture']);
            }

            $message = "Profile updated successfully!";
            $messageType = "success";
            if (function_exists('logActivity')) logActivity($currentUser['id'], 'profile_update', 'users', $currentUser['id'], 'Updated profile information');

            // Refresh user data after update
            $currentUser = getCurrentUser();

        } elseif (isset($_POST['change_password'])) {
            // --- Password change ---
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) throw new Exception("All password fields are required.");
            if (!password_verify($currentPassword, $currentUser['password'])) throw new Exception("Current password is incorrect.");
            if (strlen($newPassword) < 8) throw new Exception("New password must be at least 8 characters long.");
            if ($newPassword !== $confirmPassword) throw new Exception("New passwords do not match.");
            if (password_verify($newPassword, $currentUser['password'])) throw new Exception("New password cannot be the same as the current password.");

            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $currentUser['id']]);

            $message = "Password changed successfully!";
            $messageType = "success";
            if (function_exists('logActivity')) logActivity($currentUser['id'], 'password_change', 'users', $currentUser['id'], 'Changed account password');

            // Refresh user data to potentially update last password change date if tracked
            $currentUser = getCurrentUser();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("Profile Update/Password Change Error (User ID: {$currentUser['id']}): " . $e->getMessage());
    }
}

// Get user role information (needed regardless of POST)
$userRole = $currentUser ? getUserRole($currentUser['role_id']) : ['name' => 'N/A']; // Default if role fetch fails

// Get user activity logs (limit to 10)
$activities = [];
if ($currentUser && function_exists('getRecentActivityLogs')) { // Check if function exists
    try {
         $activityStmt = $db->prepare("
            SELECT action, description, created_at, ip_address
            FROM activity_logs
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $activityStmt->execute([':user_id' => $currentUser['id']]);
        $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user activity logs (User ID: {$currentUser['id']}): " . $e->getMessage());
        // Don't show error to user, just log it
    }
}

// Get user's articles (limit to 5)
$userArticles = [];
if ($currentUser) {
    try {
         $articlesStmt = $db->prepare("
            SELECT id, title, status, views, created_at
            FROM articles
            WHERE author_id = :author_id
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $articlesStmt->execute([':author_id' => $currentUser['id']]);
        $userArticles = $articlesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
         error_log("Error fetching user articles (User ID: {$currentUser['id']}): " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Same Base CSS as previous examples --- */
        :root {
            --primary-color: #4e73df; --secondary-color: #1cc88a; --danger-color: #e74a3b;
            --warning-color: #f6c23e; --info-color: #36b9cc; --dark-color: #5a5c69;
            --light-color: #f8f9fc; --sidebar-width: 220px; --sidebar-width-collapsed: 90px;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--light-color); color: var(--dark-color); overflow-x: hidden; }
        /* Sidebar */
        .sidebar { background: linear-gradient(180deg, var(--primary-color) 10%, #224abe 100%); height: 100vh; position: fixed; top: 0; left: 0; z-index: 1030; width: var(--sidebar-width); transition: width 0.3s ease-in-out; overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-width-collapsed); text-align: center; }
        .sidebar.collapsed .sidebar-brand { padding: 1.5rem 0.5rem; font-size: 0.8rem; } .sidebar.collapsed .sidebar-brand span { display: none; }
        .sidebar.collapsed .sidebar-item { padding: 0.75rem; justify-content: center; } .sidebar.collapsed .sidebar-item i { margin-right: 0; } .sidebar.collapsed .sidebar-item span { display: none; }
        .sidebar-brand { height: 4.375rem; padding: 1.5rem 1rem; color: #fff; text-align: center; font-size: 1.1rem; font-weight: 700; letter-spacing: 0.05rem; text-transform: uppercase; border-bottom: 1px solid rgba(255, 255, 255, 0.15); text-decoration: none; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .sidebar-brand i { vertical-align: middle; }
        .sidebar-items { margin-top: 1rem; }
        .sidebar-item { padding: 0.75rem 1rem; color: rgba(255, 255, 255, 0.8); transition: background-color 0.2s, color 0.2s; display: flex; align-items: center; margin: 0.25rem 0.5rem; border-radius: 0.35rem; text-decoration: none; white-space: nowrap; }
        .sidebar-item.active, .sidebar-item:hover { background-color: rgba(255, 255, 255, 0.1); color: #fff; }
        .sidebar-item i { margin-right: 0.75rem; opacity: 0.8; width: 1.25em; text-align: center; flex-shrink: 0; }
        /* Main Content */
        .main-content { padding: 1.5rem; margin-left: var(--sidebar-width); transition: margin-left 0.3s ease-in-out; }
        .main-content.expanded { margin-left: var(--sidebar-width-collapsed); }
        /* Top Bar */
        .top-bar { background: #fff; margin-bottom: 1.5rem; padding: 0.75rem 1rem; border-radius: 0.35rem; box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.1); display: flex; justify-content: space-between; align-items: center; }
        .top-bar .dropdown-toggle::after { display: none; }
        /* Cards */
        .card { border: none; border-radius: 0.35rem; box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 1.5rem; overflow: hidden; }
        .card-header { background-color: #fdfdfd; border-bottom: 1px solid #e3e6f0; padding: 0.75rem 1rem; font-weight: 600; color: var(--primary-color); }
        .card-header h5 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1.25rem; } /* More padding in card body */
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Forms */
        .form-label { font-weight: 500; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .form-text { font-size: 0.75rem; }
        .form-control, .form-select { font-size: 0.9rem; }
        .form-control[readonly] { background-color: #e9ecef; opacity: 1; }
        /* Profile Header */
        .profile-header { background: linear-gradient(135deg, var(--primary-color) 0%, #2e59d9 100%); padding: 2rem 1.5rem; color: white; text-align: center; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; }
        .profile-pic-wrapper { position: relative; width: 120px; height: 120px; margin: 0 auto 1rem auto; }
        .profile-pic { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 4px solid rgba(255, 255, 255, 0.8); box-shadow: 0 0.3rem 0.8rem rgba(0, 0, 0, 0.1); }
        .profile-pic-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); border-radius: 50%; opacity: 0; transition: opacity 0.3s; display: flex; justify-content: center; align-items: center; cursor: pointer; }
        .profile-pic-wrapper:hover .profile-pic-overlay { opacity: 1; }
        .profile-pic-overlay i { color: white; font-size: 1.8rem; }
        .profile-info h4 { margin-bottom: 0.25rem; font-weight: 600; font-size: 1.25rem; }
        .profile-info p { margin-bottom: 0.1rem; opacity: 0.9; font-size: 0.9rem; }
        .profile-badge { display: inline-block; padding: 0.3em 0.6em; font-size: 0.7rem; font-weight: 600; color: #fff; border-radius: 0.25rem; background-color: var(--secondary-color); margin-top: 0.5rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Tabs */
        .nav-pills .nav-link { color: var(--dark-color); border-radius: 0.35rem; font-weight: 500; padding: 0.6rem 1rem; font-size: 0.9rem; transition: background-color 0.2s, color 0.2s; }
        .nav-pills .nav-link.active { background-color: var(--primary-color); color: white; }
        .nav-pills .nav-link:not(.active):hover { background-color: #e9ecef; }
        .tab-content { padding-top: 1rem; }
        /* Activity Timeline */
        .activity-timeline { position: relative; padding-left: 35px; margin-top: 1rem; }
        .activity-timeline::before { content: ''; position: absolute; left: 10px; top: 5px; bottom: 5px; width: 2px; background: #e3e6f0; }
        .timeline-item { position: relative; padding-bottom: 1.25rem; }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-marker { position: absolute; left: -35px; top: 0; width: 22px; height: 22px; border-radius: 50%; background: var(--primary-color); color: white; text-align: center; line-height: 22px; font-size: 0.7rem; box-shadow: 0 0 0 3px var(--light-color); }
        .timeline-date { display: block; font-size: 0.75rem; color: #858796; margin-bottom: 0.15rem; }
        .timeline-content { background: white; border-radius: 0.25rem; padding: 0.75rem 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .timeline-content p { font-size: 0.85rem; margin-bottom: 0.25rem; }
        .timeline-content small { font-size: 0.75rem; }
        /* Tables */
        .table thead th { font-size: 0.75rem; text-transform: uppercase; color: #858796; font-weight: 600; background-color: #f8f9fc; border-top: 0; border-bottom: 2px solid #e3e6f0; padding: 0.6rem 0.75rem; white-space: nowrap; }
        .table td { vertical-align: middle; padding: 0.6rem 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .badge { font-weight: 600; padding: 0.3em 0.6em; font-size: 0.7rem; }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } .profile-header { padding: 1.5rem 1rem; } .profile-pic-wrapper { width: 100px; height: 100px; } .profile-info h4 { font-size: 1.1rem; } }
        @media (min-width: 992px) { .main-content { max-width: calc(100% - var(--sidebar-width)); } .main-content.expanded { max-width: calc(100% - var(--sidebar-width-collapsed)); } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <a href="index.php" class="sidebar-brand" title="<?php echo htmlspecialchars(SITE_NAME); ?>">
            <i class="fas fa-newspaper fa-fw"></i> <span><?php echo htmlspecialchars(SITE_NAME); ?></span>
        </a>
        <div class="sidebar-items">
            <a href="index.php" class="sidebar-item" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
            <?php if (hasPermission('create_article')): ?> <a href="articles.php" class="sidebar-item" title="Articles"> <i class="fas fa-newspaper fa-fw"></i> <span>Articles</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
			<?php if (hasPermission('manage_categories')): // Add this permission check ?>
            <a href="pages.php" class="sidebar-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pages.php' || basename($_SERVER['PHP_SELF']) == 'page-edit.php') ? 'active' : ''; ?>" title="Pages">
                <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span>
            </a>
            <?php endif; ?>
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_roles')): ?> <a href="roles.php" class="sidebar-item" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <?php endif; ?>
            <a href="media.php" class="sidebar-item" title="Media"> <i class="fas fa-images fa-fw"></i> <span>Media</span> </a>
            <?php if (hasPermission('manage_settings')): ?> <a href="settings.php" class="sidebar-item" title="Settings"> <i class="fas fa-cog fa-fw"></i> <span>Settings</span> </a> <?php endif; ?>
            <hr class="text-white-50 mx-3 my-2">
            <a href="profile.php" class="sidebar-item active" title="Profile"> <i class="fas fa-user-circle fa-fw"></i> <span>Profile</span> </a>
            <a href="logout.php" class="sidebar-item" title="Logout"> <i class="fas fa-sign-out-alt fa-fw"></i> <span>Logout</span> </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle" aria-label="Toggle sidebar"> <i class="fas fa-bars"></i> </button>
            <div class="dropdown">
                 <a class="btn btn-link dropdown-toggle text-decoration-none text-muted" href="#" role="button" id="userDropdownLink" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo !empty($currentUser['profile_picture']) ? htmlspecialchars($currentUser['profile_picture']) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile" id="topBarProfilePic">
                    <span class="d-none d-md-inline-block"><?php echo htmlspecialchars($currentUser['username']); ?></span> <i class="fas fa-chevron-down fa-xs ms-1"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdownLink">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle fa-fw me-2 text-muted"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2 text-muted"></i> Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt fa-fw me-2 text-muted"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Profile Header -->
        <div class="profile-header">
            <form method="POST" action="profile.php" enctype="multipart/form-data" id="profilePicForm" class="d-inline-block">
                 <input type="file" class="d-none" id="profile_picture_input" name="profile_picture" accept="image/*">
                 <input type="hidden" name="update_profile" value="1"> <!-- Indicate profile update -->
                 <!-- Add hidden fields for other required profile fields if needed, or rely on JS to submit main form -->
            </form>
            <div class="profile-pic-wrapper">
                <img src="<?php echo !empty($currentUser['profile_picture']) ? htmlspecialchars($currentUser['profile_picture']) : 'assets/images/default-avatar.png'; ?>" alt="Profile Picture" class="profile-pic" id="profilePicDisplay">
                <div class="profile-pic-overlay" onclick="document.getElementById('profile_picture_input').click();" title="Change profile picture">
                    <i class="fas fa-camera"></i>
                </div>
            </div>
            <div class="profile-info">
                <h4><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></h4>
                <p>@<?php echo htmlspecialchars($currentUser['username']); ?></p>
                <p><i class="fas fa-envelope fa-xs me-1"></i> <?php echo htmlspecialchars($currentUser['email']); ?></p>
                <div class="profile-badge"><?php echo htmlspecialchars($userRole['name']); ?></div>
            </div>
             <div class="header-meta">
                 User: <?php echo htmlspecialchars($headerLogin); ?> | UTC: <?php echo $headerUtcTime; ?>
             </div>
        </div>

        <!-- Message Area -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Profile Tabs -->
        <ul class="nav nav-pills nav-fill mb-4" id="profileTabs" role="tablist">
            <li class="nav-item" role="presentation"> <button class="nav-link active" id="personal-info-tab" data-bs-toggle="pill" data-bs-target="#personal-info" type="button" role="tab" aria-controls="personal-info" aria-selected="true"> <i class="fas fa-user-edit me-1"></i> Profile </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false"> <i class="fas fa-lock me-1"></i> Security </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="activity-tab" data-bs-toggle="pill" data-bs-target="#activity" type="button" role="tab" aria-controls="activity" aria-selected="false"> <i class="fas fa-history me-1"></i> Activity </button> </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="profileTabsContent">
            <!-- Personal Information Tab -->
            <div class="tab-pane fade show active" id="personal-info" role="tabpanel" aria-labelledby="personal-info-tab">
                <div class="card">
                    <div class="card-header"> <h5 class="mb-0">Edit Personal Information</h5> </div>
                    <div class="card-body">
                        <form method="POST" action="profile.php" enctype="multipart/form-data" id="profileInfoForm">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control form-control-sm" id="username" value="<?php echo htmlspecialchars($currentUser['username']); ?>" readonly disabled>
                                    <small class="form-text text-muted">Username cannot be changed.</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control form-control-sm" id="email" name="email" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="first_name" name="first_name" value="<?php echo htmlspecialchars($currentUser['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="last_name" name="last_name" value="<?php echo htmlspecialchars($currentUser['last_name']); ?>" required>
                                </div>
                            </div>
                             <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <input type="text" class="form-control form-control-sm" id="role" value="<?php echo htmlspecialchars($userRole['name']); ?>" readonly disabled>
                                <small class="form-text text-muted">Role changes require administrator privileges.</small>
                            </div>
                             <div class="mb-3">
                                <label for="bio" class="form-label">Bio</label>
                                <textarea class="form-control form-control-sm" id="bio" name="bio" rows="3" placeholder="Tell us a little about yourself..."><?php echo htmlspecialchars($currentUser['bio'] ?? ''); ?></textarea>
                            </div>
                             <!-- Hidden file input for profile pic, triggered by JS -->
                             <input type="file" class="d-none" id="profile_picture" name="profile_picture" accept="image/*">

                            <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-save me-1"></i> Save Changes </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Security Tab -->
            <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header"> <h5 class="mb-0">Change Password</h5> </div>
                            <div class="card-body">
                                <form method="POST" action="profile.php" id="passwordChangeForm">
                                    <input type="hidden" name="change_password" value="1">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required aria-describedby="passwordHelp">
                                        <small id="passwordHelp" class="form-text text-muted">Must be at least 8 characters long.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-key me-1"></i> Change Password </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                         <div class="card">
                            <div class="card-header"> <h5 class="mb-0">Account Information</h5> </div>
                            <div class="card-body">
                                <div class="mb-3"> <label class="form-label small text-muted">Account Created</label> <p class="mb-0"><?php echo date('F j, Y', strtotime($currentUser['registration_date'])); ?></p> </div>
                                <div class="mb-3"> <label class="form-label small text-muted">Last Login</label> <p class="mb-0"><?php echo !empty($currentUser['last_login']) ? date('F j, Y, H:i:s T', strtotime($currentUser['last_login'])) : 'N/A'; ?></p> </div>
                                <div> <label class="form-label small text-muted">Account Status</label> <p class="mb-0"> <?php $statusClass = match($currentUser['status']) {'active' => 'success', 'inactive' => 'warning', default => 'danger'}; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($currentUser['status'])); ?></span> </p> </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activity Tab -->
            <div class="tab-pane fade" id="activity" role="tabpanel" aria-labelledby="activity-tab">
                 <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header"> <h5 class="mb-0">Recent Login Activity</h5> </div>
                            <div class="card-body">
                                <div class="activity-timeline">
                                    <?php if (!empty($activities)):
                                        $loginActivities = array_filter($activities, fn($a) => str_contains(strtolower($a['action']), 'login') || str_contains(strtolower($a['action']), 'logout'));
                                        if (!empty($loginActivities)):
                                            foreach ($loginActivities as $activity):
                                                $icon = str_contains(strtolower($activity['action']), 'login') ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
                                    ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker"><i class="fas <?php echo $icon; ?>"></i></div>
                                        <div class="timeline-content">
                                            <span class="timeline-date" title="<?php echo htmlspecialchars($activity['created_at']); ?>"><?php echo function_exists('timeAgo') ? timeAgo($activity['created_at']) : date('M d, Y H:i', strtotime($activity['created_at'])); ?></span>
                                            <p><?php echo htmlspecialchars($activity['description'] ?? $activity['action']); ?></p>
                                            <?php if (!empty($activity['ip_address'])): ?><small class="text-muted">IP: <?php echo htmlspecialchars($activity['ip_address']); ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; else: ?> <p class="text-muted text-center small mb-0">No login activity recorded.</p> <?php endif; ?>
                                    <?php else: ?> <p class="text-muted text-center small mb-0">No activity recorded.</p> <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center"> <h5 class="mb-0">Your Recent Articles</h5> <a href="articles.php?author=<?php echo $currentUser['id']; ?>" class="btn btn-sm btn-outline-primary">View All</a> </div>
                            <div class="card-body p-0">
                                <?php if (!empty($userArticles)): ?>
                                <div class="table-responsive"> <table class="table table-hover mb-0">
                                    <thead> <tr> <th>Title</th> <th>Status</th> <th>Date</th> </tr> </thead>
                                    <tbody> <?php foreach ($userArticles as $article): ?>
                                        <tr> <td><a href="article-edit.php?id=<?php echo $article['id']; ?>" title="<?php echo htmlspecialchars($article['title']); ?>"><?php echo htmlspecialchars(mb_strimwidth($article['title'], 0, 35, '...')); ?></a></td> <td> <?php $statusClass = match($article['status']) {'published' => 'success', 'draft' => 'secondary', 'pending' => 'warning text-dark', default => 'info'}; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($article['status'])); ?></span> </td> <td><small><?php echo date('M d, Y', strtotime($article['created_at'])); ?></small></td> </tr>
                                    <?php endforeach; ?> </tbody>
                                </table> </div>
                                <?php else: ?> <div class="text-center p-4 text-muted"> <i class="fas fa-newspaper fa-2x mb-2"></i><p class="small mb-2">You haven't created any articles yet.</p> <a href="article-new.php" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Create One</a> </div> <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- End Tab Content -->

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(SITE_NAME) . ' ' . date('Y'); ?>
        </footer>
    </div> <!-- End Main Content -->

    <!-- Core JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>

    <script defer>
        document.addEventListener('DOMContentLoaded', function() {

            // --- Sidebar Toggle ---
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const SIDEBAR_COLLAPSED_KEY = 'sidebarCollapsed';
            function applySidebarState(collapsed) { if (sidebar && mainContent) { sidebar.classList.toggle('collapsed', collapsed); mainContent.classList.toggle('expanded', collapsed); } }
            const isCollapsed = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === 'true';
            applySidebarState(isCollapsed);
            if (sidebarToggle) { sidebarToggle.addEventListener('click', function() { const shouldCollapse = !sidebar.classList.contains('collapsed'); applySidebarState(shouldCollapse); localStorage.setItem(SIDEBAR_COLLAPSED_KEY, shouldCollapse); }); }

            // --- Profile Picture Preview & Auto-Submit ---
            const profilePicInput = document.getElementById('profile_picture_input'); // The hidden input
            const profilePicDisplay = document.getElementById('profilePicDisplay'); // The img tag in the header
            const topBarProfilePic = document.getElementById('topBarProfilePic'); // The img tag in the top bar
            const profileInfoForm = document.getElementById('profileInfoForm'); // The main profile info form

            if (profilePicInput && profilePicDisplay && profileInfoForm) {
                profilePicInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            // Update preview in header
                            profilePicDisplay.src = event.target.result;
                             // Optionally update top bar pic immediately (or wait for page reload after save)
                             if(topBarProfilePic) topBarProfilePic.src = event.target.result;

                            // Strategy 1: Auto-submit *only* the picture change
                            // document.getElementById('profilePicForm').submit(); // Requires profilePicForm to exist and work independently

                            // Strategy 2: Inject the file into the main form and let user click "Save Changes"
                            // Find or create the file input within the main form
                             let mainFormPicInput = profileInfoForm.querySelector('input[name="profile_picture"]');
                             if (!mainFormPicInput) {
                                 // If it doesn't exist in the main form, clone the hidden one (or just use the hidden one's files)
                                 // This part can be complex; easier to just have ONE file input in the main form.
                                 // For simplicity, let's assume the file input *is* in the main form now.
                                 mainFormPicInput = document.getElementById('profile_picture'); // Use the visible one in the form
                                 if(mainFormPicInput) {
                                     mainFormPicInput.files = e.target.files; // Transfer files to the main form's input
                                 }
                             } else {
                                 mainFormPicInput.files = e.target.files; // Transfer files if it exists
                             }
                             // No automatic submit here, user saves normally.

                        }
                        reader.readAsDataURL(file);
                    }
                });
            }
             // Trigger hidden file input click from overlay
             const picOverlay = document.querySelector('.profile-pic-overlay');
             if(picOverlay && profilePicInput) {
                 picOverlay.addEventListener('click', () => profilePicInput.click());
             }

            // --- Password Change Form Validation ---
            const passwordChangeForm = document.getElementById('passwordChangeForm');
            if (passwordChangeForm) {
                passwordChangeForm.addEventListener('submit', function(e) {
                    const newPassword = document.getElementById('new_password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    const currentPassword = document.getElementById('current_password').value;

                    if (!currentPassword) { alert('Please enter your current password.'); e.preventDefault(); return; }
                    if (newPassword.length < 8) { alert('New password must be at least 8 characters long.'); e.preventDefault(); return; }
                    if (newPassword !== confirmPassword) { alert('New passwords do not match.'); e.preventDefault(); return; }
                });
            }

             // --- Activate Tab from URL Hash ---
             const hash = window.location.hash;
             if (hash) {
                 const triggerEl = document.querySelector(`.nav-pills button[data-bs-target="${hash}"]`);
                 if (triggerEl) {
                     const tab = new bootstrap.Tab(triggerEl);
                     tab.show();
                 }
             }
             // Update hash on tab change
             const triggerTabList = [].slice.call(document.querySelectorAll('#profileTabs button'));
             triggerTabList.forEach(function (triggerEl) {
               const tabTrigger = new bootstrap.Tab(triggerEl);
               triggerEl.addEventListener('click', function (event) {
                  window.location.hash = event.target.dataset.bsTarget;
               });
             });

        }); // End DOMContentLoaded
    </script>

</body>
</html>