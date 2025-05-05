<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getSetting, timeAgo etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_comments')) {
    header("Location: index.php?message=Access Denied: You cannot manage comments.&type=danger");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 06:21:43"; // Use the fixed time provided by the user

// Initialize variables
$message = '';
$messageType = '';
$comments = [];
$totalComments = 0;
$limit = 15; // Comments per page
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $limit;

// --- Filtering ---
$allowedStatuses = ['all', 'pending', 'approved', 'spam', 'trash'];
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses) ? $_GET['status'] : 'pending'; // Default to pending

// --- Handle Actions (GET requests - Use POST with CSRF for production) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $commentId = (int)$_GET['id'];
    $validAction = false;
    $newStatus = null;
    $logDescription = '';

    // Add CSRF token validation here in a real application

    try {
        // Fetch comment status before action
        $statusCheckStmt = $db->prepare("SELECT status FROM comments WHERE id = ?");
        $statusCheckStmt->execute([$commentId]);
        $currentStatus = $statusCheckStmt->fetchColumn();

        if ($currentStatus === false) {
            throw new Exception("Comment not found.");
        }

        switch ($action) {
            case 'approve':
                if ($currentStatus !== 'approved') {
                    $newStatus = 'approved';
                    $logDescription = 'Approved comment';
                    $validAction = true;
                }
                break;
            case 'unapprove':
                 if ($currentStatus === 'approved') {
                    $newStatus = 'pending';
                    $logDescription = 'Unapproved comment (marked as pending)';
                    $validAction = true;
                 }
                 break;
            case 'spam':
                if ($currentStatus !== 'spam') {
                    $newStatus = 'spam';
                    $logDescription = 'Marked comment as spam';
                    $validAction = true;
                }
                break;
            case 'unspam': // Action to move out of spam (usually back to pending)
                if ($currentStatus === 'spam') {
                    $newStatus = 'pending';
                    $logDescription = 'Marked comment as not spam (pending)';
                    $validAction = true;
                }
                break;
            case 'trash':
                 if ($currentStatus !== 'trash') {
                    $newStatus = 'trash';
                    $logDescription = 'Moved comment to trash';
                    $validAction = true;
                 }
                 break;
            case 'restore': // Action to move out of trash (usually back to pending)
                 if ($currentStatus === 'trash') {
                    $newStatus = 'pending';
                    $logDescription = 'Restored comment from trash (pending)';
                    $validAction = true;
                 }
                 break;
            case 'delete': // Permanent deletion
                 if ($currentStatus === 'trash' || $currentStatus === 'spam') { // Allow delete from trash or spam
                    $delStmt = $db->prepare("DELETE FROM comments WHERE id = ?");
                    $delStmt->execute([$commentId]);
                    $message = "Comment permanently deleted.";
                    $messageType = "success";
                    if (function_exists('logActivity')) logActivity($currentUser['id'], 'comment_delete', 'comments', $commentId, 'Permanently deleted comment');
                    // No status update needed, redirect immediately
                    $_SESSION['message'] = $message;
                    $_SESSION['message_type'] = $messageType;
                    header("Location: comments.php?status=" . urlencode($filterStatus) . "&page=" . $currentPage); // Redirect back
                    exit();
                 } else {
                    throw new Exception("Comment must be in trash or spam to be deleted permanently.");
                 }
                 break;
        }

        // Perform status update if action is valid and status changed
        if ($validAction && $newStatus !== null) {
            $updateStmt = $db->prepare("UPDATE comments SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $commentId]);
            $message = ucfirst($logDescription) . " successfully.";
            $messageType = "success";
            if (function_exists('logActivity')) logActivity($currentUser['id'], 'comment_status_change', 'comments', $commentId, $logDescription . ' ID ' . $commentId);
        } elseif (!$validAction && $action !== 'delete') { // Avoid warning if action was delete
             $message = "Invalid action or comment already in the desired state.";
             $messageType = "warning";
        }

        // Store message in session and redirect (if not already exited for delete)
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header("Location: comments.php?status=" . urlencode($filterStatus) . "&page=" . $currentPage); // Redirect back
        exit();

    } catch (Exception $e) {
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
        error_log("Comment Action Error (ID: $commentId, Action: $action): " . $e->getMessage());
        header("Location: comments.php?status=" . urlencode($filterStatus) . "&page=" . $currentPage); // Redirect back
        exit();
    }
}

// --- Fetch Comments ---
try {
    // Build WHERE clause based on filter
    $whereClause = '';
    $params = [];
    if ($filterStatus !== 'all') {
        $whereClause = "WHERE c.status = :status";
        $params[':status'] = $filterStatus;
    }

    // Count total comments for pagination
    $countSql = "SELECT COUNT(*) FROM comments c {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalComments = $countStmt->fetchColumn();
    $totalPages = $totalComments > 0 ? ceil($totalComments / $limit) : 1; // Ensure totalPages is at least 1
    $currentPage = min($currentPage, $totalPages); // Adjust current page if out of bounds
    $offset = ($currentPage - 1) * $limit; // Recalculate offset

    // Fetch comments for the current page
    $sql = "SELECT c.id, c.content, c.status, c.created_at, c.parent_id,
                   u.username as author_username, u.profile_picture as author_avatar, u.email as author_email,
                   a.title as article_title, a.slug as article_slug
            FROM comments c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN articles a ON c.article_id = a.id
            {$whereClause}
            ORDER BY c.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    // Bind status param if needed
    if ($filterStatus !== 'all') {
        $stmt->bindParam(':status', $filterStatus, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Error loading comments: " . $e->getMessage();
    $messageType = "danger";
    error_log("Comments List Error: " . $e->getMessage());
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
    function timeAgo($datetime, $full = false) { return date('M d, Y H:i', strtotime($datetime)); }
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
    <title>Manage Comments - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
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
        .table td { vertical-align: middle; padding: 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .table .actions { white-space: nowrap; opacity: 0; transition: opacity 0.2s; } /* Hide actions initially */
        tr:hover .table .actions { opacity: 1; } /* Show actions on row hover */
        .table .actions a, .table .actions button { margin: 0 0.15rem; display: inline-block; }
        .table .actions .btn-sm { padding: 0.15rem 0.4rem; font-size: 0.75rem; }
        .comment-author img { width: 32px; height: 32px; border-radius: 50%; margin-right: 8px; object-fit: cover; }
        .comment-author span { font-weight: 500; }
        .comment-author small { display: block; font-size: 0.75rem; color: #858796; }
        .comment-content p { margin-bottom: 0.25rem; font-size: 0.9rem; line-height: 1.5; }
        .comment-content .meta { font-size: 0.75rem; color: #858796; margin-top: 0.3rem; }
        .comment-content .meta a { color: #858796; text-decoration: none; }
        .comment-content .meta a:hover { color: var(--primary-color); text-decoration: underline;}
        .badge { font-weight: 600; padding: 0.3em 0.6em; font-size: 0.7rem; }
        /* Filters */
        .comment-filters { margin-bottom: 1rem; padding: 0 1rem; border-bottom: 1px solid #e3e6f0;}
        .comment-filters .nav-link { font-size: 0.9rem; padding: 0.5rem 1rem; color: var(--dark-color); border: none; border-bottom: 3px solid transparent; border-radius: 0; margin-bottom: -1px; /* Overlap border */ }
        .comment-filters .nav-link.active { color: var(--primary-color); border-bottom-color: var(--primary-color); background: none; }
        .comment-filters .nav-link:hover { color: var(--primary-color); }
        /* Pagination */
        .pagination { margin-bottom: 0; }
        .pagination .page-link { font-size: 0.85rem; padding: 0.4rem 0.75rem;}
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } .comment-filters { padding: 0 0.5rem; } .comment-filters .nav-link { padding: 0.5rem 0.75rem; } }
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
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item active" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
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
                    <img src="<?php echo !empty($currentUser['profile_picture']) ? htmlspecialchars($currentUser['profile_picture']) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile">
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

        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-comments me-2"></i> Manage Comments</h2>
            <p>Approve, edit, or delete comments submitted by users.</p>
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

        <!-- Comments Card -->
        <div class="card">
            <div class="card-header">
                 <h6 class="m-0">Comments List</h6>
                 <!-- Filters -->
                 <ul class="nav nav-pills comment-filters">
                    <?php foreach ($allowedStatuses as $status): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($filterStatus === $status) ? 'active' : ''; ?>"
                           href="comments.php?status=<?php echo $status; ?>">
                           <?php echo ucfirst($status); ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($comments)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Author</th>
                                    <th style="width: 50%;">Comment</th>
                                    <th style="width: 30%;">In Response To</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comments as $comment): ?>
                                    <tr>
                                        <td class="comment-author">
                                            <img src="<?php echo !empty($comment['author_avatar']) ? htmlspecialchars($comment['author_avatar']) : 'assets/images/default-avatar.png'; ?>" alt="">
                                            <span><?php echo htmlspecialchars($comment['author_username'] ?? 'Guest'); ?></span>
                                            <small><?php echo htmlspecialchars($comment['author_email'] ?? ''); ?></small>
                                        </td>
                                        <td class="comment-content">
                                            <p><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                                            <div class="meta">
                                                Submitted <?php echo timeAgo($comment['created_at']); ?> | Status:
                                                <?php
                                                    $statusClass = match($comment['status']) {
                                                        'approved' => 'success', 'pending' => 'warning',
                                                        'spam' => 'danger', 'trash' => 'secondary', default => 'info'
                                                    };
                                                    $statusText = match($comment['status']) {
                                                        'approved' => 'Approved', 'pending' => 'Pending',
                                                        'spam' => 'Spam', 'trash' => 'Trash', default => ucfirst($comment['status'])
                                                    };
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </div>
                                            <div class="actions mt-1">
                                                <?php $baseUrl = "comments.php?id={$comment['id']}&status={$filterStatus}&page={$currentPage}"; ?>
                                                <?php if ($comment['status'] === 'pending'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=approve" class="btn btn-sm btn-outline-success" title="Approve"><i class="fas fa-check"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=spam" class="btn btn-sm btn-outline-warning" title="Mark as Spam"><i class="fas fa-shield-alt"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=trash" class="btn btn-sm btn-outline-secondary" title="Move to Trash"><i class="fas fa-trash"></i></a>
                                                <?php elseif ($comment['status'] === 'approved'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=unapprove" class="btn btn-sm btn-outline-secondary" title="Unapprove (Mark as Pending)"><i class="fas fa-undo"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=spam" class="btn btn-sm btn-outline-warning" title="Mark as Spam"><i class="fas fa-shield-alt"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=trash" class="btn btn-sm btn-outline-secondary" title="Move to Trash"><i class="fas fa-trash"></i></a>
                                                <?php elseif ($comment['status'] === 'spam'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=unspam" class="btn btn-sm btn-outline-success" title="Not Spam (Mark as Pending)"><i class="fas fa-check-circle"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=delete" class="btn btn-sm btn-outline-danger" title="Delete Permanently" onclick="return confirm('Are you sure you want to permanently delete this spam comment?');"><i class="fas fa-times-circle"></i></a>
                                                <?php elseif ($comment['status'] === 'trash'): ?>
                                                    <a href="<?php echo $baseUrl; ?>&action=restore" class="btn btn-sm btn-outline-secondary" title="Restore (Mark as Pending)"><i class="fas fa-recycle"></i></a>
                                                    <a href="<?php echo $baseUrl; ?>&action=delete" class="btn btn-sm btn-outline-danger" title="Delete Permanently" onclick="return confirm('Are you sure you want to permanently delete this comment?');"><i class="fas fa-times-circle"></i></a>
                                                <?php endif; ?>
                                                 <!-- Add Edit link if needed -->
                                                <!-- <a href="comment-edit.php?id=<?php echo $comment['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a> -->
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($comment['article_title']): ?>
                                                <a href="../article.php?slug=<?php echo htmlspecialchars($comment['article_slug']); ?>#comment-<?php echo $comment['id']; ?>" target="_blank" title="<?php echo htmlspecialchars($comment['article_title']); ?>">
                                                    <?php echo htmlspecialchars(mb_strimwidth($comment['article_title'], 0, 50, '...')); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">N/A (Article Deleted?)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted p-4 mb-0">No comments found matching the criteria "<?php echo ucfirst($filterStatus); ?>".</p>
                <?php endif; ?>
            </div>
             <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-center">
                <nav aria-label="Comments pagination">
                    <ul class="pagination mb-0">
                        <?php if ($currentPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="?status=<?php echo $filterStatus; ?>&page=<?php echo $currentPage - 1; ?>">Previous</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Previous</span></li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo ($i == $currentPage) ? 'active' : ''; ?>">
                                <a class="page-link" href="?status=<?php echo $filterStatus; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?status=<?php echo $filterStatus; ?>&page=<?php echo $currentPage + 1; ?>">Next</a></li>
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

            // Optional: Add any specific JS for this page if needed, e.g., bulk actions (more complex)

        }); // End DOMContentLoaded
    </script>

</body>
</html>