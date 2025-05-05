<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php'; // Assuming sanitizeInput, logActivity, getSetting, formatFileSize etc.
require_once 'includes/auth.php';    // Assuming isLoggedIn, hasPermission, getCurrentUser etc.

// --- Permission Check ---
if (!isLoggedIn() || !hasPermission('manage_categories')) {
    header("Location: index.php?message=Access Denied: You cannot manage media.&type=danger");
    exit();
}

// Get current logged-in user (needed for header & logging)
$loggedInUser = getCurrentUser();

// --- Configuration ---
// Define media directories to scan - these will not be displayed to users
$mediaDirs = [
    [
        'path_absolute' => rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/admin/uploads/articles/',
        'path_relative' => '/admin/uploads/articles/',
        'type' => 'Articles'
    ],
    [
        'path_absolute' => rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/admin/uploads/profile/',
        'path_relative' => '/admin/uploads/profile/',
        'type' => 'Profiles'
    ],
    [
        'path_absolute' => rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/settings/',
        'path_relative' => '/uploads/settings/',
        'type' => 'Settings'
    ]
];

// Define which directory new uploads go to
$defaultUploadDir = $mediaDirs[0]; // Default to first directory (Articles)

// For form submission and interface clarity
define('CURRENT_MEDIA_DIR_ABSOLUTE', $defaultUploadDir['path_absolute']);
define('CURRENT_MEDIA_DIR_RELATIVE', $defaultUploadDir['path_relative']);

define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'mp4', 'mov']); // Example allowed types
define('MAX_UPLOAD_SIZE_MB', 10); // Max upload size in Megabytes
define('MAX_UPLOAD_SIZE_BYTES', MAX_UPLOAD_SIZE_MB * 1024 * 1024);

// Initialize variables
$mediaFiles = [];
$currentFilter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$message = '';
$messageType = '';

// Helper function to get settings
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') { /* Basic fallback */ return $default; }
}

// Helper function to format file size (Add to functions.php if not present)
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes, $decimals = 2) {
        if ($bytes <= 0) return '0 B';
        $size = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }
}

// Helper function to check if path exists and is writable
function checkDirAccess($dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return "Directory cannot be created.";
        }
    } elseif (!is_writable($dir)) {
        return "Directory is not writable.";
    }
    return true;
}

// Ensure the default upload directory exists and is writable
$dirCheck = checkDirAccess(CURRENT_MEDIA_DIR_ABSOLUTE);
if ($dirCheck !== true) {
    $message = "Error: Upload directory " . $dirCheck;
    $messageType = "danger";
    error_log("CRITICAL: Media directory issue: " . CURRENT_MEDIA_DIR_ABSOLUTE . " - " . $dirCheck);
}

// --- Handle File Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['mediaFile'])) {
    $uploadedFile = $_FILES['mediaFile'];

    // Check for errors first
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        $message = $uploadErrors[$uploadedFile['error']] ?? 'Unknown upload error occurred.';
        $messageType = "danger";
    } else {
        $fileName = $uploadedFile['name'];
        $fileTmpPath = $uploadedFile['tmp_name'];
        $fileSize = $uploadedFile['size'];
        $fileInfo = pathinfo($fileName);
        $extension = strtolower($fileInfo['extension'] ?? '');

        // Validate file type and size
        if (!in_array($extension, ALLOWED_FILE_TYPES)) {
            $message = "Error: Invalid file type '{$extension}'. Allowed: " . implode(', ', ALLOWED_FILE_TYPES);
            $messageType = "danger";
        } elseif ($fileSize > MAX_UPLOAD_SIZE_BYTES) {
            $message = "Error: File size (" . formatFileSize($fileSize) . ") exceeds limit (" . MAX_UPLOAD_SIZE_MB . "MB).";
            $messageType = "danger";
        } elseif ($fileSize === 0) {
            $message = "Error: Uploaded file is empty.";
            $messageType = "danger";
        } else {
            // Sanitize filename
            $safeFilenameBase = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $fileInfo['filename']); // Replace invalid chars
            $safeFilenameBase = trim($safeFilenameBase, '._-'); // Remove leading/trailing separators
            if (empty($safeFilenameBase)) $safeFilenameBase = 'file'; // Handle cases where name becomes empty

            $newFilename = $safeFilenameBase . '_' . uniqid() . '.' . $extension; // Add unique ID
            $uploadPath = CURRENT_MEDIA_DIR_ABSOLUTE . $newFilename;

            // Ensure target directory is writable (double check after potential mkdir)
            if (!is_writable(CURRENT_MEDIA_DIR_ABSOLUTE)) {
                $message = "Error: Target directory is not writable.";
                $messageType = "danger";
                error_log("Upload Error: Target directory not writable: " . CURRENT_MEDIA_DIR_ABSOLUTE);
            }
            // Use move_uploaded_file() for security
            elseif (move_uploaded_file($fileTmpPath, $uploadPath)) {
                if (function_exists('logActivity')) {
                    logActivity($loggedInUser['id'], 'media_upload', 'media', null, "Uploaded media file: '{$newFilename}'");
                }
                $message = "File '{$newFilename}' uploaded successfully.";
                $messageType = "success";
            } else {
                $message = "Error: Failed to move uploaded file. Check server logs and directory permissions.";
                $messageType = "danger";
                error_log("Media Upload Error: Failed to move {$fileTmpPath} to {$uploadPath}");
            }
        }
    }
    // Redirect after POST to prevent re-submission on refresh
    header("Location: media.php?message=" . urlencode($message) . "&type=" . $messageType . "&filter=" . $currentFilter);
    exit();
}

// --- Handle File Deletion ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['file']) && isset($_GET['dir'])) {
    $fileToDelete = $_GET['file'];
    $dirIndex = $_GET['dir'];
    
    // Validate directory index is valid
    if (!is_numeric($dirIndex) || !isset($mediaDirs[$dirIndex])) {
        $message = "Error: Invalid directory specified.";
        $messageType = "danger";
        error_log("Security Issue: Invalid directory index for delete: {$dirIndex}");
    } else {
        $selectedDir = $mediaDirs[$dirIndex];
        
        // Security validation
        $baseFilename = basename($fileToDelete);
        $fullPath = realpath($selectedDir['path_absolute'] . $baseFilename);
        $expectedDir = realpath($selectedDir['path_absolute']);

        if ($baseFilename !== $fileToDelete || !$fullPath || !$expectedDir || strpos($fullPath, $expectedDir) !== 0) {
            // Attempted directory traversal or invalid file
            $message = "Error: Invalid file specified for deletion.";
            $messageType = "danger";
            error_log("Potential Security Issue: Delete media failed validation for '{$fileToDelete}'");
        } elseif (!file_exists($fullPath)) {
            $message = "Error: File not found.";
            $messageType = "danger";
        } elseif (!is_writable($fullPath)) {
            $message = "Error: Cannot delete file. Check permissions.";
            $messageType = "danger";
        } else {
            if (unlink($fullPath)) {
                if (function_exists('logActivity')) {
                    logActivity($loggedInUser['id'], 'media_delete', 'media', null, "Deleted media file: '{$baseFilename}'");
                }
                $message = "File deleted successfully.";
                $messageType = "success";
            } else {
                $message = "Error: Failed to delete file. Check server logs.";
                $messageType = "danger";
            }
        }
    }
    // Redirect after action
    header("Location: media.php?message=" . urlencode($message) . "&type=" . $messageType . "&filter=" . $currentFilter);
    exit();
}

// --- Scan All Media Directories ---
foreach ($mediaDirs as $dirIndex => $dir) {
    // Skip directories that don't match the current filter
    if ($currentFilter !== 'all' && $dir['type'] !== $currentFilter) {
        continue;
    }

    if (is_dir($dir['path_absolute'])) {
        $files = scandir($dir['path_absolute']);
        if ($files === false) {
            error_log("Error: scandir failed for " . $dir['path_absolute']);
            continue;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue; // Skip current/parent dir entries

            $filePath = $dir['path_absolute'] . $file;
            if (is_file($filePath)) {
                $fileInfo = pathinfo($filePath);
                $fileExt = strtolower($fileInfo['extension'] ?? '');
                $isImage = in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

                $mediaFiles[] = [
                    'name' => $file,
                    'path_absolute' => $filePath,
                    'path_relative' => $dir['path_relative'] . $file,
                    'size' => filesize($filePath),
                    'modified_time' => filemtime($filePath),
                    'extension' => $fileExt,
                    'is_image' => $isImage,
                    'type' => $dir['type'],
                    'dir_index' => $dirIndex
                ];
            }
        }
    } else {
        // Directory doesn't exist, try to create it (won't log errors here)
        @mkdir($dir['path_absolute'], 0755, true);
    }
}

// Sort files by modification time, newest first
usort($mediaFiles, function ($a, $b) {
    return $b['modified_time'] <=> $a['modified_time'];
});

// Check for messages passed via URL (e.g., after successful action)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = urldecode($_GET['message']);
    $messageType = urldecode($_GET['type']);
}

// Use current user/time provided for header display
$headerLogin = $loggedInUser['username'] ?? 'MdAbuNafisNiloy';
$headerUtcTime = '2025-04-20 07:57:17';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Gallery - <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')); ?></title>
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
        .card-header { background-color: #fdfdfd; border-bottom: 1px solid #e3e6f0; padding: 0.75rem 1rem; font-weight: 600; color: var(--primary-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .card-header h5 { font-size: 1rem; margin-bottom: 0; }
        .card-body { padding: 1.25rem; }
        .card-footer { background-color: #fdfdfd; border-top: 1px solid #e3e6f0; padding: 0.75rem 1rem; }
        /* Header */
        .page-header { background: linear-gradient(to right, var(--primary-color), #224abe); color: white; padding: 1.5rem; border-radius: 0.35rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header h2 { font-weight: 600; margin-bottom: 0.25rem; font-size: 1.5rem; }
        .page-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .header-meta { font-size: 0.75rem; opacity: 0.8; text-align: right; position: absolute; bottom: 0.75rem; right: 1rem; }
        /* Media Grid */
        .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
        .media-item { border: 1px solid #e3e6f0; border-radius: 0.35rem; background-color: #fff; overflow: hidden; position: relative; transition: box-shadow 0.2s ease; display: flex; flex-direction: column; }
        .media-item:hover { box-shadow: 0 0.2rem 0.8rem rgba(58, 59, 69, 0.15); }
        .media-preview { height: 120px; background-color: #f8f9fc; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; position: relative; }
        .media-preview img { max-height: 100%; max-width: 100%; object-fit: cover; }
        .media-preview .file-icon { font-size: 3rem; color: #adb5bd; }
        .media-type-badge { position: absolute; top: 5px; right: 5px; font-size: 0.65rem; padding: 2px 5px; border-radius: 3px; background: rgba(0,0,0,0.5); color: white; }
        .media-info { padding: 0.75rem; font-size: 0.8rem; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .media-name { font-weight: 600; color: var(--dark-color); margin-bottom: 0.25rem; word-break: break-all; display: block; line-height: 1.3; }
        .media-meta { color: #858796; margin-bottom: 0.5rem; font-size: 0.75rem; }
        .media-actions { display: flex; gap: 0.25rem; justify-content: flex-end; margin-top: auto; }
        .media-actions .btn { padding: 0.2rem 0.4rem; font-size: 0.7rem; }
        /* Upload Form */
        .upload-card .progress { height: 10px; margin-top: 0.5rem; display: none; }
        /* Filter tabs */
        .media-filter-tabs { margin-bottom: 1rem; }
        .media-filter-tabs .nav-link { font-size: 0.85rem; padding: 0.5rem 1rem; }
        .media-filter-tabs .nav-link.active { background-color: var(--primary-color); color: white; }
        /* Responsive */
        @media (max-width: 992px) { .main-content { padding: 1rem; } .page-header { padding: 1rem; } .page-header h2 { font-size: 1.3rem; } .header-meta { position: static; text-align: center; margin-top: 0.5rem; } .media-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); } }
        @media (max-width: 768px) { .main-content { padding: 0.75rem; } .top-bar .dropdown span { display: none; } .media-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); } .media-preview { height: 100px; } .media-info { padding: 0.5rem; font-size: 0.75rem; } .media-name { margin-bottom: 0.1rem; } .media-actions .btn { font-size: 0.65rem; } .card-header { flex-direction: column; } .card-header .btn-group { margin-top: 0.5rem; } }
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
      
            <a href="media.php" class="sidebar-item active" title="Media"> <i class="fas fa-images fa-fw"></i> <span>Media</span> </a>
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
                    <img src="<?php echo !empty($loggedInUser['profile_picture']) && file_exists(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($loggedInUser['profile_picture'], '/')) ? htmlspecialchars($loggedInUser['profile_picture']) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile" style="object-fit: cover;">
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
            <h2><i class="fas fa-images me-2"></i> Media Gallery</h2>
            <p>Upload and manage images and other media files across your website.</p>
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

        <!-- Upload Card -->
        <div class="card upload-card">
             <div class="card-header">
                 <h5 class="mb-0">Upload New Media</h5>
             </div>
             <div class="card-body">
                 <form method="POST" action="media.php" enctype="multipart/form-data" id="uploadForm">
                     <div class="mb-3">
                         <label for="mediaFile" class="form-label">Select file to upload</label>
                         <input class="form-control form-control-sm" type="file" id="mediaFile" name="mediaFile" required accept="<?php echo implode(',', array_map(function($ext) { return '.' . $ext; }, ALLOWED_FILE_TYPES)); ?>">
                         <small class="form-text text-muted">
                             Max size: <?php echo MAX_UPLOAD_SIZE_MB; ?>MB. Allowed types: <?php echo implode(', ', ALLOWED_FILE_TYPES); ?>
                         </small>
                         <div class="invalid-feedback" id="fileError"></div> <!-- Placeholder for client-side validation errors -->
                     </div>
                     <button type="submit" class="btn btn-primary btn-sm" id="uploadButton">
                         <i class="fas fa-upload me-1"></i> Upload File
                     </button>
                     <div class="progress mt-2" id="uploadProgressContainer" style="display: none;">
                        <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                     </div>
                 </form>
             </div>
        </div>

        <!-- Media Filter -->
        <ul class="nav nav-pills media-filter-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="media.php?filter=all">All Media</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $currentFilter === 'Articles' ? 'active' : ''; ?>" href="media.php?filter=Articles">Articles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $currentFilter === 'Profiles' ? 'active' : ''; ?>" href="media.php?filter=Profiles">Profiles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $currentFilter === 'Settings' ? 'active' : ''; ?>" href="media.php?filter=Settings">Settings</a>
            </li>
        </ul>

        <!-- Media Grid -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Media Files <?php echo $currentFilter !== 'all' ? "- {$currentFilter}" : ""; ?></h5>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-primary" id="viewGridBtn" title="Grid View"><i class="fas fa-th"></i></button>
                    <button type="button" class="btn btn-outline-primary" id="viewListBtn" title="List View"><i class="fas fa-list"></i></button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($mediaFiles)): ?>
                    <div class="alert alert-info mb-0">No media files found. Upload a file to get started.</div>
                <?php else: ?>
                    <div class="media-grid" id="mediaContainer">
                        <?php foreach ($mediaFiles as $file): 
                            // Construct URL ensuring proper formatting
                            $fileUrl = $file['path_relative'];
                        ?>
                            <div class="media-item" data-media-type="<?php echo htmlspecialchars($file['type']); ?>">
                                <div class="media-preview" title="<?php echo htmlspecialchars($file['name']); ?>">
                                    <?php if ($file['is_image']): ?>
                                        <img src="<?php echo htmlspecialchars($fileUrl); ?>?t=<?php echo $file['modified_time']; ?>" alt="<?php echo htmlspecialchars($file['name']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <?php
                                        // Choose icon based on extension
                                        $iconClass = 'fa-file'; // Default
                                        if (in_array($file['extension'], ['pdf'])) $iconClass = 'fa-file-pdf text-danger';
                                        elseif (in_array($file['extension'], ['doc', 'docx'])) $iconClass = 'fa-file-word text-primary';
                                        elseif (in_array($file['extension'], ['xls', 'xlsx'])) $iconClass = 'fa-file-excel text-success';
                                        elseif (in_array($file['extension'], ['mp4', 'mov', 'avi', 'wmv'])) $iconClass = 'fa-file-video text-warning';
                                        elseif (in_array($file['extension'], ['mp3', 'wav', 'ogg'])) $iconClass = 'fa-file-audio text-info';
                                        elseif (in_array($file['extension'], ['zip', 'rar', '7z'])) $iconClass = 'fa-file-archive text-secondary';
                                        elseif (in_array($file['extension'], ['txt'])) $iconClass = 'fa-file-alt text-muted';
                                        ?>
                                        <i class="fas <?php echo $iconClass; ?> file-icon"></i>
                                    <?php endif; ?>
                                    <div class="media-type-badge"><?php echo $file['type']; ?></div>
                                </div>
                                <div class="media-info">
                                    <div> <!-- Wrapper for name and meta -->
                                        <span class="media-name" title="<?php echo htmlspecialchars($file['name']); ?>"><?php echo htmlspecialchars($file['name']); ?></span>
                                        <div class="media-meta">
                                            <?php echo formatFileSize($file['size']); ?> &bull; <?php echo date('M d, Y', $file['modified_time']); ?>
                                        </div>
                                    </div>
                                    <div class="media-actions">
                                         <button type="button" class="btn btn-outline-secondary btn-sm copy-url-btn" data-url="<?php echo htmlspecialchars($fileUrl); ?>" title="Copy URL">
                                             <i class="fas fa-link fa-fw"></i>
                                         </button>
                                         <button type="button" class="btn btn-outline-danger btn-sm delete-media-btn"
                                                 data-filename="<?php echo htmlspecialchars($file['name']); ?>" 
                                                 data-dir-index="<?php echo $file['dir_index']; ?>"
                                                 title="Delete File">
                                             <i class="fas fa-trash-alt fa-fw"></i>
                                         </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="mt-4 mb-3 text-center text-muted small">
            Copyright &copy; <?php echo htmlspecialchars(getSetting('site_name', 'Alpha News')) . ' ' . date('Y'); ?>
        </footer>

    </div> <!-- End Main Content -->

     <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteMediaModal" tabindex="-1" aria-labelledby="deleteMediaModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                 <div class="modal-header bg-danger text-white">
                     <h5 class="modal-title" id="deleteMediaModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                     <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <div class="modal-body">
                     <p>Are you sure you want to permanently delete this file?<br><strong><span id="delete_filename_display"></span></strong></p>
                     <p class="text-danger fw-bold">This action cannot be undone.</p>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                     <a href="#" class="btn btn-danger" id="confirmDeleteMediaButton"><i class="fas fa-trash-alt me-1"></i>Delete File</a>
                 </div>
            </div>
        </div>
    </div>

    <!-- Toast container for copy feedback -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
      <div id="copyToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body">
            <i class="fas fa-check-circle me-2"></i>URL copied to clipboard!
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    </div>


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

            // --- Bootstrap Tooltips ---
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
              return new bootstrap.Tooltip(tooltipTriggerEl)
            });

            // --- Copy URL with Toast Feedback ---
            const copyToastEl = document.getElementById('copyToast');
            const copyToast = copyToastEl ? new bootstrap.Toast(copyToastEl, { delay: 2000 }) : null;

            document.querySelectorAll('.copy-url-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const urlToCopy = this.getAttribute('data-url');
                    const fullUrl = window.location.origin + urlToCopy;

                    navigator.clipboard.writeText(fullUrl).then(() => {
                        if (copyToast) {
                            copyToast.show();
                        } else {
                            // Fallback if toast init failed
                            const originalTitle = this.getAttribute('data-bs-original-title');
                            this.setAttribute('data-bs-original-title', 'Copied!');
                            const btnTooltip = bootstrap.Tooltip.getInstance(this);
                            if(btnTooltip) btnTooltip.show();
                            setTimeout(() => {
                                this.setAttribute('data-bs-original-title', originalTitle);
                                if(btnTooltip) btnTooltip.hide();
                            }, 1500);
                        }
                    }).catch(err => {
                        console.error('Failed to copy URL: ', err);
                        alert('Failed to copy URL. Please copy manually:\n' + fullUrl);
                    });
                });
            });

             // --- Delete Media Modal ---
            const deleteMediaModalEl = document.getElementById('deleteMediaModal');
            const filenameDisplay = document.getElementById('delete_filename_display');
            const confirmDeleteButton = document.getElementById('confirmDeleteMediaButton');
            const deleteMediaModal = deleteMediaModalEl ? new bootstrap.Modal(deleteMediaModalEl) : null;

            document.querySelectorAll('.delete-media-btn').forEach(button => {
                button.addEventListener('click', function() {
                    if (!deleteMediaModal) return; // Guard if modal init failed

                    const filename = this.getAttribute('data-filename');
                    const dirIndex = this.getAttribute('data-dir-index');
                    filenameDisplay.textContent = filename;
                    // Construct the delete URL for the confirmation button's href
                    confirmDeleteButton.href = `media.php?action=delete&file=${encodeURIComponent(filename)}&dir=${encodeURIComponent(dirIndex)}&filter=<?php echo $currentFilter; ?>`;

                    deleteMediaModal.show();
                });
            });

            // --- View Toggle (Grid/List) ---
            const mediaContainer = document.getElementById('mediaContainer');
            const viewGridBtn = document.getElementById('viewGridBtn');
            const viewListBtn = document.getElementById('viewListBtn');
            
            const VIEW_MODE_KEY = 'mediaViewMode';
            const currentViewMode = localStorage.getItem(VIEW_MODE_KEY) || 'grid';
            
            function setViewMode(mode) {
                if (mode === 'grid') {
                    mediaContainer.classList.remove('media-list-view');
                    mediaContainer.classList.add('media-grid');
                    viewGridBtn.classList.add('active');
                    viewListBtn.classList.remove('active');
                } else {
                    mediaContainer.classList.add('media-list-view');
                    mediaContainer.classList.remove('media-grid');
                    viewGridBtn.classList.remove('active');
                    viewListBtn.classList.add('active');
                }
                localStorage.setItem(VIEW_MODE_KEY, mode);
            }
            
            // Set initial view mode
            setViewMode(currentViewMode);
            
            // Add click event listeners
            viewGridBtn.addEventListener('click', () => setViewMode('grid'));
            viewListBtn.addEventListener('click', () => setViewMode('list'));

            // --- Client-side File Validation ---
            const uploadForm = document.getElementById('uploadForm');
            const fileInput = document.getElementById('mediaFile');
            const fileError = document.getElementById('fileError');
            const uploadButton = document.getElementById('uploadButton');
            const allowedTypes = <?php echo json_encode(ALLOWED_FILE_TYPES); ?>;
            const maxSize = <?php echo MAX_UPLOAD_SIZE_BYTES; ?>;
            const maxSizeMB = <?php echo MAX_UPLOAD_SIZE_MB; ?>;

            if (fileInput && fileError && uploadButton) {
                fileInput.addEventListener('change', function() {
                    fileError.textContent = ''; // Clear previous error
                    fileInput.classList.remove('is-invalid');
                    uploadButton.disabled = false;

                    if (this.files.length > 0) {
                        const file = this.files[0];
                        const extension = file.name.split('.').pop().toLowerCase();

                        // Check type
                        if (!allowedTypes.includes(extension)) {
                            fileError.textContent = `Invalid file type '.${extension}'. Allowed: ${allowedTypes.join(', ')}.`;
                            fileInput.classList.add('is-invalid');
                            uploadButton.disabled = true;
                            return;
                        }

                        // Check size
                        if (file.size > maxSize) {
                            fileError.textContent = `File size (${(file.size / 1024 / 1024).toFixed(2)}MB) exceeds limit (${maxSizeMB}MB).`;
                            fileInput.classList.add('is-invalid');
                            uploadButton.disabled = true;
                            return;
                        }

                         if (file.size === 0) {
                            fileError.textContent = `File appears to be empty.`;
                            fileInput.classList.add('is-invalid');
                            uploadButton.disabled = true;
                            return;
                        }
                    }
                });
            }

        }); // End DOMContentLoaded
    </script>

    <style>
        /* Additional CSS for List View */
        .media-list-view {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .media-list-view .media-item {
            flex-direction: row;
            align-items: center;
        }
        
        .media-list-view .media-preview {
            width: 60px;
            height: 60px;
            flex-shrink: 0;
            border-right: 1px solid #e3e6f0;
        }
        
        .media-list-view .media-info {
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            flex: 1;
        }
        
        .media-list-view .media-actions {
            margin-left: auto;
        }
        
        /* Make buttons in card header appear active */
        .btn-group .btn.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        @media (max-width: 576px) {
            .media-list-view .media-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .media-list-view .media-actions {
                margin-top: 0.5rem;
                margin-left: 0;
            }
        }
    </style>

</body>
</html>