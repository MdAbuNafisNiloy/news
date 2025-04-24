<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, generateSlug, logActivity etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_categories')) {
    // Redirect to dashboard or login page if not authorized
    header("Location: index.php?message=Access Denied: You cannot manage categories.&type=danger");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Use fixed user/time provided
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 05:03:16";

// Initialize variables
$message = '';
$messageType = '';
$categoryName = ''; // For form repopulation on error
$categorySlug = '';
$categoryDescription = '';

// --- Handle Category Creation (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    // Get and sanitize input
    $categoryName = sanitizeInput($_POST['name'] ?? '');
    $categorySlug = sanitizeInput($_POST['slug'] ?? '');
    $categoryDescription = sanitizeInput($_POST['description'] ?? '');

    try {
        if (empty($categoryName)) {
            throw new Exception("Category name is required.");
        }

        // Auto-generate slug if empty
        if (empty($categorySlug)) {
            $categorySlug = generateSlug($categoryName);
            if (empty($categorySlug)) throw new Exception("Could not generate slug from name.");
        }

        // Check slug uniqueness
        $originalSlug = $categorySlug;
        $counter = 1;
        while (true) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
            $stmt->execute([$categorySlug]);
            if ($stmt->fetchColumn() == 0) break; // Slug is unique
            $categorySlug = $originalSlug . '-' . $counter++;
            if ($counter > 10) throw new Exception("Could not generate a unique slug.");
        }

        // Insert into database
        $sql = "INSERT INTO categories (name, slug, description, created_at) VALUES (:name, :slug, :description, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':name' => $categoryName,
            ':slug' => $categorySlug,
            ':description' => $categoryDescription
        ]);

        $newCategoryId = $db->lastInsertId();

        // Log activity (optional)
        if (function_exists('logActivity')) {
             logActivity($currentUser['id'], 'category_create', 'categories', $newCategoryId, 'Created category: "' . $categoryName . '"');
        }

        $_SESSION['message'] = "Category \"$categoryName\" created successfully!";
        $_SESSION['message_type'] = "success";

        // Redirect to prevent form resubmission (PRG pattern)
        header("Location: categories.php");
        exit();

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        // Keep form values for repopulation
    }
}

// --- Handle Category Deletion (GET Request - Use POST/DELETE for real apps) ---
// Note: Using GET for deletion is NOT recommended for security (CSRF).
// This is a simplified example. Use a POST request with a form and CSRF token in production.
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = (int)$_GET['id'];
    // Add nonce/CSRF check here in a real application
    try {
        // Optional: Check if category is in use before deleting
        // $checkStmt = $db->prepare("SELECT COUNT(*) FROM article_categories WHERE category_id = ?");
        // $checkStmt->execute([$deleteId]);
        // if ($checkStmt->fetchColumn() > 0) {
        //     throw new Exception("Cannot delete category: It is currently assigned to articles.");
        // }

        $stmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
        $stmt->execute([$deleteId]);
        $categoryToDelete = $stmt->fetchColumn();

        if ($categoryToDelete) {
            $db->beginTransaction();
            // Remove associations first (if desired, or handle via FOREIGN KEY constraints)
            // $delAssocStmt = $db->prepare("DELETE FROM article_categories WHERE category_id = ?");
            // $delAssocStmt->execute([$deleteId]);

            $delStmt = $db->prepare("DELETE FROM categories WHERE id = ?");
            $delStmt->execute([$deleteId]);
            $db->commit();

             // Log activity (optional)
            if (function_exists('logActivity')) {
                 logActivity($currentUser['id'], 'category_delete', 'categories', $deleteId, 'Deleted category: "' . $categoryToDelete . '"');
            }

            $_SESSION['message'] = "Category \"$categoryToDelete\" deleted successfully.";
            $_SESSION['message_type'] = "success";
        } else {
             $_SESSION['message'] = "Category not found for deletion.";
             $_SESSION['message_type'] = "warning";
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['message'] = "Error deleting category: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }
    // Redirect back after processing deletion
    header("Location: categories.php");
    exit();
}


// --- Fetch Existing Categories ---
$categories = [];
try {
    // Query to get categories and count of associated articles
    $sql = "SELECT c.id, c.name, c.slug, c.description, COUNT(ac.article_id) as article_count
            FROM categories c
            LEFT JOIN article_categories ac ON c.id = ac.category_id
            GROUP BY c.id, c.name, c.slug, c.description
            ORDER BY c.name ASC";
    $stmt = $db->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading categories list: " . $e->getMessage();
    $messageType = "danger";
    error_log("Categories List Error: " . $e->getMessage());
}

// Get messages from session (after redirects)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - <?php echo htmlspecialchars(SITE_NAME); ?></title>
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
        .card-header h5, .card-header h6 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1rem; }
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
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; margin-top: 1rem; }
        /* Tables */
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; margin-bottom: 0; }
        .table thead th { font-size: 0.75rem; text-transform: uppercase; color: #858796; font-weight: 600; background-color: #f8f9fc; border-top: 0; border-bottom: 2px solid #e3e6f0; padding: 0.6rem 0.75rem; white-space: nowrap; }
        .table td { vertical-align: middle; padding: 0.6rem 0.75rem; border-top: 1px solid #e3e6f0; font-size: 0.85rem; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .table .actions a, .table .actions button { margin: 0 0.2rem; } /* Spacing for action buttons */
        .table .actions .btn-sm { padding: 0.15rem 0.4rem; font-size: 0.75rem; }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { text-align: left; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } }
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
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item active" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
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
            <h2><i class="fas fa-folder me-2"></i> Manage Categories</h2>
            <p>Add, edit, and organize your article categories.</p>
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
            <!-- Add Category Form Column -->
            <div class="col-lg-4 mb-4 mb-lg-0">
                <div class="card">
                    <div class="card-header"> <h6 class="m-0">Add New Category</h6> </div>
                    <div class="card-body">
                        <form method="POST" action="categories.php" id="addCategoryForm">
                            <input type="hidden" name="add_category" value="1">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="name" name="name" value="<?php echo htmlspecialchars($categoryName); ?>" required>
                                <small class="form-text text-muted">The name is how it appears on your site.</small>
                            </div>
                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control form-control-sm" id="slug" name="slug" value="<?php echo htmlspecialchars($categorySlug); ?>" placeholder="auto-generated-from-name">
                                <small class="form-text text-muted">The URL-friendly version. Usually all lowercase and contains only letters, numbers, and hyphens.</small>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control form-control-sm" id="description" name="description" rows="3"><?php echo htmlspecialchars($categoryDescription); ?></textarea>
                                <small class="form-text text-muted">Optional description for the category.</small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-plus me-1"></i> Add New Category
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Categories List Column -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"> <h6 class="m-0">Existing Categories</h6> </div>
                    <div class="card-body p-0">
                        <?php if (!empty($categories)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Slug</th>
                                            <th>Description</th>
                                            <th class="text-center">Count</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categories as $category): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($category['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($category['slug']); ?></td>
                                                <td><small><?php echo htmlspecialchars(mb_strimwidth($category['description'] ?? '', 0, 50, '...')); ?></small></td>
                                                <td class="text-center">
                                                    <a href="articles.php?category=<?php echo $category['id']; ?>" class="badge bg-secondary text-decoration-none">
                                                        <?php echo $category['article_count']; ?>
                                                    </a>
                                                </td>
                                                <td class="text-end actions">
                                                    <a href="category-edit.php?id=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="categories.php?action=delete&id=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete the category \'<?php echo htmlspecialchars(addslashes($category['name'])); ?>\'? This cannot be undone.');">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted p-4 mb-0">No categories found. Add one using the form!</p>
                        <?php endif; ?>
                    </div>
                     <?php if (!empty($categories)): ?>
                    <div class="card-footer text-muted small">
                        Total categories: <?php echo count($categories); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div> <!-- End Row -->

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

            // --- Auto Slug Generation ---
            const nameInput = document.getElementById('name');
            const slugInput = document.getElementById('slug');
            if (nameInput && slugInput) {
                 slugInput.addEventListener('input', function() { slugInput.dataset.manualEdit = 'true'; }); // Mark manual edit
                 nameInput.addEventListener('blur', function() {
                    // Only generate if slug is empty AND not manually edited
                    if (slugInput.value.trim() === '' && !slugInput.dataset.manualEdit) {
                        const nameValue = this.value.trim();
                        if (nameValue) {
                            slugInput.value = nameValue.toLowerCase()
                                .replace(/\s+/g, '-')
                                .replace(/[^\w-]+/g, '')
                                .replace(/--+/g, '-')
                                .replace(/^-+/, '')
                                .replace(/-+$/, '');
                        }
                    }
                });
            }

            // --- Form Validation ---
            const addCategoryForm = document.getElementById('addCategoryForm');
            if(addCategoryForm) {
                addCategoryForm.addEventListener('submit', function(e) {
                    const name = document.getElementById('name').value.trim();
                    if (!name) {
                        e.preventDefault();
                        alert('Category name cannot be empty.');
                    }
                });
            }

        }); // End DOMContentLoaded
    </script>

</body>
</html>