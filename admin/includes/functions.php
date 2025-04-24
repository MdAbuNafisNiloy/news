<?php
/**
 * Core functions for the news website
 */

// Get current user information
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    global $db;
    $stmt = $db->prepare("SELECT u.*, r.name as role_name FROM users u 
                          JOIN roles r ON u.role_id = r.id 
                          WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// Get user role information
function getUserRole($roleId) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$roleId]);
    return $stmt->fetch();
}

// Check if a user has a specific permission
function hasPermission($permissionName) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    global $db;
    $stmt = $db->prepare("SELECT COUNT(*) FROM role_permissions rp
                          JOIN permissions p ON rp.permission_id = p.id
                          JOIN users u ON u.role_id = rp.role_id
                          WHERE u.id = ? AND p.name = ?");
    $stmt->execute([$_SESSION['user_id'], $permissionName]);
    return (bool) $stmt->fetchColumn();
}

// Count total articles
function countArticles() {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) FROM articles");
    return $stmt->fetchColumn();
}

// Count articles by status
function countArticlesByStatus($status) {
    global $db;
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE status = ?");
    $stmt->execute([$status]);
    return $stmt->fetchColumn();
}

// Count total users
function countUsers() {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    return $stmt->fetchColumn();
}

// Count total comments
function countComments() {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) FROM comments");
    return $stmt->fetchColumn();
}

// Count comments by status
function countCommentsByStatus($status) {
    global $db;
    $stmt = $db->prepare("SELECT COUNT(*) FROM comments WHERE status = ?");
    $stmt->execute([$status]);
    return $stmt->fetchColumn();
}

// Get recent articles
function getRecentArticles($limit) {
    global $db;
    $stmt = $db->prepare("
        SELECT a.*, u.username as author_name, c.name as category_name
        FROM articles a
        LEFT JOIN users u ON a.author_id = u.id
        LEFT JOIN article_categories ac ON a.id = ac.article_id
        LEFT JOIN categories c ON ac.category_id = c.id
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get recent users
function getRecentUsers($limit) {
    global $db;
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.registration_date DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get recent comments
function getRecentComments($limit) {
    global $db;
    $stmt = $db->prepare("
        SELECT c.*, u.username, a.title as article_title
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN articles a ON c.article_id = a.id
        ORDER BY c.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
//function generateSlug($string) {
//    $string = preg_replace('/[^a-zA-Z0-9\s]/', '', $string);
//    $string = strtolower($string);
//    $string = str_replace(' ', '-', $string);
//    $string = preg_replace('/-+/', '-', $string);
//    return $string;
//}

// Generate a slug from a string

// Format date
function formatDate($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

// Get categories
function getCategories() {
    global $db;
    $stmt = $db->query("SELECT * FROM categories ORDER BY name");
    return $stmt->fetchAll();
}

// Get tags
function getTags() {
    global $db;
    $stmt = $db->query("SELECT * FROM tags ORDER BY name");
    return $stmt->fetchAll();
}

// Log activity
function logActivity($userId, $action, $entityType = null, $entityId = null, $description = null) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $description,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
}

function getRecentActivityLogs(int $limit = 5): array {
    global $db; // Assuming $db is accessible globally

    if (!$db) {
        error_log("Database connection not available in getRecentActivityLogs");
        return [];
    }

    try {
        // Join with users table to get username, handle NULL user_id
        $sql = "SELECT al.action, al.created_at, u.username
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT :limit";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching activity logs: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Converts a datetime string into a user-friendly "time ago" format.
 *
 * @param string $datetime Input datetime string (parsable by DateTime).
 * @param bool $full If true, returns full details (e.g., "1 year, 2 months ago").
 * @return string The relative time string.
 */
function timeAgo(string $datetime, bool $full = false): string {
    try {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day',
            'h' => 'hour', 'i' => 'minute', 's' => 'second',
        ];
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1); // Only show the largest unit
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    } catch (Exception $e) {
        error_log("Error in timeAgo function: " . $e->getMessage());
        return 'invalid date'; // Return an error indicator
    }
}


/**
 * Handles file uploads with validation
 * 
 * @param string $fileInputName Name of the file input field
 * @param string $uploadDir Directory to save file (relative to document root)
 * @param array $allowedExtensions Array of allowed file extensions
 * @param int $maxFileSize Maximum file size in bytes
 * @param string $filenamePrefix Prefix for the saved filename
 * @return string|false Relative path to saved file or false on failure
 */
function handleFileUpload($fileInputName, $uploadDir, $allowedExtensions = [], $maxFileSize = 2097152, $filenamePrefix = '') {
    // Validate file exists and no errors in upload
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    
    $file = $_FILES[$fileInputName];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        
        $errorMessage = isset($errorMessages[$file['error']]) 
            ? $errorMessages[$file['error']] 
            : 'Unknown upload error';
            
        error_log("File upload error: {$errorMessage}");
        throw new Exception($errorMessage);
    }
    
    // Check file size
    if ($file['size'] > $maxFileSize) {
        throw new Exception("File size exceeds the limit of " . ($maxFileSize / 1024 / 1024) . " MB");
    }
    
    // Validate file extension
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
        throw new Exception("Invalid file type. Allowed types: " . implode(', ', $allowedExtensions));
    }
    
    // Create target directory if it doesn't exist
    $docRoot = $_SERVER['DOCUMENT_ROOT'];
    $fullUploadDir = $docRoot . '/' . ltrim($uploadDir, '/');
    
    if (!file_exists($fullUploadDir)) {
        if (!mkdir($fullUploadDir, 0755, true)) {
            throw new Exception("Failed to create upload directory: {$uploadDir}");
        }
    }
    
    // Generate unique filename
    $safeFilename = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $fileInfo['filename']);
    $uniqueName = $filenamePrefix . $safeFilename . '_' . uniqid() . '.' . $extension;
    $fullPath = $fullUploadDir . '/' . $uniqueName;
    $relativePath = rtrim($uploadDir, '/') . '/' . $uniqueName;
    
    // Move the uploaded file
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new Exception("Failed to move uploaded file to {$relativePath}. Check directory permissions.");
    }
    
    // Return the relative path for storage
    return $relativePath;
}


function isAscii(string $string): bool
{
    return mb_detect_encoding($string, 'ASCII', true);
}

/**
 * Generates a random alphanumeric string suitable for slugs.
 *
 * @param int $length The desired length of the random string (default: 10).
 * @return string The generated random slug.
 */
function generateRandomSlug(int $length = 10): string
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    // Prefix to avoid potential conflicts or starting with numbers if desired
    return 'article-' . $randomString;
}


/**
 * Generates a URL-friendly slug from an ASCII string.
 * (Modified to primarily handle ASCII after the initial check)
 *
 * @param string $text The input ASCII string (e.g., English title).
 * @param string $divider The character to use as a separator (default: '-').
 * @return string The generated slug.
 */
function generateAsciiSlug(string $text, string $divider = '-'): string
{
    // Remove characters that are not alphanumeric, whitespace, or the divider
    $text = preg_replace('~[^\pL\d\s' . preg_quote($divider) . ']+~u', '', $text);

    // Replace whitespace and repeated dividers with a single divider
    $text = preg_replace('~[\s' . preg_quote($divider) . ']+~u', $divider, $text);

    // Trim divider characters from the beginning and end
    $text = trim($text, $divider);

    // Convert to lowercase
    $text = strtolower($text);

    if (empty($text)) {
        return 'n-a-' . uniqid(); // Fallback for empty slugs
    }

    return $text;
}
