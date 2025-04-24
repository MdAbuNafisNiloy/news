<?php
// Start session for user authentication
session_start();

// Database configuration
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Make sure this includes getRecentActivityLogs & timeAgo
require_once 'includes/auth.php';

// Check if user is logged in and has access to dashboard
if (!isLoggedIn() || !hasPermission('access_dashboard')) {
    header("Location: login.php");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();
$userRole = $currentUser ? getUserRole($currentUser['role_id']) : null;

// Get dashboard statistics
try {
    $totalArticles = countArticles();
    $pendingArticles = countArticlesByStatus('pending');
    $publishedArticles = countArticlesByStatus('published');
    $totalUsers = countUsers();
    $totalComments = countComments();
    $pendingComments = countCommentsByStatus('pending');

    // Get recent items
    $recentArticles = getRecentArticles(5);
    $recentUsers = getRecentUsers(5);
    $recentComments = getRecentComments(5);

    // *** Fetch recent activities ***
    $recentActivities = getRecentActivityLogs(5); // Fetch 5 recent activities

} catch (Exception $e) {
    $errorMessage = "Error fetching dashboard data: " . $e->getMessage();
    error_log($errorMessage);
    $totalArticles = $pendingArticles = $publishedArticles = $totalUsers = $totalComments = $pendingComments = 0;
    $recentArticles = $recentUsers = $recentComments = $recentActivities = []; // Ensure activities is empty array on error
}

// User's current time
date_default_timezone_set('UTC'); // Or user's timezone if available
$currentTime = date('Y-m-d H:i:s T');

// Use the provided UTC time for the header display
$headerUtcTime = '2025-04-20 04:42:52'; // From user input

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Same CSS as previous correct version --- */
        :root {
            --primary-color: #4e73df;
            --secondary-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
            --sidebar-width: 220px;
            --sidebar-width-collapsed: 90px; /* Slightly wider collapsed */
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
            overflow-x: hidden; /* Prevent horizontal scroll on body */
        }

        /* --- Sidebar --- */
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color) 10%, #224abe 100%);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1030;
            width: var(--sidebar-width);
            transition: width 0.3s ease-in-out;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
            text-align: center;
        }
        .sidebar.collapsed .sidebar-brand { padding: 1.5rem 0.5rem; font-size: 0.8rem; }
        .sidebar.collapsed .sidebar-brand span { display: none; }
        .sidebar.collapsed .sidebar-item { padding: 0.75rem; justify-content: center; }
        .sidebar.collapsed .sidebar-item i { margin-right: 0; }
        .sidebar.collapsed .sidebar-item span { display: none; }

        .sidebar-brand {
            height: 4.375rem; /* Standard height */
            padding: 1.5rem 1rem;
            color: #fff;
            text-align: center;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.05rem;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-decoration: none;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-brand i { vertical-align: middle; }

        .sidebar-items { margin-top: 1rem; }
        .sidebar-item {
            padding: 0.75rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            transition: background-color 0.2s, color 0.2s;
            display: flex;
            align-items: center;
            margin: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            text-decoration: none;
            white-space: nowrap;
        }
        .sidebar-item.active, .sidebar-item:hover { background-color: rgba(255, 255, 255, 0.1); color: #fff; }
        .sidebar-item i {
            margin-right: 0.75rem;
            opacity: 0.8;
            width: 1.25em;
            text-align: center;
            flex-shrink: 0;
        }

        /* --- Main Content --- */
        .main-content {
            padding: 1.5rem;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease-in-out;
            /* width calculation removed, let it flow naturally */
        }
        .main-content.expanded {
            margin-left: var(--sidebar-width-collapsed);
        }

        /* --- Top Bar --- */
        .top-bar {
            background: #fff;
            margin-bottom: 1.5rem;
            padding: 0.75rem 1rem;
            border-radius: 0.35rem;
            box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .top-bar .dropdown-toggle::after { display: none; }

        /* --- General Card --- */
        .card {
            border: none;
            border-radius: 0.35rem;
            box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s ease-out;
        }
        .card:hover { transform: translateY(-2px); }
        .card-header {
            background-color: #fdfdfd;
            border-bottom: 1px solid #e3e6f0;
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .card-body { padding: 1rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }

        /* --- Stat Cards --- */
        .stat-card {
            border-left: 4px solid;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem; /* Slightly less padding */
        }
        .stat-card.primary { border-left-color: var(--primary-color); }
        .stat-card.success { border-left-color: var(--secondary-color); }
        .stat-card.warning { border-left-color: var(--warning-color); }
        .stat-card.info { border-left-color: var(--info-color); }
        .stat-title { text-transform: uppercase; font-size: 0.7rem; font-weight: 700; color: #858796; } /* Adjusted color/size */
        .stat-value { color: var(--dark-color); font-size: 1.25rem; font-weight: 600; margin-bottom: 0; }
        .stat-icon { font-size: 1.8rem; opacity: 0.2; }
        .stat-card.primary .stat-icon { color: var(--primary-color); }
        .stat-card.success .stat-icon { color: var(--secondary-color); }
        .stat-card.warning .stat-icon { color: var(--warning-color); }
        .stat-card.info .stat-icon { color: var(--info-color); }

        /* --- Welcome & Time --- */
        .user-welcome { font-size: 1.5rem; font-weight: 400; color: #5a5c69; margin-bottom: 0.25rem; }
        .current-time { color: #858796; font-size: 0.8rem; margin-bottom: 1.5rem; }
        .header-meta { font-size: 0.75rem; color: #858796; margin-bottom: 1.5rem; text-align: right; margin-top: -1rem; }


        /* --- Tables --- */
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; margin-bottom: 0; }
        .table thead th { font-size: 0.75rem; text-transform: uppercase; color: #858796; font-weight: 600; background-color: #f8f9fc; border-top: 0; border-bottom: 2px solid #e3e6f0; padding: 0.6rem 0.75rem; white-space: nowrap; }
        .table td { vertical-align: middle; padding: 0.6rem 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }

        /* --- Badges --- */
        .badge { font-weight: 600; padding: 0.3em 0.6em; font-size: 0.7rem; }

        /* --- Activity Feed --- */
        .activity-feed { padding: 0; list-style: none; max-height: 300px; overflow-y: auto; /* Add scroll if feed is long */ }
        .activity-feed .feed-item { position: relative; padding-bottom: 1rem; padding-left: 1.75rem; border-left: 2px solid #e9ecef; }
        .activity-feed .feed-item:last-child { border-color: transparent; padding-bottom: 0; }
        .activity-feed .feed-item::before { content: ''; position: absolute; width: 12px; height: 12px; border-radius: 50%; background: #fff; border: 2px solid var(--primary-color); left: -7px; top: 4px; /* Adjusted top */ }
        .activity-feed .feed-item .date { display: block; color: #999; font-size: 0.7rem; line-height: 1.2; }
        .activity-feed .feed-item .text { position: relative; font-size: 0.85rem; }
        .activity-feed .feed-item .text strong { color: var(--primary-color); }


        /* --- Quick Actions --- */
        .quick-actions .btn { display: flex; align-items: center; justify-content: center; font-size: 0.85rem; padding: 0.5rem; }
        .quick-actions .btn i { margin-right: 0.5rem; }

        /* --- Responsive --- */
        @media (max-width: 768px) {
            /* Sidebar collapsed state handled by JS */
            .main-content { padding: 1rem; }
            .user-welcome { font-size: 1.3rem; }
            .stat-card { flex-direction: column; align-items: flex-start; }
            .stat-icon { align-self: flex-end; position: absolute; bottom: 0.5rem; right: 1rem; }
            .top-bar .dropdown span { display: none; } /* Hide username text */
            .header-meta { text-align: left; margin-top: 0.5rem; }
        }
         @media (min-width: 769px) {
             /* Ensure main content doesn't get too wide on large screens */
            .main-content { max-width: calc(100% - var(--sidebar-width)); }
            .main-content.expanded { max-width: calc(100% - var(--sidebar-width-collapsed)); }
         }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <a href="index.php" class="sidebar-brand" title="<?php echo htmlspecialchars(SITE_NAME); ?>">
            <i class="fas fa-newspaper fa-fw"></i> <span><?php echo htmlspecialchars(SITE_NAME); ?></span>
        </a>
        <div class="sidebar-items">
            <a href="index.php" class="sidebar-item active" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
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
            <a href="profile.php" class="sidebar-item" title="Profile"> <i class="fas fa-user-circle fa-fw"></i> <span>Profile</span> </a>
            <a href="logout.php" class="sidebar-item" title="Logout"> <i class="fas fa-sign-out-alt fa-fw"></i> <span>Logout</span> </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
             <!-- Hamburger Button -->
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <!-- User Dropdown -->
            <div class="dropdown profile-dropdown">
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

        <!-- Welcome & Time -->
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
             <div class="user-welcome"> Hello, <?php echo htmlspecialchars($currentUser['first_name'] ?? $currentUser['username']); ?> 👋 </div>
             <div class="header-meta">
                 User: <?php echo htmlspecialchars($currentUser['username']); ?> | UTC: <?php echo $headerUtcTime; ?> <br>
                 <span id="currentTimeDisplay">Current Client Time: ...</span>
             </div>
        </div>


        <!-- Stats Cards -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-4 mb-4">
            <div class="col"> <div class="card h-100"> <div class="card-body stat-card primary"> <div> <div class="stat-title">Total Articles</div> <div class="stat-value"><?php echo $totalArticles; ?></div> </div> <div class="stat-icon"><i class="fas fa-newspaper"></i></div> </div> </div> </div>
            <div class="col"> <div class="card h-100"> <div class="card-body stat-card success"> <div> <div class="stat-title">Published</div> <div class="stat-value"><?php echo $publishedArticles; ?></div> </div> <div class="stat-icon"><i class="fas fa-check-circle"></i></div> </div> </div> </div>
            <div class="col"> <div class="card h-100"> <div class="card-body stat-card warning"> <div> <div class="stat-title">Pending Articles</div> <div class="stat-value"><?php echo $pendingArticles; ?></div> </div> <div class="stat-icon"><i class="fas fa-clock"></i></div> </div> </div> </div>
            <div class="col"> <div class="card h-100"> <div class="card-body stat-card info"> <div> <div class="stat-title">Total Comments</div> <div class="stat-value"><?php echo $totalComments; ?></div> </div> <div class="stat-icon"><i class="fas fa-comments"></i></div> </div> </div> </div>
        </div>

        <!-- Main Content Sections -->
        <div class="row">
            <!-- Left Column -->
            <div class="col-xl-8 col-lg-7">
                <!-- Recent Articles Card -->
                <div class="card mb-4">
                    <div class="card-header"> <h6 class="m-0">Recent Articles</h6> <a href="articles.php" class="btn btn-sm btn-outline-primary">View All</a> </div>
                    <div class="card-body p-0"> <div class="table-responsive"> <table class="table">
                        <thead> <tr> <th>Title</th> <th>Author</th> <th>Category</th> <th>Status</th> <th>Date</th> </tr> </thead>
                        <tbody> <?php if (!empty($recentArticles)): foreach ($recentArticles as $article): ?>
                            <tr> <td><a href="article-edit.php?id=<?php echo $article['id']; ?>" title="<?php echo htmlspecialchars($article['title']); ?>"><?php echo htmlspecialchars(mb_strimwidth($article['title'], 0, 35, '...')); ?></a></td> <td><?php echo htmlspecialchars($article['author_name']); ?></td> <td><small><?php echo htmlspecialchars($article['category_name'] ?? 'N/A'); ?></small></td> <td> <?php $statusClass = match($article['status']) {'published' => 'success', 'pending' => 'warning text-dark', 'draft' => 'secondary', default => 'info'}; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($article['status'])); ?></span> </td> <td><small><?php echo date('M d, Y', strtotime($article['created_at'])); ?></small></td> </tr>
                        <?php endforeach; else: ?> <tr><td colspan="5" class="text-center text-muted p-3">No recent articles.</td></tr> <?php endif; ?> </tbody>
                    </table> </div> </div>
                </div>
                <!-- Recent Comments & Users Row -->
                <div class="row">
                    <div class="col-lg-6"> <div class="card mb-4">
                        <div class="card-header"> <h6 class="m-0">Recent Comments</h6> <a href="comments.php" class="btn btn-sm btn-outline-primary">View All</a> </div>
                        <div class="card-body p-0"> <div class="table-responsive"> <table class="table">
                            <thead> <tr> <th>User</th> <th>Comment</th> <th>Status</th> </tr> </thead>
                            <tbody> <?php if (!empty($recentComments)): foreach ($recentComments as $comment): ?>
                                <tr> <td><?php echo htmlspecialchars($comment['username']); ?></td> <td><small><?php echo htmlspecialchars(mb_strimwidth($comment['content'], 0, 30, '...')); ?></small></td> <td> <?php $statusClass = match($comment['status']) {'approved' => 'success', 'pending' => 'warning text-dark', default => 'secondary'}; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($comment['status'])); ?></span> </td> </tr>
                            <?php endforeach; else: ?> <tr><td colspan="3" class="text-center text-muted p-3">No recent comments.</td></tr> <?php endif; ?> </tbody>
                        </table> </div> </div>
                    </div> </div>
                    <div class="col-lg-6"> <div class="card mb-4">
                        <div class="card-header"> <h6 class="m-0">Recent Users</h6> <a href="users.php" class="btn btn-sm btn-outline-primary">View All</a> </div>
                        <div class="card-body p-0"> <div class="table-responsive"> <table class="table">
                            <thead> <tr> <th>User</th> <th>Role</th> <th>Status</th> </tr> </thead>
                            <tbody> <?php if (!empty($recentUsers)): foreach ($recentUsers as $user): ?>
                                <tr> <td> <img src="<?php echo !empty($user['profile_picture']) ? htmlspecialchars($user['profile_picture']) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-2" width="24" height="24" alt=""><?php echo htmlspecialchars($user['username']); ?> </td> <td><small><?php echo htmlspecialchars($user['role_name']); ?></small></td> <td> <?php $statusClass = match($user['status']) {'active' => 'success', 'inactive' => 'secondary', default => 'danger'}; ?> <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span> </td> </tr>
                            <?php endforeach; else: ?> <tr><td colspan="3" class="text-center text-muted p-3">No recent users.</td></tr> <?php endif; ?> </tbody>
                        </table> </div> </div>
                    </div> </div>
                </div>
            </div>
            <!-- Right Column -->
            <div class="col-xl-4 col-lg-5">
                <!-- Quick Actions -->
                <div class="card mb-4 quick-actions">
                    <div class="card-header"><h6 class="m-0">Quick Actions</h6></div>
                    <div class="card-body"> <div class="row g-2">
                        <?php if (hasPermission('create_article')): ?> <div class="col-6"><a href="article-new.php" class="btn btn-sm btn-primary w-100"><i class="fas fa-plus"></i> New Article</a></div> <?php endif; ?>
                        <?php if (hasPermission('manage_categories')): ?> <div class="col-6"><a href="categories.php?action=new" class="btn btn-sm btn-info w-100 text-white"><i class="fas fa-folder-plus"></i> New Category</a></div> <?php endif; ?>
                        <div class="col-6"><a href="media.php" class="btn btn-sm btn-success w-100"><i class="fas fa-upload"></i> Upload Media</a></div>
                        <?php if (hasPermission('manage_comments')): ?> <div class="col-6"><a href="comments.php?status=pending" class="btn btn-sm btn-warning w-100 text-dark"><i class="fas fa-comment-dots"></i> Pending Comments</a></div> <?php endif; ?>
                    </div> </div>
                </div>
                <!-- Recent Activities -->
                <div class="card mb-4">
                    <div class="card-header"><h6 class="m-0">Recent Activities</h6></div>
                    <div class="card-body">
                        <?php if (!empty($recentActivities)): ?>
                            <ul class="activity-feed">
                                <?php foreach ($recentActivities as $activity): ?>
                                    <li class="feed-item">
                                        <div class="text">
                                            <strong><?php echo htmlspecialchars($activity['username'] ?? 'System'); ?></strong>
                                            <?php echo htmlspecialchars($activity['action']); ?>
                                            <span class="date" title="<?php echo htmlspecialchars($activity['created_at']); ?>">
                                                <?php echo timeAgo($activity['created_at']); ?>
                                            </span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No recent activity recorded.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(SITE_NAME) . ' ' . date('Y'); ?>
        </footer>
    </div> <!-- End Main Content -->

    <!-- Core JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <!-- Chart.js (Optional) -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" defer></script> -->

    <script defer>
        document.addEventListener('DOMContentLoaded', function() {

            // --- Sidebar Toggle ---
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const SIDEBAR_COLLAPSED_KEY = 'sidebarCollapsed';

            function applySidebarState(collapsed) {
                if (sidebar && mainContent) {
                    sidebar.classList.toggle('collapsed', collapsed);
                    mainContent.classList.toggle('expanded', collapsed);
                }
            }
            const isCollapsed = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === 'true';
            applySidebarState(isCollapsed);

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    const shouldCollapse = !sidebar.classList.contains('collapsed');
                    applySidebarState(shouldCollapse);
                    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, shouldCollapse);
                });
            }

            // --- Live Clock Update (Client Time) ---
            const timeDisplay = document.getElementById('currentTimeDisplay');
            function updateClientTime() {
                if (!timeDisplay) return;
                const now = new Date();
                const options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZoneName: 'shortOffset' };
                 try {
                     timeDisplay.textContent = 'Current Client Time: ' + now.toLocaleString(undefined, options);
                 } catch (e) { timeDisplay.textContent = 'Current Client Time: ' + now.toISOString(); }
            }
            updateClientTime();
            setInterval(updateClientTime, 1000);

            // --- Tooltips (Optional) ---
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl); });

        }); // End DOMContentLoaded
    </script>
</body>
</html>