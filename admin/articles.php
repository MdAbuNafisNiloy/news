<?php
// Start session for user authentication
session_start();

// Include database and configuration files
require_once 'config/database.php';
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Check if user is logged in and has permission
if (!isLoggedIn() || !hasPermission('create_article')) {
    header("Location: login.php");
    exit();
}

// Get current user information
$currentUser = getCurrentUser();

// Format the current date and time in UTC
$currentDateTime = gmdate('Y-m-d H:i:s');

// Initialize variables
$message = '';
$messageType = '';
$articles = [];
$totalArticles = 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; // Ensure page is at least 1
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
// Ensure limit is a reasonable value
if (!in_array($limit, [10, 25, 50, 100])) {
    $limit = 10;
}
$offset = ($page - 1) * $limit;
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$authorFilter = isset($_GET['author']) ? (int)$_GET['author'] : 0;
$sortBy = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'created_at';
$sortDir = isset($_GET['dir']) ? sanitizeInput($_GET['dir']) : 'DESC';

// --- Process article actions (publish, unpublish, delete) ---
// [ This section remains unchanged - keep the existing POST handling logic here ]
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check for bulk actions
        if (isset($_POST['bulk_action']) && isset($_POST['article_ids'])) {
            $action = $_POST['bulk_action'];
            $articleIds = $_POST['article_ids'];

            if (empty($articleIds)) {
                throw new Exception("No articles selected for bulk action");
            }

            // Ensure articleIds are integers
            $articleIds = array_map('intval', $articleIds);
            $placeholders = implode(',', array_fill(0, count($articleIds), '?'));

            // Begin transaction
            $db->beginTransaction();

            switch ($action) {
                case 'publish':
                    // Check permission
                    if (!hasPermission('publish_article')) {
                        throw new Exception("You don't have permission to publish articles");
                    }

                    // Update articles status
                    $stmt = $db->prepare("UPDATE articles SET status = 'published', published_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($articleIds);

                    $message = count($articleIds) . " article(s) published successfully";
                    $messageType = "success";
                    // Log activity
                    if (function_exists('logActivity')) {
                        foreach ($articleIds as $articleId) {
                           logActivity($currentUser['id'], 'article_bulk_publish', 'articles', $articleId, 'Bulk published article');
                        }
                    }
                    break;

                case 'draft':
                    // Update articles status
                    $stmt = $db->prepare("UPDATE articles SET status = 'draft' WHERE id IN ($placeholders)");
                    $stmt->execute($articleIds);

                    $message = count($articleIds) . " article(s) moved to draft successfully";
                    $messageType = "success";
                     // Log activity
                    if (function_exists('logActivity')) {
                        foreach ($articleIds as $articleId) {
                           logActivity($currentUser['id'], 'article_bulk_draft', 'articles', $articleId, 'Bulk moved article to draft');
                        }
                    }
                    break;

                case 'delete':
                    $deletedCount = 0;
                    // Check permissions for each article
                    foreach ($articleIds as $articleId) {
                        $checkStmt = $db->prepare("SELECT author_id FROM articles WHERE id = ?");
                        $checkStmt->execute([$articleId]);
                        $article = $checkStmt->fetch();

                        if (!$article) {
                            continue; // Skip if article not found
                        }

                        // Check if user can delete this article
                        $isOwn = $article['author_id'] == $currentUser['id'];
                        if (!($isOwn && hasPermission('delete_own_article')) &&
                            !(!$isOwn && hasPermission('delete_any_article'))) {
                             // Rollback before throwing exception
                            if ($db->inTransaction()) $db->rollBack();
                            throw new Exception("You don't have permission to delete article ID: $articleId");
                        }
                    }

                     // Get article titles before deleting for logging
                    $titlesStmt = $db->prepare("SELECT id, title FROM articles WHERE id IN ($placeholders)");
                    $titlesStmt->execute($articleIds);
                    $deletedTitles = $titlesStmt->fetchAll(PDO::FETCH_KEY_PAIR);


                    // Delete article category relations
                    $stmt = $db->prepare("DELETE FROM article_categories WHERE article_id IN ($placeholders)");
                    $stmt->execute($articleIds);

                    // Delete article tag relations
                    $stmt = $db->prepare("DELETE FROM article_tags WHERE article_id IN ($placeholders)");
                    $stmt->execute($articleIds);

                    // Delete comments
                    $stmt = $db->prepare("DELETE FROM comments WHERE article_id IN ($placeholders)");
                    $stmt->execute($articleIds);

                    // Delete articles
                    $stmt = $db->prepare("DELETE FROM articles WHERE id IN ($placeholders)");
                    $stmt->execute($articleIds);
                    $deletedCount = $stmt->rowCount(); // Get actual deleted count

                    $message = $deletedCount . " article(s) deleted successfully";
                    $messageType = "success";

                    // Log activity
                    if (function_exists('logActivity')) {
                        foreach ($deletedTitles as $id => $title) {
                           logActivity($currentUser['id'], 'article_bulk_delete', 'articles', $id, 'Bulk deleted article: "' . $title . '"');
                        }
                    }
                    break;

                default:
                     // Rollback before throwing exception
                    if ($db->inTransaction()) $db->rollBack();
                    throw new Exception("Invalid bulk action");
            }

            // Commit transaction
            $db->commit();

        } else if (isset($_POST['action']) && isset($_POST['article_id'])) {
            // Single article action
            $action = $_POST['action'];
            $articleId = (int)$_POST['article_id'];

            // Get article info to check permissions and for logging
            $checkStmt = $db->prepare("SELECT author_id, title FROM articles WHERE id = ?");
            $checkStmt->execute([$articleId]);
            $article = $checkStmt->fetch();

            if (!$article) {
                throw new Exception("Article not found");
            }
            $articleTitle = $article['title']; // For logging
            $isOwn = $article['author_id'] == $currentUser['id'];

            switch ($action) {
                case 'publish':
                    // Check permission
                    if (!hasPermission('publish_article')) {
                        throw new Exception("You don't have permission to publish articles");
                    }

                    // Update article status
                    $stmt = $db->prepare("UPDATE articles SET status = 'published', published_at = NOW() WHERE id = ?");
                    $stmt->execute([$articleId]);

                    $message = "Article published successfully";
                    $messageType = "success";
                    // Log activity
                    if (function_exists('logActivity')) {
                        logActivity($currentUser['id'], 'article_publish', 'articles', $articleId, 'Published article: "' . $articleTitle . '"');
                    }
                    break;

                case 'draft':
                    // Check if user can edit this article
                    if (!($isOwn && hasPermission('edit_own_article')) &&
                        !(!$isOwn && hasPermission('edit_any_article'))) {
                        throw new Exception("You don't have permission to edit this article");
                    }

                    // Update article status
                    $stmt = $db->prepare("UPDATE articles SET status = 'draft' WHERE id = ?");
                    $stmt->execute([$articleId]);

                    $message = "Article moved to draft successfully";
                    $messageType = "success";
                     // Log activity
                    if (function_exists('logActivity')) {
                        logActivity($currentUser['id'], 'article_draft', 'articles', $articleId, 'Moved article to draft: "' . $articleTitle . '"');
                    }
                    break;

                case 'delete':
                    // Check if user can delete this article
                    if (!($isOwn && hasPermission('delete_own_article')) &&
                        !(!$isOwn && hasPermission('delete_any_article'))) {
                        throw new Exception("You don't have permission to delete this article");
                    }

                    // Begin transaction
                    $db->beginTransaction();

                    // Delete article category relations
                    $stmt = $db->prepare("DELETE FROM article_categories WHERE article_id = ?");
                    $stmt->execute([$articleId]);

                    // Delete article tag relations
                    $stmt = $db->prepare("DELETE FROM article_tags WHERE article_id = ?");
                    $stmt->execute([$articleId]);

                    // Delete comments
                    $stmt = $db->prepare("DELETE FROM comments WHERE article_id = ?");
                    $stmt->execute([$articleId]);

                    // Delete article
                    $stmt = $db->prepare("DELETE FROM articles WHERE id = ?");
                    $stmt->execute([$articleId]);

                    // Commit transaction
                    $db->commit();

                    $message = "Article deleted successfully";
                    $messageType = "success";
                     // Log activity
                    if (function_exists('logActivity')) {
                        logActivity($currentUser['id'], 'article_delete', 'articles', $articleId, 'Deleted article: "' . $articleTitle . '"');
                    }
                    break;

                default:
                    throw new Exception("Invalid action");
            }
        }
    } catch (Exception $e) {
        // Roll back transaction if active
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $message = "Error: " . $e->getMessage();
        $messageType = "danger";
        // Log the detailed error
        error_log("Article Action Error: UserID={$currentUser['id']}, Error: " . $e->getMessage());

    }
}
// --- End of POST handling ---


// --- Build the WHERE clause and parameters for filtering ---
$whereConditions = [];
$paramValues = []; // Store the parameter values for prepared statements

// Author filter
if ($authorFilter > 0) {
    $whereConditions[] = "a.author_id = ?";
    $paramValues[] = $authorFilter;
}

// Status filter
if ($statusFilter) {
    // Basic validation for status values
    $validStatuses = ['published', 'draft', 'pending', 'archived']; // Add other valid statuses if any
    if (in_array($statusFilter, $validStatuses)) {
        $whereConditions[] = "a.status = ?";
        $paramValues[] = $statusFilter;
    } else {
        // Handle invalid status potentially passed in URL? Optional: log warning or ignore.
        $statusFilter = ''; // Reset if invalid
    }
}

// Category filter
if ($categoryFilter > 0) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM article_categories ac WHERE ac.article_id = a.id AND ac.category_id = ?)";
    $paramValues[] = $categoryFilter;
}

// Search term
if ($searchTerm) {
    $whereConditions[] = "(a.title LIKE ? OR a.content LIKE ?)";
    $paramValues[] = "%" . $searchTerm . "%";
    $paramValues[] = "%" . $searchTerm . "%";
}

// If not admin or editor, apply permission filter
if (!hasPermission('edit_any_article') && !hasPermission('view_any_article')) { // Added view_any_article check
    $whereConditions[] = "(a.author_id = ? OR a.status = 'published')"; // Show own OR published
    $paramValues[] = $currentUser['id'];
} elseif (!hasPermission('edit_any_article')) {
     // If they can view any but not edit any, maybe show non-published only if they are the author?
     // Or, if view_any_article implies seeing all statuses, remove this block entirely.
     // Let's assume view_any_article means see all statuses for simplicity here. Adjust if needed.
}

// Build the final WHERE clause string
$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
try {
    // --- Get total count for pagination ---
    $countQuery = "SELECT COUNT(DISTINCT a.id) FROM articles a $whereClause";
    $countStmt = $db->prepare($countQuery);
    
    // FIX #1: Use proper parameter binding for the count query
    for ($i = 0; $i < count($paramValues); $i++) {
        $paramType = is_int($paramValues[$i]) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $countStmt->bindValue($i + 1, $paramValues[$i], $paramType);
    }
    $countStmt->execute();
    
    $totalArticles = $countStmt->fetchColumn();
    if ($totalArticles === false) {
        throw new Exception("Failed to fetch article count.");
    }

    // --- Calculate total pages and validate current page ---
    $totalPages = ($limit > 0) ? ceil($totalArticles / $limit) : 1;
    $page = max(1, min($page, $totalPages)); // Ensure page is within valid range [1, totalPages]
    $offset = ($page - 1) * $limit; // Recalculate offset based on validated page

    // --- Validate sort parameters ---
    $validSortFields = ['title', 'status', 'created_at', 'published_at', 'views', 'author_name']; // Added author_name
    $validSortDirs = ['ASC', 'DESC'];
    
    // Map author_name sort to the correct column/alias
    $sortColumn = ($sortBy === 'author_name') ? 'u.username' : 'a.' . $sortBy;
    if (!in_array($sortBy, $validSortFields)) {
        $sortBy = 'created_at';
        $sortColumn = 'a.created_at';
    }
    if (!in_array(strtoupper($sortDir), $validSortDirs)) {
        $sortDir = 'DESC';
    }

    // --- Get articles with filtering, sorting and pagination ---
    // FIX #2: Use named parameters for LIMIT to avoid any potential issues
    $query = "
        SELECT a.*,
               u.username as author_name,
               (SELECT GROUP_CONCAT(c.name SEPARATOR ', ')
                FROM article_categories ac
                JOIN categories c ON ac.category_id = c.id
                WHERE ac.article_id = a.id) as categories_list
        FROM articles a
        LEFT JOIN users u ON a.author_id = u.id
        $whereClause
        ORDER BY $sortColumn $sortDir
        LIMIT :offset, :limit
    ";

    $stmt = $db->prepare($query);
    
    // FIX #3: Properly bind all parameters including LIMIT parameters
    for ($i = 0; $i < count($paramValues); $i++) {
        $paramType = is_int($paramValues[$i]) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($i + 1, $paramValues[$i], $paramType);
    }
    
    // FIX #4: Bind LIMIT parameters as named parameters with PDO::PARAM_INT
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC); // Use FETCH_ASSOC for consistency

} catch (PDOException $e) {
    $message = "Database Error: Could not retrieve articles. " . $e->getMessage();
    $messageType = "danger";
    error_log("Article Fetch Error: UserID={$currentUser['id']}, Error: " . $e->getMessage() . ", Query: " . ($query ?? 'N/A'));
    // Reset articles and pagination info on error
    $articles = [];
    $totalArticles = 0;
    $totalPages = 1;
    $page = 1;
} catch (Exception $e) {
     $message = "Error: " . $e->getMessage();
     $messageType = "danger";
     error_log("Article Fetch Error: UserID={$currentUser['id']}, Error: " . $e->getMessage());
     // Reset articles and pagination info on error
     $articles = [];
     $totalArticles = 0;
     $totalPages = 1;
     $page = 1;
}


// --- Get categories and authors for filter dropdowns ---
try {
    $categoriesStmt = $db->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Get only authors who have actually written articles
    $authorsStmt = $db->query("
        SELECT DISTINCT u.id, u.username
        FROM users u
        JOIN articles a ON u.id = a.author_id
        WHERE u.status = 'active' -- Optional: filter only active users
        ORDER BY u.username
    ");
    $authors = $authorsStmt ? $authorsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
     // Log error but don't necessarily stop page load
     error_log("Filter Dropdown Error: " . $e->getMessage());
     $categories = [];
     $authors = [];
     if(empty($message)) { // Show error only if no other major error occurred
        $message = "Warning: Could not load filter options.";
        $messageType = "warning";
     }
}

// Helper function to generate pagination links (moved outside HTML for clarity)
function generatePageLink($pageNum, $text, $ariaLabel, $disabled = false, $active = false) {
    global $searchTerm, $statusFilter, $categoryFilter, $authorFilter, $limit, $sortBy, $sortDir;
    $class = 'page-item';
    if ($disabled) $class .= ' disabled';
    if ($active) $class .= ' active';
    // Ensure all current filter/sort parameters are included in the link
    $queryParams = http_build_query([
        'page' => $pageNum,
        'search' => $searchTerm,
        'status' => $statusFilter,
        'category' => $categoryFilter,
        'author' => $authorFilter,
        'limit' => $limit,
        'sort' => $sortBy,
        'dir' => $sortDir
    ]);
    $url = "articles.php?" . $queryParams;
    return "<li class=\"$class\">" .
           "<a class=\"page-link\" href=\"".($disabled ? '#' : $url)."\" aria-label=\"$ariaLabel\">$text</a>" .
           "</li>";
}

// Helper function to generate sorting links
function generateSortLink($field, $label) {
    global $sortBy, $sortDir, $searchTerm, $statusFilter, $categoryFilter, $authorFilter, $limit, $page;
    $currentSort = ($sortBy === $field);
    $nextDir = ($currentSort && strtoupper($sortDir) === 'ASC') ? 'DESC' : 'ASC';
    $iconClass = '';
    if ($currentSort) {
        $iconClass = (strtoupper($sortDir) === 'ASC') ? 'fas fa-sort-up' : 'fas fa-sort-down';
    }
    $linkClass = 'sort-link' . ($currentSort ? ' active' : '');
    $queryParams = http_build_query([
        'sort' => $field,
        'dir' => $nextDir,
        'search' => $searchTerm,
        'status' => $statusFilter,
        'category' => $categoryFilter,
        'author' => $authorFilter,
        'limit' => $limit,
        'page' => $page // Keep current page when sorting
    ]);
    $url = "articles.php?" . $queryParams;
    $iconHtml = $currentSort ? "<i class=\"{$iconClass}\"></i>" : '';

    return "<a href=\"{$url}\" class=\"{$linkClass}\">{$label} {$iconHtml}</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Articles Management - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom Admin CSS (assuming it exists) -->
    <!-- <link rel="stylesheet" href="assets/css/admin.css"> -->
    <style>
        /* --- Keep existing CSS from the original file --- */
        /* --- Add or modify styles as needed --- */
        :root {
            --primary-color: #4e73df;
            --secondary-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
            --sidebar-width: 220px;
            --sidebar-width-collapsed: 80px; /* Consistent with JS */
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
            overflow-x: hidden; /* Prevent horizontal scroll on body */
        }

        .sidebar {
            background: linear-gradient(180deg, var(--primary-color) 10%, #224abe 100%);
            height: 100vh; /* Use vh for full height */
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1030; /* Above main content, below modals */
            width: var(--sidebar-width);
            transition: width 0.3s ease-in-out;
            overflow-y: auto;
            overflow-x: hidden; /* Prevent horizontal scroll within sidebar */
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
            height: 4.375rem;
            padding: 1.5rem 1rem;
            color: #fff;
            text-align: center;
            font-size: 1.1rem; /* Adjusted size */
            font-weight: 700; /* Adjusted weight */
            letter-spacing: 0.05rem;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-decoration: none;
            display: flex; /* Use flex for icon/text alignment */
            align-items: center;
            justify-content: center; /* Center content when collapsed */
            white-space: nowrap;
        }
         .sidebar-brand i {
             margin-right: 0.5rem; /* Space between icon and text */
             transition: margin 0.3s ease-in-out;
         }
         .sidebar.collapsed .sidebar-brand i {
             margin-right: 0;
         }


        .sidebar-items { margin-top: 1rem; } /* Add some top margin */
        .sidebar-item {
            padding: 0.75rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            transition: background-color 0.2s, color 0.2s;
            display: flex;
            align-items: center;
            margin: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            text-decoration: none;
            white-space: nowrap; /* Prevent text wrapping */
        }

        .sidebar-item.active, .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .sidebar-item i {
            margin-right: 0.75rem;
            opacity: 0.8; /* Slightly less prominent icons */
            width: 1.25em; /* Ensure icons align vertically */
            text-align: center; /* Center icons */
            flex-shrink: 0; /* Prevent icons from shrinking */
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 1.5rem;
            transition: margin-left 0.3s ease-in-out;
            min-height: 100vh; /* Ensure content area takes full height */
            display: flex; /* Use flexbox for footer */
            flex-direction: column;
        }
        .main-content.expanded {
             margin-left: var(--sidebar-width-collapsed);
        }

        .top-bar {
            background: #fff;
            margin-bottom: 1.5rem; /* Space below top bar */
            padding: 0.75rem 1rem;
            border-radius: 0.35rem;
            box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky; /* Make top bar sticky */
            top: 0;
            z-index: 1020; /* Below sidebar, above content */
        }
        .top-bar .dropdown-toggle::after { display: none; } /* Hide default Bootstrap caret */


        .card {
            border: none;
            border-radius: 0.35rem; /* Slightly smaller radius */
            box-shadow: 0 0.1rem 1rem 0 rgba(58, 59, 69, 0.08); /* Softer shadow */
            margin-bottom: 1.5rem;
            overflow: hidden; /* Contain children */
        }

        .card-header {
            background-color: #fdfdfd; /* Slightly off-white */
            border-bottom: 1px solid #e3e6f0;
            padding: 0.75rem 1rem; /* Adjusted padding */
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; /* Allow wrapping */
            gap: 0.5rem; /* Add gap between items */
        }
        .card-header h5 {
            font-size: 1rem; /* Slightly smaller heading */
            margin-bottom: 0; /* Remove default margin */
        }

        .articles-header {
            background: linear-gradient(to right, var(--primary-color), #224abe);
            color: white;
            padding: 1.5rem; /* Adjusted padding */
            border-radius: 0.35rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .articles-header h2 {
            font-weight: 600; /* Adjusted weight */
            margin-bottom: 0.25rem;
            font-size: 1.5rem; /* Adjusted size */
        }
        .articles-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .articles-header::after { display: none; } /* Removed decorative element */

        .header-meta {
            font-size: 0.75rem;
            opacity: 0.8;
            text-align: right;
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
             .header-meta { text-align: left; margin-top: 0.5rem; }
        }


        .table-responsive {
            /* Let mobile container handle overflow */
        }

        .table { margin-bottom: 0; } /* Remove margin inside card body */
        .table th {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem; /* Smaller header */
            letter-spacing: 0.05em;
            white-space: nowrap;
            padding: 0.75rem; /* Consistent padding */
            vertical-align: middle;
        }
        .table td {
            vertical-align: middle;
            padding: 0.75rem; /* Consistent padding */
            font-size: 0.85rem; /* Slightly smaller body text */
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.03); /* Subtle hover */
        }


        .status-badge {
            padding: 0.3em 0.6em; /* Adjusted padding */
            font-size: 0.7em; /* Smaller badge text */
            font-weight: 700;
            border-radius: 0.25rem;
            text-transform: uppercase;
            white-space: nowrap;
            color: #fff; /* Default white text */
            vertical-align: middle;
        }
        /* Ensure text color contrasts with background */
        .bg-warning.status-badge { color: #333 !important; }
        .bg-light.status-badge { color: #333 !important; }
        .bg-info.status-badge { color: #fff !important; } /* Keep white for info */
        .bg-secondary.status-badge { color: #fff !important; } /* Keep white for secondary */


        .pagination { margin-bottom: 0; }
        .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); }
        .page-link { color: var(--primary-color); font-size: 0.8rem; }
        .page-item.disabled .page-link { color: #858796; }


        .filter-card {
            background-color: #fff;
            border: 1px solid #e3e6f0;
            border-radius: 0.35rem;
            padding: 1rem; /* Adjusted padding */
            margin-bottom: 1.5rem;
        }
        .filter-card .form-label { font-size: 0.8rem; margin-bottom: 0.25rem; }
        .filter-card .form-control, .filter-card .form-select { font-size: 0.85rem; }


        .sort-link {
            text-decoration: none;
            color: inherit; /* Inherit color from th */
            display: inline-flex; /* Use inline-flex */
            align-items: center;
            white-space: nowrap;
        }
        .sort-link i { margin-left: 0.4rem; opacity: 0.6; } /* Adjusted spacing and opacity */
        .sort-link.active { color: var(--primary-color); /* Use primary color */ font-weight: 700; /* Make active bold */ }
        .sort-link.active i { opacity: 1; }
        .sort-link:hover { color: var(--primary-color); } /* Hover effect */


        .article-stats {
            display: flex;
            align-items: center;
            font-size: 0.75rem; /* Smaller stats */
            color: #858796;
            flex-wrap: wrap;
            gap: 0.75rem; /* Use gap for spacing */
        }
        .article-stats i { margin-right: 0.25rem; }
        .article-stats > div { white-space: nowrap; }


        .dropdown-menu {
            border: 1px solid #e3e6f0 !important; /* Add subtle border */
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1) !important; /* Softer shadow */
            border-radius: 0.35rem !important;
            z-index: 1055 !important; /* Ensure above most elements */
            font-size: 0.85rem;
        }
        .dropdown-item { padding: 0.5rem 1rem; }
        .dropdown-item i { margin-right: 0.5rem; width: 1.1em; text-align: center; opacity: 0.7; }
        .dropdown-item:active { background-color: var(--primary-color); color: #fff; }
        .dropdown-item:active i { opacity: 1; }


        .actions-column { white-space: nowrap; text-align: right; /* Align actions right */ }
        .action-dropdown-btn {
             background-color: transparent;
             border: 1px solid #d1d3e2;
             color: var(--dark-color);
             padding: 0.25rem 0.5rem;
             font-size: 0.8rem;
        }
        .action-dropdown-btn:hover, .action-dropdown-btn:focus {
             background-color: #e9ecef;
             border-color: #adb5bd;
        }


        .featured-indicator, .breaking-indicator {
            width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 0.4rem; vertical-align: middle;
        }
        .featured-indicator { background-color: var(--warning-color); }
        .breaking-indicator { background-color: var(--danger-color); }


        .article-title {
            font-weight: 500; /* Normal weight */
            color: var(--dark-color);
            display: flex;
            align-items: center;
            font-size: 0.9rem; /* Slightly smaller title */
        }
        .article-title a { text-decoration: none; color: var(--dark-color); transition: color 0.2s; }
        .article-title a:hover { color: var(--primary-color); text-decoration: underline; }


        .bulk-actions { display: flex; align-items: center; gap: 0.5rem; /* Use gap */ }
        .bulk-actions select { width: auto; }


        .card-footer {
            background-color: #fdfdfd; /* Match header */
            border-top: 1px solid #e3e6f0;
            padding: 0.75rem 1rem; /* Match header padding */
            position: sticky; /* Make footer sticky within card */
            bottom: 0;
            z-index: 10; /* Above table content, below dropdowns */
        }


        /* Mobile horizontal swipe container */
        .mobile-table-container {
            display: block; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;
            scrollbar-width: thin; scrollbar-color: #ccc #f8f9fc;
        }
        .mobile-table-container::-webkit-scrollbar { height: 6px; }
        .mobile-table-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .mobile-table-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
        .mobile-table-container::-webkit-scrollbar-thumb:hover { background: #aaa; }


        .mobile-filters-swiper {
            width: 100%; overflow-x: auto; display: flex; -webkit-overflow-scrolling: touch;
            padding-bottom: 8px; scrollbar-width: thin; scrollbar-color: #ccc #f8f9fc; gap: 1rem; /* Add gap */
        }
        .mobile-filters-swiper::-webkit-scrollbar { height: 5px; }
        .mobile-filters-swiper::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .mobile-filters-swiper::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
        .mobile-filters-swiper::-webkit-scrollbar-thumb:hover { background: #aaa; }
        .filter-item { min-width: 140px; flex: 0 0 auto; }


        /* Swipe indicator */
        .swipe-indicator {
            text-align: center; color: #6c757d; font-size: 0.8rem; margin-bottom: 0.5rem;
            display: none; align-items: center; justify-content: center;
            visibility: hidden; opacity: 0; transition: visibility 0s linear 0.3s, opacity 0.3s linear;
        }
        .swipe-indicator.visible { visibility: visible; opacity: 1; transition-delay: 0s; }
        .swipe-indicator i { animation: swipeRight 1.5s infinite ease-in-out; margin: 0 5px; }
        @keyframes swipeRight { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(5px); } }


        .sticky-footer {
            padding: 1rem 0;
            background-color: #e9ecef; /* Light background */
            margin-top: auto; /* Push to bottom */
            flex-shrink: 0; /* Prevent shrinking */
            font-size: 0.8rem;
        }


        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .main-content { padding: 1rem; }
            .card-header { flex-direction: column; align-items: flex-start; }
            .card-header > div:last-child { align-self: flex-end; } /* Button right on mobile */
            .articles-header { padding: 1rem; }
            .articles-header h2 { font-size: 1.3rem; }
            .article-title { font-size: 0.85rem; }
            .table td, .table th { padding: 0.6rem; } /* Reduce padding */
        }

        @media (max-width: 768px) {
            .main-content { padding: 0.75rem; }
            .top-bar .dropdown span { display: none; } /* Hide username text */
            .swipe-indicator.for-table { display: flex; } /* Show table swipe hint */
            .filter-card .row > div { margin-bottom: 0.75rem; } /* Space between stacked filters */
        }

        @media (max-width: 576px) {
            .articles-header h2 { font-size: 1.15rem; }
            .article-stats { gap: 0.5rem; }
            .card-footer .d-flex { flex-direction: column; align-items: stretch !important; }
            .card-footer .bulk-actions { margin-bottom: 0.75rem; }
            .pagination { justify-content: center !important; }
            .actions-column .btn { font-size: 0.75rem; padding: 0.2rem 0.4rem; } /* Smaller action button */
            .dropdown-menu { min-width: auto; } /* Prevent overly wide dropdowns */
        }

    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <a href="index.php" class="sidebar-brand" title="<?php echo htmlspecialchars(SITE_NAME); ?>">
            <i class="fas fa-newspaper"></i> <span><?php echo htmlspecialchars(SITE_NAME); ?></span>
        </a>

        <div class="sidebar-items">
             <a href="index.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" title="Dashboard"> <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span> </a>
            <?php if (hasPermission('create_article')): ?> <a href="articles.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'articles.php' ? 'active' : ''; ?>" title="Articles"> <i class="fas fa-newspaper fa-fw"></i> <span>Articles</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_categories')): ?> <a href="categories.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" title="Categories"> <i class="fas fa-folder fa-fw"></i> <span>Categories</span> </a> <?php endif; ?>
			<?php if (hasPermission('manage_categories')): // Assuming 'manage_pages' permission ?>
            <a href="pages.php" class="sidebar-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pages.php' || basename($_SERVER['PHP_SELF']) == 'page-edit.php') ? 'active' : ''; ?>" title="Pages">
                <i class="fas fa-file-alt fa-fw"></i> <span>Pages</span>
            </a>
            <?php endif; ?>
            <?php if (hasPermission('manage_comments')): ?> <a href="comments.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'comments.php' ? 'active' : ''; ?>" title="Comments"> <i class="fas fa-comments fa-fw"></i> <span>Comments</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_users')): ?> <a href="users.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" title="Users"> <i class="fas fa-users fa-fw"></i> <span>Users</span> </a> <?php endif; ?>
            <?php if (hasPermission('manage_roles')): ?> <a href="roles.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'roles.php' ? 'active' : ''; ?>" title="Roles"> <i class="fas fa-user-tag fa-fw"></i> <span>Roles</span> </a> <?php endif; ?>
            <a href="media.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'media.php' ? 'active' : ''; ?>" title="Media"> <i class="fas fa-images fa-fw"></i> <span>Media</span> </a>
            <?php if (hasPermission('manage_settings')): ?> <a href="settings.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" title="Settings"> <i class="fas fa-cog fa-fw"></i> <span>Settings</span> </a> <?php endif; ?>
            <hr class="text-white-50 mx-3 my-2">
            <a href="profile.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>" title="Profile"> <i class="fas fa-user-circle fa-fw"></i> <span>Profile</span> </a>
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
                    <img src="<?php echo !empty($currentUserPic) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($currentUserPic, '/')) ? htmlspecialchars(ltrim($currentUserPic, '/')) : 'assets/images/default-avatar.png'; ?>" class="rounded-circle me-1" width="30" height="30" alt="Profile" style="object-fit: cover;">
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

        <!-- Articles Header -->
        <div class="articles-header">
            <h2><i class="fas fa-newspaper me-2"></i> Articles Management</h2>
            <p>Create, edit, publish, and manage your news articles</p>
            <div class="header-meta">
                 User: <?php echo htmlspecialchars($currentUser['username']); ?> | UTC: <?php echo $currentDateTime; ?>
             </div>
        </div>

        <!-- Message Area -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Filter & Search Card -->
        <div class="filter-card">
            <form action="articles.php" method="GET" id="filterForm">
                <!-- Persist sort order -->
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sortDir); ?>">

                <!-- Desktop Filters (Hidden on small screens) -->
                <div class="row g-2 align-items-end d-none d-lg-flex">
                    <div class="col">
                        <label for="desktopSearch" class="form-label">Search</label>
                        <input type="text" id="desktopSearch" class="form-control form-control-sm" placeholder="Title or content..." name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="col">
                        <label for="desktopStatus" class="form-label">Status</label>
                        <select id="desktopStatus" class="form-select form-select-sm" name="status">
                            <option value="">All Statuses</option>
                            <option value="published" <?php echo $statusFilter === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col">
                        <label for="desktopCategory" class="form-label">Category</label>
                        <select id="desktopCategory" class="form-select form-select-sm" name="category">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                     <div class="col">
                        <label for="desktopAuthor" class="form-label">Author</label>
                        <select id="desktopAuthor" class="form-select form-select-sm" name="author">
                            <option value="0">All Authors</option>
                            <?php foreach ($authors as $author): ?>
                                <option value="<?php echo $author['id']; ?>" <?php echo $authorFilter == $author['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($author['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col" style="max-width: 120px;">
                         <label for="desktopLimit" class="form-label">Per Page</label>
                        <select id="desktopLimit" class="form-select form-select-sm" name="limit">
                            <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                         <a href="articles.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters"><i class="fas fa-times"></i></a>
                    </div>
                </div>

                 <!-- Mobile Filters (Visible on small screens) -->
                <div class="d-lg-none">
                    <div class="mb-2">
                        <label for="mobileSearch" class="form-label">Search</label>
                        <input type="text" id="mobileSearch" class="form-control form-control-sm" placeholder="Title or content..." name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>

                    <div class="swipe-indicator mb-2 for-filters">
                        <i class="fas fa-arrow-left"></i> Swipe for more filters <i class="fas fa-arrow-right"></i>
                    </div>

                    <div class="mobile-filters-swiper mb-3">
                        <div class="filter-item">
                            <label class="form-label" for="mobileStatus">Status</label>
                            <select id="mobileStatus" class="form-select form-select-sm" name="status">
                                <option value="">All</option>
                                <option value="published" <?php echo $statusFilter === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label class="form-label" for="mobileCategory">Category</label>
                            <select id="mobileCategory" class="form-select form-select-sm" name="category">
                                <option value="0">All</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label class="form-label" for="mobileAuthor">Author</label>
                            <select id="mobileAuthor" class="form-select form-select-sm" name="author">
                                <option value="0">All</option>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?php echo $author['id']; ?>" <?php echo $authorFilter == $author['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($author['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label class="form-label" for="mobileLimit">Per Page</label>
                            <select id="mobileLimit" class="form-select form-select-sm" name="limit">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Apply Filters</button>
                        <a href="articles.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters"><i class="fas fa-times"></i></a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Articles Table Card -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex align-items-center">
                    <h5 class="mb-0 me-2">Articles List</h5>
                    <span class="badge bg-primary rounded-pill"><?php echo number_format($totalArticles); ?> total</span>
                </div>
                <div>
                    <a href="article-new.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i> New Article
                    </a>
                </div>
            </div>

            <div class="card-body p-0">
                <form id="articlesForm" method="POST" action="articles.php?page=<?php echo $page; ?>&search=<?php echo urlencode($searchTerm); ?>&status=<?php echo urlencode($statusFilter); ?>&category=<?php echo $categoryFilter; ?>&author=<?php echo $authorFilter; ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sortBy; ?>&dir=<?php echo $sortDir; ?>">
                    <!-- Mobile swipe indicator -->
                    <div class="swipe-indicator for-table">
                        <i class="fas fa-arrow-left"></i> Swipe to view full table <i class="fas fa-arrow-right"></i>
                    </div>

                    <div class="mobile-table-container">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 1%;"> <!-- Minimal width -->
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll" title="Select all articles on this page">
                                                <label class="form-check-label visually-hidden" for="selectAll">Select all</label>
                                            </div>
                                        </th>
                                        <th><?php echo generateSortLink('title', 'Title'); ?></th>
                                        <th><?php echo generateSortLink('author_name', 'Author'); ?></th>
                                        <th>Categories</th>
                                        <th><?php echo generateSortLink('status', 'Status'); ?></th>
                                        <th style="width: 1%;"><?php echo generateSortLink('views', '<i class="fas fa-eye" title="Views"></i>'); ?></th> <!-- Icon for views -->
                                        <th><?php echo generateSortLink('created_at', 'Created'); ?></th>
                                        <th class="actions-column">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($articles) > 0): ?>
                                        <?php foreach ($articles as $article): ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input article-checkbox" type="checkbox" name="article_ids[]" value="<?php echo $article['id']; ?>" id="article_<?php echo $article['id']; ?>">
                                                        <label class="form-check-label visually-hidden" for="article_<?php echo $article['id']; ?>">Select article</label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="article-title">
                                                        <?php if ($article['featured']): ?><span class="featured-indicator" title="Featured Article"></span><?php endif; ?>
                                                        <?php if ($article['breaking_news']): ?><span class="breaking-indicator" title="Breaking News"></span><?php endif; ?>
                                                        <a href="article-edit.php?id=<?php echo $article['id']; ?>" title="<?php echo htmlspecialchars($article['title']); ?>">
                                                            <?php echo htmlspecialchars(mb_strimwidth($article['title'], 0, 70, "...")); // Limit title length ?>
                                                        </a>
                                                    </div>
                                                    <div class="article-stats mt-1">
                                                        <?php if ($article['published_at']): ?>
                                                            <div><i class="far fa-clock fa-xs"></i> <?php echo date('M d, Y', strtotime($article['published_at'])); ?></div>
                                                        <?php endif; ?>
                                                        <!-- Add more stats if needed, e.g., comments -->
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($article['author_name'] ?? 'N/A'); ?></td>
                                                <td class="small text-muted"><?php echo htmlspecialchars(mb_strimwidth($article['categories_list'] ?? 'Uncategorized', 0, 40, "...")); ?></td>
                                                <td>
                                                    <?php
                                                        $statusClass = 'secondary'; // Default (Draft)
                                                        $statusText = ucfirst($article['status']);
                                                        if ($article['status'] === 'published') $statusClass = 'success';
                                                        elseif ($article['status'] === 'pending') $statusClass = 'warning';
                                                        elseif ($article['status'] === 'archived') $statusClass = 'info';
                                                        // Add other statuses if needed
                                                    ?>
                                                    <span class="status-badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></span>
                                                </td>
                                                <td class="text-center"><?php echo number_format($article['views']); ?></td>
                                                <td class="small"><?php echo date('M d, Y', strtotime($article['created_at'])); ?></td>
                                                <td class="actions-column">
                                                    <div class="dropdown article-actions">
                                                        <button class="btn btn-sm btn-light dropdown-toggle action-dropdown-btn" type="button" id="actionDropdown<?php echo $article['id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="fas fa-ellipsis-v"></i> <span class="visually-hidden">Actions</span>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="actionDropdown<?php echo $article['id']; ?>">
                                                             <?php $canEdit = ($article['author_id'] == $currentUser['id'] && hasPermission('edit_own_article')) || hasPermission('edit_any_article'); ?>
                                                            <?php if ($canEdit): ?>
                                                            <li><a class="dropdown-item" href="article-edit.php?id=<?php echo $article['id']; ?>"><i class="fas fa-edit text-primary"></i> Edit</a></li>
                                                            <?php endif; ?>
                                                            <li><a class="dropdown-item" href="../article.php?slug=<?php echo $article['slug']; ?>" target="_blank"><i class="fas fa-eye text-info"></i> View</a></li> <!-- Assuming frontend uses slug -->

                                                            <?php if ($article['status'] !== 'published' && hasPermission('publish_article')): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="singleAction(<?php echo $article['id']; ?>, 'publish')"><i class="fas fa-check-circle text-success"></i> Publish</button></li>
                                                            <?php endif; ?>
                                                            <?php if ($article['status'] !== 'draft' && $canEdit): // Only show draft if editable and not already draft ?>
                                                                <li><button type="button" class="dropdown-item" onclick="singleAction(<?php echo $article['id']; ?>, 'draft')"><i class="fas fa-file text-secondary"></i> Move to Draft</button></li>
                                                            <?php endif; ?>

                                                            <?php $canDelete = ($article['author_id'] == $currentUser['id'] && hasPermission('delete_own_article')) || hasPermission('delete_any_article'); ?>
                                                            <?php if ($canDelete): ?>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li><button type="button" class="dropdown-item text-danger" onclick="confirmDelete(<?php echo $article['id']; ?>)"><i class="fas fa-trash-alt"></i> Delete</button></li>
                                                             <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5"> <!-- Adjusted colspan -->
                                                <i class="fas fa-newspaper fa-3x text-muted mb-3"></i>
                                                <p class="mb-2">No articles found matching your criteria.</p>
                                                <?php if (empty($searchTerm) && empty($statusFilter) && empty($categoryFilter) && empty($authorFilter)): ?>
                                                    <a href="article-new.php" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-plus me-1"></i> Create New Article
                                                    </a>
                                                <?php else: ?>
                                                     <a href="articles.php" class="btn btn-outline-secondary btn-sm">
                                                        <i class="fas fa-times me-1"></i> Clear Filters
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Hidden fields for single/bulk actions -->
                    <input type="hidden" name="action" id="action">
                    <input type="hidden" name="article_id" id="article_id">
                    <input type="hidden" name="bulk_action" id="bulk_action_hidden"> <!-- Use a hidden input for bulk action submission -->
                </form>
            </div>

            <?php if ($totalArticles > 0): // Show footer only if there are articles ?>
            <div class="card-footer">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <!-- Bulk Actions -->
                    <div class="bulk-actions">
                        <label for="bulk_action_select" class="visually-hidden">Bulk Actions</label>
                        <select class="form-select form-select-sm" id="bulk_action_select"> <!-- Changed ID -->
                            <option value="">Bulk Actions...</option>
                            <?php if (hasPermission('publish_article')): ?>
                                <option value="publish">Publish Selected</option>
                            <?php endif; ?>
                            <option value="draft">Move Selected to Draft</option>
                            <?php if (hasPermission('delete_own_article') || hasPermission('delete_any_article')): ?>
                                <option value="delete">Delete Selected</option>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="applyBulkAction">Apply</button>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Articles pagination">
                            <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-center">
                                <?php
                                    echo generatePageLink(1, '&laquo;&laquo;', 'First', $page <= 1);
                                    echo generatePageLink($page - 1, '&laquo;', 'Previous', $page <= 1);

                                    $range = 2;
                                    $startPage = max(1, $page - $range);
                                    $endPage = min($totalPages, $page + $range);

                                    if ($startPage > 1) {
                                         echo generatePageLink(1, '1', 'Page 1');
                                         if ($startPage > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    for ($i = $startPage; $i <= $endPage; $i++) {
                                        echo generatePageLink($i, $i, "Page $i", false, $i == $page);
                                    }
                                     if ($endPage < $totalPages) {
                                         if ($endPage < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                         echo generatePageLink($totalPages, $totalPages, "Page $totalPages");
                                    }
                                    echo generatePageLink($page + 1, '&raquo;', 'Next', $page >= $totalPages);
                                    echo generatePageLink($totalPages, '&raquo;&raquo;', 'Last', $page >= $totalPages);
                                ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="sticky-footer">
            <div class="container my-auto">
                <span>Copyright &copy; <?php echo htmlspecialchars(SITE_NAME) . ' ' . date('Y'); ?></span>
            </div>
        </footer>
    </div> <!-- End Main Content -->


    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the selected article(s)?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i> This action cannot be undone. Comments and related data will also be deleted.</p>
                    <p id="deleteArticleCount" class="fw-bold"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteButton">Delete</button>
                </div>
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
            const SIDEBAR_COLLAPSED_KEY = 'sidebarCollapsed'; // Define key

            function applySidebarState(collapsed) {
                if (sidebar && mainContent) {
                    sidebar.classList.toggle('collapsed', collapsed);
                    mainContent.classList.toggle('expanded', collapsed);
                }
            }

            if (sidebarToggle && sidebar && mainContent) {
                // Apply initial state from localStorage
                const isCollapsed = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === 'true';
                applySidebarState(isCollapsed);

                // Add click listener
                sidebarToggle.addEventListener('click', function() {
                    const shouldCollapse = !sidebar.classList.contains('collapsed');
                    applySidebarState(shouldCollapse);
                    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, shouldCollapse);
                });
            }


            // --- Checkbox Logic ---
            const selectAllCheckbox = document.getElementById('selectAll');
            const articleCheckboxes = document.querySelectorAll('.article-checkbox');

            function updateSelectAllCheckbox() {
                if (!selectAllCheckbox) return;
                const totalCheckboxes = articleCheckboxes.length;
                const checkedCheckboxes = document.querySelectorAll('.article-checkbox:checked').length;

                if (totalCheckboxes === 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                } else if (checkedCheckboxes === totalCheckboxes) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else if (checkedCheckboxes > 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    articleCheckboxes.forEach(checkbox => checkbox.checked = this.checked);
                    updateSelectAllCheckbox();
                });
            }
            articleCheckboxes.forEach(checkbox => checkbox.addEventListener('change', updateSelectAllCheckbox));
            updateSelectAllCheckbox(); // Initial check


            // --- Delete Confirmation Modal ---
            const deleteModalElement = document.getElementById('deleteModal');
            const confirmDeleteButton = document.getElementById('confirmDeleteButton');
            const deleteArticleCountElement = document.getElementById('deleteArticleCount');
            let itemToDelete = { type: null, id: null };
            let deleteModalInstance = null; // Initialize instance variable

            if(deleteModalElement) {
                 deleteModalInstance = new bootstrap.Modal(deleteModalElement); // Create instance
            }

            // Function called by single delete button onclick
            window.confirmDelete = function(articleId) {
                itemToDelete = { type: 'single', id: articleId };
                if (deleteArticleCountElement) deleteArticleCountElement.textContent = 'Deleting 1 article.';
                if(deleteModalInstance) deleteModalInstance.show();
            };

            // Final confirm button inside modal
            if (confirmDeleteButton) {
                 confirmDeleteButton.addEventListener('click', function() {
                     const form = document.getElementById('articlesForm');
                     if (!form) return;

                     if (itemToDelete.type === 'single' && itemToDelete.id) {
                         // Submit form for single delete
                         submitFormWithAction('delete', itemToDelete.id);
                     } else if (itemToDelete.type === 'bulk') {
                         // Submit form for bulk delete
                         submitFormWithBulkAction('delete');
                     }
                     if(deleteModalInstance) deleteModalInstance.hide();
                 });
            }

            // Reset on modal hide
            if (deleteModalElement) {
                deleteModalElement.addEventListener('hidden.bs.modal', function () {
                    itemToDelete = { type: null, id: null };
                    if (deleteArticleCountElement) deleteArticleCountElement.textContent = '';
                 });
            }


            // --- Single Article Actions (Publish, Draft) ---
            window.singleAction = function(articleId, action) {
                submitFormWithAction(action, articleId);
            };


            // --- Bulk Actions ---
            const applyBulkActionButton = document.getElementById('applyBulkAction');
            const bulkActionSelect = document.getElementById('bulk_action_select'); // Use select ID

            if (applyBulkActionButton && bulkActionSelect) {
                applyBulkActionButton.addEventListener('click', function() {
                    const selectedAction = bulkActionSelect.value;
                    const checkedBoxes = document.querySelectorAll('.article-checkbox:checked');

                    if (selectedAction === '') {
                        alert('Please select a bulk action.'); return;
                    }
                    if (checkedBoxes.length === 0) {
                        alert('Please select at least one article.'); return;
                    }

                    if (selectedAction === 'delete') {
                        itemToDelete = { type: 'bulk', id: null };
                        if (deleteArticleCountElement) deleteArticleCountElement.textContent = `Deleting ${checkedBoxes.length} article(s).`;
                        if(deleteModalInstance) deleteModalInstance.show();
                        // Submission happens via modal confirm button
                    } else {
                        // Submit directly for non-delete actions
                        submitFormWithBulkAction(selectedAction);
                    }
                });
            }

            // --- Helper function to submit form with single action ---
            function submitFormWithAction(action, articleId) {
                const form = document.getElementById('articlesForm');
                const actionInput = document.getElementById('action');
                const articleIdInput = document.getElementById('article_id');
                const bulkActionHiddenInput = document.getElementById('bulk_action_hidden');

                if (form && actionInput && articleIdInput && bulkActionHiddenInput) {
                    actionInput.value = action;
                    articleIdInput.value = articleId;
                    bulkActionHiddenInput.value = ''; // Clear bulk action
                    bulkActionHiddenInput.disabled = true; // Disable bulk hidden input
                    actionInput.disabled = false; // Enable single action inputs
                    articleIdInput.disabled = false;

                    // Uncheck all checkboxes before submitting single action
                    articleCheckboxes.forEach(cb => cb.checked = false);
                    if (selectAllCheckbox) selectAllCheckbox.checked = false;

                    form.submit();
                } else {
                    console.error("Form or hidden inputs not found for single action.");
                }
            }

            // --- Helper function to submit form with bulk action ---
            function submitFormWithBulkAction(action) {
                 const form = document.getElementById('articlesForm');
                 const actionInput = document.getElementById('action');
                 const articleIdInput = document.getElementById('article_id');
                 const bulkActionHiddenInput = document.getElementById('bulk_action_hidden');

                 if (form && actionInput && articleIdInput && bulkActionHiddenInput) {
                    bulkActionHiddenInput.value = action; // Set bulk action
                    actionInput.value = ''; // Clear single action
                    articleIdInput.value = ''; // Clear single ID
                    bulkActionHiddenInput.disabled = false; // Enable bulk hidden input
                    actionInput.disabled = true; // Disable single action inputs
                    articleIdInput.disabled = true;

                    form.submit();
                 } else {
                     console.error("Form or hidden inputs not found for bulk action.");
                 }
            }


            // --- Mobile Filters Swiper Logic (Drag Scroll) ---
            const mobileFiltersSwiper = document.querySelector('.mobile-filters-swiper');
             if (mobileFiltersSwiper) {
                 let isDown = false;
                 let startX;
                 let scrollLeft;

                 const start = (e) => {
                    isDown = true;
                    startX = (e.pageX || e.touches[0].pageX) - mobileFiltersSwiper.offsetLeft;
                    scrollLeft = mobileFiltersSwiper.scrollLeft;
                    mobileFiltersSwiper.style.cursor = 'grabbing';
                    mobileFiltersSwiper.style.userSelect = 'none'; // Prevent text selection
                 };
                 const end = () => {
                    if (!isDown) return;
                    isDown = false;
                    mobileFiltersSwiper.style.cursor = 'grab';
                     mobileFiltersSwiper.style.removeProperty('user-select');
                 };
                 const move = (e) => {
                    if (!isDown) return;
                    e.preventDefault(); // Prevent default scroll/selection on move
                    const x = (e.pageX || e.touches[0].pageX) - mobileFiltersSwiper.offsetLeft;
                    const walk = (x - startX) * 1.5; // Drag speed multiplier
                    mobileFiltersSwiper.scrollLeft = scrollLeft - walk;
                 };

                 mobileFiltersSwiper.addEventListener('mousedown', start);
                 mobileFiltersSwiper.addEventListener('mouseleave', end);
                 mobileFiltersSwiper.addEventListener('mouseup', end);
                 mobileFiltersSwiper.addEventListener('mousemove', move);

                 mobileFiltersSwiper.addEventListener('touchstart', start, { passive: true });
                 mobileFiltersSwiper.addEventListener('touchend', end);
                 mobileFiltersSwiper.addEventListener('touchcancel', end); // Handle cancellation
                 mobileFiltersSwiper.addEventListener('touchmove', move, { passive: false }); // Need passive false to preventDefault

                 // Show swipe indicator if scrollable
                 const filtersSwipeIndicator = document.querySelector('.swipe-indicator.for-filters');
                 if(filtersSwipeIndicator && mobileFiltersSwiper.scrollWidth > mobileFiltersSwiper.clientWidth) {
                     filtersSwipeIndicator.classList.add('visible');
                 }
                  mobileFiltersSwiper.style.cursor = 'grab'; // Initial cursor
            }


            // --- Mobile Table Horizontal Scroll Indicator ---
            const tableContainer = document.querySelector('.mobile-table-container');
            if (tableContainer) {
                const checkTableOverflow = () => {
                    const table = tableContainer.querySelector('.table');
                    const tableSwipeIndicator = document.querySelector('.swipe-indicator.for-table');
                    if (table && tableSwipeIndicator && window.innerWidth <= 768) {
                        tableSwipeIndicator.classList.toggle('visible', table.offsetWidth > tableContainer.offsetWidth);
                    } else if (tableSwipeIndicator) {
                        tableSwipeIndicator.classList.remove('visible');
                    }
                };
                checkTableOverflow();
                window.addEventListener('resize', checkTableOverflow);
            }

        }); // End DOMContentLoaded
    </script>

</body>
</html>
