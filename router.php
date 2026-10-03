<?php
declare(strict_types=1);
// Development router. Production must use public/ as its document root.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$public = realpath(__DIR__ . '/public');
if ($path === '/' || $path === '/index.php') { require __DIR__ . '/public/index.php'; return true; }
if ($path === '/api.php') { require __DIR__ . '/public/api.php'; return true; }
if (str_starts_with($path, '/admin/inc/')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'مسیر پیدا نشد.';
    return true;
}
$file = realpath(__DIR__ . '/public' . $path);
if ($file && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file) && !str_contains($path, '/.')) {
    // Static assets: served by the built-in server directly.
    if (preg_match('/\.(css|js|jpg|jpeg|png|svg|woff2|txt|ico)$/i', $file)) return false;
    // PHP pages (login.php, admin/*.php): let the built-in server execute them.
    if (preg_match('/\.php$/i', $file)) return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'مسیر پیدا نشد.';
return true;
