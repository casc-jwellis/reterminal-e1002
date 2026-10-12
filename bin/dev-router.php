<?php
declare(strict_types=1);

// Router for PHP's built-in web server, mirroring the nginx rewrite for the .png image URL:
//   php -d extension=gd -S localhost:8080 -t public bin/dev-router.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('~^/placard/([a-z0-9-]+)/([A-Za-z0-9]+)\.png$~', $path, $m)) {
    $_GET['room'] = $m[1];
    $_GET['key'] = $m[2];
    require __DIR__ . '/../public/placard.php';
    return true;
}

return false; // serve everything else as usual
