<?php
$site = rtrim(env('APP_URL', 'https://finobe.wtf'), '/');
$scheme = parse_url($site, PHP_URL_SCHEME) ?: 'https';
return [
    'url' => rtrim(env('BLOG_URL', $scheme.'://blog.'.env('FINOBE_DOMAIN', 'finobe.wtf')), '/'),
    'site_url' => $site,
    'title' => 'Finobe',
    'description' => 'News and updates for Finobe.',
    'logo' => '/s/blog/logo.png',
    'cover' => '/s/blog/cover.jpg',
    'twitter_url' => env('BLOG_TWITTER_URL'),
    'posts_per_page' => 25,
];
