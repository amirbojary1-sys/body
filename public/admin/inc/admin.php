<?php
declare(strict_types=1);
/**
 * Shared bootstrap for every admin page: starts the isolated admin session,
 * loads the app autoloader and provides layout/format helpers.
 * Pages call AdminAuth::requireAdmin() themselves; login.php does not.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
\FitBot\AdminAuth::start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');

/** HTML-escape for output. */
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

/** Global CSRF guard: every admin POST must carry the session token. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') \FitBot\AdminAuth::verifyCsrf();

/** Latin → Persian digits. */
function fa_num(string|int|float|null $text): string
{
    return str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], (string) $text);
}

/** Gregorian → Jalali (algorithmic, no extensions needed). */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + $days % 31; }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + ($days - 186) % 30; }
    return [$jy, $jm, $jd];
}

const JALALI_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

/** Milliseconds (UTC) → '۱۱ مهر ۱۴۰۵، ۱۵:۳۰' in Asia/Tehran. */
function jdate(?int $ms): string
{
    if ($ms === null || $ms <= 0) return '—';
    $dt = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    return fa_num($jd) . ' ' . JALALI_MONTHS[$jm - 1] . ' ' . fa_num($jy) . '، ' . fa_num($dt->format('H:i'));
}

/** Relative, short Persian time. */
function jago(?int $ms): string
{
    if ($ms === null || $ms <= 0) return 'هرگز';
    $diff = ((int) round(microtime(true) * 1000)) - $ms;
    if ($diff < 60_000) return 'همین حالا';
    if ($diff < 3_600_000) return fa_num((string) intdiv($diff, 60_000)) . ' دقیقه پیش';
    if ($diff < 86_400_000) return fa_num((string) intdiv($diff, 3_600_000)) . ' ساعت پیش';
    if ($diff < 2_592_000_000) return fa_num((string) intdiv($diff, 86_400_000)) . ' روز پیش';
    return jdate($ms);
}

function admin_url(string $page): string { return $page . '.php'; }

/** Sidebar + topbar chrome. $active ∈ dashboard|users|admins|activity|profile */
function admin_header(array $admin, string $title, string $active): void
{
    $nav = [
        'dashboard' => ['index.php', 'داشبورد', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
        'users' => ['users.php', 'کاربران', 'M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3Zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z'],
        'admins' => ['admins.php', 'مدیران', 'm12 2 8 4v7c0 5-8 9-8 9S4 18 4 13V6l8-4Zm-1.2 12.6 6-6-1.4-1.4-4.6 4.6-2.2-2.2-1.4 1.4 3.6 3.6Z'],
        'activity' => ['activity.php', 'گزارش فعالیت', 'M3 3v17h18M7 14l4-5 4 3 6-8'],
        'profile' => ['profile.php', 'پروفایل من', 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 3a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm0 14.2a7.2 7.2 0 0 1-6-3.2c.03-2 4-3.1 6-3.1s5.97 1.1 6 3.1a7.2 7.2 0 0 1-6 3.2Z'],
    ];
    $driver = \FitBot\Database::isMysql() ? 'MySQL' : 'SQLite';
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' — پنل مدیریت فیت‌بات</title>'
        . '<link rel="stylesheet" href="assets/admin.css">'
        . '</head><body>';
    $flash = \FitBot\AdminAuth::takeFlash();
    if ($flash) echo '<div class="flash-wrap"><div class="flash flash-' . e($flash['type']) . '">' . e($flash['message']) . '</div></div>';
    echo '<aside class="sidebar"><div class="brand"><span class="brand-mark">⚡</span><div><strong>فیت‌بات</strong><small>پنل مدیریت</small></div></div>';
    echo '<nav>';
    foreach ($nav as $key => [$href, $label, $icon]) {
        echo '<a href="' . e($href) . '" class="' . ($active === $key ? 'active' : '') . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $icon . '"/></svg><span>' . e($label) . '</span></a>';
    }
    echo '</nav>';
    echo '<div class="sidebar-foot">'
        . '<a class="btn btn-ghost btn-block" href="../index.php" target="_blank" rel="noopener">↗ مشاهده سایت</a>'
        . '<form method="post" action="logout.php" class="mt">' . \FitBot\AdminAuth::csrfField() . '<button class="btn btn-danger-ghost btn-block" type="submit">خروج از پنل</button></form>'
        . '</div></aside>';
    echo '<div class="main"><header class="topbar"><h1>' . e($title) . '</h1>'
        . '<div class="topbar-user"><span class="db-chip" title="درایور فعال دیتابیس">' . e($driver) . '</span>'
        . '<span class="avatar">' . e(mb_strtoupper(mb_substr($admin['username'], 0, 1, 'UTF-8'))) . '</span>'
        . '<div class="topbar-name"><strong>' . e($admin['username']) . '</strong><small>' . ($admin['isSuper'] ? 'مدیر کل' : 'مدیر') . '</small></div>'
        . '</div></header>';
    if ($admin['defaultPassword']) {
        echo '<div class="alert alert-warning">⚠ هنوز با رمز پیش‌فرض <code>Admin@12345</code> کار می‌کنی. همین حالا از <a href="profile.php">پروفایل من</a> رمز را عوض کن.</div>';
    }
    echo '<main class="content">';
}

function admin_footer(): void
{
    echo '</main><footer class="foot">پنل مدیریت فیت‌بات · نسخه ۳٫۱ · ' . fa_num(jalali_today()) . '</footer></div>';
    echo '<script>document.querySelectorAll("form[data-confirm]").forEach(f=>f.addEventListener("submit",e=>{if(!confirm(f.dataset.confirm||"مطمئنی؟"))e.preventDefault();}));</script>';
    echo '</body></html>';
}

function jalali_today(): string
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')));
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
    return $jd . ' ' . JALALI_MONTHS[$jm - 1] . ' ' . $jy;
}

/** [limit, offset, totalPages] for ?page=N */
function paginate_params(int $total, int $perPage, int &$page): array
{
    $page = max(1, filter_var($page, FILTER_VALIDATE_INT) ?: 1);
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    return [$perPage, ($page - 1) * $perPage, $pages];
}

function pagination_links(int $page, int $pages, string $extraQuery = ''): string
{
    if ($pages <= 1) return '';
    $q = static function (int $p) use ($extraQuery): string { return '?' . ($extraQuery !== '' ? $extraQuery . '&' : '') . 'page=' . $p; };
    $html = '<nav class="pagination">';
    if ($page > 1) $html .= '<a href="' . e($q($page - 1)) . '">قبلی</a>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $html .= '<a href="' . e($q($i)) . '" class="' . ($i === $page ? 'active' : '') . '">' . fa_num($i) . '</a>';
    }
    if ($page < $pages) $html .= '<a href="' . e($q($page + 1)) . '">بعدی</a>';
    return $html . '</nav>';
}

/** Redirect helper (same admin folder). */
function admin_redirect(string $page): never
{
    header('Location: ' . $page);
    exit;
}
