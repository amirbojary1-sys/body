<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

final class Database
{
    private static ?PDO $pdo = null;
    public static function close(): void { self::$pdo = null; }
    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
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
            );');
            self::$pdo = $pdo;
        } finally { umask($old); }
        return self::$pdo;
    }
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
        $stmt = $pdo->prepare('INSERT INTO rate_limits(bucket, window_start, hits) VALUES(?, ?, 1)
            ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN window_start = excluded.window_start THEN hits + 1 ELSE 1 END, window_start = excluded.window_start');
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
