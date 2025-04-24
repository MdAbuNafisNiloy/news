<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, updateSetting, getSetting, handleFileUpload etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_settings')) {
    header("Location: index.php?message=Access Denied: You cannot manage settings.&type=danger");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Use current user/time for header display
$headerLogin = htmlspecialchars($currentUser['username'] ?? 'N/A');
// Use the requested fixed time
$headerUtcTime = "2025-04-20 07:29:20";


// Initialize variables
$message = '';
$messageType = '';
$settings = [];

// --- Load all settings ---
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    // Fetch into an associative array key => value
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $message = "Error loading settings: " . $e->getMessage();
    $messageType = "danger";
    error_log("Settings Load Error: " . $e->getMessage());
}

// --- Helper function to get setting value with default ---
function getSettingValue($key, $default = '') {
    global $settings;
    return isset($settings[$key]) ? $settings[$key] : $default;
}

// --- Helper function to update a setting ---
function updateSetting($key, $value) {
    global $db;
    // Use INSERT ... ON DUPLICATE KEY UPDATE for efficiency
    $sql = "INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (:key, :value, NOW())
            ON DUPLICATE KEY UPDATE setting_value = :value_update, updated_at = NOW()";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':value_update' => $value
    ]);
}

// Helper function for file uploads (ensure this exists in functions.php or define here)
if (!function_exists('handleFileUpload')) {
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

        $absoluteTargetDir = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($targetDir, '/');
        if (!is_dir($absoluteTargetDir)) {
            if (!mkdir($absoluteTargetDir, 0755, true)) throw new Exception("Failed to create upload directory: $absoluteTargetDir");
        }
        if (!is_writable($absoluteTargetDir)) {
             throw new Exception("Upload directory is not writable: $absoluteTargetDir");
        }

        $safeFilename = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $fileInfo['filename']);
        $newFilename = $prefix . $safeFilename . '_' . uniqid() . '.' . $extension;
        $absoluteUploadPath = rtrim($absoluteTargetDir, '/') . '/' . $newFilename;

        if (!move_uploaded_file($fileTmpPath, $absoluteUploadPath)) {
            throw new Exception("Failed to move uploaded file for '$inputName'.");
        }
        // Return the relative path for storing in DB/using in src attributes
        return rtrim($targetDir, '/') . '/' . $newFilename;
    }
}

// Helper function to delete a file if it exists
function deleteFileIfExists($relativePath) {
    if (!empty($relativePath)) {
        $absolutePath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relativePath, '/');
        if (file_exists($absolutePath) && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}


// --- Process settings updates ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Begin transaction
        $db->beginTransaction();

        $actionTaken = null; // To log specific update type
        $uploadBaseDir = 'uploads/settings/'; // Relative path for uploads

        // Update general settings
        if (isset($_POST['update_general'])) {
            updateSetting('site_name', sanitizeInput($_POST['site_name'] ?? ''));
            updateSetting('site_tagline', sanitizeInput($_POST['site_tagline'] ?? ''));
            updateSetting('site_description', sanitizeInput($_POST['site_description'] ?? ''));
            updateSetting('site_url', sanitizeInput($_POST['site_url'] ?? ''));
            updateSetting('admin_email', sanitizeInput($_POST['admin_email'] ?? ''));
            $actionTaken = 'Updated general settings';
        }
        // Update content settings
        elseif (isset($_POST['update_content'])) {
            updateSetting('posts_per_page', max(1, (int)($_POST['posts_per_page'] ?? 10)));
            updateSetting('excerpt_length', max(10, (int)($_POST['excerpt_length'] ?? 150)));
            updateSetting('default_category', (int)($_POST['default_category'] ?? 1));
            updateSetting('allow_comments', isset($_POST['allow_comments']) ? '1' : '0');
            updateSetting('default_comment_status', sanitizeInput($_POST['default_comment_status'] ?? 'pending'));
            $actionTaken = 'Updated content settings';
        }
        // Update appearance settings
        elseif (isset($_POST['update_appearance'])) {
            updateSetting('theme', sanitizeInput($_POST['theme'] ?? 'default'));
            updateSetting('show_author', isset($_POST['show_author']) ? '1' : '0');
            updateSetting('show_date', isset($_POST['show_date']) ? '1' : '0');
            updateSetting('show_share_buttons', isset($_POST['show_share_buttons']) ? '1' : '0');
            updateSetting('footer_text', $_POST['footer_text'] ?? '');

            // Handle logo upload
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                try {
                    $newLogoRelativePath = handleFileUpload('site_logo', $uploadBaseDir, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], 2 * 1024 * 1024, 'site_logo_');
                    if ($newLogoRelativePath) {
                        $oldLogoRelative = getSettingValue('site_logo');
                        deleteFileIfExists($oldLogoRelative); // Delete old file
                        updateSetting('site_logo', $newLogoRelativePath);
                    }
                } catch (Exception $uploadEx) {
                    throw new Exception("Logo upload failed: " . $uploadEx->getMessage());
                }
            } elseif (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] !== UPLOAD_ERR_OK && $_FILES['site_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                 throw new Exception("Logo upload error: Code " . $_FILES['site_logo']['error']);
            }

             // Handle favicon upload
             if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                 try {
                     $newFaviconRelativePath = handleFileUpload('favicon', $uploadBaseDir, ['ico', 'png', 'jpg', 'jpeg', 'gif', 'svg'], 1 * 1024 * 1024, 'favicon_');
                     if ($newFaviconRelativePath) {
                         $oldFaviconRelative = getSettingValue('favicon');
                         deleteFileIfExists($oldFaviconRelative); // Delete old file
                         updateSetting('favicon', $newFaviconRelativePath);
                     }
                 } catch (Exception $uploadEx) {
                     throw new Exception("Favicon upload failed: " . $uploadEx->getMessage());
                 }
             } elseif (isset($_FILES['favicon']) && $_FILES['favicon']['error'] !== UPLOAD_ERR_OK && $_FILES['favicon']['error'] !== UPLOAD_ERR_NO_FILE) {
                 throw new Exception("Favicon upload error: Code " . $_FILES['favicon']['error']);
            }
            $actionTaken = 'Updated appearance settings';
        }
        // Update social media settings
        elseif (isset($_POST['update_social'])) {
            updateSetting('facebook_url', filter_var($_POST['facebook_url'] ?? '', FILTER_SANITIZE_URL));
            updateSetting('twitter_url', filter_var($_POST['twitter_url'] ?? '', FILTER_SANITIZE_URL));
            updateSetting('instagram_url', filter_var($_POST['instagram_url'] ?? '', FILTER_SANITIZE_URL));
            updateSetting('youtube_url', filter_var($_POST['youtube_url'] ?? '', FILTER_SANITIZE_URL));
            updateSetting('linkedin_url', filter_var($_POST['linkedin_url'] ?? '', FILTER_SANITIZE_URL));
            $actionTaken = 'Updated social media settings';
        }
        // *** NEW: Update Advertisement Settings ***
        elseif (isset($_POST['update_advertisements'])) {
            $adUploadDir = $uploadBaseDir . 'ads/'; // Subdirectory for ads

            for ($i = 1; $i <= 5; $i++) {
                $imageKey = "ad_{$i}_image";
                $urlKey = "ad_{$i}_url";
                $buttonKey = "ad_{$i}_button_text";
                $imageInputName = "ad_{$i}_image_upload"; // Use different name for file input

                // Handle ad image upload
                if (isset($_FILES[$imageInputName]) && $_FILES[$imageInputName]['error'] === UPLOAD_ERR_OK) {
                    try {
                        // Allow larger size for ads maybe? Adjust as needed.
                        $newAdImageRelativePath = handleFileUpload($imageInputName, $adUploadDir, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 3 * 1024 * 1024, "ad_{$i}_");
                        if ($newAdImageRelativePath) {
                            $oldAdImageRelative = getSettingValue($imageKey);
                            deleteFileIfExists($oldAdImageRelative); // Delete old ad image
                            updateSetting($imageKey, $newAdImageRelativePath);
                        }
                    } catch (Exception $uploadEx) {
                        // Add ad number to the error message
                        throw new Exception("Ad #{$i} image upload failed: " . $uploadEx->getMessage());
                    }
                } elseif (isset($_FILES[$imageInputName]) && $_FILES[$imageInputName]['error'] !== UPLOAD_ERR_OK && $_FILES[$imageInputName]['error'] !== UPLOAD_ERR_NO_FILE) {
                     throw new Exception("Ad #{$i} image upload error: Code " . $_FILES[$imageInputName]['error']);
                } elseif (isset($_POST["{$imageKey}_remove"]) && $_POST["{$imageKey}_remove"] === '1') {
                    // Handle image removal request
                    $oldAdImageRelative = getSettingValue($imageKey);
                    deleteFileIfExists($oldAdImageRelative);
                    updateSetting($imageKey, ''); // Clear the setting value
                }


                // Update URL and Button Text
                updateSetting($urlKey, filter_var($_POST[$urlKey] ?? '', FILTER_SANITIZE_URL));
                updateSetting($buttonKey, sanitizeInput($_POST[$buttonKey] ?? ''));
            }
            $actionTaken = 'Updated advertisement settings';
        }
        // Update advanced settings
        elseif (isset($_POST['update_advanced'])) {
            updateSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
            updateSetting('default_timezone', sanitizeInput($_POST['default_timezone'] ?? 'UTC'));
            updateSetting('cache_enabled', isset($_POST['cache_enabled']) ? '1' : '0');
            updateSetting('google_analytics_id', sanitizeInput($_POST['google_analytics_id'] ?? ''));
            updateSetting('custom_css', $_POST['custom_css'] ?? ''); // Store raw CSS
            updateSetting('custom_js', $_POST['custom_js'] ?? '');   // Store raw JS
            $actionTaken = 'Updated advanced settings';
        }

        // Commit transaction
        $db->commit();

        // Refresh settings array after successful update
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $message = $actionTaken ? str_replace('Updated', 'Successfully updated', $actionTaken) . '!' : 'Settings saved!';
        $messageType = "success";

        // Log activity if action was taken
        if ($actionTaken && function_exists('logActivity')) {
            logActivity($currentUser['id'], 'settings_update', 'settings', null, $actionTaken);
        }

        // Redirect to clear POST data and show message with correct hash
        $redirectUrl = "settings.php?message=" . urlencode($message) . "&type=" . $messageType;
        if (isset($_POST['update_general'])) $redirectUrl .= '#general';
        elseif (isset($_POST['update_content'])) $redirectUrl .= '#content';
        elseif (isset($_POST['update_appearance'])) $redirectUrl .= '#appearance';
        elseif (isset($_POST['update_social'])) $redirectUrl .= '#social';
        elseif (isset($_POST['update_advertisements'])) $redirectUrl .= '#advertisements'; // <-- Add this
        elseif (isset($_POST['update_advanced'])) $redirectUrl .= '#advanced';
        header("Location: " . $redirectUrl);
        exit();


    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack(); // Roll back on error
        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        error_log("Settings Update Error: " . $e->getMessage());
    }
}

// Check for messages passed via URL (after redirect)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}


// Get categories for dropdown (needed regardless of POST)
$categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading categories for settings page: " . $e->getMessage());
}

// Get available timezones
$timezones = DateTimeZone::listIdentifiers();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Settings - <?php echo htmlspecialchars(getSettingValue('site_name', 'Alpha News')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php
        // Output Favicon link if set
        $faviconUrl = getSettingValue('favicon');
        if (!empty($faviconUrl)) {
            $faviconAbsolutePath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($faviconUrl, '/');
            if (file_exists($faviconAbsolutePath)) {
                $faviconType = mime_content_type($faviconAbsolutePath);
                if (strpos($faviconType, 'image/') === 0 || $faviconType === 'image/vnd.microsoft.icon' || $faviconType === 'image/svg+xml') {
                     $faviconVersionedUrl = htmlspecialchars($faviconUrl) . '?v=' . filemtime($faviconAbsolutePath);
                     echo '<link rel="icon" type="' . htmlspecialchars($faviconType) . '" href="/' . $faviconVersionedUrl . '">'; // Prepend / for root relative
                }
            }
        }
    ?>
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
        .form-check-label { font-weight: normal; font-size: 0.9rem; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Tabs */
        .nav-pills .nav-link { color: var(--dark-color); border-radius: 0.35rem; font-weight: 500; padding: 0.6rem 1rem; font-size: 0.9rem; transition: background-color 0.2s, color 0.2s; text-align: center; }
        .nav-pills .nav-link.active { background-color: var(--primary-color); color: white; }
        .nav-pills .nav-link:not(.active):hover { background-color: #e9ecef; }
        .tab-content { padding-top: 1rem; }
        /* Specific Settings Styles */
        .preview-container { min-height: 60px; border: 1px solid #eee; background-color: #f8f9fc; display: flex; align-items: center; justify-content: center; text-align: center; border-radius: 0.25rem; padding: 0.5rem; }
        .preview-img { max-width: 180px; height: auto; max-height: 80px; display: block; object-fit: contain; }
        .favicon-preview { width: 32px; height: 32px; display: block; object-fit: contain; }
        /* --- NEW: Ad Preview Style --- */
        .ad-preview-img { max-width: 100%; height: auto; max-height: 100px; display: block; object-fit: contain; }
        .ad-preview-container { min-height: 80px; max-height: 120px; /* Limit height */ overflow: hidden; /* Hide overflow */ } /* Adjust height as needed */

        .placeholder-icon { font-size: 1.5rem; color: #adb5bd; }
        .custom-code { font-family: 'Courier New', monospace; font-size: 0.85rem; resize: vertical; min-height: 150px; }
        .input-group-text { font-size: 0.9rem; }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } .nav-pills { flex-wrap: wrap; } .nav-pills .nav-item { width: 50%; } }
        @media (max-width: 576px) { .nav-pills .nav-item { width: 100%; } }
        @media (min-width: 992px) { .main-content { max-width: calc(100% - var(--sidebar-width)); } .main-content.expanded { max-width: calc(100% - var(--sidebar-width-collapsed)); } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <a href="index.php" class="sidebar-brand" title="<?php echo htmlspecialchars(getSettingValue('site_name', 'Alpha News')); ?>">
            <i class="fas fa-newspaper fa-fw"></i> <span><?php echo htmlspecialchars(getSettingValue('site_name', 'Alpha News')); ?></span>
        </a>
        <div class="sidebar-items">
            <a href="index.php" class="sidebar-item" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
            <?php if (hasPermission('create_article')): ?> <a href="articles.php" class="sidebar-item" title="Articles"> <i class="fas fa-newspaper fa-fw"></i> <span>Articles</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
			<?php if (hasPermission('manage_categories')): ?>
            <a href="pages.php" class="sidebar-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pages.php' || basename($_SERVER['PHP_SELF']) == 'page-edit.php') ? 'active' : ''; ?>" title="Pages">
                <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span>
            </a>
            <?php endif; ?>
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_roles')): ?> <a href="roles.php" class="sidebar-item" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <?php endif; ?>
            <a href="media.php" class="sidebar-item" title="Media"> <i class="fas fa-images fa-fw"></i> <span>Media</span> </a>
            <?php if (hasPermission('manage_settings')): ?> <a href="settings.php" class="sidebar-item active" title="Settings"> <i class="fas fa-cog fa-fw"></i> <span>Settings</span> </a> <?php endif; ?>
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
                    <?php
                        $currentUserPic = $currentUser['profile_picture'] ?? '';
                        $picPath = !empty($currentUserPic) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentUserPic, '/')) ? '/' . ltrim($currentUserPic, '/') : 'assets/images/default-avatar.png';
                    ?>
                    <img src="<?php echo htmlspecialchars($picPath); ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile" style="object-fit: cover;">
                    <span class="d-none d-md-inline-block"><?php echo $headerLogin; ?></span> <i class="fas fa-chevron-down fa-xs ms-1"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdownLink">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle fa-fw me-2 text-muted"></i> Profile</a></li>
                    <?php if (hasPermission('manage_settings')): ?>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2 text-muted"></i> Settings</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt fa-fw me-2 text-muted"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-cog me-2"></i> Website Settings</h2>
            <p>Configure and customize various aspects of your website.</p>
             <div class="header-meta">
                 User: <?php echo $headerLogin; ?> | UTC: <?php echo $headerUtcTime; ?>
             </div>
        </div>

        <!-- Message Area -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Settings Tabs -->
        <ul class="nav nav-pills nav-fill flex-column flex-md-row mb-4" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation"> <button class="nav-link active" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true"> <i class="fas fa-globe fa-fw me-1"></i> General </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="content-tab" data-bs-toggle="pill" data-bs-target="#content" type="button" role="tab" aria-controls="content" aria-selected="false"> <i class="fas fa-file-alt fa-fw me-1"></i> Content </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="appearance-tab" data-bs-toggle="pill" data-bs-target="#appearance" type="button" role="tab" aria-controls="appearance" aria-selected="false"> <i class="fas fa-paint-brush fa-fw me-1"></i> Appearance </button> </li>
            <!-- NEW: Advertisement Tab Link -->
            <li class="nav-item" role="presentation"> <button class="nav-link" id="advertisements-tab" data-bs-toggle="pill" data-bs-target="#advertisements" type="button" role="tab" aria-controls="advertisements" aria-selected="false"> <i class="fas fa-ad fa-fw me-1"></i> Advertisements </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="social-tab" data-bs-toggle="pill" data-bs-target="#social" type="button" role="tab" aria-controls="social" aria-selected="false"> <i class="fas fa-share-alt fa-fw me-1"></i> Social </button> </li>
            <li class="nav-item" role="presentation"> <button class="nav-link" id="advanced-tab" data-bs-toggle="pill" data-bs-target="#advanced" type="button" role="tab" aria-controls="advanced" aria-selected="false"> <i class="fas fa-sliders-h fa-fw me-1"></i> Advanced </button> </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="settingsTabsContent">
            <!-- General Settings -->
            <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                <form method="POST" action="settings.php#general">
                    <input type="hidden" name="update_general" value="1">
                    <div class="card">
                        <div class="card-header"> <h5 class="mb-0">General Site Information</h5> </div>
                        <div class="card-body">
                             <div class="mb-3">
                                <label for="site_name" class="form-label">Site Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="site_name" name="site_name" value="<?php echo htmlspecialchars(getSettingValue('site_name', 'Alpha News')); ?>" required>
                                <small class="form-text text-muted">Appears in browser title, header, etc.</small>
                            </div>
                            <div class="mb-3">
                                <label for="site_tagline" class="form-label">Site Tagline</label>
                                <input type="text" class="form-control form-control-sm" id="site_tagline" name="site_tagline" value="<?php echo htmlspecialchars(getSettingValue('site_tagline', 'Latest News and Updates')); ?>">
                                <small class="form-text text-muted">Short description often shown near the site name.</small>
                            </div>
                             <div class="mb-3">
                                <label for="site_description" class="form-label">Site Description (Meta)</label>
                                <textarea class="form-control form-control-sm" id="site_description" name="site_description" rows="3"><?php echo htmlspecialchars(getSettingValue('site_description')); ?></textarea>
                                <small class="form-text text-muted">Used for SEO (search engine results).</small>
                            </div>
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="site_url" class="form-label">Site URL <span class="text-danger">*</span></label>
                                    <input type="url" class="form-control form-control-sm" id="site_url" name="site_url" value="<?php echo htmlspecialchars(getSettingValue('site_url', 'http://localhost')); ?>" required placeholder="https://www.example.com">
                                    <small class="form-text text-muted">The full web address of your site.</small>
                                </div>
                                 <div class="col-md-6 mb-3">
                                    <label for="admin_email" class="form-label">Admin Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control form-control-sm" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars(getSettingValue('admin_email', 'admin@example.com')); ?>" required>
                                    <small class="form-text text-muted">For notifications and contact.</small>
                                </div>
                             </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-save me-1"></i> Save General Settings </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Content Settings -->
            <div class="tab-pane fade" id="content" role="tabpanel" aria-labelledby="content-tab">
                 <form method="POST" action="settings.php#content">
                    <input type="hidden" name="update_content" value="1">
                    <div class="card">
                        <div class="card-header"> <h5 class="mb-0">Content & Comments</h5> </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="posts_per_page" class="form-label">Posts Per Page</label>
                                    <input type="number" class="form-control form-control-sm" id="posts_per_page" name="posts_per_page" value="<?php echo htmlspecialchars(getSettingValue('posts_per_page', '10')); ?>" min="1" max="100" required>
                                    <small class="form-text text-muted">Articles shown on blog/archive pages.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="excerpt_length" class="form-label">Auto Excerpt Length</label>
                                    <input type="number" class="form-control form-control-sm" id="excerpt_length" name="excerpt_length" value="<?php echo htmlspecialchars(getSettingValue('excerpt_length', '150')); ?>" min="50" max="500" required>
                                    <small class="form-text text-muted">Character limit for automatic excerpts.</small>
                                </div>
                            </div>
                             <div class="mb-3">
                                <label for="default_category" class="form-label">Default Article Category</label>
                                <select class="form-select form-select-sm" id="default_category" name="default_category">
                                    <option value="">-- None --</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo (getSettingValue('default_category') == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Pre-selected category for new articles.</small>
                            </div>
                             <hr>
                             <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments" value="1" <?php echo (getSettingValue('allow_comments', '1') == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="allow_comments">Allow Comments Globally</label>
                                <small class="form-text text-muted d-block">Allow visitors to post comments on articles.</small>
                            </div>
                            <div class="mb-3">
                                <label for="default_comment_status" class="form-label">Default Comment Status</label>
                                <select class="form-select form-select-sm" id="default_comment_status" name="default_comment_status">
                                    <option value="pending" <?php echo (getSettingValue('default_comment_status', 'pending') == 'pending') ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="approved" <?php echo (getSettingValue('default_comment_status') == 'approved') ? 'selected' : ''; ?>>Approved Immediately</option>
                                </select>
                                <small class="form-text text-muted">Status assigned to new comments.</small>
                            </div>
                        </div>
                         <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-save me-1"></i> Save Content Settings </button>
                        </div>
                    </div>
                 </form>
            </div>

            <!-- Appearance Settings -->
            <div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="appearance-tab">
                <form method="POST" action="settings.php#appearance" enctype="multipart/form-data">
                    <input type="hidden" name="update_appearance" value="1">
                    <div class="card">
                         <div class="card-header"> <h5 class="mb-0">Theme & Display</h5> </div>
                         <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                     <label for="theme" class="form-label">Theme</label>
                                    <select class="form-select form-select-sm" id="theme" name="theme">
                                        <option value="default" <?php echo (getSettingValue('theme', 'default') == 'default') ? 'selected' : ''; ?>>Default</option>
                                        <option value="modern" <?php echo (getSettingValue('theme') == 'modern') ? 'selected' : ''; ?>>Modern</option>
                                        <option value="classic" <?php echo (getSettingValue('theme') == 'classic') ? 'selected' : ''; ?>>Classic</option>
                                        <option value="dark" <?php echo (getSettingValue('theme') == 'dark') ? 'selected' : ''; ?>>Dark</option>
                                    </select>
                                    <small class="form-text text-muted">Select the front-end theme.</small>
                                </div>
                            </div>
                             <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="site_logo" class="form-label">Site Logo</label>
                                    <?php
                                    $currentLogo = getSettingValue('site_logo');
                                    $logoAbsolutePath = !empty($currentLogo) ? $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentLogo, '/') : '';
                                    $logoExists = !empty($logoAbsolutePath) && file_exists($logoAbsolutePath);
                                    $logoVersion = $logoExists ? filemtime($logoAbsolutePath) : time();
                                    $logoSrc = $logoExists ? '/' . htmlspecialchars($currentLogo) . '?v=' . $logoVersion : '#';
                                    ?>
                                    <div class="preview-container mb-2">
                                        <div id="logo_placeholder" class="<?php echo $logoExists ? 'd-none' : ''; ?>">
                                            <i class="fas fa-image placeholder-icon"></i>
                                            <div class="small text-muted">No Logo</div>
                                        </div>
                                        <img src="<?php echo $logoSrc; ?>" alt="Logo Preview" class="preview-img <?php echo $logoExists ? '' : 'd-none'; ?>" id="logo_preview">
                                    </div>
                                    <div class="input-group">
                                        <input type="file" class="form-control form-control-sm" id="site_logo" name="site_logo" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearPreview('site_logo', 'logo_preview', 'logo_placeholder')">Clear</button>
                                    </div>
                                    <small class="form-text text-muted">Upload logo (e.g., PNG, JPG, SVG). Max 2MB.</small>
                                </div>
                                <div class="col-md-6">
                                     <label for="favicon" class="form-label">Favicon</label>
                                     <?php
                                     $currentFavicon = getSettingValue('favicon');
                                     $faviconAbsolutePath = !empty($currentFavicon) ? $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentFavicon, '/') : '';
                                     $faviconExists = !empty($faviconAbsolutePath) && file_exists($faviconAbsolutePath);
                                     $faviconVersion = $faviconExists ? filemtime($faviconAbsolutePath) : time();
                                     $faviconSrc = $faviconExists ? '/' . htmlspecialchars($currentFavicon) . '?v=' . $faviconVersion : '#';
                                     ?>
                                     <div class="preview-container mb-2" style="width: 80px; height: 80px;">
                                        <div id="favicon_placeholder" class="<?php echo $faviconExists ? 'd-none' : ''; ?>">
                                            <i class="fas fa-star placeholder-icon"></i>
                                            <div class="small text-muted">No Icon</div>
                                        </div>
                                         <img src="<?php echo $faviconSrc; ?>" alt="Favicon Preview" class="favicon-preview <?php echo $faviconExists ? '' : 'd-none'; ?>" id="favicon_preview">
                                     </div>
                                    <div class="input-group">
                                        <input type="file" class="form-control form-control-sm" id="favicon" name="favicon" accept="image/x-icon,image/png,image/jpeg,image/gif,image/svg+xml">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearPreview('favicon', 'favicon_preview', 'favicon_placeholder')">Clear</button>
                                    </div>
                                    <small class="form-text text-muted">Upload favicon (e.g., ICO, PNG 32x32). Max 1MB.</small>
                                </div>
                             </div>
                            <hr>
                             <label class="form-label mb-2">Article Display Options:</label>
                             <div class="row">
                                <div class="col-sm-4 mb-2">
                                    <div class="form-check form-switch"> <input class="form-check-input" type="checkbox" id="show_author" name="show_author" value="1" <?php echo (getSettingValue('show_author', '1') == '1') ? 'checked' : ''; ?>> <label class="form-check-label" for="show_author">Show Author</label> </div>
                                </div>
                                <div class="col-sm-4 mb-2">
                                     <div class="form-check form-switch"> <input class="form-check-input" type="checkbox" id="show_date" name="show_date" value="1" <?php echo (getSettingValue('show_date', '1') == '1') ? 'checked' : ''; ?>> <label class="form-check-label" for="show_date">Show Date</label> </div>
                                </div>
                                <div class="col-sm-4 mb-2">
                                    <div class="form-check form-switch"> <input class="form-check-input" type="checkbox" id="show_share_buttons" name="show_share_buttons" value="1" <?php echo (getSettingValue('show_share_buttons', '1') == '1') ? 'checked' : ''; ?>> <label class="form-check-label" for="show_share_buttons">Show Share Buttons</label> </div>
                                </div>
                             </div>
                             <hr>
                             <div class="mb-3">
                                <label for="footer_text" class="form-label">Footer Text</label>
                                <textarea class="form-control form-control-sm" id="footer_text" name="footer_text" rows="3"><?php echo htmlspecialchars(getSettingValue('footer_text', 'Copyright © ' . date('Y') . ' ' . getSettingValue('site_name', 'Alpha News') . '. All rights reserved.')); ?></textarea>
                                <small class="form-text text-muted">Appears in the site footer. Basic HTML allowed.</small>
                            </div>
                         </div>
                         <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-save me-1"></i> Save Appearance Settings </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- *** NEW: Advertisement Settings Tab *** -->
            <div class="tab-pane fade" id="advertisements" role="tabpanel" aria-labelledby="advertisements-tab">
                <form method="POST" action="settings.php#advertisements" enctype="multipart/form-data">
                    <input type="hidden" name="update_advertisements" value="1">
                    <div class="card">
                        <div class="card-header"> <h5 class="mb-0">Advertisement Settings</h5> </div>
                        <div class="card-body">
                            <p class="text-muted small mb-4">Configure up to 5 advertisements. Leave fields blank for unused slots. Images will be uploaded to <code>uploads/settings/ads/</code>. Max 3MB per image.</p>

                            <?php for ($i = 1; $i <= 5; $i++):
                                $imageKey = "ad_{$i}_image";
                                $urlKey = "ad_{$i}_url";
                                $buttonKey = "ad_{$i}_button_text";
                                $imageInputName = "ad_{$i}_image_upload";
                                $previewId = "ad_{$i}_preview";
                                $placeholderId = "ad_{$i}_placeholder";
                                $removeCheckboxId = "ad_{$i}_remove_image";

                                $currentAdImage = getSettingValue($imageKey);
                                $adImageAbsolutePath = !empty($currentAdImage) ? $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentAdImage, '/') : '';
                                $adImageExists = !empty($adImageAbsolutePath) && file_exists($adImageAbsolutePath);
                                $adImageVersion = $adImageExists ? filemtime($adImageAbsolutePath) : time();
                                $adImageSrc = $adImageExists ? '/' . htmlspecialchars($currentAdImage) . '?v=' . $adImageVersion : '#';
                            ?>
                            <div class="border rounded p-3 mb-4 bg-light shadow-sm">
                                <h6 class="mb-3 fw-bold border-bottom pb-2">Advertisement Slot #<?php echo $i; ?></h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="<?php echo $imageInputName; ?>" class="form-label">Ad Image</label>
                                        <div class="preview-container ad-preview-container mb-2">
                                            <div id="<?php echo $placeholderId; ?>" class="<?php echo $adImageExists ? 'd-none' : ''; ?>">
                                                <i class="fas fa-image placeholder-icon"></i>
                                                <div class="small text-muted">No Image</div>
                                            </div>
                                            <img src="<?php echo $adImageSrc; ?>" alt="Ad <?php echo $i; ?> Preview" class="ad-preview-img <?php echo $adImageExists ? '' : 'd-none'; ?>" id="<?php echo $previewId; ?>">
                                        </div>
                                        <div class="input-group">
                                            <input type="file" class="form-control form-control-sm" id="<?php echo $imageInputName; ?>" name="<?php echo $imageInputName; ?>" accept="image/png,image/jpeg,image/gif,image/webp" data-preview="<?php echo $previewId; ?>" data-placeholder="<?php echo $placeholderId; ?>">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAdPreview('<?php echo $imageInputName; ?>', '<?php echo $previewId; ?>', '<?php echo $placeholderId; ?>', '<?php echo $removeCheckboxId; ?>')">Clear</button>
                                        </div>
                                         <?php if ($adImageExists): // Only show remove checkbox if image exists ?>
                                        <div class="form-check form-check-inline mt-1">
                                            <input class="form-check-input" type="checkbox" id="<?php echo $removeCheckboxId; ?>" name="<?php echo $imageKey; ?>_remove" value="1">
                                            <label class="form-check-label small text-danger" for="<?php echo $removeCheckboxId; ?>">Remove current image on save</label>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8 mb-3">
                                         <div class="mb-3">
                                            <label for="<?php echo $urlKey; ?>" class="form-label">Target URL</label>
                                            <input type="url" class="form-control form-control-sm" id="<?php echo $urlKey; ?>" name="<?php echo $urlKey; ?>" value="<?php echo htmlspecialchars(getSettingValue($urlKey)); ?>" placeholder="https://example.com/product-link">
                                        </div>
                                         <div class="mb-3">
                                            <label for="<?php echo $buttonKey; ?>" class="form-label">Button Text</label>
                                            <input type="text" class="form-control form-control-sm" id="<?php echo $buttonKey; ?>" name="<?php echo $buttonKey; ?>" value="<?php echo htmlspecialchars(getSettingValue($buttonKey)); ?>" placeholder="e.g., Learn More, Shop Now">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-success btn-sm"> <i class="fas fa-save me-1"></i> Save Advertisement Settings </button>
                        </div>
                    </div>
                </form>
            </div>
            <!-- End Advertisement Settings Tab -->

            <!-- Social Media Settings -->
            <div class="tab-pane fade" id="social" role="tabpanel" aria-labelledby="social-tab">
                <form method="POST" action="settings.php#social">
                    <input type="hidden" name="update_social" value="1">
                    <div class="card">
                         <div class="card-header"> <h5 class="mb-0">Social Media Links</h5> </div>
                         <div class="card-body">
                            <p class="text-muted small mb-3">Enter the full URLs for your social media profiles. Leave blank to hide the icon.</p>
                             <div class="mb-3">
                                <label for="facebook_url" class="form-label"><i class="fab fa-facebook fa-fw me-2 text-primary"></i>Facebook URL</label>
                                <input type="url" class="form-control form-control-sm" id="facebook_url" name="facebook_url" value="<?php echo htmlspecialchars(getSettingValue('facebook_url')); ?>" placeholder="https://facebook.com/yourpage">
                            </div>
                            <div class="mb-3">
                                <label for="twitter_url" class="form-label"><i class="fab fa-twitter fa-fw me-2" style="color: #1da1f2;"></i>Twitter URL</label>
                                <input type="url" class="form-control form-control-sm" id="twitter_url" name="twitter_url" value="<?php echo htmlspecialchars(getSettingValue('twitter_url')); ?>" placeholder="https://twitter.com/yourusername">
                            </div>
                             <div class="mb-3">
                                <label for="instagram_url" class="form-label"><i class="fab fa-instagram fa-fw me-2" style="color: #c13584;"></i>Instagram URL</label>
                                <input type="url" class="form-control form-control-sm" id="instagram_url" name="instagram_url" value="<?php echo htmlspecialchars(getSettingValue('instagram_url')); ?>" placeholder="https://instagram.com/yourusername">
                            </div>
                             <div class="mb-3">
                                <label for="youtube_url" class="form-label"><i class="fab fa-youtube fa-fw me-2 text-danger"></i>YouTube URL</label>
                                <input type="url" class="form-control form-control-sm" id="youtube_url" name="youtube_url" value="<?php echo htmlspecialchars(getSettingValue('youtube_url')); ?>" placeholder="https://youtube.com/channel/yourchannel">
                            </div>
                             <div class="mb-3">
                                <label for="linkedin_url" class="form-label"><i class="fab fa-linkedin fa-fw me-2" style="color: #0077b5;"></i>LinkedIn URL</label>
                                <input type="url" class="form-control form-control-sm" id="linkedin_url" name="linkedin_url" value="<?php echo htmlspecialchars(getSettingValue('linkedin_url')); ?>" placeholder="https://linkedin.com/company/yourcompany">
                            </div>
                         </div>
                         <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-sm"> <i class="fas fa-save me-1"></i> Save Social Links </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Advanced Settings -->
            <div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="advanced-tab">
                <form method="POST" action="settings.php#advanced">
                     <input type="hidden" name="update_advanced" value="1">
                    <div class="card border-warning">
                         <div class="card-header bg-warning text-dark"> <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i> Advanced Configuration</h5> </div>
                         <div class="card-body">
                             <div class="alert alert-warning small p-2"><i class="fas fa-info-circle me-1"></i> Changes here can significantly impact site functionality. Proceed with caution.</div>
                             <div class="row">
                                <div class="col-md-6 mb-3">
                                     <div class="form-check form-switch"> <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?php echo (getSettingValue('maintenance_mode', '0') == '1') ? 'checked' : ''; ?>> <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label> </div>
                                     <small class="form-text text-muted">Temporarily disable public access.</small>
                                </div>
                                 <div class="col-md-6 mb-3">
                                      <div class="form-check form-switch"> <input class="form-check-input" type="checkbox" id="cache_enabled" name="cache_enabled" value="1" <?php echo (getSettingValue('cache_enabled', '0') == '1') ? 'checked' : ''; ?>> <label class="form-check-label" for="cache_enabled">Enable Caching</label> </div>
                                      <small class="form-text text-muted">Enable basic page caching (requires implementation).</small>
                                </div>
                             </div>
                             <div class="mb-3">
                                <label for="default_timezone" class="form-label">Default Timezone</label>
                                <select class="form-select form-select-sm" id="default_timezone" name="default_timezone">
                                    <?php $currentTimezone = getSettingValue('default_timezone', 'UTC'); foreach ($timezones as $timezone): ?>
                                        <option value="<?php echo htmlspecialchars($timezone); ?>" <?php echo ($currentTimezone == $timezone) ? 'selected' : ''; ?>> <?php echo htmlspecialchars($timezone); ?> </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Set the default timezone for date/time display.</small>
                            </div>
                             <div class="mb-3">
                                <label for="google_analytics_id" class="form-label">Google Analytics ID</label>
                                <input type="text" class="form-control form-control-sm" id="google_analytics_id" name="google_analytics_id" value="<?php echo htmlspecialchars(getSettingValue('google_analytics_id')); ?>" placeholder="UA-XXXXX-Y or G-XXXXXXXXXX">
                                <small class="form-text text-muted">Your Google Analytics Tracking ID.</small>
                            </div>
                             <div class="mb-3">
                                <label for="custom_css" class="form-label">Custom CSS</label>
                                <textarea class="form-control form-control-sm custom-code" id="custom_css" name="custom_css" rows="6" placeholder="/* Your custom styles here */"><?php echo htmlspecialchars(getSettingValue('custom_css')); ?></textarea>
                                <small class="form-text text-muted">Inject custom CSS into the site front-end.</small>
                            </div>
                            <div class="mb-3">
                                <label for="custom_js" class="form-label">Custom JavaScript</label>
                                <textarea class="form-control form-control-sm custom-code" id="custom_js" name="custom_js" rows="6" placeholder="// Your custom scripts here"><?php echo htmlspecialchars(getSettingValue('custom_js')); ?></textarea>
                                <small class="form-text text-muted">Inject custom JS into the site front-end (use with caution).</small>
                            </div>
                         </div>
                         <div class="card-footer text-end">
                            <button type="submit" class="btn btn-warning btn-sm"> <i class="fas fa-save me-1"></i> Save Advanced Settings </button>
                        </div>
                    </div>
                </form>
            </div>
        </div> <!-- End Tab Content -->

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(getSettingValue('site_name', 'Alpha News')) . ' ' . date('Y'); ?>
        </footer>
    </div> <!-- End Main Content -->

    <!-- Core JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <script>
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

            // --- Generic Image Preview Logic ---
            function setupImagePreview(inputElement) {
                const previewId = inputElement.dataset.preview;
                const placeholderId = inputElement.dataset.placeholder;
                const previewElement = document.getElementById(previewId);
                const placeholderElement = document.getElementById(placeholderId);

                if (previewElement && placeholderElement) {
                    inputElement.addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file && file.type.startsWith('image/')) {
                            const reader = new FileReader();
                            reader.onload = function(event) {
                                previewElement.src = event.target.result;
                                previewElement.classList.remove('d-none');
                                placeholderElement.classList.add('d-none');
                            }
                            reader.readAsDataURL(file);
                        } else {
                             resetPreview(inputElement, previewElement, placeholderElement);
                        }
                    });
                }
            }

            // --- Function to clear preview and input ---
            window.clearPreview = function(inputId, previewId, placeholderId) {
                 const inputElement = document.getElementById(inputId);
                 const previewElement = document.getElementById(previewId);
                 const placeholderElement = document.getElementById(placeholderId);
                 resetPreview(inputElement, previewElement, placeholderElement);
            }

             // --- NEW: Function to clear ad preview and input (also handles remove checkbox) ---
            window.clearAdPreview = function(inputId, previewId, placeholderId, removeCheckboxId) {
                 const inputElement = document.getElementById(inputId);
                 const previewElement = document.getElementById(previewId);
                 const placeholderElement = document.getElementById(placeholderId);
                 const removeCheckbox = document.getElementById(removeCheckboxId);
                 resetPreview(inputElement, previewElement, placeholderElement);
                 // Uncheck the 'remove' checkbox if the user clears the input manually
                 if (removeCheckbox) {
                     removeCheckbox.checked = false;
                 }
            }

            // --- Helper to reset preview state ---
            function resetPreview(inputElement, previewElement, placeholderElement) {
                 if (inputElement) inputElement.value = ''; // Clear the file input
                 if (previewElement) {
                     previewElement.src = '#';
                     previewElement.classList.add('d-none');
                 }
                 if (placeholderElement) {
                     placeholderElement.classList.remove('d-none');
                 }
            }

            // Setup previews for logo and favicon
            const logoInput = document.getElementById('site_logo');
            const faviconInput = document.getElementById('favicon');
            if(logoInput) setupImagePreview(logoInput);
            if(faviconInput) setupImagePreview(faviconInput);

            // *** NEW: Setup previews for all ad images ***
            const adImageInputs = document.querySelectorAll('input[type="file"][name^="ad_"]');
            adImageInputs.forEach(input => setupImagePreview(input));


            // --- Tab Activation & Hash Update ---
             function activateTabFromHash() {
                const hash = window.location.hash;
                let triggerEl = null;
                if (hash) {
                    try {
                         triggerEl = document.querySelector(`.nav-pills button[data-bs-target="${hash}"]`);
                    } catch (e) {
                        console.warn("Invalid hash selector:", hash);
                    }
                }
                if (!triggerEl) {
                     triggerEl = document.querySelector('#settingsTabs button:first-child');
                }

                if (triggerEl) {
                    const tabInstance = bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl);
                    tabInstance.show();
                }
            }

             activateTabFromHash();

            const triggerTabList = [].slice.call(document.querySelectorAll('#settingsTabs button'));
            triggerTabList.forEach(function (triggerEl) {
              triggerEl.addEventListener('shown.bs.tab', function (event) {
                 const targetHash = event.target.dataset.bsTarget;
                 if (window.location.hash !== targetHash) {
                     history.pushState(null, null, targetHash);
                 }
              });
            });

             window.addEventListener('hashchange', activateTabFromHash, false);

        }); // End DOMContentLoaded
    </script>

</body>
</html>