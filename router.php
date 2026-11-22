<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/[a-zA-Z0-9_-]+\.(css|js)$~D', $path) && is_file(__DIR__ . $path)) {
    return false;
}
if (preg_match('~^/(?:\.|app(?:/|$)|deploy(?:/|$)|config(?:\.|/)|router\.php)~i', rawurldecode($path))) {
    http_response_code(404);
    exit;
}
require __DIR__ . '/index.php';
