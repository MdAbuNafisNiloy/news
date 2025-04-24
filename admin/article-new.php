<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Includes isAscii, generateRandomSlug, generateAsciiSlug
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc. are here

// Check if user is logged in and has permission
if (!isLoggedIn() || !hasPermission('create_article')) {
    header("Location: login.php");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Use the provided fixed time for header display
$headerLogin = htmlspecialchars($currentUser['username'] ?? 'N/A');
$headerUtcTime = '2025-04-20 16:13:57'; // Use the fixed time provided by the user

// Initialize variables
$message = '';
$messageType = '';
$article = [
    'title' => '',
    'slug' => '',
    'content' => '',
    'excerpt' => '',
    'status' => 'draft',
    'visibility' => 'public',
    'password' => '',
    'featured' => 0,
    'breaking_news' => 0,
    'featured_image' => '',
    'selected_categories' => [], // For repopulating form
    'selected_tags' => []       // For repopulating form
];
$featuredImagePath = null; // Initialize outside try block for potential cleanup

// Get categories for selection
$categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $message = "Error loading categories: " . $e->getMessage();
    $messageType = "danger";
    error_log("Category Load Error: " . $e->getMessage());
}

// Get tags for selection
$tags = [];
try {
    $stmt = $db->query("SELECT id, name FROM tags ORDER BY name");
    $tags = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    // Silent fail for tags is acceptable
    error_log("Tag Load Error: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store POST data for repopulation on error
    $article['title'] = $_POST['title'] ?? '';
    $article['slug'] = $_POST['slug'] ?? '';
    $article['content'] = $_POST['content'] ?? ''; // Raw content from editor
    $article['excerpt'] = $_POST['excerpt'] ?? '';
    $article['status'] = $_POST['status'] ?? 'draft';
    $article['visibility'] = $_POST['visibility'] ?? 'public';
    $article['password'] = $_POST['password'] ?? '';
    $article['featured'] = isset($_POST['featured']) ? 1 : 0;
    $article['breaking_news'] = isset($_POST['breaking_news']) ? 1 : 0;
    $article['selected_categories'] = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];
    $article['selected_tags'] = isset($_POST['tags']) && is_array($_POST['tags']) ? $_POST['tags'] : [];

    try {
        // --- Input Validation ---
        if (empty($article['title'])) throw new Exception("Article title is required.");
        if (empty($article['content']) || strlen(trim(strip_tags($article['content']))) === 0 || $article['content'] === '<p><br></p>') {
            throw new Exception("Article content cannot be empty.");
        }
        if ($article['visibility'] === 'password_protected' && empty($article['password'])) throw new Exception("Password is required for password-protected visibility.");

        // Sanitize inputs (where appropriate)
        $title = trim($article['title']); // Trim title
        $slug_input = trim($article['slug']); // User provided slug or empty
        $content = $article['content']; // Content is handled by editor, sanitize on output
        $excerpt = sanitizeInput($article['excerpt']);
        $status = sanitizeInput($article['status']);
        $visibility = sanitizeInput($article['visibility']);
        $password = $visibility === 'password_protected' ? trim($article['password']) : ''; // Trim password
        $featured = $article['featured'];
        $breaking_news = $article['breaking_news'];
        $selectedCategories = array_map('intval', $article['selected_categories']);
        $selectedTags = array_map('intval', $article['selected_tags']);

        // *** Slug Generation Logic ***
        $slug = ''; // Initialize slug variable
        if (!empty($slug_input)) {
            // If user provided a slug, clean it using the ASCII slug function
            $slug = generateAsciiSlug($slug_input);
        } else {
            // If slug is empty, check the title
            if (empty($title)) {
                 throw new Exception("Article title is required to generate a slug.");
            }
            // Check if title is purely ASCII
            if (isAscii($title)) {
                // Generate slug from ASCII title
                $slug = generateAsciiSlug($title);
            } else {
                // Generate a random slug for non-ASCII titles
                $slug = generateRandomSlug();
            }
        }

        // Final check if slug generation somehow failed
        if (empty($slug)) {
            throw new Exception("Could not generate a valid slug.");
        }


        // Check if slug already exists and make unique
        $originalSlug = $slug;
        $counter = 1;
        while (true) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() == 0) {
                break; // Slug is unique
            }
            // Append counter (ensure slug doesn't exceed DB column length)
            $suffix = '-' . $counter++;
            if (mb_strlen($originalSlug . $suffix) > 250) {
                 $slug = mb_substr($originalSlug, 0, 250 - mb_strlen($suffix)) . $suffix;
            } else {
                 $slug = $originalSlug . $suffix;
            }
            if ($counter > 20) {
                 throw new Exception("Could not generate a unique slug after multiple attempts. Please provide a unique slug manually.");
            }
        }
        $article['slug'] = $slug; // Update slug in array for potential repopulation

        // --- Featured Image Handling ---
      $featuredImagePath = null; // Reset path for this attempt
if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'uploads/articles/'; // Relative path
    try {
        // Validate the uploaded file
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxFileSize = 5 * 1024 * 1024; // 5 MB
        $fileTmpPath = $_FILES['featured_image']['tmp_name'];
        $fileName = $_FILES['featured_image']['name'];
        $fileSize = $_FILES['featured_image']['size'];
        $fileInfo = pathinfo($fileName);
        $extension = strtolower($fileInfo['extension']);

        // Check file extension
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("Invalid image format. Allowed formats: JPG, JPEG, PNG, GIF, WEBP.");
        }

        // Check file size
        if ($fileSize > $maxFileSize) {
            throw new Exception("Image size too large. Maximum size allowed is 5MB.");
        }

        // Generate a unique file name
        $safeSlugPart = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($article['title'])), 0, 30);
        $newFilename = 'article_' . $article['id'] . '_' . trim($safeSlugPart, '-') . '-' . uniqid() . '.' . $extension;
        $uploadPath = $uploadDir . $newFilename;

        // Ensure the upload directory exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create the upload directory.");
            }
        }

        // Move the uploaded file
        if (!move_uploaded_file($fileTmpPath, $uploadPath)) {
            throw new Exception("Failed to move the uploaded image to the target directory.");
        }

        // Set the path for the database update
        $featuredImagePath = $uploadPath;
    } catch (Exception $uploadEx) {
        throw new Exception("Featured image upload failed: " . $uploadEx->getMessage());
    }
} elseif (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    throw new Exception("Featured image upload error: Code " . $_FILES['featured_image']['error']);
}


        // --- Database Operations ---
        $db->beginTransaction();

        // Insert article
        $sql = "INSERT INTO articles (
                    title, slug, content, excerpt, author_id, status,
                    visibility, password, featured, breaking_news, featured_image,
                    created_at, updated_at, published_at
                ) VALUES (
                    :title, :slug, :content, :excerpt, :author_id, :status,
                    :visibility, :password, :featured, :breaking_news, :featured_image,
                    NOW(), NOW(), :published_at
                )";
        $stmt = $db->prepare($sql);

        $publishedAt = ($status === 'published' && hasPermission('publish_article')) ? date('Y-m-d H:i:s') : null;

        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug, // Use the final unique slug
            ':content' => $content,
            ':excerpt' => $excerpt,
            ':author_id' => $currentUser['id'],
            ':status' => $status,
            ':visibility' => $visibility,
            ':password' => $password ?: null, // Store NULL if empty password
            ':featured' => $featured,
            ':breaking_news' => $breaking_news,
            ':featured_image' => $featuredImagePath,
            ':published_at' => $publishedAt
        ]);

        $articleId = $db->lastInsertId();
        if (!$articleId) throw new Exception("Failed to retrieve last inserted article ID.");

        // Handle categories
        if (!empty($selectedCategories)) {
            $catSql = "INSERT INTO article_categories (article_id, category_id) VALUES (:article_id, :category_id)";
            $catStmt = $db->prepare($catSql);
            foreach ($selectedCategories as $categoryId) {
                if($categoryId > 0) {
                    $catStmt->execute([':article_id' => $articleId, ':category_id' => $categoryId]);
                }
            }
        }

        // Handle tags
        if (!empty($selectedTags)) {
            $tagSql = "INSERT INTO article_tags (article_id, tag_id) VALUES (:article_id, :tag_id)";
            $tagStmt = $db->prepare($tagSql);
            foreach ($selectedTags as $tagId) {
                 if($tagId > 0) {
                    $tagStmt->execute([':article_id' => $articleId, ':tag_id' => $tagId]);
                 }
            }
        }

        // Commit transaction
        $db->commit();

        // Log activity
        if (function_exists('logActivity')) {
            logActivity($currentUser['id'], 'article_create', 'articles', $articleId, 'Created new article: "' . $title . '"');
        }

        $message = "Article created successfully!";
        $messageType = "success";

        // Handle 'Save & Publish' explicitly if status wasn't set to published initially
        if (isset($_POST['publish']) && $status !== 'published' && hasPermission('publish_article')) {
            $updateStmt = $db->prepare("UPDATE articles SET status = 'published', published_at = NOW() WHERE id = ?");
            $updateStmt->execute([$articleId]);
            $message = "Article published successfully!";
            if (function_exists('logActivity')) {
                logActivity($currentUser['id'], 'article_publish', 'articles', $articleId, 'Published article: "' . $title . '"');
            }
        }

        // --- Redirect based on button pressed ---
        if (isset($_POST['save_and_continue'])) {
            header("Location: article-edit.php?id=$articleId&message=" . urlencode($message) . "&type=" . $messageType); exit();
        }
        if (isset($_POST['save_and_new'])) {
            header("Location: article-new.php?message=" . urlencode($message) . "&type=" . $messageType); exit();
        }
        // Default redirect (Save Draft or Save & Publish)
        header("Location: articles.php?message=" . urlencode($message) . "&type=" . $messageType); exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Delete uploaded file if transaction failed and path was set
        if (!empty($featuredImagePath) && file_exists($featuredImagePath)) {
             $expectedBaseDir = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim('uploads/articles/', '/');
             if (strpos(realpath($featuredImagePath), realpath($expectedBaseDir)) === 0) {
                 @unlink($featuredImagePath);
             }
        }
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("Article Creation Error: UserID={$currentUser['id']}, Error: " . $e->getMessage());
        // $article array is already repopulated from POST data above
    }
}

// Check for messages passed via URL (e.g., after 'Save & Add New')
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}

// Define SITE_NAME constant if not defined in config.php for testing
if (!defined('SITE_NAME')) { define('SITE_NAME', 'Alpha News Test'); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Article - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Quill.js CSS -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
            --sidebar-width: 220px;
            --sidebar-width-collapsed: 90px;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--light-color); color: var(--dark-color); overflow-x: hidden; }

        /* --- Sidebar --- */
        .sidebar { background: linear-gradient(180deg, var(--primary-color) 10%, #224abe 100%); height: 100vh; position: fixed; top: 0; left: 0; z-index: 1030; width: var(--sidebar-width); transition: width 0.3s ease-in-out; overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-width-collapsed); text-align: center; }
        .sidebar.collapsed .sidebar-brand { padding: 1.5rem 0.5rem; font-size: 0.8rem; }
        .sidebar.collapsed .sidebar-brand span { display: none; }
        .sidebar.collapsed .sidebar-item { padding: 0.75rem; justify-content: center; }
        .sidebar.collapsed .sidebar-item i { margin-right: 0; }
        .sidebar.collapsed .sidebar-item span { display: none; }
        .sidebar-brand { height: 4.375rem; padding: 1.5rem 1rem; color: #fff; text-align: center; font-size: 1.1rem; font-weight: 700; letter-spacing: 0.05rem; text-transform: uppercase; border-bottom: 1px solid rgba(255, 255, 255, 0.15); text-decoration: none; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-brand i { vertical-align: middle; }
        .sidebar-items { margin-top: 1rem; }
        .sidebar-item { padding: 0.75rem 1rem; color: rgba(255, 255, 255, 0.8); transition: background-color 0.2s, color 0.2s; display: flex; align-items: center; margin: 0.25rem 0.5rem; border-radius: 0.35rem; text-decoration: none; white-space: nowrap; }
        .sidebar-item.active, .sidebar-item:hover { background-color: rgba(255, 255, 255, 0.1); color: #fff; }
        .sidebar-item i { margin-right: 0.75rem; opacity: 0.8; width: 1.25em; text-align: center; flex-shrink: 0; }

        /* --- Main Content --- */
        .main-content { padding: 1.5rem; margin-left: var(--sidebar-width); transition: margin-left 0.3s ease-in-out; }
        .main-content.expanded { margin-left: var(--sidebar-width-collapsed); }

        /* --- Top Bar --- */
        .top-bar { background: #fff; margin-bottom: 1.5rem; padding: 0.75rem 1rem; border-radius: 0.35rem; box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.1); display: flex; justify-content: space-between; align-items: center; }
        .top-bar .dropdown-toggle::after { display: none; }

        /* --- General Card --- */
        .card { border: none; border-radius: 0.35rem; box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.08); margin-bottom: 1.5rem; overflow: hidden; }
        .card-header { background-color: #fdfdfd; border-bottom: 1px solid #e3e6f0; padding: 0.75rem 1rem; font-weight: 600; color: var(--primary-color); }
        .card-header h5 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }

        /* --- Form Styles --- */
        .form-label { font-weight: 500; margin-bottom: 0.3rem; font-size: 0.9rem; }
        .form-text { font-size: 0.75rem; }
        .form-control, .form-select { font-size: 0.9rem; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; }

        /* --- Header --- */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; margin-top: 1rem; }

        /* --- Quill Editor --- */
        #editor-container { height: 350px; /* Adjust height as needed */ margin-bottom: 0.5rem; background-color: #fff; }
        .ql-toolbar.ql-snow { border-top-left-radius: 0.35rem; border-top-right-radius: 0.35rem; border-color: #d1d3e2; background-color: #f8f9fc; padding: 8px; }
        .ql-container.ql-snow { border-bottom-left-radius: 0.35rem; border-bottom-right-radius: 0.35rem; border-color: #d1d3e2; font-size: 1rem; /* Base editor font size */ }
        .ql-editor { line-height: 1.6; }
        .ql-editor p, .ql-editor li { font-size: 1rem; } /* Ensure consistent P/LI size */

        /* --- Sidebar Column Specifics --- */
        .publish-card .action-buttons { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e3e6f0; }
        .visibility-options { display: none; margin-top: 0.5rem; padding-left: 0.5rem; border-left: 3px solid #eee; }
        .visibility-options.show { display: block; }
        .preview-img { max-width: 100%; height: auto; max-height: 150px; border-radius: 0.25rem; display: none; margin-top: 0.75rem; border: 1px solid #eee; }
        .multi-select { min-height: 120px; font-size: 0.85rem; }

        /* --- Responsive Adjustments --- */
        @media (max-width: 992px) { /* Stack columns below large screens */
            .main-content { padding: 1rem; }
            .page-header { padding: 1rem; }
            .page-header h2 { font-size: 1.3rem; }
            .header-meta { text-align: left; margin-top: 0.5rem; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 0.75rem; }
            .top-bar .dropdown span { display: none; }
            #editor-container { height: 300px; }
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
            <a href="index.php" class="sidebar-item" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
            <?php if (hasPermission('create_article')): ?> <a href="articles.php" class="sidebar-item active" title="Articles"> <i class="fas fa-newspaper fa-fw"></i> <span>Articles</span> </a> <?php endif; ?>
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
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle" aria-label="Toggle sidebar"> <i class="fas fa-bars"></i> </button>
            <div class="dropdown">
                <a class="btn btn-link dropdown-toggle text-decoration-none text-muted" href="#" role="button" id="userDropdownLink" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php $currentUserPic = $currentUser['profile_picture'] ?? ''; ?>
                    <img src="<?php echo !empty($currentUserPic) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentUserPic, '/')) ? htmlspecialchars($currentUserPic) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile" style="object-fit: cover;">
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
            <h2><i class="fas fa-plus-circle me-2"></i> Create New Article</h2>
            <p>Write and publish a new article for your website.</p>
             <div class="header-meta">
                 User: <?php echo htmlspecialchars($currentUser['username']); ?> | UTC: <?php echo $headerUtcTime; ?>
             </div>
        </div>

        <!-- Message Area -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="article-new.php" enctype="multipart/form-data" id="articleForm">
            <div class="row">
                <!-- Main Content Column (Larger on desktop) -->
                <div class="col-lg-8 order-lg-1">
                    <!-- Title Card -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg" id="title" name="title" value="<?php echo htmlspecialchars($article['title']); ?>" required placeholder="Enter article title here">
                            </div>
                             <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control form-control-sm" id="slug" name="slug" value="<?php echo htmlspecialchars($article['slug']); ?>" placeholder="auto-generated-if-blank">
                                <small class="form-text text-muted">URL-friendly identifier.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Content Editor Card -->
                     <div class="card mb-4">
                        <div class="card-header"> <h5 class="mb-0">Content <span class="text-danger">*</span></h5> </div>
                        <div class="card-body p-0"> <!-- Remove padding for editor -->
                            <!-- Quill editor container -->
                            <div id="editor-container"><?php echo $article['content']; /* Output raw HTML for Quill */ ?></div>
                            <!-- Hidden input to store Quill HTML content -->
                            <input type="hidden" name="content" id="content">
                        </div>
                         <div class="card-footer">
                             <small class="form-text text-muted">Use the editor to write and format your article content.</small>
                         </div>
                    </div>

                    <!-- Excerpt Card -->
                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Excerpt</h5> </div>
                         <div class="card-body">
                            <textarea class="form-control form-control-sm" id="excerpt" name="excerpt" rows="3" placeholder="Optional: Enter a short summary..."><?php echo htmlspecialchars($article['excerpt']); ?></textarea>
                            <small class="form-text text-muted">A brief summary shown in listings. Auto-generated if blank.</small>
                         </div>
                    </div>

                     <!-- Featured Image Card -->
                    <div class="card mb-4">
                        <div class="card-header"> <h5 class="mb-0">Featured Image</h5> </div>
                        <div class="card-body">
                            <label for="featured_image" class="form-label">Upload Image</label>
                            <input type="file" class="form-control form-control-sm" id="featured_image" name="featured_image" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small class="form-text text-muted">Recommended: 1200x630px. Max: 5MB (JPG, PNG, GIF, WEBP).</small>
                            <img id="image_preview" src="#" alt="Image Preview" class="preview-img">
                        </div>
                    </div>
                </div>

                <!-- Sidebar Column (Smaller on desktop) -->
                <div class="col-lg-4 order-lg-2">
                    <!-- Publish Card -->
                    <div class="card mb-4 publish-card">
                        <div class="card-header"> <h5 class="mb-0">Publish Settings</h5> </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select form-select-sm" id="status" name="status">
                                    <option value="draft" <?php echo $article['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="pending" <?php echo $article['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                    <?php if (hasPermission('publish_article')): ?>
                                    <option value="published" <?php echo $article['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="visibility" class="form-label">Visibility</label>
                                <select class="form-select form-select-sm" id="visibility" name="visibility">
                                    <option value="public" <?php echo $article['visibility'] === 'public' ? 'selected' : ''; ?>>Public</option>
                                    <option value="private" <?php echo $article['visibility'] === 'private' ? 'selected' : ''; ?>>Private</option>
                                    <option value="password_protected" <?php echo $article['visibility'] === 'password_protected' ? 'selected' : ''; ?>>Password Protected</option>
                                </select>
                            </div>
                            <div id="password_field" class="visibility-options mb-3 <?php echo $article['visibility'] === 'password_protected' ? 'show' : ''; ?>">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control form-control-sm" id="password" name="password" value="<?php echo htmlspecialchars($article['password']); ?>" placeholder="Enter password">
                            </div>
                            <hr>
                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="featured" name="featured" value="1" <?php echo $article['featured'] ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="featured">Featured Article</label>
                            </div>
                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="breaking_news" name="breaking_news" value="1" <?php echo $article['breaking_news'] ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="breaking_news">Breaking News</label>
                            </div>
                            <div class="action-buttons">
                                <div class="d-grid gap-2">
                                    <button type="submit" name="save" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save Draft</button>
                                    <?php if (hasPermission('publish_article')): ?>
                                    <button type="submit" name="publish" class="btn btn-success btn-sm"><i class="fas fa-check-circle me-1"></i> Save & Publish</button>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <button type="submit" name="save_and_continue" class="btn btn-outline-primary btn-sm flex-grow-1 me-1"><i class="fas fa-edit fa-xs"></i> Continue</button>
                                    <button type="submit" name="save_and_new" class="btn btn-outline-secondary btn-sm flex-grow-1 ms-1"><i class="fas fa-plus fa-xs"></i> Add New</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Categories Card -->
                    <div class="card mb-4">
                        <div class="card-header"> <h5 class="mb-0">Categories</h5> </div>
                        <div class="card-body">
                            <?php if (!empty($categories)): ?>
                                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 0.5rem; border-radius: 0.25rem;">
                                    <?php foreach ($categories as $category): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo $category['id']; ?>" id="cat_<?php echo $category['id']; ?>"
                                                <?php echo in_array($category['id'], $article['selected_categories']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="cat_<?php echo $category['id']; ?>">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="form-text text-muted mt-1 d-block">Select one or more categories.</small>
                            <?php else: ?>
                                <p class="text-muted text-center small mb-1">No categories found.</p>
                                <a href="categories.php?action=new" class="btn btn-sm btn-outline-secondary d-block"><i class="fas fa-folder-plus me-1"></i> Add Category</a>
                            <?php endif; ?>
                        </div>
                    </div>


                    <!-- Tags Card -->
                    <div class="card mb-4">
                         <div class="card-header"> <h5 class="mb-0">Tags</h5> </div>
                        <div class="card-body">
                            <?php if (!empty($tags)): ?>
                                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 0.5rem; border-radius: 0.25rem;">
                                     <?php foreach ($tags as $tag): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="tags[]" value="<?php echo $tag['id']; ?>" id="tag_<?php echo $tag['id']; ?>"
                                                <?php echo in_array($tag['id'], $article['selected_tags']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="tag_<?php echo $tag['id']; ?>">
                                                <?php echo htmlspecialchars($tag['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="form-text text-muted mt-1 d-block">Select relevant tags.</small>
                            <?php else: ?>
                                <p class="text-muted text-center small mb-0">No tags found.</p>
                                <!-- Add link to create tags if applicable -->
                            <?php endif; ?>
                        </div>
                    </div>
                </div> <!-- End Sidebar Column -->
            </div> <!-- End Row -->
        </form>

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(SITE_NAME) . ' ' . date('Y'); ?>
        </footer>
    </div> <!-- End Main Content -->

    <!-- Core JavaScript -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous" defer></script>
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
            let quill = null; // Declare quill variable
            const editorContainer = document.getElementById('editor-container');
            if (editorContainer) {
                 quill = new Quill('#editor-container', {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'link'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            ['blockquote', 'code-block'],
                            ['image', 'video'], // Allow image and video embedding
                            ['clean']
                        ]
                    },
                    placeholder: 'Start writing your amazing article...',
                });

                 // Set initial content safely (handle potential script injection if content wasn't trusted)
                 const initialContent = <?php echo json_encode($article['content']); ?>;
                 if (initialContent) {
                     // It's generally safer to set content via delta or pasteHTML if source is untrusted
                     // But assuming content from DB is okay for now:
                     quill.root.innerHTML = initialContent;
                 }
            }

            // --- Form Submission Handling ---
            const articleForm = document.getElementById('articleForm');
            if (articleForm) {
                articleForm.addEventListener('submit', function(e) {
                    // Update hidden input with Quill content
                    const contentInput = document.getElementById('content');
                    if (quill && contentInput) {
                        contentInput.value = quill.root.innerHTML;
                    }

                    // Basic Validation
                    const title = document.getElementById('title').value.trim();
                    if (!title) {
                        e.preventDefault(); alert('Article title is required.'); return;
                    }
                    // Check Quill content (getText() gets plain text, check length > 1 for more than just a newline)
                    if (quill && quill.getText().trim().length < 1) {
                        e.preventDefault(); alert('Article content cannot be empty.'); return;
                    }
                    const visibility = document.getElementById('visibility').value;
                    const password = document.getElementById('password').value;
                    if (visibility === 'password_protected' && !password.trim()) {
                        e.preventDefault(); alert('Password is required for password-protected visibility.'); return;
                    }
                    // Optional: Add validation for file size/type again on client-side if desired
                });
            }

			// Function to transliterate non-English characters to ASCII
 
            // --- Slug Generation ---
            const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');

// Function to generate random characters
function generateRandomCharacters(length = 6) {
    const characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += characters.charAt(Math.floor(Math.random() * characters.length));
    }
    return result;
}

if (titleInput && slugInput) {
    titleInput.addEventListener('blur', function () {
        if (slugInput.value.trim() === '') {
            const titleValue = this.value.trim();
            if (titleValue) {
                slugInput.value = titleValue.toLowerCase()
                    .replace(/\s+/g, '-')           // Replace spaces with -
                    .replace(/[^\w-]+/g, '')       // Remove all non-word chars except -
                    .replace(/--+/g, '-')         // Replace multiple - with single -
                    .replace(/^-+/, '')           // Trim - from start
                    .replace(/-+$/, '');          // Trim - from end
                
                // Append random characters at the end of the slug
                slugInput.value += '-' + generateRandomCharacters();
            }
        }
    });
}

            // --- Visibility Toggle ---
            const visibilitySelect = document.getElementById('visibility');
            const passwordField = document.getElementById('password_field');
            if (visibilitySelect && passwordField) {
                visibilitySelect.addEventListener('change', function() {
                    passwordField.classList.toggle('show', this.value === 'password_protected');
                });
            }

            // --- Image Preview ---
            const imageInput = document.getElementById('featured_image');
            const imagePreview = document.getElementById('image_preview');
            if (imageInput && imagePreview) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            imagePreview.src = event.target.result;
                            imagePreview.style.display = 'block';
                        }
                        reader.readAsDataURL(file);
                    } else {
                        imagePreview.src = '#';
                        imagePreview.style.display = 'none';
                    }
                });
            }

        }); // End DOMContentLoaded
    </script>

</body>
</html>