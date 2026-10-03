<?php
declare(strict_types=1);
namespace FitBot;

final class Config
{
    private static array $values = [];
    private static string $root;

    public static function boot(string $root): void
    {
        self::$root = $root;
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) continue;
                $value = $m[2];
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$m[1]] = $value;
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        return $env !== false ? $env : (self::$values[$key] ?? $default);
    }
    public static function bool(string $key, bool $default = false): bool
    {
        return in_array(strtolower(self::get($key, $default ? 'true' : 'false')), ['1', 'true', 'yes', 'on'], true);
    }
    public static function root(string $suffix = ''): string { return self::$root . ($suffix ? '/' . ltrim($suffix, '/') : ''); }
    public static function storage(): string { return self::get('STORAGE_PATH', self::root('storage')); }
    public static function secure(): bool
    {
        if (self::bool('APP_HTTPS')) return true;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        return self::bool('TRUST_PROXY') && strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]) === 'https';
    }
}
