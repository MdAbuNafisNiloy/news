<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, generateSlug, logActivity, getSetting etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_categories')) {
    header("Location: index.php?message=Access Denied: You cannot manage pages.&type=danger");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 05:58:30"; // Use the fixed time provided by the user

// Initialize variables
$message = '';
$messageType = '';
$pageTitle = ''; // For form repopulation on error
$pageSlug = '';
$pageContent = '';
$pageStatus = 'draft';

// --- Handle Page Creation (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_page'])) {
    // Get and sanitize input
    $pageTitle = sanitizeInput($_POST['title'] ?? '');
    $pageSlug = sanitizeInput($_POST['slug'] ?? '');
    $pageContent = $_POST['content'] ?? ''; // Content from Quill editor (sanitize on output)
    $pageStatus = sanitizeInput($_POST['status'] ?? 'draft');

    // Store raw content for repopulation if error
    $rawContentForRepopulation = $_POST['content'] ?? '';

    try {
        if (empty($pageTitle)) throw new Exception("Page title is required.");
        if (empty($pageContent) || $pageContent === '<p><br></p>') throw new Exception("Page content cannot be empty.");

        // Auto-generate slug if empty
        if (empty($pageSlug)) {
            $pageSlug = generateSlug($pageTitle);
            if (empty($pageSlug)) throw new Exception("Could not generate slug from title.");
        }

        // Check slug uniqueness
        $originalSlug = $pageSlug;
        $counter = 1;
        while (true) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM pages WHERE slug = ?");
            $stmt->execute([$pageSlug]);
            if ($stmt->fetchColumn() == 0) break; // Slug is unique
            $pageSlug = $originalSlug . '-' . $counter++;
            if ($counter > 10) throw new Exception("Could not generate a unique slug.");
        }

        // Insert into database
        $sql = "INSERT INTO pages (title, slug, content, author_id, status, created_at, updated_at)
                VALUES (:title, :slug, :content, :author_id, :status, NOW(), NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':title' => $pageTitle,
            ':slug' => $pageSlug,
            ':content' => $pageContent, // Store raw HTML from editor
            ':author_id' => $currentUser['id'],
            ':status' => $pageStatus
        ]);

        $newPageId = $db->lastInsertId();

        // Log activity (optional)
        if (function_exists('logActivity')) {
             logActivity($currentUser['id'], 'page_create', 'pages', $newPageId, 'Created page: "' . $pageTitle . '"');
        }

        $_SESSION['message'] = "Page \"$pageTitle\" created successfully!";
        $_SESSION['message_type'] = "success";

        // Redirect to prevent form resubmission
        header("Location: pages.php");
        exit();

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        $pageContent = $rawContentForRepopulation; // Keep raw content for repopulation
        // Keep other form values ($pageTitle, $pageSlug, $pageStatus) already set
        error_log("Page Creation Error: " . $e->getMessage());
    }
}

// --- Handle Page Deletion (GET Request - Use POST/DELETE for real apps) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = (int)$_GET['id'];
    // Add nonce/CSRF check here in a real application
    try {
        $stmt = $db->prepare("SELECT title FROM pages WHERE id = ?");
        $stmt->execute([$deleteId]);
        $pageToDelete = $stmt->fetchColumn();

        if ($pageToDelete) {
            $delStmt = $db->prepare("DELETE FROM pages WHERE id = ?");
            $delStmt->execute([$deleteId]);

             // Log activity (optional)
            if (function_exists('logActivity')) {
                 logActivity($currentUser['id'], 'page_delete', 'pages', $deleteId, 'Deleted page: "' . $pageToDelete . '"');
            }
            $_SESSION['message'] = "Page \"$pageToDelete\" deleted successfully.";
            $_SESSION['message_type'] = "success";
        } else {
             $_SESSION['message'] = "Page not found for deletion.";
             $_SESSION['message_type'] = "warning";
        }
    } catch (Exception $e) {
        $_SESSION['message'] = "Error deleting page: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
        error_log("Page Deletion Error (ID: $deleteId): " . $e->getMessage());
    }
    // Redirect back after processing deletion
    header("Location: pages.php");
    exit();
}

// --- Fetch Existing Pages ---
$pages = [];
try {
    // Query to get pages and author username
    $sql = "SELECT p.id, p.title, p.slug, p.status, p.created_at, u.username as author_name
            FROM pages p
            LEFT JOIN users u ON p.author_id = u.id
            ORDER BY p.title ASC";
    $stmt = $db->query($sql);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading pages list: " . $e->getMessage();
    $messageType = "danger";
    error_log("Pages List Error: " . $e->getMessage());
}

// Get messages from session (after redirects)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Helper function to get settings (assuming it exists in functions.php or included)
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pages - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Quill.js CSS -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
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
        .card-header h5, .card-header h6 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1.25rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Forms */
        .form-label { font-weight: 500; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .form-text { font-size: 0.75rem; }
        .form-control, .form-select { font-size: 0.9rem; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Tables */
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; margin-bottom: 0; }
        .table thead th { font-size: 0.75rem; text-transform: uppercase; color: #858796; font-weight: 600; background-color: #f8f9fc; border-top: 0; border-bottom: 2px solid #e3e6f0; padding: 0.6rem 0.75rem; white-space: nowrap; }
        .table td { vertical-align: middle; padding: 0.6rem 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .table .actions a, .table .actions button { margin: 0 0.2rem; }
        .table .actions .btn-sm { padding: 0.15rem 0.4rem; font-size: 0.75rem; }
        .badge { font-weight: 600; padding: 0.3em 0.6em; font-size: 0.7rem; }
        /* Quill Editor */
        #editor-container { height: 300px; margin-bottom: 0.5rem; background-color: #fff; }
        .ql-toolbar.ql-snow { border-top-left-radius: 0.35rem; border-top-right-radius: 0.35rem; border-color: #d1d3e2; background-color: #f8f9fc; padding: 8px; }
        .ql-container.ql-snow { border-bottom-left-radius: 0.35rem; border-bottom-right-radius: 0.35rem; border-color: #d1d3e2; font-size: 1rem; }
        .ql-editor { line-height: 1.6; min-height: 200px; /* Ensure minimum height */ }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } #editor-container { height: 250px; } }
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
            <h2><i class="fas fa-file-alt me-2"></i> Manage Pages</h2>
            <p>Create and manage static pages like Privacy Policy, Terms, About Us, etc.</p>
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

        <!-- Main Row -->
        <div class="row">
            <!-- Add Page Form Column (Adjusted width for balance) -->
            <div class="col-lg-5 col-xl-4 mb-4 mb-lg-0">
                <div class="card">
                    <div class="card-header"> <h6 class="m-0">Add New Page</h6> </div>
                    <div class="card-body">
                        <form method="POST" action="pages.php" id="addPageForm">
                            <input type="hidden" name="add_page" value="1">
                            <div class="mb-3">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="title" name="title" value="<?php echo htmlspecialchars($pageTitle); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control form-control-sm" id="slug" name="slug" value="<?php echo htmlspecialchars($pageSlug); ?>" placeholder="auto-generated-from-title">
                                <small class="form-text text-muted">URL-friendly version.</small>
                            </div>
                             <div class="mb-3">
                                <label for="content" class="form-label">Content <span class="text-danger">*</span></label>
                                <div id="editor-container"><?php echo $pageContent; /* Output raw HTML */ ?></div>
                                <input type="hidden" name="content" id="content">
                            </div>
                             <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select form-select-sm" id="status" name="status">
                                    <option value="draft" <?php echo $pageStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $pageStatus === 'published' ? 'selected' : ''; ?>>Published</option>
                                </select>
                             </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-plus me-1"></i> Add New Page
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Pages List Column (Adjusted width for balance) -->
            <div class="col-lg-7 col-xl-8">
                <div class="card">
                    <div class="card-header"> <h6 class="m-0">Existing Pages</h6> </div>
                    <div class="card-body p-0">
                        <?php if (!empty($pages)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Slug</th>
                                            <th>Status</th>
                                            <th>Author</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pages as $page): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($page['title']); ?></strong></td>
                                                <td>/<?php echo htmlspecialchars($page['slug']); ?></td>
                                                <td>
                                                     <?php $statusClass = ($page['status'] === 'published') ? 'success' : 'secondary'; ?>
                                                     <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($page['status'])); ?></span>
                                                </td>
                                                 <td><?php echo htmlspecialchars($page['author_name'] ?? 'N/A'); ?></td>
                                                <td class="text-end actions white-space-nowrap">
                                                    <a href="page-edit.php?id=<?php echo $page['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <!-- Link to view page on front-end (adjust URL structure as needed) -->
                                                    <a href="../page.php?slug=<?php echo $page['slug']; ?>" target="_blank" class="btn btn-sm btn-outline-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="pages.php?action=delete&id=<?php echo $page['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete the page \'<?php echo htmlspecialchars(addslashes($page['title'])); ?>\'? This cannot be undone.');">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted p-4 mb-0">No pages found. Add one using the form!</p>
                        <?php endif; ?>
                    </div>
                     <?php if (!empty($pages)): ?>
                    <div class="card-footer text-muted small">
                        Total pages: <?php echo count($pages); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div> <!-- End Row -->

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')) . ' ' . date('Y'); ?>
        </footer>
    </div> <!-- End Main Content -->

    <!-- Core JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <!-- Quill.js JavaScript -->
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js" defer></script>

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

            // --- Quill Editor Initialization ---
            let quill = null;
            const editorContainer = document.getElementById('editor-container');
            if (editorContainer) {
                 quill = new Quill('#editor-container', {
                    theme: 'snow',
                    modules: {
                        toolbar: [ // Simplified toolbar for pages
                            [{ 'header': [2, 3, false] }],
                            ['bold', 'italic', 'underline', 'link'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            ['blockquote', 'code-block'],
                            ['clean']
                        ]
                    },
                    placeholder: 'Enter page content...',
                });
                // Set initial content if repopulating after error
                const initialContent = <?php echo json_encode($pageContent); ?>;
                 if (initialContent) { quill.root.innerHTML = initialContent; }
            }

            // --- Form Submission Handling (Sync Quill Content) ---
            const addPageForm = document.getElementById('addPageForm');
            if (addPageForm) {
                addPageForm.addEventListener('submit', function(e) {
                    const contentInput = document.getElementById('content');
                    if (quill && contentInput) {
                        contentInput.value = quill.root.innerHTML;
                    }
                    // Basic Validation
                    const title = document.getElementById('title').value.trim();
                    if (!title) { e.preventDefault(); alert('Page title is required.'); return; }
                    if (quill && quill.getText().trim().length < 1) { e.preventDefault(); alert('Page content cannot be empty.'); return; }
                });
            }

            // --- Auto Slug Generation ---
            const titleInput = document.getElementById('title');
            const slugInput = document.getElementById('slug');
            if (titleInput && slugInput) {
                 slugInput.addEventListener('input', function() { slugInput.dataset.manualEdit = 'true'; });
                 titleInput.addEventListener('blur', function() {
                    if (slugInput.value.trim() === '' && !slugInput.dataset.manualEdit) {
                        const titleValue = this.value.trim();
                        if (titleValue) {
                            slugInput.value = titleValue.toLowerCase()
                                .replace(/\s+/g, '-')
                                .replace(/[^\w-]+/g, '')
                                .replace(/--+/g, '-')
                                .replace(/^-+/, '')
                                .replace(/-+$/, '');
                        }
                    }
                });
            }

        }); // End DOMContentLoaded
    </script>

</body>
</html>