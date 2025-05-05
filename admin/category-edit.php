<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, generateSlug, logActivity, getSetting etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Get Category ID and Initial Data Fetch ---
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($categoryId <= 0) {
    header("Location: categories.php?message=Invalid category ID.&type=danger");
    exit();
}

$category = null;
$message = '';
$messageType = '';

// Helper function to get settings (assuming it exists in functions.php or included)
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

try {
    // Fetch category data
    $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        header("Location: categories.php?message=Category not found.&type=danger");
        exit();
    }

    // --- Permission Check ---
    if (!isLoggedIn() || !hasPermission('manage_categories')) {
         header("Location: index.php?message=Access Denied: You cannot manage categories.&type=danger");
         exit();
    }

} catch (PDOException $e) {
    $message = "Error loading category data: " . $e->getMessage();
    $messageType = "danger";
    error_log("Category Load Error (ID: $categoryId): " . $e->getMessage());
    $category = null; // Prevent form rendering if load failed
}

// --- Process Form Submission (Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $category) { // Only process if category was loaded
    // Store original data for comparison
    $originalSlug = $category['slug'];

    // Get data from POST, default to original category data
    $submittedData = [
        'name' => $_POST['name'] ?? $category['name'],
        'slug' => $_POST['slug'] ?? $category['slug'],
        'description' => $_POST['description'] ?? $category['description'],
    ];
    // Update the $category array immediately for form repopulation on error
    $category = array_merge($category, $submittedData);

    try {
        // --- Input Validation ---
        if (empty($submittedData['name'])) throw new Exception("Category name is required.");

        // Sanitize inputs
        $name = sanitizeInput($submittedData['name']);
        $slug = sanitizeInput($submittedData['slug']);
        $description = sanitizeInput($submittedData['description']);

        // Auto-generate slug if empty
        if (empty($slug)) {
            $slug = generateSlug($name);
            if (empty($slug)) throw new Exception("Could not generate slug from name.");
        }

        // Check slug uniqueness IF it changed
        if ($slug !== $originalSlug) {
            $uniqueSlug = $slug;
            $counter = 1;
            while (true) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?");
                $stmt->execute([$uniqueSlug, $categoryId]);
                if ($stmt->fetchColumn() == 0) {
                    $slug = $uniqueSlug; break;
                }
                $uniqueSlug = $slug . '-' . $counter++;
                if ($counter > 10) throw new Exception("Could not generate a unique slug.");
            }
            $category['slug'] = $slug; // Update for repopulation
        }

        // --- Database Update ---
        $db->beginTransaction();

        // ** Removed updated_at = NOW() **
        $sql = "UPDATE categories SET
                    name = :name, slug = :slug, description = :description
                WHERE id = :id";
        $stmt = $db->prepare($sql);

        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description,
            ':id' => $categoryId
        ]);

        $db->commit();

        // Log activity
        if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
             logActivity($_SESSION['user_id'], 'category_update', 'categories', $categoryId, 'Updated category: "' . $name . '"');
        }

        $message = "Category updated successfully!";
        $messageType = "success";

        // Redirect back to edit page with success message
        header("Location: category-edit.php?id=$categoryId&message=" . urlencode($message) . "&type=" . $messageType);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("Category Update Error (ID: $categoryId): " . $e->getMessage());
        // $category array is already updated with submitted data for repopulation
    }
}

// Get user information (needed for header)
$currentUser = getCurrentUser();
// Use fixed user/time provided for header display
$headerLogin = "MdAbuNafisNiloy";
$headerUtcTime = "2025-04-20 06:15:18"; // Updated Time

// Check for messages passed via URL (e.g., after successful save)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category - <?php echo $category ? htmlspecialchars($category['name']) : 'Error'; ?> - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Same Base Responsive CSS as before --- */
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
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
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
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item active" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_categories')): ?> <a href="pages.php" class="sidebar-item" title="Pages"> <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span> </a> <?php endif; ?>
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
            <h2><i class="fas fa-edit me-2"></i> Edit Category</h2>
            <p>Update the details for this category.</p>
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

        <?php if ($category): // Only show form if category was loaded ?>
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Category Details</h5>
                    </div>
                    <div class="card-body">
                         <form method="POST" action="category-edit.php?id=<?php echo $categoryId; ?>" id="editCategoryForm">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="name" name="name" value="<?php echo htmlspecialchars($category['name']); ?>" required>
                                <small class="form-text text-muted">The name is how it appears on your site.</small>
                            </div>
                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control form-control-sm" id="slug" name="slug" value="<?php echo htmlspecialchars($category['slug']); ?>" placeholder="auto-generated-from-name">
                                <small class="form-text text-muted">The URL-friendly version. Usually all lowercase and contains only letters, numbers, and hyphens.</small>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control form-control-sm" id="description" name="description" rows="4"><?php echo htmlspecialchars($category['description']); ?></textarea>
                                <small class="form-text text-muted">Optional description for the category.</small>
                            </div>
                             <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-sync-alt me-1"></i> Update Category
                                </button>
                                <a href="categories.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-arrow-left me-1"></i> Back to Categories
                                </a>
                            </div>
                        </form>
                    </div>
                     <div class="card-footer text-muted small">
                         Category ID: <?php echo $category['id']; ?>
                         <?php if (isset($category['created_at'])): ?>
                         | Created: <?php echo date('M d, Y', strtotime($category['created_at'])); ?>
                         <?php endif; ?>
                     </div>
                </div>
            </div>
        </div>
        <?php else: // Show error if category couldn't be loaded ?>
            <div class="alert alert-danger">Could not load category data. Please check the ID or contact support.</div>
            <a href="categories.php" class="btn btn-secondary btn-sm">Back to Categories List</a>
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

            // --- Auto Slug Generation (if field cleared) ---
            const nameInput = document.getElementById('name');
            const slugInput = document.getElementById('slug');
            if (nameInput && slugInput) {
                 slugInput.addEventListener('input', function() { slugInput.dataset.manualEdit = 'true'; });
                 nameInput.addEventListener('blur', function() {
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
            const editCategoryForm = document.getElementById('editCategoryForm');
            if(editCategoryForm) {
                editCategoryForm.addEventListener('submit', function(e) {
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