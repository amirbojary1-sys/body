<?php
declare(strict_types=1);
use FitBot\{ApiException, AgentService, Auth, Config, Database, FitnessService, Http};

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    Auth::start();
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) throw new ApiException(404, 'مسیر پیدا نشد.', 'not_found');
    switch ($action) {
        case 'status':
            Http::method('GET');
            Http::json(['service' => 'fitbot', 'engine' => 'php', 'version' => '3.0.0', 'available' => AgentService::available(), 'model' => AgentService::available() ? AgentService::model() : null, 'user' => Auth::user(), 'csrf' => Auth::csrf()]);
        case 'register':
            Http::method('POST'); Http::guardMutation();
            Http::json(Auth::register(Http::body(12000)), 201);
        case 'login':
            Http::method('POST'); Http::guardMutation();
            Http::json(Auth::login(Http::body(12000)));
        case 'logout':
            Http::method('POST'); Http::guardMutation();
            Http::json(Auth::logout());
        case 'account':
            Http::method('DELETE'); Http::guardMutation();
            $user = Auth::requireUser();
            Database::rateLimit('delete-account:' . $user['id'], 5, 60);
            $body = Http::body(12000);
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $pdo = Database::connection();
            $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $q->execute([$user['id']]);
            if (strlen($password) > 72 || !password_verify($password, (string) $q->fetchColumn())) throw new ApiException(401, 'رمز عبور درست نیست؛ حساب حذف نشد.', 'invalid_credentials');
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
            Http::json(['deleted' => true, ...Auth::logout()]);
        case 'state':
            $method = Http::method('GET', 'PUT');
            $user = Auth::requireUser();
            if ($method === 'GET') Http::json(Database::userState($user['id']));
            Http::guardMutation();
            $body = Http::body();
            if (!is_array($body['state'] ?? null) || !is_int($body['revision'] ?? null) || $body['revision'] < 0) throw new ApiException(422, 'داده و شماره نسخه معتبر لازم است.', 'invalid_state');
            Http::json(Database::saveState($user['id'], $body['state'], $body['revision']));
        case 'calculate':
            Http::method('POST'); Http::guardMutation();
            Database::rateLimit('calculate:' . Http::ipKey(), 120, 60);
            $body = Http::body(12000);
            if (!is_array($body['profile'] ?? null)) throw new ApiException(422, 'مشخصات بدن معتبر لازم است.', 'invalid_profile');
            Http::json(FitnessService::calculate($body['profile']));
        case 'agent':
            Http::method('POST'); Http::guardMutation();
            $user = Auth::requireUser();
            $body = Http::body(240000);
            if (($body['consent'] ?? false) !== true) throw new ApiException(422, 'رضایت ارسال مشخصات و پیام به سرویس مدل لازم است.', 'ai_consent_required');
            Http::json(AgentService::respond($body, $user['id']));
        default:
            throw new ApiException(404, 'مسیر پیدا نشد.', 'not_found');
    }
} catch (ApiException $e) {
    Http::json(['error' => $e->getMessage(), 'code' => $e->errorKey, ...$e->details], $e->status);
} catch (Throwable $e) {
    // Never log request bodies, passwords, provider keys or fitness snapshots.
    error_log('FitBot internal error: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::json(['error' => 'عملیات سرور کامل نشد. افزونه‌ها، دسترسی storage و گزارش خطای PHP را بررسی کن.', 'code' => 'internal_error'], 500);
}
