<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getSetting, handleFileUpload etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Get User ID and Initial Data Fetch ---
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    header("Location: users.php?message=Invalid user ID.&type=danger");
    exit();
}

$userToEdit = null;
$roles = [];
$message = '';
$messageType = '';

// Helper function to get settings (assuming it exists in functions.php or included)
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

// Helper function for file uploads (assuming it exists in functions.php or included)
if (!function_exists('handleFileUpload')) {
    // Basic fallback or include the actual function definition here/in functions.php
    function handleFileUpload($inputName, $targetDir, $allowedExts, $maxSize, $prefix = '') {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
            return null; // No file uploaded is okay
        }
        if ($_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
             throw new Exception("File upload error for '$inputName'. Code: " . $_FILES[$inputName]['error']);
        }

        $fileTmpPath = $_FILES[$inputName]['tmp_name'];
        $fileName = $_FILES[$inputName]['name'];
        $fileSize = $_FILES[$inputName]['size'];
        $fileInfo = pathinfo($fileName);
        $extension = strtolower($fileInfo['extension'] ?? '');

        if (!in_array($extension, $allowedExts)) {
            throw new Exception("Invalid file format for '$inputName'. Allowed: " . implode(', ', $allowedExts));
        }
        if ($fileSize > $maxSize) {
            throw new Exception("File size for '$inputName' too large (Max " . ($maxSize / 1024 / 1024) . "MB).");
        }

        $newFilename = $prefix . uniqid() . '.' . $extension;
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) throw new Exception("Failed to create upload directory: $targetDir");
        }
        $uploadPath = rtrim($targetDir, '/') . '/' . $newFilename;

        if (!move_uploaded_file($fileTmpPath, $uploadPath)) {
            throw new Exception("Failed to move uploaded file for '$inputName'.");
        }
        return $uploadPath;
    }
}


try {
    // --- Permission Check --- <<< CHANGED HERE
    if (!isLoggedIn() || !hasPermission('manage_users')) { // Changed from manage_users
         header("Location: index.php?message=Access Denied: Insufficient permissions.&type=danger");
         exit();
    }

    // Fetch user data
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userToEdit) {
        header("Location: users.php?message=User not found.&type=danger");
        exit();
    }

    // Fetch available roles for dropdown
    $rolesStmt = $db->query("SELECT id, name FROM roles ORDER BY name");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Error loading user data: " . $e->getMessage();
    $messageType = "danger";
    error_log("User Load Error (ID: $userId): " . $e->getMessage());
    $userToEdit = null; // Prevent form rendering if load failed
}

// Get current logged-in user (needed for header and self-edit checks)
$loggedInUser = getCurrentUser();

// --- Process Form Submission (Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userToEdit) { // Only process if user was loaded
    // Store original data for comparison
    $originalEmail = $userToEdit['email'];
    $originalProfilePic = $userToEdit['profile_picture'];
    $originalStatus = $userToEdit['status'];
    $originalRoleId = $userToEdit['role_id'];
    $isEditingSelf = ($userId === $loggedInUser['id']);

    // Get data from POST, default to original user data
    $submittedData = [
        'email' => $_POST['email'] ?? $userToEdit['email'],
        'first_name' => $_POST['first_name'] ?? $userToEdit['first_name'],
        'last_name' => $_POST['last_name'] ?? $userToEdit['last_name'],
        // Use original value if editing self and fields are disabled
        'role_id' => $isEditingSelf ? $originalRoleId : ($_POST['role_id'] ?? $userToEdit['role_id']),
        'status' => $isEditingSelf ? $originalStatus : ($_POST['status'] ?? $userToEdit['status']),
        'bio' => $_POST['bio'] ?? $userToEdit['bio'],
    ];
    // Update the $userToEdit array immediately for form repopulation on error
    $userToEdit = array_merge($userToEdit, $submittedData);

    try {
        // --- Input Validation ---
        if (empty($submittedData['email']) || !filter_var($submittedData['email'], FILTER_VALIDATE_EMAIL)) throw new Exception("Valid email address is required.");
        if (empty($submittedData['first_name'])) throw new Exception("First name is required.");
        if (empty($submittedData['last_name'])) throw new Exception("Last name is required.");
        if (empty($submittedData['role_id'])) throw new Exception("User role must be selected.");
        if (empty($submittedData['status']) || !in_array($submittedData['status'], ['active', 'inactive', 'suspended'])) throw new Exception("Invalid user status selected.");

        // Sanitize inputs
        $email = sanitizeInput($submittedData['email']);
        $firstName = sanitizeInput($submittedData['first_name']);
        $lastName = sanitizeInput($submittedData['last_name']);
        $roleId = (int)$submittedData['role_id'];
        $status = sanitizeInput($submittedData['status']);
        $bio = sanitizeInput($submittedData['bio']);

        // Prevent changing own status or role (server-side check)
        if ($isEditingSelf) {
            if ($status !== $originalStatus) {
                throw new Exception("You cannot change your own status.");
            }
            if ($roleId !== $originalRoleId) {
                 throw new Exception("You cannot change your own role.");
            }
        }

        // Check if email already exists for *another* user
        if (strtolower($email) !== strtolower($originalEmail)) {
            $checkEmailStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
            $checkEmailStmt->execute([$email, $userId]);
            if ($checkEmailStmt->fetchColumn() > 0) {
                throw new Exception("Email address is already in use by another user.");
            }
        }

        // Handle profile picture upload
        $profilePicPath = $originalProfilePic; // Keep current picture by default
        $newImageUploaded = false;
        $newProfilePicPath = handleFileUpload(
            'profile_picture',      // Input name
            'uploads/profile/',     // Target directory
            ['jpg', 'jpeg', 'png', 'gif', 'webp'], // Allowed extensions
            2 * 1024 * 1024,        // Max size (2MB)
            'profile_' . $userId . '_' // Filename prefix
        );
        if ($newProfilePicPath) {
            $profilePicPath = $newProfilePicPath;
            $newImageUploaded = true;
        } // Exception is thrown by handleFileUpload on error if one occurs other than NO_FILE


        // Handle password change if fields are filled
        $passwordSqlPart = '';
        $passwordParams = [];
        if (!empty($_POST['new_password'])) {
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (strlen($newPassword) < 8) throw new Exception("New password must be at least 8 characters long.");
            if ($newPassword !== $confirmPassword) throw new Exception("New passwords do not match.");

            // If editing another user, current password is not needed.
            // If editing self, you might want to add a current password check here.
             // if ($isEditingSelf && !password_verify($_POST['current_password'] ?? '', $loggedInUser['password'])) {
             //    throw new Exception("Current password verification failed.");
             // }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $passwordSqlPart = ', password = :password';
            $passwordParams[':password'] = $hashedPassword;
        }


        // --- Database Update ---
        $db->beginTransaction();

        // Build SQL dynamically based on password change
        $sql = "UPDATE users SET
                    email = :email, first_name = :first_name, last_name = :last_name,
                    role_id = :role_id, status = :status, bio = :bio,
                    profile_picture = :profile_picture {$passwordSqlPart}
                WHERE id = :id";
        $stmt = $db->prepare($sql);

        $baseParams = [
            ':email' => $email,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':role_id' => $roleId,
            ':status' => $status,
            ':bio' => $bio,
            ':profile_picture' => $profilePicPath,
            ':id' => $userId
        ];

        $executeParams = array_merge($baseParams, $passwordParams);
        $stmt->execute($executeParams);

        $db->commit();

        // Delete old profile picture *after* successful DB update if a new one was uploaded
        if ($newImageUploaded && $originalProfilePic && $originalProfilePic !== $profilePicPath && file_exists($originalProfilePic)) {
            @unlink($originalProfilePic); // Use @ to suppress errors if file gone
        }

        // Log activity
        if (function_exists('logActivity')) {
             $logDesc = "Updated user profile: '{$userToEdit['username']}'";
             if(!empty($passwordSqlPart)) $logDesc .= " (Password Changed)";
             logActivity($loggedInUser['id'], 'user_update', 'users', $userId, $logDesc);
        }

        $message = "User profile updated successfully" . (!empty($passwordSqlPart) ? " (Password Changed)" : "") . "!";
        $messageType = "success";

        // Refresh data after update
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

        // Redirect back to edit page with success message (stay on edit page)
        header("Location: user-edit.php?id=$userId&message=" . urlencode($message) . "&type=" . $messageType);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Delete newly uploaded file if transaction failed
        if ($newImageUploaded && isset($profilePicPath) && $profilePicPath !== $originalProfilePic && file_exists($profilePicPath)) {
            @unlink($profilePicPath); // Use @ to suppress errors if file gone
        }
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("User Update Error (ID: $userId): " . $e->getMessage());
        // $userToEdit array is already updated with submitted data for repopulation
    }
}

// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 06:37:35"; // <<< UPDATED TIME

// Check for messages passed via URL (e.g., after successful save)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}

$isEditingSelf = ($userToEdit && $loggedInUser && $userToEdit['id'] === $loggedInUser['id']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - <?php echo $userToEdit ? htmlspecialchars($userToEdit['username']) : 'Error'; ?> - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Same Base Responsive CSS --- */
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
        .card-body { padding: 1.25rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Forms */
        .form-label { font-weight: 500; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .form-text { font-size: 0.75rem; }
        .form-control, .form-select { font-size: 0.9rem; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; }
        .form-control[readonly], .form-select[disabled] { background-color: #e9ecef; opacity: 1; cursor: not-allowed; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Profile Picture Preview */
        .profile-pic-preview { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #eee; margin-bottom: 0.5rem; display: block; }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } }
        @media (min-width: 992px) { .main-content { max-width: calc(100% - var(--sidebar-width)); } .main-content.expanded { max-width: calc(100% - var(--sidebar-width-collapsed)); } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <a href="index.php" class="sidebar-brand" title="<?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?>">
            <i class="fas fa-newspaper fa-fw"></i> <span><?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></span>
        </a>
        <div class="sidebar-items">
             <a href="index.php" class="sidebar-item" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
            <?php if (hasPermission('create_article')): ?> <a href="articles.php" class="sidebar-item" title="Articles"> <i class="fas fa-newspaper fa-fw"></i> <span>Articles</span> </a> <?php endif; ?>
            <!-- <<< CHANGED HERE -->
            <?php if (hasPermission('manage_categories')): ?>
                <a href="categories.php" class="sidebar-item" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a>
                <a href="pages.php" class="sidebar-item" title="Pages"> <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span> </a> <!-- Assuming Pages needs same perm -->
                <a href="users.php" class="sidebar-item active" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <!-- Assuming Users needs same perm -->
                <a href="roles.php" class="sidebar-item" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <!-- Assuming Roles needs same perm -->
            <?php endif; ?>
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <a href="media.php" class="sidebar-item" title="Media"> <i class="fas fa-images fa-fw"></i> <span>Media</span> </a>
            <?php if (hasPermission('manage_settings')): ?> <a href="settings.php" class="sidebar-item" title="Settings"> <i class="fas fa-cog fa-fw"></i> <span>Settings</span> </a> <?php endif; ?>
            <hr class="text-white-50 mx-3 my-2">
            <a href="profile.php" class="sidebar-item" title="Profile"> <i class="fas fa-user-circle fa-fw"></i> <span>Profile</span> </a>
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
                    <img src="<?php echo !empty($loggedInUser['profile_picture']) ? htmlspecialchars($loggedInUser['profile_picture']) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile">
                    <span class="d-none d-md-inline-block"><?php echo htmlspecialchars($loggedInUser['username']); ?></span> <i class="fas fa-chevron-down fa-xs ms-1"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdownLink">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle fa-fw me-2 text-muted"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2 text-muted"></i> Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt fa-fw me-2 text-muted"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-user-edit me-2"></i> Edit User</h2>
            <p>Update profile information, role, and status for <?php echo $userToEdit ? "<strong>".htmlspecialchars($userToEdit['username'])."</strong>" : "user"; ?>.</p>
             <div class="header-meta">
                 UTC: <?php echo $headerUtcTime; ?>
             </div>
        </div>

         <!-- Message Area -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if ($userToEdit): // Only show form if user was loaded ?>
        <form method="POST" action="user-edit.php?id=<?php echo $userId; ?>" id="editUserForm" enctype="multipart/form-data">
             <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Account Information</h5> </div>
                         <div class="card-body">
                             <div class="row">
                                 <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control form-control-sm" id="username" value="<?php echo htmlspecialchars($userToEdit['username']); ?>" readonly disabled>
                                    <small class="form-text text-muted">Username cannot be changed.</small>
                                 </div>
                                 <div class="col-md-6 mb-3">
                                     <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                     <input type="email" class="form-control form-control-sm" id="email" name="email" value="<?php echo htmlspecialchars($userToEdit['email']); ?>" required>
                                 </div>
                             </div>
                             <div class="row">
                                 <div class="col-md-6 mb-3">
                                     <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                     <input type="text" class="form-control form-control-sm" id="first_name" name="first_name" value="<?php echo htmlspecialchars($userToEdit['first_name']); ?>" required>
                                 </div>
                                 <div class="col-md-6 mb-3">
                                     <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                     <input type="text" class="form-control form-control-sm" id="last_name" name="last_name" value="<?php echo htmlspecialchars($userToEdit['last_name']); ?>" required>
                                 </div>
                             </div>
                             <div class="mb-3">
                                <label for="bio" class="form-label">Bio</label>
                                <textarea class="form-control form-control-sm" id="bio" name="bio" rows="3" placeholder="Brief description about the user..."><?php echo htmlspecialchars($userToEdit['bio'] ?? ''); ?></textarea>
                            </div>
                         </div>
                    </div>

                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Change Password</h5> </div>
                         <div class="card-body">
                             <div class="row">
                                  <!-- Add current password field only if editing self - more complex logic needed if required -->
                                 <!-- <?php if ($isEditingSelf): ?>
                                     <div class="col-md-12 mb-3">
                                         <label for="current_password" class="form-label">Current Password</label>
                                         <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" placeholder="Required if changing own password">
                                     </div>
                                 <?php endif; ?> -->
                                 <div class="col-md-6 mb-3">
                                     <label for="new_password" class="form-label">New Password</label>
                                     <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" placeholder="Leave blank to keep current">
                                     <small class="form-text text-muted">Min 8 characters.</small>
                                 </div>
                                 <div class="col-md-6 mb-3">
                                     <label for="confirm_password" class="form-label">Confirm New Password</label>
                                     <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                                 </div>
                             </div>
                         </div>
                    </div>

                </div>

                <div class="col-lg-4">
                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Role & Status</h5> </div>
                         <div class="card-body">
                             <div class="mb-3">
                                <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="role_id" name="role_id" <?php echo $isEditingSelf ? 'disabled' : ''; ?> required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo ($userToEdit['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($isEditingSelf): ?>
                                    <small class="form-text text-muted">You cannot change your own role.</small>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="status" name="status" <?php echo $isEditingSelf ? 'disabled' : ''; ?> required>
                                    <option value="active" <?php echo ($userToEdit['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($userToEdit['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo ($userToEdit['status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                                 <?php if ($isEditingSelf): ?>
                                    <small class="form-text text-muted">You cannot change your own status.</small>
                                <?php endif; ?>
                            </div>
                         </div>
                    </div>

                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Profile Picture</h5> </div>
                         <div class="card-body">
                             <?php $currentPic = $userToEdit['profile_picture']; ?>
                             <img src="<?php echo !empty($currentPic) ? htmlspecialchars($currentPic) : 'assets/images/default-avatar.png'; ?>?t=<?php echo time();?>" alt="Profile Picture" class="profile-pic-preview" id="profile_pic_preview">
                             <input type="file" class="form-control form-control-sm" id="profile_picture" name="profile_picture" accept="image/*">
                             <small class="form-text text-muted">Upload new picture (Max 2MB).</small>
                         </div>
                    </div>

                     <div class="card mb-4">
                        <div class="card-body d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt me-1"></i> Update User</button>
                            <a href="users.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Users</a>
                        </div>
                     </div>

                </div>
             </div>
        </form>
        <?php else: // Show error if user couldn't be loaded ?>
            <div class="alert alert-danger">Could not load user data. Please check the ID or contact support.</div>
            <a href="users.php" class="btn btn-secondary btn-sm">Back to Users List</a>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')) . ' ' . date('Y'); ?>
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

             // --- Profile Picture Preview ---
            const picInput = document.getElementById('profile_picture');
            const picPreview = document.getElementById('profile_pic_preview');
            if (picInput && picPreview) {
                picInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            picPreview.src = event.target.result;
                        }
                        reader.readAsDataURL(file);
                    } else {
                        // Optional: Reset preview if invalid file selected
                        // picPreview.src = '<?php echo !empty($userToEdit['profile_picture']) ? htmlspecialchars($userToEdit['profile_picture']) : 'assets/images/default-avatar.png'; ?>';
                    }
                });
            }

             // --- Password Confirmation Validation ---
             const editUserForm = document.getElementById('editUserForm');
             const newPassword = document.getElementById('new_password');
             const confirmPassword = document.getElementById('confirm_password');
             if(editUserForm && newPassword && confirmPassword) {
                 editUserForm.addEventListener('submit', function(e) {
                     if (newPassword.value !== '' && newPassword.value !== confirmPassword.value) {
                         alert('New passwords do not match.');
                         confirmPassword.focus();
                         e.preventDefault();
                         return false;
                     }
                     if (newPassword.value !== '' && newPassword.value.length < 8) {
                         alert('New password must be at least 8 characters long.');
                         newPassword.focus();
                         e.preventDefault();
                         return false;
                     }
                     // Add current password check if needed for self-edit
                 });
             }


        }); // End DOMContentLoaded
    </script>

</body>
</html>