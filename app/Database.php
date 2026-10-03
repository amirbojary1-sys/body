<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

/**
 * Database layer. Two drivers, one API:
 *   - mysql : set DB_DRIVER=mysql (+ DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS) in .env
 *   - sqlite: default, zero-config, storage/fitbot.sqlite
 * Import database/fitbot_mysql.sql into MySQL, or let the schema auto-create
 * (the MySQL account then needs CREATE privileges).
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = '';

    public static function close(): void { self::$pdo = null; self::$driver = ''; }

    public static function driver(): string { self::connection(); return self::$driver; }
    public static function isMysql(): bool { return self::driver() === 'mysql'; }

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $driver = strtolower(trim(Config::get('DB_DRIVER', 'sqlite')));
        self::$pdo = $driver === 'mysql' ? self::mysql() : self::sqlite();
        self::$driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
        return self::$pdo;
    }

    // ---------------------------------------------------------------- MySQL

    private static function mysql(): PDO
    {
        if (!extension_loaded('pdo_mysql')) throw new \RuntimeException('افزونه pdo_mysql روی سرور فعال نیست. در php.ini فعالش کن و وب‌سرور را ری‌استارت کن.');
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME', 'fitbot');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');
        $socket = Config::get('DB_SOCKET', '');
        $dsn = $socket !== ''
            ? 'mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=utf8mb4'
            : 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('اتصال به MySQL برقرار نشد («' . $e->getMessage() . '»). مقادیر DB_HOST/DB_NAME/DB_USER/DB_PASS در فایل .env و روشن‌بودن سرویس MySQL را بررسی کن و مطمئن شو فایل database/fitbot_mysql.sql ایمپورت شده است.');
        }
        try {
            self::mysqlSchema($pdo);
            self::seedDefaultAdmin($pdo);
        } catch (\PDOException $e) {
            // Import already done by a limited-privilege account? Verify core tables exist.
            try {
                $pdo->query('SELECT 1 FROM users LIMIT 1');
                $pdo->query('SELECT 1 FROM admins LIMIT 1');
            } catch (\PDOException) {
                throw new \RuntimeException('جدول‌های دیتابیس در MySQL پیدا نشدند و ساختن خودکار آن‌ها ممکن نبود («' . $e->getMessage() . '»). فایل database/fitbot_mysql.sql را با phpMyAdmin یا دستور mysql ایمپورت کن یا به کاربر MySQL مجوز CREATE بده.');
            }
        }
        return $pdo;
    }

    /** Guarantees a first super admin exists (admin / Admin@12345) on fresh installs. */
    private static function seedDefaultAdmin(PDO $pdo): void
    {
        $exists = $pdo->query('SELECT 1 FROM admins LIMIT 1')->fetchColumn();
        if ($exists !== false) return;
        $pdo->prepare('INSERT INTO admins(username, email, password_hash, is_super, created_at) VALUES(?, ?, ?, 1, ?)')
            ->execute(['admin', 'admin@fitbot.local', password_hash('Admin@12345', PASSWORD_BCRYPT), Http::now()]);
    }

    private static function mysqlSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(254) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at BIGINT UNSIGNED NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            KEY idx_users_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS user_states (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            data LONGTEXT NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL,
            CONSTRAINT fk_user_states_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket VARCHAR(64) PRIMARY KEY,
            window_start BIGINT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_super TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at BIGINT UNSIGNED NULL,
            created_at BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS admin_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NULL,
            admin_name VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            entity VARCHAR(30) NOT NULL DEFAULT \'\',
            entity_id INT UNSIGNED NULL,
            details VARCHAR(500) NOT NULL DEFAULT \'\',
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            created_at BIGINT UNSIGNED NOT NULL,
            KEY idx_activity_created (created_at),
            CONSTRAINT fk_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    // --------------------------------------------------------------- SQLite

    private static function sqlite(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) throw new \RuntimeException('The pdo_sqlite extension is required.');
        $dir = Config::storage();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Storage directory is not writable.');
        $old = umask(0077);
        try {
            $pdo = new PDO('sqlite:' . $dir . '/fitbot.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
            $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login_at INTEGER,
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS user_states (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                data TEXT NOT NULL DEFAULT \'null\',
                revision INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_super INTEGER NOT NULL DEFAULT 0,
                last_login_at INTEGER,
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS admin_activity (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
                admin_name TEXT NOT NULL,
                action TEXT NOT NULL,
                entity TEXT NOT NULL DEFAULT \'\',
                entity_id INTEGER,
                details TEXT NOT NULL DEFAULT \'\',
                ip TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            );');
            self::sqliteMigrate($pdo);
            self::seedDefaultAdmin($pdo);
            self::$pdo = $pdo;
        } finally { umask($old); }
        return $pdo;
    }

    /** Adds columns introduced after the first release to existing SQLite files. */
    private static function sqliteMigrate(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $column) $columns[$column['name']] = true;
        if (!isset($columns['is_active'])) $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
        if (!isset($columns['last_login_at'])) $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at INTEGER');
    }

    // ------------------------------------------------------------ Operations

    public static function userState(int $userId): array
    {
        $stmt = self::connection()->prepare('SELECT data, revision, updated_at FROM user_states WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiException(404, 'اطلاعات حساب پیدا نشد.', 'state_not_found');
        return ['state' => json_decode($row['data'], true, 64, JSON_THROW_ON_ERROR), 'revision' => (int) $row['revision'], 'updatedAt' => (int) $row['updated_at']];
    }

    public static function saveState(int $userId, array $state, int $revision): array
    {
        $valid = StateValidator::clean($state);
        $json = json_encode($valid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 2_500_000) throw new ApiException(413, 'حجم اطلاعات حساب بیش از حد مجاز است.', 'state_too_large');
        $now = Http::now();
        $stmt = self::connection()->prepare('UPDATE user_states SET data = :data, revision = revision + 1, updated_at = :at WHERE user_id = :uid AND revision = :rev');
        $stmt->execute(['data' => $json, 'at' => $now, 'uid' => $userId, 'rev' => $revision]);
        if ($stmt->rowCount() !== 1) {
            $current = self::userState($userId);
            throw new ApiException(409, 'نسخه جدیدتری در حساب ذخیره شده است. پیش از جایگزینی، نسخه موردنظر را انتخاب کن.', 'state_conflict', ['revision' => $current['revision'], 'updatedAt' => $current['updatedAt']]);
        }
        return ['revision' => $revision + 1, 'updatedAt' => $now, 'saved' => true];
    }

    public static function rateLimit(string $bucket, int $maximum, int $seconds): void
    {
        $pdo = self::connection();
        $start = (int) (floor(time() / $seconds) * $seconds);
        $key = hash('sha256', $bucket . '|' . $seconds);
        if (self::isMysql()) {
            $stmt = $pdo->prepare('INSERT INTO rate_limits(bucket, window_start, hits) VALUES(?, ?, 1)
                ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)');
        } else {
            $stmt = $pdo->prepare('INSERT INTO rate_limits(bucket, window_start, hits) VALUES(?, ?, 1)
                ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN window_start = excluded.window_start THEN hits + 1 ELSE 1 END, window_start = excluded.window_start');
        }
        $stmt->execute([$key, $start]);
        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$key]);
        if ((int) $stmt->fetchColumn() > $maximum) {
            header('Retry-After: ' . max(1, $start + $seconds - time()));
            throw new ApiException(429, 'تعداد درخواست‌ها به محدودیت رسیده؛ کمی بعد دوباره تلاش کن.', 'rate_limited');
        }
        if (random_int(1, 50) === 1) $pdo->exec('DELETE FROM rate_limits WHERE window_start < ' . (time() - 172800));
    }
}
