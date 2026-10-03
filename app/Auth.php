<?php
declare(strict_types=1);
namespace FitBot;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $path = Config::storage() . '/sessions';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Cannot create private session storage.');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '7200');
        ini_set('session.cookie_httponly', '1');
        session_save_path($path);
        session_name('FITBOTSESSID');
        $sameSite = Config::get('SESSION_SAMESITE', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) $sameSite = 'Lax';
        if ($sameSite === 'None' && !Config::secure()) $sameSite = 'Lax';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $cookiePath = $scriptDir === '/' || $scriptDir === '.' ? '/' : rtrim($scriptDir, '/') . '/';
        session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'secure' => Config::secure(), 'httponly' => true, 'samesite' => $sameSite]);
        session_start();
        if (isset($_SESSION['user_id'], $_SESSION['last_active']) && time() - (int) $_SESSION['last_active'] > 7200) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (isset($_SESSION['user_id'])) $_SESSION['last_active'] = time();
        self::partitionCookie();
    }
    private static function partitionCookie(): void
    {
        // Optional CHIPS cookie for HTTPS embeds. Standard deployments retain SameSite=Lax.
        if (!Config::bool('SESSION_PARTITIONED') || !Config::secure() || Config::get('SESSION_SAMESITE', 'Lax') !== 'None') return;
        $cookies = array_values(array_filter(headers_list(), static fn(string $h): bool => str_starts_with(strtolower($h), 'set-cookie:')));
        if (!$cookies) return;
        header_remove('Set-Cookie');
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'Set-Cookie: ' . session_name() . '=') && !str_contains(strtolower($cookie), 'partitioned')) $cookie .= '; Partitioned';
            header($cookie, false);
        }
    }
    public static function csrf(): string { return $_SESSION['csrf']; }
    public static function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) return null;
        $stmt = Database::connection()->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u) { unset($_SESSION['user_id']); return null; }
        return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'createdAt' => (int) $u['created_at']];
    }
    public static function requireUser(bool $checkAccount = true): array
    {
        $u = self::user();
        if (!$u) throw new ApiException(401, 'برای این قابلیت وارد حساب شو. داده‌های مهمان همچنان روی دستگاه خودت هستند.', 'auth_required');
        if ($checkAccount) {
            $expected = $_SERVER['HTTP_X_FITBOT_ACCOUNT'] ?? '';
            if ($expected !== (string) $u['id']) throw new ApiException(409, 'حساب فعال در این مرورگر تغییر کرده است. پیش از ادامه، صفحه را تازه کن.', 'account_changed');
        }
        return $u;
    }
    private static function identity(array $u): array
    {
        session_regenerate_id(true);
        self::partitionCookie();
        $_SESSION = ['user_id' => (int) $u['id'], 'last_active' => time(), 'csrf' => bin2hex(random_bytes(32))];
        $public = self::user();
        return ['user' => $public, 'csrf' => self::csrf(), ...Database::userState((int) $u['id'])];
    }
    private static function secureDeployment(): void
    {
        if (Config::get('APP_ENV', 'local') === 'production' && !Config::secure()) {
            throw new ApiException(503, 'برای استفاده از حساب در محیط عمومی، HTTPS و تنظیمات پروکسی را فعال کن.', 'https_required');
        }
    }
    public static function register(array $body): array
    {
        self::secureDeployment();
        Database::rateLimit('register:' . Http::ipKey(), 6, 3600);
        $name = trim(Http::text($body['name'] ?? null, 50));
        $email = strtolower(trim(Http::text($body['email'] ?? null, 254)));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (mb_strlen($name) < 2) throw new ApiException(422, 'نام نمایشی باید دست‌کم ۲ حرف داشته باشد.', 'invalid_name');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException(422, 'نشانی ایمیل معتبر وارد کن.', 'invalid_email');
        if (mb_strlen($password) < 10 || strlen($password) > 72) throw new ApiException(422, 'رمز باید حداقل ۱۰ نویسه و حداکثر ۷۲ بایت باشد؛ برای حروف فارسی طول کمتری انتخاب کن.', 'invalid_password');
        if (($body['consent'] ?? false) !== true) throw new ApiException(422, 'آگاهی از ذخیره داده‌ها روی سرور را تأیید کن.', 'consent_required');
        $pdo = Database::connection();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO users(email, name, password_hash, created_at) VALUES(?, ?, ?, ?)');
            $stmt->execute([$email, $name, $hash, Http::now()]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_states(user_id, updated_at) VALUES(?, ?)')->execute([$id, Http::now()]);
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string) $e->getCode() === '23000') throw new ApiException(422, 'این ایمیل برای ساخت حساب قابل استفاده نیست. اگر حساب داری، وارد شو.', 'email_unavailable');
            throw $e;
        }
        return self::identity(['id' => $id]);
    }
    public static function login(array $body): array
    {
        self::secureDeployment();
        Database::rateLimit('login:' . Http::ipKey(), 10, 60);
        Database::rateLimit('login-hour:' . Http::ipKey(), 40, 3600);
        $email = strtolower(trim(Http::text($body['email'] ?? null, 254)));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (strlen($password) > 72) throw new ApiException(401, 'ایمیل یا رمز عبور درست نیست.', 'invalid_credentials');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        // A dummy hash avoids a fast path that reveals whether an email exists.
        $dummy = '$2y$12$6XzGiYPWhgmIu6LMOglQnOnFWcikCSnbFXh5raBRRlzrYZaNI1IU6';
        $valid = password_verify($password, $row['password_hash'] ?? $dummy);
        if (!$row || !$valid) throw new ApiException(401, 'ایمیل یا رمز عبور درست نیست.', 'invalid_credentials');
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        return self::identity($row);
    }
    public static function logout(): array
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::partitionCookie();
        return ['user' => null, 'csrf' => self::csrf()];
    }
}
