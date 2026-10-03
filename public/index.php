<?php
declare(strict_types=1);
use FitBot\{AgentService, Auth, Database, FitnessService};
try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    Auth::start();
    $user = Auth::user();
    $stored = $user ? Database::userState($user['id']) : ['state' => null, 'revision' => 0, 'updatedAt' => null];
    $boot = ['apiUrl' => 'api.php', 'user' => $user, 'csrf' => Auth::csrf(), ...$stored, 'aiAvailable' => AgentService::available(), 'engine' => 'php', 'calculation' => FitnessService::calculate(is_array($stored['state']['profile'] ?? null) ? $stored['state']['profile'] : [])];
    $nonce = base64_encode(random_bytes(18));
    $assetVersion = '3.0.0';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
    require dirname(__DIR__) . '/templates/layout.php';
} catch (Throwable $e) {
    error_log('FitBot startup error: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>راه‌اندازی فیت‌بات</title><body style="font-family:Tahoma;background:#151618;color:#eee;padding:8vw;line-height:2"><h1>یک قدم تا راه‌اندازی فیت‌بات</h1><p>PHP 8.2 یا جدیدتر، افزونه‌های pdo_sqlite و mbstring و دسترسی نوشتن در پوشه storage لازم است. راهنمای README.fa.md را بررسی کن.</p></body></html>';
}
