<?php
declare(strict_types=1);
namespace FitBot;

/**
 * Separate admin identity: its own cookie (FITBOTADMINSESS), its own session
 * storage folder and its own CSRF token, fully isolated from user sessions.
 */
final class AdminAuth
{
    public const SESSION_LIFETIME = 7200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $path = Config::storage() . '/admin-sessions';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Cannot create private admin session storage.');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME);
        ini_set('session.cookie_httponly', '1');
        session_save_path($path);
        session_name('FITBOTADMINSESS');
        $sameSite = Config::get('SESSION_SAMESITE', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) $sameSite = 'Lax';
        if ($sameSite === 'None' && !Config::secure()) $sameSite = 'Lax';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $cookiePath = $scriptDir === '/' || $scriptDir === '.' ? '/' : rtrim($scriptDir, '/') . '/';
        session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'secure' => Config::secure(), 'httponly' => true, 'samesite' => $sameSite]);
        session_start();
        if (isset($_SESSION['admin_id'], $_SESSION['admin_last_active']) && time() - (int) $_SESSION['admin_last_active'] > self::SESSION_LIFETIME) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
        if (isset($_SESSION['admin_id'])) $_SESSION['admin_last_active'] = time();
    }

    public static function csrf(): string { return $_SESSION['admin_csrf']; }
    public static function csrfField(): string { return '<input type="hidden" name="csrf" value="' . htmlspecialchars(self::csrf(), ENT_QUOTES) . '">'; }

    /** Verifies the CSRF token of an admin form POST. */
    public static function verifyCsrf(): void
    {
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || $sent === '' || !hash_equals(self::csrf(), $sent)) {
            http_response_code(419);
            exit('نشست مدیریتی منقضی شده است. صفحه را دوباره باز کن.');
        }
    }

    public static function admin(): ?array
    {
        if (!isset($_SESSION['admin_id'])) return null;
        $stmt = Database::connection()->prepare('SELECT id, username, email, password_hash, is_super, last_login_at, created_at FROM admins WHERE id = ?');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $a = $stmt->fetch();
        if (!$a) { unset($_SESSION['admin_id']); return null; }
        return self::publicView($a);
    }

    private static function publicView(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'username' => $a['username'],
            'email' => $a['email'],
            'isSuper' => (int) $a['is_super'] === 1,
            'lastLoginAt' => $a['last_login_at'] !== null ? (int) $a['last_login_at'] : null,
            'createdAt' => (int) $a['created_at'],
            'defaultPassword' => password_verify('Admin@12345', $a['password_hash']),
        ];
    }

    /** For admin pages: redirects to the login form when nobody is signed in. */
    public static function requireAdmin(): array
    {
        $a = self::admin();
        if (!$a) {
            header('Location: login.php');
            exit;
        }
        return $a;
    }

    public static function attemptLogin(string $login, string $password): array
    {
        Database::rateLimit('admin-login:' . Http::ipKey(), 8, 300);
        $login = trim($login);
        if ($login === '' || strlen($password) > 72) throw new ApiException(401, 'نام کاربری یا رمز عبور درست نیست.', 'invalid_credentials');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, username, email, password_hash, is_super, last_login_at, created_at FROM admins WHERE username = ? OR email = ?');
        $stmt->execute([$login, strtolower($login)]);
        $row = $stmt->fetch();
        $dummy = '$2y$12$6XzGiYPWhgmIu6LMOglQnOnFWcikCSnbFXh5raBRRlzrYZaNI1IU6';
        $valid = password_verify($password, $row['password_hash'] ?? $dummy);
        if (!$row || !$valid) {
            self::log('login_failed', 'admin', null, 'نام کاربری واردشده: ' . mb_substr($login, 0, 50, 'UTF-8'));
            throw new ApiException(401, 'نام کاربری یا رمز عبور درست نیست.', 'invalid_credentials');
        }
        session_regenerate_id(true);
        $_SESSION = ['admin_id' => (int) $row['id'], 'admin_last_active' => time(), 'admin_csrf' => bin2hex(random_bytes(32))];
        $pdo->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([Http::now(), $row['id']]);
        self::log('login');
        return self::publicView($row);
    }

    public static function logout(): void
    {
        self::log('logout');
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    /** Appends to the admin audit trail; never throws into the page flow. */
    public static function log(string $action, string $entity = '', ?int $entityId = null, string $details = ''): void
    {
        try {
            $admin = self::admin();
            $name = $admin['username'] ?? 'ناشناس';
            $id = $admin['id'] ?? null;
        } catch (\Throwable) {
            $name = 'ناشناس'; $id = null;
        }
        try {
            $stmt = Database::connection()->prepare('INSERT INTO admin_activity(admin_id, admin_name, action, entity, entity_id, details, ip, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id, $name, $action, $entity, $entityId, mb_substr($details, 0, 480, 'UTF-8'), $_SERVER['REMOTE_ADDR'] ?? '', Http::now()]);
        } catch (\Throwable $e) {
            error_log('FitBot admin activity log failed: ' . $e->getMessage());
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): ?array
    {
        $f = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        return is_array($f) ? $f : null;
    }
}
