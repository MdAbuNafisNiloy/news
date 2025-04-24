<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getSetting etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_roles')) {
     header("Location: index.php?message=Access Denied: You cannot manage roles.&type=danger");
     exit();
}

// Get current logged-in user (needed for header & logging)
$loggedInUser = getCurrentUser();

// Initialize variables
$roles = [];
$permissions = [];
$message = '';
$messageType = '';

// Helper function to get settings
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

// --- Fetch Initial Data ---
try {
    // Fetch all roles
    $rolesStmt = $db->query("SELECT r.*, COUNT(u.id) as user_count
                             FROM roles r
                             LEFT JOIN users u ON r.id = u.role_id
                             GROUP BY r.id
                             ORDER BY r.name");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all permissions
    $permissionsStmt = $db->query("SELECT * FROM permissions ORDER BY name");
    $permissions = $permissionsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Error loading data: " . $e->getMessage();
    $messageType = "danger";
    error_log("Roles Page Load Error: " . $e->getMessage());
}

// --- Handle POST Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $db->beginTransaction();

        // --- Create Role ---
        if ($action === 'create') {
            $name = sanitizeInput($_POST['name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $selectedPermissions = $_POST['permissions'] ?? []; // Array of permission IDs

            if (empty($name)) throw new Exception("Role name is required.");

            // Check if role name already exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM roles WHERE LOWER(name) = LOWER(?)");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetchColumn() > 0) {
                throw new Exception("Role name '{$name}' already exists.");
            }

            // Insert the role
            $insertRoleStmt = $db->prepare("INSERT INTO roles (name, description, created_at) VALUES (?, ?, NOW())");
            $insertRoleStmt->execute([$name, $description]);
            $newRoleId = $db->lastInsertId();

            // Insert permissions
            if (!empty($selectedPermissions) && $newRoleId) {
                $insertPermStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($selectedPermissions as $permId) {
                    if (is_numeric($permId)) { // Basic validation
                        $insertPermStmt->execute([$newRoleId, (int)$permId]);
                    }
                }
            }

            if (function_exists('logActivity')) {
                logActivity($loggedInUser['id'], 'role_create', 'roles', $newRoleId, "Created role: '{$name}'");
            }
            $message = "Role '{$name}' created successfully.";
            $messageType = "success";
        }
        // --- Update Role ---
        elseif ($action === 'update') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $selectedPermissions = $_POST['permissions'] ?? []; // Array of permission IDs

            if ($roleId <= 0) throw new Exception("Invalid role ID.");
            if (empty($name)) throw new Exception("Role name is required.");

            // Prevent renaming essential roles (optional, based on ID or name)
            if ($roleId === 1 || $roleId === 4) { // Example: Assuming ID 1=Admin, 4=User are protected
                 // Check original name to allow description update
                 $originalRoleStmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
                 $originalRoleStmt->execute([$roleId]);
                 $originalName = $originalRoleStmt->fetchColumn();
                 if (strtolower($name) !== strtolower($originalName)) {
                     throw new Exception("Cannot rename the essential '{$originalName}' role.");
                 }
            }

            // Check if role name already exists (for another role)
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM roles WHERE LOWER(name) = LOWER(?) AND id != ?");
            $checkStmt->execute([$name, $roleId]);
            if ($checkStmt->fetchColumn() > 0) {
                throw new Exception("Role name '{$name}' already exists.");
            }

            // Update role details
            $updateRoleStmt = $db->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
            $updateRoleStmt->execute([$name, $description, $roleId]);

            // Update permissions (delete old, insert new)
            $deletePermStmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $deletePermStmt->execute([$roleId]);

            if (!empty($selectedPermissions)) {
                $insertPermStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($selectedPermissions as $permId) {
                    if (is_numeric($permId)) {
                        $insertPermStmt->execute([$roleId, (int)$permId]);
                    }
                }
            }

            if (function_exists('logActivity')) {
                 logActivity($loggedInUser['id'], 'role_update', 'roles', $roleId, "Updated role: '{$name}'");
            }
            $message = "Role '{$name}' updated successfully.";
            $messageType = "success";
        }
        // --- Delete Role ---
        elseif ($action === 'delete') {
            $roleId = (int)($_POST['role_id'] ?? 0);

            if ($roleId <= 0) throw new Exception("Invalid role ID.");

             // Prevent deleting essential roles (e.g., Admin, User)
            if ($roleId === 1 || $roleId === 4) { // Assuming ID 1=Admin, 4=User are protected
                throw new Exception("Cannot delete essential roles.");
            }

            // Check if any users are assigned to this role
            $userCheckStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $userCheckStmt->execute([$roleId]);
            $userCount = $userCheckStmt->fetchColumn();

            if ($userCount > 0) {
                throw new Exception("Cannot delete role: {$userCount} user(s) are assigned to it. Please reassign users first.");
            }

            // Get role name for logging before deleting
            $roleNameStmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $roleNameStmt->execute([$roleId]);
            $roleName = $roleNameStmt->fetchColumn();

            // Delete permissions associated with the role
            $deletePermStmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $deletePermStmt->execute([$roleId]);

            // Delete the role itself
            $deleteRoleStmt = $db->prepare("DELETE FROM roles WHERE id = ?");
            $deleteRoleStmt->execute([$roleId]);

            if (function_exists('logActivity')) {
                logActivity($loggedInUser['id'], 'role_delete', 'roles', $roleId, "Deleted role: '{$roleName}' (ID: {$roleId})");
            }
            $message = "Role '{$roleName}' deleted successfully.";
            $messageType = "success";
        }

        $db->commit();

        // Refresh data after changes
        header("Location: roles.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("Roles Action Error ({$action}): " . $e->getMessage());
        // Data might be stale here if error occurred after initial fetch, but usually okay for display
        // Re-fetch might be needed if error was critical for display logic
    }
}

// Check for messages passed via URL (e.g., after successful action)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}

// --- AJAX Handler for getting role permissions ---
if (isset($_GET['action']) && $_GET['action'] === 'get_role_permissions' && isset($_GET['role_id'])) {
    header('Content-Type: application/json');
    $roleId = (int)$_GET['role_id'];
    $rolePerms = [];
    $roleData = ['name' => '', 'description' => ''];

    if ($roleId > 0) {
        try {
            // Get role details
            $roleStmt = $db->prepare("SELECT name, description FROM roles WHERE id = ?");
            $roleStmt->execute([$roleId]);
            $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: $roleData;

            // Get role permissions (fetch as integers)
            $stmt = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            // Fetch permission IDs as an array of integers directly
            $rolePerms = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            // Ensure they are integers (though PDO should handle this if the column is INT)
            $rolePerms = array_map('intval', $rolePerms);


        } catch (PDOException $e) {
            // Log error, return empty array
            error_log("AJAX Get Role Permissions Error: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to fetch permissions']);
            exit;
        }
    }
    // Return the permission IDs as an array of numbers
    echo json_encode(['permissions' => $rolePerms, 'details' => $roleData]);
    exit;
}


// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 06:44:06"; // <<< UPDATED TIME

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Roles - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
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
        .card-header { background-color: #fdfdfd; border-bottom: 1px solid #e3e6f0; padding: 0.75rem 1rem; font-weight: 600; color: var(--primary-color); display: flex; justify-content: space-between; align-items: center; }
        .card-header h5 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1.25rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Forms & Modals */
        .form-label { font-weight: 500; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .form-text { font-size: 0.75rem; }
        .form-control, .form-select { font-size: 0.9rem; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; }
        .modal-header { border-bottom: none; }
        .modal-footer { border-top: none; }
        .permission-group { margin-bottom: 0.75rem; }
        .permission-list { max-height: 300px; overflow-y: auto; padding: 0.5rem; border: 1px solid #eee; border-radius: 0.25rem; background-color: #f8f9fa; }
        .form-check-label { font-size: 0.85rem; display: block; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Table */
        .table th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; background-color: #f8f9fc; color: var(--primary-color); }
        .table td { vertical-align: middle; font-size: 0.9rem; }
        .table .actions a, .table .actions button { margin-right: 0.25rem; }
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
            <?php if (hasPermission('manage_categories')): ?>
                <a href="categories.php" class="sidebar-item" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a>
                <a href="pages.php" class="sidebar-item" title="Pages"> <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span> </a>
            <?php endif; ?>
			  <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
             <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
             <?php if (hasPermission('manage_roles')): ?> <a href="roles.php" class="sidebar-item active" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <?php endif; ?>
          
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
            <h2><i class="fas fa-user-tag me-2"></i> Role Management</h2>
            <p>Define user roles and assign specific permissions.</p>
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

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Roles</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#roleModal" data-action="create">
                    <i class="fas fa-plus me-1"></i> Add New Role
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($roles) && empty($message)): ?>
                    <div class="alert alert-info mb-0 border-0 rounded-0">No roles found. Add a new role to get started.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th class="text-center">Users</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($role['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($role['description'] ?: '-'); ?></td>
                                <td class="text-center"><?php echo $role['user_count']; ?></td>
                                <td class="text-end actions">
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#roleModal"
                                            data-action="edit" data-role-id="<?php echo $role['id']; ?>"
                                            title="Edit Role">
                                        <i class="fas fa-edit"></i> <span class="d-none d-md-inline">Edit</span>
                                    </button>
                                    <?php if ($role['id'] != 1 && $role['id'] != 4): // Prevent deleting Admin/User ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-role-btn"
                                                data-role-id="<?php echo $role['id']; ?>"
                                                data-role-name="<?php echo htmlspecialchars($role['name']); ?>"
                                                data-user-count="<?php echo $role['user_count']; ?>"
                                                title="Delete Role">
                                            <i class="fas fa-trash-alt"></i> <span class="d-none d-md-inline">Delete</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Cannot delete essential roles">
                                            <i class="fas fa-trash-alt"></i> <span class="d-none d-md-inline">Delete</span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')) . ' ' . date('Y'); ?>
        </footer>

    </div> <!-- End Main Content -->

    <!-- Add/Edit Role Modal -->
    <div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="roles.php" id="roleForm">
                    <input type="hidden" name="action" id="form_action">
                    <input type="hidden" name="role_id" id="form_role_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="roleModalLabel">Add New Role</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="modal_error_message" class="alert alert-danger d-none"></div>
                        <div class="mb-3">
                            <label for="form_name" class="form-label">Role Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="form_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="form_description" class="form-label">Description</label>
                            <textarea class="form-control form-control-sm" id="form_description" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3 permission-group">
                            <label class="form-label">Permissions</label>
                            <div class="permission-list">
                                <?php if (empty($permissions)): ?>
                                    <p class="text-muted">No permissions defined in the system.</p>
                                <?php else: ?>
                                    <?php foreach ($permissions as $permission): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $permission['id']; ?>" id="perm_<?php echo $permission['id']; ?>">
                                            <label class="form-check-label" for="perm_<?php echo $permission['id']; ?>" title="<?php echo htmlspecialchars($permission['description']); ?>">
                                                <?php echo htmlspecialchars($permission['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                             <small class="form-text text-muted">Select the permissions this role should have.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="saveRoleButton">Save Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-labelledby="deleteRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                 <form method="POST" action="roles.php" id="deleteRoleForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="role_id" id="delete_role_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteRoleModalLabel">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the role: <strong id="delete_role_name_display"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> This action cannot be undone.</p>
                        <div id="delete_warning_users" class="alert alert-warning d-none">
                           Warning: <span id="delete_user_count_display">0</span> user(s) are currently assigned to this role. You must reassign them before deleting.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="confirmDeleteRoleButton">Delete Role</button>
                    </div>
                 </form>
            </div>
        </div>
    </div>


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

            // --- Add/Edit Role Modal Logic ---
            const roleModal = document.getElementById('roleModal');
            const roleForm = document.getElementById('roleForm');
            const modalTitle = document.getElementById('roleModalLabel');
            const formAction = document.getElementById('form_action');
            const formRoleId = document.getElementById('form_role_id');
            const formName = document.getElementById('form_name');
            const formDescription = document.getElementById('form_description');
            const modalErrorMessage = document.getElementById('modal_error_message');
            const permissionCheckboxes = roleForm.querySelectorAll('input[name="permissions[]"]');

            roleModal.addEventListener('show.bs.modal', async function (event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-action');

                // Reset form
                roleForm.reset();
                formRoleId.value = '';
                modalErrorMessage.classList.add('d-none');
                modalErrorMessage.textContent = '';
                permissionCheckboxes.forEach(cb => cb.checked = false); // Ensure all are unchecked initially

                if (action === 'create') {
                    modalTitle.textContent = 'Add New Role';
                    formAction.value = 'create';
                } else if (action === 'edit') {
                    modalTitle.textContent = 'Edit Role';
                    formAction.value = 'update';
                    const roleId = button.getAttribute('data-role-id');
                    formRoleId.value = roleId;

                    // Fetch role details and permissions via AJAX
                    try {
                        const response = await fetch(`roles.php?action=get_role_permissions&role_id=${roleId}`);
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        const data = await response.json();

                        if (data.error) {
                             throw new Error(data.error);
                        }

                        formName.value = data.details.name || '';
                        formDescription.value = data.details.description || '';

                        // Check the permissions for this role
                        // Ensure data.permissions is an array and contains numbers (or strings that can be compared)
                        if (Array.isArray(data.permissions)) {
                            permissionCheckboxes.forEach(cb => {
                                // Convert checkbox value to number for comparison if needed,
                                // or ensure data.permissions contains strings if checkbox values are strings
                                const permissionId = parseInt(cb.value, 10); // Assuming checkbox value is the ID (number)
                                if (data.permissions.includes(permissionId)) {
                                    cb.checked = true;
                                }
                            });
                        }

                    } catch (error) {
                        console.error('Error fetching role details:', error);
                        modalErrorMessage.textContent = 'Error loading role details: ' + error.message;
                        modalErrorMessage.classList.remove('d-none');
                        // Optionally disable form submission
                        // document.getElementById('saveRoleButton').disabled = true;
                    }
                }
            });

            // --- Delete Role Modal Logic ---
            const deleteRoleModal = document.getElementById('deleteRoleModal');
            const deleteRoleForm = document.getElementById('deleteRoleForm');
            const deleteRoleIdInput = document.getElementById('delete_role_id');
            const deleteRoleNameDisplay = document.getElementById('delete_role_name_display');
            const deleteWarningUsers = document.getElementById('delete_warning_users');
            const deleteUserCountDisplay = document.getElementById('delete_user_count_display');
            const confirmDeleteButton = document.getElementById('confirmDeleteRoleButton');

            document.querySelectorAll('.delete-role-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const roleId = this.getAttribute('data-role-id');
                    const roleName = this.getAttribute('data-role-name');
                    const userCount = parseInt(this.getAttribute('data-user-count') || '0', 10);

                    deleteRoleIdInput.value = roleId;
                    deleteRoleNameDisplay.textContent = roleName;

                    if (userCount > 0) {
                        deleteUserCountDisplay.textContent = userCount;
                        deleteWarningUsers.classList.remove('d-none');
                        confirmDeleteButton.disabled = true; // Disable delete if users assigned
                    } else {
                        deleteWarningUsers.classList.add('d-none');
                        confirmDeleteButton.disabled = false;
                    }

                    const bsModal = new bootstrap.Modal(deleteRoleModal);
                    bsModal.show();
                });
            });

        }); // End DOMContentLoaded
    </script>

</body>
</html>