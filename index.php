<?php
// Start session to access user data if needed
session_start();

// Include necessary configuration files
require_once 'admin/config/database.php';
require_once 'admin/config/config.php';
require_once 'admin/includes/functions.php';

// Get site settings from database
function getSiteSettings() {
    global $db;
    $settings = [];
    
    try {
        // Get all settings from the settings table
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $result = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if ($result) {
            $settings = $result;
        }
    } catch (PDOException $e) {
        error_log("Error loading settings: " . $e->getMessage());
    }
    
    return $settings;
}

// Load site settings
$siteSettings = getSiteSettings();

// Get current theme from settings
$currentTheme = isset($siteSettings['theme']) ? $siteSettings['theme'] : 'default';

// Function to fetch featured articles
function getFeaturedArticles($limit = 5) {
    global $db;
    $articles = [];
    
    try {
        $query = "
            SELECT a.*, u.username as author_name, u.profile_picture as author_image,
                   (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') 
                    FROM article_categories ac 
                    JOIN categories c ON ac.category_id = c.id 
                    WHERE ac.article_id = a.id) as categories,
                   (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') 
                    FROM article_tags at 
                    JOIN tags t ON at.tag_id = t.id 
                    WHERE at.article_id = a.id) as tags
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'published' AND a.featured = 1
            ORDER BY a.published_at DESC
            LIMIT ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$limit]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching featured articles: " . $e->getMessage());
    }
    
    return $articles;
}

// Function to fetch breaking news
function getBreakingNews($limit = 3) {
    global $db;
    $articles = [];
    
    try {
        $query = "
            SELECT a.*, u.username as author_name, u.profile_picture as author_image
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'published' AND a.breaking_news = 1
            ORDER BY a.published_at DESC
            LIMIT ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$limit]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching breaking news: " . $e->getMessage());
    }
    
    return $articles;
}

// Function to fetch latest articles by category
function getArticlesByCategory($categoryId, $limit = 4) {
    global $db;
    $articles = [];
    
    try {
        $query = "
            SELECT a.*, u.username as author_name, u.profile_picture as author_image
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            JOIN article_categories ac ON a.id = ac.article_id
            WHERE a.status = 'published' AND ac.category_id = ?
            ORDER BY a.published_at DESC
            LIMIT ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$categoryId, $limit]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching articles by category: " . $e->getMessage());
    }
    
    return $articles;
}

// Function to fetch all categories
function getAllCategories() {
    global $db;
    $categories = [];
    
    try {
        $query = "
            SELECT * FROM categories
            WHERE parent_id IS NULL
            ORDER BY created_at ASC
        ";
        
        $stmt = $db->query($query);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching categories: " . $e->getMessage());
    }
    
    return $categories;
}

// Function to fetch popular/trending articles
function getTrendingArticles($limit = 6) {
    global $db;
    $articles = [];
    
    try {
        $query = "
            SELECT a.*, u.username as author_name, u.profile_picture as author_image
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'published'
            ORDER BY a.views DESC, a.published_at DESC
            LIMIT ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$limit]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching trending articles: " . $e->getMessage());
    }
    
    return $articles;
}

// Function to get excerpt from content
function getExcerpt($content, $length = 150) {
    $excerpt = strip_tags($content);
    if (strlen($excerpt) > $length) {
        $excerpt = substr($excerpt, 0, $length) . '...';
    }
    return $excerpt;
}

// Get data for the front page
$featuredArticles = getFeaturedArticles(5);
$breakingNews = getBreakingNews(3);
$categories = getAllCategories();
$trendingArticles = getTrendingArticles(6);

// Handle missing featured image
function getArticleImage($article) {
    $defaultImage = 'assets/images/default-article.jpg';

    if (!empty($article['featured_image'])) {
        $featuredImage =$article['featured_image'];
        // Check if the image exists
            return "/admin/".$featuredImage;
        
    }
    
    return $defaultImage;
}

// Get author image
function getAuthorImage($article) {
    $defaultImage = 'assets/images/default-avatar.jpg';
    
    if (!empty($article['author_image'])) {
        $authorImage = $article['author_image'];
        // Check if the image exists
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($authorImage, '/'))) {
            return $authorImage;
        }
    }
    
    return $defaultImage;
}

// Get social media links
$facebookUrl = isset($siteSettings['facebook_url']) ? $siteSettings['facebook_url'] : '#';
$twitterUrl = isset($siteSettings['twitter_url']) ? $siteSettings['twitter_url'] : '#';
$instagramUrl = isset($siteSettings['instagram_url']) ? $siteSettings['instagram_url'] : '#';
$youtubeUrl = isset($siteSettings['youtube_url']) ? $siteSettings['youtube_url'] : '#';
$linkedinUrl = isset($siteSettings['linkedin_url']) ? $siteSettings['linkedin_url'] : '#';

// Get site info
$siteName = isset($siteSettings['site_name']) ? $siteSettings['site_name'] : 'Alpha News';
$siteTagline = isset($siteSettings['site_tagline']) ? $siteSettings['site_tagline'] : 'Latest News and Updates';
$siteLogo = isset($siteSettings['site_logo']) ? $siteSettings['site_logo'] : 'assets/images/logo.png';
$footerText = isset($siteSettings['footer_text']) ? $siteSettings['footer_text'] : 'Copyright © ' . date('Y') . ' ' . $siteName . '. All rights reserved.';
$adminEmail = isset($siteSettings['admin_email']) ? $siteSettings['admin_email'] : 'info@example.com';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($currentTheme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName . ' - ' . $siteTagline); ?></title>
    
    <!-- Favicon -->
    <?php if (isset($siteSettings['favicon']) && !empty($siteSettings['favicon'])): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($siteSettings['favicon']); ?>" type="image/x-icon">
    <?php endif; ?>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- AOS CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Splide Carousel -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css">
    
    <!-- Custom CSS for theming -->
    <link rel="stylesheet" href="/style.css">
    
    <?php if (isset($siteSettings['custom_css'])): ?>
    <style>
        <?php echo $siteSettings['custom_css']; ?>
    </style>
    <?php endif; ?>
</head>

<body>
    <!-- Search Overlay -->
    <div class="search-overlay" id="searchOverlay">
        <button class="search-close" id="searchClose">
            <i class="fas fa-times"></i>
        </button>
        <form class="search-form">
            <input type="text" class="search-input" placeholder="Search articles..." name="q">
            <button type="submit" class="search-submit">
                <i class="fas fa-search"></i>
            </button>
        </form>
    </div>

    <!-- Back to Top Button -->
    <div class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <?php if (!empty($siteLogo)): ?>
                    <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" class="img-fluid">
                <?php else: ?>
                    <span class="fw-bold"><?php echo htmlspecialchars($siteName); ?></span>
                <?php endif; ?>
            </a>
            
            <div class="d-flex align-items-center order-lg-last ms-2">
                <button class="navbar-search-btn me-2 d-none d-lg-block" id="searchBtn">
                    <i class="fas fa-search"></i>
                </button>
                <a href="#" class="btn btn-subscribe d-none d-md-inline-block">Subscribe</a>
                <button class="navbar-toggler ms-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                   
                    
                    <?php foreach(array_slice($categories, 0, 5) as $category): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="category.php?id=<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></a>
                        </li>
                    <?php endforeach; ?>
                    
                    <?php if (count($categories) > 5): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                More
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <?php foreach(array_slice($categories, 5) as $category): ?>
                                    <li><a class="dropdown-item" href="category.php?id=<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Mobile Search Button -->
                <div class="d-lg-none mt-3 mb-2">
                    <button class="btn btn-primary w-100" id="searchBtnMobile">
                        <i class="fas fa-search me-2"></i> Search
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Breaking News -->
    <?php if (!empty($breakingNews)): ?>
        <div class="breaking-news">
            <div class="container">
                <div class="d-flex h-100">
                    <div class="breaking-news-label">
                        <i class="fas fa-bolt me-2"></i> BREAKING
                    </div>
                    <div class="breaking-news-content">
                        <div class="ticker-wrap">
                            <div class="ticker">
                                <?php foreach($breakingNews as $news): ?>
                                    <div class="ticker-item">
                                        <a href="article.php?id=<?php echo $news['id']; ?>" class="text-white"><?php echo htmlspecialchars($news['title']); ?></a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Featured Articles -->
        <section class="featured-area">
            <div class="row g-4">
                <div class="col-lg-8" data-aos="fade-up" data-aos-duration="1000">
                    <?php if (!empty($featuredArticles)): ?>
                        <a href="article.php?id=<?php echo $featuredArticles[0]['id']; ?>" class="featured-main d-block">
                            <img src="<?php echo getArticleImage($featuredArticles[0]); ?>" alt="<?php echo htmlspecialchars($featuredArticles[0]['title']); ?>" class="featured-main-img">
                            <div class="featured-overlay">
                                <?php if (isset($featuredArticles[0]['categories'])): ?>
                                    <span class="featured-category"><?php echo htmlspecialchars(explode(',', $featuredArticles[0]['categories'])[0]); ?></span>
                                <?php endif; ?>
                                <h2 class="featured-title"><?php echo htmlspecialchars($featuredArticles[0]['title']); ?></h2>
                                <p class="featured-excerpt"><?php echo htmlspecialchars(getExcerpt($featuredArticles[0]['content'], 200)); ?></p>
                                <div class="featured-meta">
                                    <span><i class="far fa-user"></i> <?php echo htmlspecialchars($featuredArticles[0]['author_name']); ?></span>
                                    <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($featuredArticles[0]['published_at'])); ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-4">
                    <div class="row g-4">
                        <?php for ($i = 1; $i < min(3, count($featuredArticles)); $i++): ?>
                            <div class="col-12" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="<?php echo $i * 100; ?>">
                                <a href="article.php?id=<?php echo $featuredArticles[$i]['id']; ?>" class="featured-secondary d-block">
                                    <img src="<?php echo getArticleImage($featuredArticles[$i]); ?>" alt="<?php echo htmlspecialchars($featuredArticles[$i]['title']); ?>" class="featured-secondary-img">
                                    <div class="featured-overlay">
                                        <?php if (isset($featuredArticles[$i]['categories'])): ?>
                                            <span class="featured-category"><?php echo htmlspecialchars(explode(',', $featuredArticles[$i]['categories'])[0]); ?></span>
                                        <?php endif; ?>
                                        <h3 class="featured-title"><?php echo htmlspecialchars($featuredArticles[$i]['title']); ?></h3>
                                        <div class="featured-meta">
                                            <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($featuredArticles[$i]['published_at'])); ?></span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <!-- Main Content Area -->
            <div class="col-lg-8">
                <?php 
                // Display first 3 categories with articles
                $displayedCategories = 0;
                foreach ($categories as $category):
                    if ($displayedCategories >= 3) break;
                    
                    $categoryArticles = getArticlesByCategory($category['id'], 4);
                    if (empty($categoryArticles)) continue;
                    $displayedCategories++;
                ?>
                    <section class="category-section" data-aos="fade-up" data-aos-duration="1000">
                        <h2 class="section-title">
                            <?php echo htmlspecialchars($category['name']); ?>
                            <a href="category.php?id=<?php echo $category['id']; ?>" class="view-all">View All <i class="fas fa-arrow-right ms-1"></i></a>
                        </h2>
                        
                        <div class="row g-4">
                            <?php if (!empty($categoryArticles[0])): ?>
                                <div class="col-md-6 mb-4">
                                    <div class="article-card h-100">
                                        <div class="article-card-img-container">
                                            <img src="<?php echo getArticleImage($categoryArticles[0]); ?>" alt="<?php echo htmlspecialchars($categoryArticles[0]['title']); ?>" class="article-card-img">
                                        </div>
                                        <div class="article-card-body d-flex flex-column">
                                            <span class="article-card-category"><?php echo htmlspecialchars($category['name']); ?></span>
                                            <h3 class="article-card-title">
                                                <a href="article.php?id=<?php echo $categoryArticles[0]['id']; ?>"><?php echo htmlspecialchars($categoryArticles[0]['title']); ?></a>
                                            </h3>
                                            <p class="article-card-excerpt"><?php echo htmlspecialchars(getExcerpt($categoryArticles[0]['content'], 120)); ?></p>
                                            <div class="article-card-meta mt-auto">
                                                <span><i class="far fa-user"></i> <?php echo htmlspecialchars($categoryArticles[0]['author_name']); ?></span>
                                                <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($categoryArticles[0]['published_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="col-md-6">
                                <?php for ($i = 1; $i < count($categoryArticles); $i++): ?>
                                    <div class="article-list-item">
                                        <img src="<?php echo getArticleImage($categoryArticles[$i]); ?>" alt="<?php echo htmlspecialchars($categoryArticles[$i]['title']); ?>" class="article-list-img">
                                        <div class="article-list-content">
                                            <h3 class="article-list-title">
                                                <a href="article.php?id=<?php echo $categoryArticles[$i]['id']; ?>"><?php echo htmlspecialchars($categoryArticles[$i]['title']); ?></a>
                                            </h3>
                                            <div class="article-list-meta">
                                                <span><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($categoryArticles[$i]['published_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Trending Articles -->
                <div class="sidebar-section" data-aos="fade-up" data-aos-duration="1000">
                    <h3 class="sidebar-title">Trending Now</h3>
                    
                    <?php foreach ($trendingArticles as $index => $article): ?>
                        <div class="trending-item">
                            <div class="trending-count"><?php echo $index + 1; ?></div>
                            <div class="trending-content">
                                <h3 class="trending-title">
                                    <a href="article.php?id=<?php echo $article['id']; ?>"><?php echo htmlspecialchars($article['title']); ?></a>
                                </h3>
                                <div class="trending-meta">
                                    <span><i class="far fa-eye"></i> <?php echo number_format($article['views']); ?> views</span>
                                    <span class="ms-3"><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($article['published_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Newsletter -->
                <div class="sidebar-section" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="100">
                    <h3 class="sidebar-title">Newsletter</h3>
                    <div class="sidebar-newsletter">
                        <p>Subscribe to our newsletter and get the latest news delivered to your inbox.</p>
                        <form class="sidebar-newsletter-form">
                            <input type="text" class="form-control" placeholder="Your Name" required>
                            <input type="email" class="form-control" placeholder="Your Email" required>
                            <button type="submit" class="btn btn-primary">Subscribe</button>
                        </form>
                    </div>
                </div>
                
                <!-- Categories -->
                <div class="sidebar-section" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="200">
                    <h3 class="sidebar-title">Categories</h3>
                    <div class="category-tags">
                        <?php foreach ($categories as $category): ?>
                            <a href="category.php?id=<?php echo $category['id']; ?>" class="category-tag">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Ad Banner -->
                <div class="sidebar-section text-center" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="300">
                    <div class="p-3 rounded">
                        <span class="text-muted small d-block mb-2">ADVERTISEMENT</span>
                        <div class="ad-container">
                            <img src="assets/images/ad-placeholder.jpg" alt="Advertisement" class="img-fluid rounded">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Newsletter Section -->
    <section class="newsletter-section mt-5">
        <div class="container">
            <div class="newsletter-content" data-aos="fade-up" data-aos-duration="1000">
                <h2 class="newsletter-title">Stay Updated with Our Newsletter</h2>
                <p class="newsletter-description">Get the latest news and updates delivered straight to your inbox.</p>
                <form class="newsletter-form">
                    <input type="email" class="newsletter-input" placeholder="Enter your email address" required>
                    <button type="submit" class="newsletter-btn">Subscribe</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer mt-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4" data-aos="fade-up" data-aos-duration="1000">
                    <?php if (!empty($siteLogo)): ?>
                        <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" class="footer-logo">
                    <?php else: ?>
                        <h3 class="text-white mb-4"><?php echo htmlspecialchars($siteName); ?></h3>
                    <?php endif; ?>
                    
                    <p class="footer-text"><?php echo htmlspecialchars($siteTagline); ?> - Your trusted source for the latest news, analysis, and insights on topics that matter.</p>
                    
                    <div class="social-links">
                        <?php if (!empty($facebookUrl)): ?>
                            <a href="<?php echo htmlspecialchars($facebookUrl); ?>" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <?php endif; ?>
                        
                        <?php if (!empty($twitterUrl)): ?>
                            <a href="<?php echo htmlspecialchars($twitterUrl); ?>" target="_blank" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <?php endif; ?>
                        
                        <?php if (!empty($instagramUrl)): ?>
                            <a href="<?php echo htmlspecialchars($instagramUrl); ?>" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <?php endif; ?>
                        
                        <?php if (!empty($youtubeUrl)): ?>
                            <a href="<?php echo htmlspecialchars($youtubeUrl); ?>" target="_blank" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                        <?php endif; ?>
                        
                        <?php if (!empty($linkedinUrl)): ?>
                            <a href="<?php echo htmlspecialchars($linkedinUrl); ?>" target="_blank" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="100">
                    <h4 class="footer-header">Categories</h4>
                    <ul class="footer-links">
                        <?php foreach(array_slice($categories, 0, 5) as $category): ?>
                            <li>
                                <a href="category.php?id=<?php echo $category['id']; ?>">
                                    <i class="fas fa-chevron-right"></i> <?php echo htmlspecialchars($category['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="200">
                    <h4 class="footer-header">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="about.php"><i class="fas fa-chevron-right"></i> About Us</a></li>
                        <li><a href="contact.php"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
                        <li><a href="privacy-policy.php"><i class="fas fa-chevron-right"></i> Privacy Policy</a></li>
                        <li><a href="terms.php"><i class="fas fa-chevron-right"></i> Terms of Service</a></li>
                        <li><a href="sitemap.php"><i class="fas fa-chevron-right"></i> Sitemap</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-4 col-md-4 mb-4" data-aos="fade-up" data-aos-duration="1000" data-aos-delay="300">
                    <h4 class="footer-header">Contact Us</h4>
                    <ul class="footer-links">
                        <li><i class="fas fa-map-marker-alt me-2"></i> 123 News Street, City, Country</li>
                        <li>
                            <i class="fas fa-envelope me-2"></i> 
                            <a href="mailto:<?php echo htmlspecialchars(isset($siteSettings['admin_email']) ? $siteSettings['admin_email'] : 'info@example.com'); ?>">
                                <?php echo htmlspecialchars(isset($siteSettings['admin_email']) ? $siteSettings['admin_email'] : 'info@example.com'); ?>
                            </a>
                        </li>
                        <li><i class="fas fa-phone me-2"></i> +1 234 567 890</li>
                        <li><i class="fas fa-clock me-2"></i> Mon - Fri: 9:00 AM - 5:00 PM</li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="footer-bottom-links">
                    <a href="privacy-policy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="contact.php">Contact Us</a>
                </div>
                <p><?php echo $footerText; ?></p>
                <p class="mt-2">Last updated: <?php echo date('F d, Y'); ?></p>
            </div>
        </div>
    </footer>

    <!-- Theme Switcher -->
    <div class="theme-switcher" id="themeSwitcher">
        <i class="fas fa-palette"></i>
    </div>
    
    <div class="theme-options" id="themeOptions">
        <div class="theme-option<?php echo $currentTheme === 'default' ? ' active' : ''; ?>" data-theme="default">
            <i class="fas fa-sun"></i> Light
        </div>
        <div class="theme-option<?php echo $currentTheme === 'dark' ? ' active' : ''; ?>" data-theme="dark">
            <i class="fas fa-moon"></i> Dark
        </div>
        <div class="theme-option<?php echo $currentTheme === 'modern' ? ' active' : ''; ?>" data-theme="modern">
            <i class="fas fa-leaf"></i> Modern
        </div>
        <div class="theme-option<?php echo $currentTheme === 'classic' ? ' active' : ''; ?>" data-theme="classic">
            <i class="fas fa-book"></i> Classic
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <!-- Splide Carousel -->
    <script src="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize AOS animation
            AOS.init({
                once: true,
                disable: 'mobile'
            });
            
            // Navbar scroll effect
            window.addEventListener('scroll', function() {
                const navbar = document.querySelector('.navbar');
                if (window.scrollY > 50) {
                    navbar.classList.add('navbar-scrolled');
                } else {
                    navbar.classList.remove('navbar-scrolled');
                }
            });
            
            // Search overlay functionality
            const searchBtn = document.getElementById('searchBtn');
            const searchBtnMobile = document.getElementById('searchBtnMobile');
            const searchOverlay = document.getElementById('searchOverlay');
            const searchClose = document.getElementById('searchClose');
            const searchInput = document.querySelector('.search-input');
            
            function openSearch() {
                searchOverlay.classList.add('show');
                setTimeout(() => {
                    searchInput.focus();
                }, 300);
                document.body.style.overflow = 'hidden';
            }
            
            function closeSearch() {
                searchOverlay.classList.remove('show');
                document.body.style.overflow = '';
            }
            
            if(searchBtn) {
                searchBtn.addEventListener('click', openSearch);
            }
            
            if(searchBtnMobile) {
                searchBtnMobile.addEventListener('click', openSearch);
            }
            
            if(searchClose) {
                searchClose.addEventListener('click', closeSearch);
            }
            
            // Close search on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeSearch();
                }
            });
            
            // Back to top button
            const backToTopBtn = document.getElementById('backToTop');
            
            window.addEventListener('scroll', function() {
                if (window.scrollY > 300) {
                    backToTopBtn.classList.add('show');
                } else {
                    backToTopBtn.classList.remove('show');
                }
            });
            
            backToTopBtn.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
            
            // Theme Switcher
            const themeSwitcher = document.getElementById('themeSwitcher');
            const themeOptions = document.getElementById('themeOptions');
            const themeOptionElements = document.querySelectorAll('.theme-option');
            
            // Show/hide theme options
            themeSwitcher.addEventListener('click', function() {
                themeOptions.classList.toggle('show');
            });
            
            // Theme selection
            themeOptionElements.forEach(option => {
                option.addEventListener('click', function() {
                    const selectedTheme = this.getAttribute('data-theme');
                    document.documentElement.setAttribute('data-theme', selectedTheme);
                    
                    // Update active class
                    themeOptionElements.forEach(el => el.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Save theme preference via AJAX
                    saveThemePreference(selectedTheme);
                    
                    // Hide options
                    themeOptions.classList.remove('show');
                });
            });
            
            // Close theme options when clicking outside
            document.addEventListener('click', function(event) {
                if (!themeSwitcher.contains(event.target) && !themeOptions.contains(event.target)) {
                    themeOptions.classList.remove('show');
                }
            });
            
            // Function to save theme preference
            function saveThemePreference(theme) {
                // Store in local storage
                localStorage.setItem('preferredTheme', theme);
                
                // Optional: Save to user settings via AJAX if logged in
                // This is a placeholder for actual AJAX implementation
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'save_theme_preference.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        console.log('Theme preference saved successfully');
                    }
                };
                xhr.send('theme=' + theme);
            }
            
            // Check for saved theme in local storage
            const savedTheme = localStorage.getItem('preferredTheme');
            if (savedTheme && savedTheme !== document.documentElement.getAttribute('data-theme')) {
                document.documentElement.setAttribute('data-theme', savedTheme);
                
                // Update active class
                themeOptionElements.forEach(el => {
                    if (el.getAttribute('data-theme') === savedTheme) {
                        el.classList.add('active');
                    } else {
                        el.classList.remove('active');
                    }
                });
            }
            
            // Animate elements when they come into view
            const animateElements = document.querySelectorAll('.animate-fade-up');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in-view');
                    }
                });
            }, {
                threshold: 0.1
            });
            
            animateElements.forEach(el => {
                observer.observe(el);
            });
            
            // Current date display
            const currentDateElement = document.getElementById('currentDate');
            if (currentDateElement) {
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                const today = new Date();
                currentDateElement.textContent = today.toLocaleDateString('en-US', options);
            }
        });
    </script>
    
    <?php if (isset($siteSettings['custom_js'])): ?>
    <script>
        <?php echo $siteSettings['custom_js']; ?>
    </script>
    <?php endif; ?>
    
    <?php if (isset($siteSettings['google_analytics_id'])): ?>
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($siteSettings['google_analytics_id']); ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo htmlspecialchars($siteSettings['google_analytics_id']); ?>');
    </script>
    <?php endif; ?>
</body>
</html>