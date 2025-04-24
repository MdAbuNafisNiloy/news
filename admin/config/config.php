<?php
// Website Configuration
define('SITE_NAME', 'Alpha News');
define('SITE_URL', 'http://localhost/news-website'); // Change this to your site URL
define('ADMIN_EMAIL', 'niloynafis234@gmail.com'); // Change to your admin email

// Upload Configuration
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 5000000); // 5MB
define('ALLOWED_EXTENSIONS', serialize(array('jpg', 'jpeg', 'png', 'gif', 'pdf')));

// Pagination Configuration
define('ITEMS_PER_PAGE', 10);

// Session lifetime (in seconds)
define('SESSION_LIFETIME', 3600); // 1 hour

// Default timezone
date_default_timezone_set('UTC');