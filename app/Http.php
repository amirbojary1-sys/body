<?php
declare(strict_types=1);
namespace FitBot;

final class Http
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    public static function body(int $limit = 3_000_000): array
    {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new ApiException(415, 'درخواست باید با قالب JSON ارسال شود.', 'invalid_content_type');
        }
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($raw === false || strlen($raw) > $limit) throw new ApiException(413, 'حجم داده بیشتر از حد مجاز است.', 'body_too_large');
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'قالب داده معتبر نیست.', 'invalid_json'); }
        if (!is_array($data) || array_is_list($data)) throw new ApiException(400, 'یک شیء JSON معتبر لازم است.', 'invalid_json');
        return $data;
    }
    public static function method(string ...$allowed): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ApiException(405, 'این روش درخواست مجاز نیست.', 'method_not_allowed');
        }
        return $method;
    }
    public static function guardMutation(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $expected = rtrim(Config::get('APP_ORIGIN'), '/');
            $parts = parse_url($origin);
            $host = is_array($parts) ? strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')) : '';
            $requestHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
            if (Config::bool('TRUST_PROXY') && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $requestHost = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]));
            }
            if ($origin === 'null' || ($expected !== '' ? $origin !== $expected : $host !== $requestHost)) {
                throw new ApiException(403, 'درخواست باید از مبدأ خود برنامه ارسال شود.', 'origin_denied');
            }
        }
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($csrf === '' || !hash_equals(Auth::csrf(), $csrf)) {
            throw new ApiException(419, 'نشست یا توکن صفحه معتبر نیست. اگر در پیش‌نمایش هستی، برنامه را در تب مستقل باز کن؛ سپس صفحه را تازه کن.', 'csrf_mismatch');
        }
    }
    public static function ipKey(): string
    {
        // Never trust client-supplied X-Forwarded-For by default.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return hash('sha256', 'fitbot-rate-v1|' . $ip);
    }
    public static function text(mixed $value, int $length = 200): string
    {
        return is_string($value) ? mb_substr($value, 0, $length, 'UTF-8') : '';
    }
    public static function now(): int { return (int) round(microtime(true) * 1000); }
}
