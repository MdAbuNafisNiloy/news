<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getSetting, timeAgo etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_users')) {
    header("Location: index.php?message=Access Denied: You cannot manage users.&type=danger");
    exit();
}

// Get current user information
$loggedInUser = getCurrentUser();

// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 06:29:16"; // Use the fixed time provided by the user

// Initialize variables
$message = '';
$messageType = '';
$users = [];
$totalUsers = 0;
$limit = 15; // Users per page
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $limit;

// --- Filtering/Sorting (Optional - Example: by role or status) ---
$filterRole = isset($_GET['role']) ? (int)$_GET['role'] : null;
$filterStatus = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all'; // e.g., 'all', 'active', 'inactive', 'suspended'

// --- Handle Actions (GET requests - Use POST with CSRF for production) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $userId = (int)$_GET['id'];
    $validAction = false;
    $logDescription = '';

    // Prevent acting on self
    if ($userId === $loggedInUser['id']) {
        $_SESSION['message'] = "You cannot perform this action on your own account.";
        $_SESSION['message_type'] = "warning";
        header("Location: users.php?page=" . $currentPage);
        exit();
    }

    // Add CSRF token validation here in a real application

    try {
        // Fetch user details before action
        $userCheckStmt = $db->prepare("SELECT username, status FROM users WHERE id = ?");
        $userCheckStmt->execute([$userId]);
        $userToAction = $userCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userToAction) {
            throw new Exception("User not found.");
        }
        $usernameToAction = $userToAction['username'];
        $currentStatus = $userToAction['status'];

        switch ($action) {
            case 'activate':
                if ($currentStatus !== 'active') {
                    $newStatus = 'active';
                    $logDescription = "Activated user '{$usernameToAction}'";
                    $validAction = true;
                }
                break;
            case 'deactivate': // Change to 'inactive'
                 if ($currentStatus === 'active') {
                    $newStatus = 'inactive';
                    $logDescription = "Deactivated user '{$usernameToAction}'";
                    $validAction = true;
                 }
                 break;
            case 'suspend':
                if ($currentStatus !== 'suspended') {
                    $newStatus = 'suspended';
                    $logDescription = "Suspended user '{$usernameToAction}'";
                    $validAction = true;
                }
                break;
            case 'unsuspend': // Usually back to 'active' or 'inactive' depending on logic
                 if ($currentStatus === 'suspended') {
                    $newStatus = 'active'; // Or 'inactive' based on your workflow
                    $logDescription = "Unsuspended user '{$usernameToAction}' (set to active)";
                    $validAction = true;
                 }
                 break;
            case 'delete': // Permanent deletion
                 // Add extra checks if needed (e.g., cannot delete admin role, reassign content)
                 $db->beginTransaction();
                 // Optional: Delete related data first (e.g., comments, logs) or handle via FK constraints
                 $delStmt = $db->prepare("DELETE FROM users WHERE id = ?");
                 $delStmt->execute([$userId]);
                 $db->commit();
                 $message = "User '{$usernameToAction}' permanently deleted.";
                 $messageType = "success";
                 if (function_exists('logActivity')) logActivity($loggedInUser['id'], 'user_delete', 'users', $userId, "Permanently deleted user '{$usernameToAction}'");
                 $_SESSION['message'] = $message;
                 $_SESSION['message_type'] = $messageType;
                 header("Location: users.php?page=" . $currentPage);
                 exit();
                 break; // Though exit() stops script
        }

        // Perform status update if action is valid
        if ($validAction && isset($newStatus)) {
            $updateStmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $userId]);
            $message = ucfirst($logDescription) . " successfully.";
            $messageType = "success";
            if (function_exists('logActivity')) logActivity($loggedInUser['id'], 'user_status_change', 'users', $userId, $logDescription);
        } elseif (!$validAction && $action !== 'delete') {
             $message = "Invalid action or user already in the desired state.";
             $messageType = "warning";
        }

        // Store message in session and redirect
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: users.php?page=" . $currentPage);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack(); // Rollback delete if it failed
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
        error_log("User Action Error (ID: $userId, Action: $action): " . $e->getMessage());
        header("Location: users.php?page=" . $currentPage);
        exit();
    }
}

// --- Fetch Users ---
try {
    // Build WHERE clause for filtering
    $whereClauses = [];
    $params = [];
    if ($filterRole !== null) {
        $whereClauses[] = "u.role_id = :role_id";
        $params[':role_id'] = $filterRole;
    }
    if ($filterStatus !== 'all' && in_array($filterStatus, ['active', 'inactive', 'suspended'])) {
        $whereClauses[] = "u.status = :status";
        $params[':status'] = $filterStatus;
    }
    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : '';

    // Count total users for pagination with filters
    $countSql = "SELECT COUNT(*) FROM users u {$whereSql}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalUsers = $countStmt->fetchColumn();
    $totalPages = $totalUsers > 0 ? ceil($totalUsers / $limit) : 1;
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $limit;

    // Fetch users for the current page with filters and role name
    $sql = "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.registration_date, u.profile_picture, r.name as role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            {$whereSql}
            ORDER BY u.username ASC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    // Bind filter params if they exist
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val); // PDO determines type automatically
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Error loading users: " . $e->getMessage();
    $messageType = "danger";
    error_log("Users List Error: " . $e->getMessage());
}

// Get messages from session (after redirects)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Helper function for time ago (or use fallback)
if (!function_exists('timeAgo')) {
    function timeAgo($datetime, $full = false) { return $datetime ? date('M d, Y', strtotime($datetime)) : 'N/A'; }
}
// Helper function to get settings (assuming it exists)
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
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
        .card-header { background-color: #fdfdfd; border-bottom: 1px solid #e3e6f0; padding: 0.75rem 1rem; font-weight: 600; color: var(--primary-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;}
        .card-header h5, .card-header h6 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 0; } /* Remove padding for table */
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Tables */
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; margin-bottom: 0; }
        .table thead th { font-size: 0.75rem; text-transform: uppercase; color: #858796; font-weight: 600; background-color: #f8f9fc; border-top: 0; border-bottom: 2px solid #e3e6f0; padding: 0.75rem; white-space: nowrap; vertical-align: middle;}
        .table td { vertical-align: middle; padding: 0.5rem 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .table .actions { white-space: nowrap; }
        .table .actions a, .table .actions button { margin: 0 0.15rem; display: inline-block; }
        .table .actions .btn-sm { padding: 0.15rem 0.4rem; font-size: 0.75rem; }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; margin-right: 10px; object-fit: cover; }
        .user-info { display: flex; align-items: center; }
        .user-info span { font-weight: 500; }
        .user-info small { display: block; font-size: 0.75rem; color: #858796; }
        .badge { font-weight: 600; padding: 0.3em 0.6em; font-size: 0.7rem; }
        /* Pagination */
        .pagination { margin-bottom: 0; }
        .pagination .page-link { font-size: 0.85rem; padding: 0.4rem 0.75rem;}
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
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_categories')): ?> <a href="pages.php" class="sidebar-item" title="Pages"> <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item active" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_roles')): ?> <a href="roles.php" class="sidebar-item" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <?php endif; ?>
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
            <h2><i class="fas fa-users me-2"></i> Manage Users</h2>
            <p>View, edit, and manage user accounts.</p>
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

        <!-- Users Card -->
        <div class="card">
            <div class="card-header">
                 <h6 class="m-0">Users List</h6>
                 <a href="user-new.php" class="btn btn-primary btn-sm">
                     <i class="fas fa-plus me-1"></i> Add New User
                 </a>
                 <!-- Optional: Add filter dropdowns here if needed -->
            </div>
            <div class="card-body p-0">
                <?php if (!empty($users)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                 <img class="user-avatar" src="<?php echo !empty($user['profile_picture']) ? htmlspecialchars($user['profile_picture']) : 'assets/images/default-avatar.png'; ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
                                                 <div>
                                                     <span><?php echo htmlspecialchars($user['username']); ?></span>
                                                     <small><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></small>
                                                 </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                                $statusClass = match($user['status']) {
                                                    'active' => 'success', 'inactive' => 'secondary', 'suspended' => 'danger', default => 'info'
                                                };
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span>
                                        </td>
                                        <td><?php echo timeAgo($user['registration_date']); ?></td>
                                        <td class="text-end actions">
                                            <?php $baseUrl = "users.php?id={$user['id']}&page={$currentPage}"; ?>
                                            <a href="user-edit.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit User"><i class="fas fa-edit"></i></a>
                                            <?php if ($user['id'] !== $loggedInUser['id']): // Don't show status/delete for self ?>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=deactivate" class="btn btn-sm btn-outline-warning" title="Deactivate User"><i class="fas fa-user-slash"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=suspend" class="btn btn-sm btn-outline-danger" title="Suspend User"><i class="fas fa-user-lock"></i></a>
                                                <?php elseif ($user['status'] === 'inactive'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=activate" class="btn btn-sm btn-outline-success" title="Activate User"><i class="fas fa-user-check"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=suspend" class="btn btn-sm btn-outline-danger" title="Suspend User"><i class="fas fa-user-lock"></i></a>
                                                <?php elseif ($user['status'] === 'suspended'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=unsuspend" class="btn btn-sm btn-outline-success" title="Unsuspend User"><i class="fas fa-unlock"></i></a>
                                                <?php endif; ?>
                                                <a href="<?php echo $baseUrl; ?>&action=delete" class="btn btn-sm btn-outline-danger" title="Delete User Permanently" onclick="return confirm('Are you sure you want to permanently delete user \'<?php echo htmlspecialchars(addslashes($user['username'])); ?>\'? This cannot be undone.');"><i class="fas fa-trash-alt"></i></a>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">(Your Account)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted p-4 mb-0">No users found.</p>
                <?php endif; ?>
            </div>
             <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-center">
                <nav aria-label="Users pagination">
                    <ul class="pagination mb-0">
                        <?php if ($currentPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $currentPage - 1; // Add other filters if used ?>">Previous</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Previous</span></li>
                        <?php endif; ?>

                        <?php
                        // Simple pagination links (limit number shown if many pages)
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);

                        if ($startPage > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';

                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo ($i == $currentPage) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; // Add other filters if used ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor;

                        if ($endPage < $totalPages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $currentPage + 1; // Add other filters if used ?>">Next</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Next</span></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>

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

            // Confirmations are handled inline via onclick for simplicity here

        }); // End DOMContentLoaded
    </script>

</body>
</html>