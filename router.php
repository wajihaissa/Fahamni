<?php
// Router script for PHP built-in server
// Serves static files with correct MIME types, delegates rest to index.php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly with correct MIME types
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    $ext = pathinfo($uri, PATHINFO_EXTENSION);
    $mimeTypes = [
        'js'   => 'application/javascript',
        'mjs'  => 'application/javascript',
        'css'  => 'text/css',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'map'  => 'application/json',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        readfile(__DIR__.'/public'.$uri);
        return true;
    }

    return false; // Let PHP built-in server handle it
}

// Route everything else through Symfony
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';
require __DIR__.'/public/index.php';
