<?php
// Test only: make WordPress think it lives in /blog/ (folder install, #561).
add_filter('home_url', function ($url, $path) { return preg_replace('#^(https?://[^/]+)(?!/blog(/|$))#', '$1/blog', $url, 1); }, 10, 2);
