<?php
declare(strict_types=1);
/**
 * Member portal (proposal §6, §7, §8): membership status, wallet, tokens,
 * service reservations, store & cafe ordering — one page, four tabs.
 */
use FitBot\Auth;
use FitBot\Database;
use FitBot\Ledger;
use FitBot\Http;
use FitBot\ApiException;

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    Auth::start();
} catch (Throwable $e) {
    error_log('FitBot portal error: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>راه‌اندازی فیت‌بات</title><body style="font-family:Tahoma;background:#151618;color:#eee;padding:8vw;line-height:2"><h1>یک قدم تا راه‌اندازی فیت‌بات</h1><p>PHP 8.2+، افزونه دیتابیس و mbstring لازم است. README.fa.md را ببین.</p></body></html>');
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');

$user = Auth::user();
if (!$user) { header('Location: login.php'); exit; }
$uid = (int) $user['id'];

/** Latin → Persian digits. */
function fa_num(string|int|float|null $t): string { return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string) $t); }
function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053); $days %= 12053;
    $jy += 4 * intdiv($days, 1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + $days % 31; } else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + ($days - 186) % 30; }
    return [$jy, $jm, $jd];
}
const JM = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
function jdate(?int $ms): string {
    if ($ms === null || $ms <= 0) return '—';
    $dt = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    return fa_num($jd) . ' ' . JM[$jm - 1] . ' ' . fa_num($jy) . '، ' . fa_num($dt->format('H:i'));
}
function jday(?int $ms): string {
    if ($ms === null || $ms <= 0) return '—';
    $dt = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    return fa_num($jd) . ' ' . JM[$jm - 1];
}
function money(int|float $n): string { return fa_num(number_format((float) $n)); }

$pdo = Database::connection();
$now = Http::now();
$day = 86_400_000;
$notice = ''; $noticeType = 'ok';
$tab = in_array($_GET['tab'] ?? '', ['services', 'store', 'orders'], true) ? $_GET['tab'] : 'home';

// ---------- actions ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(Auth::csrf(), $sent)) {
        $notice = 'نشست منقضی شده؛ دوباره تلاش کن.'; $noticeType = 'err';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'reserve') {
                $serviceId = (int) ($_POST['service_id'] ?? 0);
                $date = (string) ($_POST['date'] ?? '');
                $time = (string) ($_POST['time'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new ApiException(422, 'تاریخ و ساعت نوبت را درست وارد کن.', 'invalid_time');
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", new DateTimeZone('Asia/Tehran'));
                if ($dt === false) throw new ApiException(422, 'تاریخ و ساعت نوبت معتبر نیست.', 'invalid_time');
                Ledger::reserve($uid, $serviceId, $dt->getTimestamp() * 1000, (string) ($_POST['note'] ?? ''));
                $notice = 'درخواست رزرو ثبت شد؛ پس از تأیید پذیرش، در همین صفحه می‌بینی‌اش.';
                $tab = 'orders';
            } elseif ($action === 'order') {
                $r = Ledger::placeOrder($uid, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 1), ($_POST['pay'] ?? '') === 'wallet' ? 'wallet' : 'cash');
                $notice = $r['status'] === 'paid'
                    ? 'سفارش ثبت و مبلغ ' . money($r['total']) . ' تومان از کیف پول پرداخت شد.'
                    : 'سفارش ثبت شد؛ هنگام تحویل در محل نقدی پرداخت کن.';
                $tab = 'orders';
            }
        } catch (ApiException $ex) {
            $notice = $ex->getMessage(); $noticeType = 'err';
        } catch (Throwable $ex) {
            error_log('FitBot portal action error: ' . get_class($ex) . ' ' . basename($ex->getFile()) . ':' . $ex->getLine());
            $notice = 'عملیات انجام نشد؛ کمی بعد دوباره تلاش کن.'; $noticeType = 'err';
        }
    }
}

// ---------- data ----------
$wallet = Ledger::walletBalance($uid);
$tokens = Ledger::tokenBalance($uid);
$stmt = $pdo->prepare('SELECT s.*, p.name AS plan_name, p.kind FROM subscriptions s JOIN membership_plans p ON p.id = s.plan_id
    WHERE s.user_id = ? AND s.status = \'active\' AND (s.expires_at IS NULL OR s.expires_at >= ?) ORDER BY s.created_at DESC LIMIT 1');
$stmt->execute([$uid, $now]);
$sub = $stmt->fetch();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM visit_logs WHERE user_id = ?');
$stmt->execute([$uid]);
$visits = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT * FROM visit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
$stmt->execute([$uid]);
$visitRows = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT r.*, s.name AS service_name FROM reservations r JOIN services s ON s.id = r.service_id
    WHERE r.user_id = ? ORDER BY r.reserved_at DESC LIMIT 12');
$stmt->execute([$uid]);
$myRes = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 12');
$stmt->execute([$uid]);
$myOrders = $stmt->fetchAll();
$orderItems = [];
foreach ($myOrders as $o) {
    $q = $pdo->prepare('SELECT oi.qty, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $q->execute([(int) $o['id']]);
    $orderItems[(int) $o['id']] = $q->fetchAll();
}
$services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
$products = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY department, name')->fetchAll();
$storeProducts = array_values(array_filter($products, fn($p) => $p['department'] === 'store'));
$cafeProducts = array_values(array_filter($products, fn($p) => $p['department'] === 'cafe'));

$serviceCats = ['gym' => 'باشگاه', 'coach' => 'مربی خصوصی', 'class' => 'کلاس گروهی', 'massage' => 'ماساژ', 'salon' => 'آرایشگاه', 'cafe' => 'کافه', 'parking' => 'پارکینگ', 'locker' => 'کمد', 'physio' => 'فیزیوتراپی', 'med' => 'پزشکی ورزشی', 'pool' => 'استخر', 'slimming' => 'دستگاه لاغری', 'other' => 'سایر'];
$resLabels = ['pending' => 'در انتظار تأیید', 'confirmed' => 'تأییدشده', 'done' => 'انجام‌شده', 'canceled' => 'لغوشده'];
$orderLabels = ['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت‌شده', 'preparing' => 'در حال آماده‌سازی', 'done' => 'تحویل‌شده', 'canceled' => 'لغوشده'];

$daysLeft = 0; $progress = 0;
if ($sub) {
    $daysLeft = $sub['expires_at'] !== null ? max(0, (int) ceil(((int) $sub['expires_at'] - $now) / $day)) : 0;
    $total = max(1, (int) $sub['expires_at'] - (int) $sub['starts_at']);
    $progress = min(100, max(0, (int) round(($now - (int) $sub['starts_at']) / $total * 100)));
}
$csrf = Auth::csrf();
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پنل باشگاه — <?= e($user['name']) ?></title>
<link rel="stylesheet" href="assets/css/portal.css">
</head>
<body>
<header class="portal-top">
  <a class="portal-brand" href="index.php"><span class="mark">⚡</span><span>فیت‌بات<span style="color:var(--muted);font-weight:400;font-size:11px;display:block">پنل باشگاه</span></span></a>
  <nav class="portal-nav">
    <a href="panel.php" class="<?= $tab === 'home' ? 'active' : '' ?>">خانه</a>
    <a href="panel.php?tab=services" class="<?= $tab === 'services' ? 'active' : '' ?>">رزرو خدمات</a>
    <a href="panel.php?tab=store" class="<?= $tab === 'store' ? 'active' : '' ?>">فروشگاه و کافه</a>
    <a href="panel.php?tab=orders" class="<?= $tab === 'orders' ? 'active' : '' ?>">سفارش‌ها و نوبت‌ها</a>
  </nav>
  <div class="portal-user">
    <div style="text-align:left"><strong style="font-size:13px"><?= e($user['name']) ?></strong><small>اعتبار: <?= money($wallet) ?> تومان</small></div>
    <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1, 'UTF-8'))) ?></span>
    <a class="btn small btn-ghost" href="index.php">بازگشت به اپ ↩</a>
  </div>
</header>

<?php if ($notice !== ''): ?><div class="wrap" style="padding-bottom:0"><div class="notice <?= $noticeType ?>"><?= e($notice) ?></div></div><?php endif; ?>

<?php if ($tab === 'home'): ?>
<div class="hero">
  <img src="assets/images/portal-hero.jpg" alt="باشگاه فیت‌بات">
  <div class="hero-in">
    <h1>سلام <?= e($user['name']) ?> 👋</h1>
    <p>امروز روز خوبی برای تمرین است. وضعیت باشگاهت را اینجا دنبال کن.</p>
    <div class="chips">
      <span class="chip"><?= $sub ? 'عضویت: <b>' . e($sub['plan_name']) . '</b>' : 'عضویت: <b>فعالی نداری</b>' ?></span>
      <?php if ($sub): ?><span class="chip green"><?= $sub['expires_at'] !== null ? '<b>' . fa_num((string) $daysLeft) . '</b> روز اعتبار' : 'بدون انقضا' ?></span><?php endif; ?>
      <span class="chip">کیف پول: <b><?= money($wallet) ?></b> تومان</span>
      <span class="chip">توکن: <b><?= fa_num((string) $tokens) ?></b></span>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="grid cols-4">
    <div class="stat accent"><div class="label">کیف پول</div><div class="value num"><?= money($wallet) ?></div><div class="hint">تومان — شارژ در پذیرش</div></div>
    <div class="stat green"><div class="label">توکن جایزه</div><div class="value num"><?= fa_num((string) $tokens) ?></div><div class="hint">برای بازی‌ها و خدمات</div></div>
    <div class="stat"><div class="label">ورود به باشگاه</div><div class="value num"><?= fa_num((string) $visits) ?></div><div class="hint">بار از اول</div></div>
    <div class="stat amber"><div class="label">نوبت‌های پیش‌رو</div><div class="value num"><?= fa_num((string) count(array_filter($myRes, fn($r) => in_array($r['status'], ['pending', 'confirmed'], true) && (int) $r['reserved_at'] >= $now))) ?></div><div class="hint">رزروهای فعال</div></div>
  </div>

  <div class="grid cols-2" style="margin-top:14px;align-items:start">
    <div class="card">
      <div class="card-head"><h2>عضویت من</h2><a class="sub" href="panel.php?tab=services">رزرو خدمت ←</a></div>
      <div class="card-body">
        <?php if (!$sub): ?>
          <div class="empty"><span class="glyph">🎫</span>عضویت فعالی نداری. برای تمدید یا خرید پلن به پذیرش باشگاه مراجعه کن یا با ما تماس بگیر.</div>
        <?php else: ?>
          <div class="stat accent" style="margin-bottom:12px"><div class="label"><?= e($sub['plan_name']) ?></div><div class="value num" style="font-size:19px"><?= $sub['expires_at'] !== null ? fa_num((string) $daysLeft) . ' روز مانده' : 'بدون انقضا' ?></div><div class="hint">پایان: <?= jdate((int) ($sub['expires_at'] ?? null)) ?></div></div>
          <div class="progress"><span style="width:<?= $progress ?>%"></span></div>
          <p class="meta" style="color:var(--muted);font-size:12px;margin-top:8px"><?= $sub['sessions_total'] > 0 ? 'جلسات مصرف‌شده: ' . fa_num((string) $sub['sessions_used']) . ' از ' . fa_num((string) $sub['sessions_total']) : 'عضویت نامحدود' ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>آخرین ترددها</h2><span class="sub">گوشی‌زنی ورود/خروج</span></div>
      <div class="card-body tight table-wrap">
        <table>
          <thead><tr><th>زمان</th><th>نوع</th><th>روش</th></tr></thead>
          <tbody>
          <?php if (!$visitRows): ?><tr><td colspan="3"><div class="empty"><span class="glyph">🚪</span>هنوز ترددی ثبت نشده.</div></td></tr><?php endif; ?>
          <?php foreach ($visitRows as $v): ?>
          <tr><td class="num"><?= jdate((int) $v['created_at']) ?></td><td><?= $v['direction'] === 'in' ? '<span class="badge green">ورود</span>' : '<span class="badge gray">خروج</span>' ?></td><td><?= e($v['method']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'services'): ?>
<div class="wrap">
  <div class="banner">
    <img src="assets/images/services-banner.jpg" alt="خدمات باشگاه">
    <div class="in"><h2>رزرو خدمات باشگاه</h2><p>مربی خصوصی، ماساژ، کلاس گروهی، فیزیوتراپی، استخر و… نوبتت را همین‌جا بگیر.</p></div>
  </div>
  <div class="product-grid">
    <?php foreach ($services as $s): ?>
    <div class="product">
      <h3><?= e($s['name']) ?></h3>
      <span class="badge gray"><?= e($serviceCats[$s['category']] ?? $s['category']) ?></span>
      <div class="price"><?= money($s['price']) ?> تومان</div>
      <div class="meta"><?= fa_num((string) $s['duration_minutes']) ?> دقیقه · ظرفیت <?= fa_num((string) $s['capacity']) ?> نفر</div>
      <form method="post" action="panel.php?tab=orders">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="reserve">
        <input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>">
        <input type="date" name="date" required min="<?= date('Y-m-d', (int) ($now / 1000)) ?>" max="<?= date('Y-m-d', (int) ($now / 1000) + 60 * 86400) ?>" title="تاریخ">
        <input type="time" name="time" required value="18:00" title="ساعت">
        <button class="btn btn-primary small" type="submit">رزرو</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$services): ?><div class="empty"><span class="glyph">🧖</span>فعلاً خدمتی برای رزرو تعریف نشده است.</div><?php endif; ?>
  </div>
  <p style="color:var(--muted);font-size:12px;margin-top:14px">رزرو شما ابتدا «در انتظار تأیید» ثبت می‌شود و پس از تأیید پذیرش قطعی می‌شود. پرداخت هنگام ارائه خدمت در پذیرش یا از کیف پول انجام می‌شود.</p>
</div>

<?php elseif ($tab === 'store'): ?>
<div class="wrap">
  <div class="banner">
    <img src="assets/images/store-banner.jpg" alt="فروشگاه باشگاه">
    <div class="in"><h2>فروشگاه باشگاه</h2><p>مکمل‌ها و تجهیزات تمرین — سفارش بده، در تحویل بار خودت بگیر.</p></div>
  </div>
  <div class="product-grid" style="margin-bottom:22px">
    <?php foreach ($storeProducts as $p): ?>
    <div class="product">
      <h3><?= e($p['name']) ?></h3>
      <span class="badge gray"><?= e($p['category']) ?></span>
      <div class="price"><?= money($p['price']) ?> تومان</div>
      <div class="meta">موجودی: <?= fa_num((string) $p['stock']) ?></div>
      <form method="post" action="panel.php?tab=orders">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="order">
        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
        <input type="number" name="qty" min="1" max="20" value="1" required>
        <select name="pay"><option value="cash">نقدی</option><option value="wallet">کیف پول</option></select>
        <button class="btn btn-primary small" type="submit">سفارش</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$storeProducts): ?><div class="empty"><span class="glyph">🛒</span>محصولی در فروشگاه نیست.</div><?php endif; ?>
  </div>

  <div class="banner">
    <img src="assets/images/cafe-banner.jpg" alt="کافه باشگاه">
    <div class="in"><h2>کافه باشگاه</h2><p>شیک پروتئینی، قهوه و اسموتی — قبل یا بعد تمرین سفارش بده.</p></div>
  </div>
  <div class="product-grid">
    <?php foreach ($cafeProducts as $p): ?>
    <div class="product">
      <h3><?= e($p['name']) ?></h3>
      <span class="badge accent">نوشیدنی</span>
      <div class="price"><?= money($p['price']) ?> تومان</div>
      <div class="meta">موجودی: <?= fa_num((string) $p['stock']) ?></div>
      <form method="post" action="panel.php?tab=orders">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="order">
        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
        <input type="number" name="qty" min="1" max="20" value="1" required>
        <select name="pay"><option value="cash">نقدی</option><option value="wallet">کیف پول</option></select>
        <button class="btn btn-primary small" type="submit">سفارش</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$cafeProducts): ?><div class="empty"><span class="glyph">🥤</span>محصولی در کافه نیست.</div><?php endif; ?>
  </div>
  <p style="color:var(--muted);font-size:12px;margin-top:14px">پرداخت با کیف پول فوری است؛ سفارش نقدی هنگام تحویل تسویه می‌شود. موجودی کیف پول تو: <b><?= money($wallet) ?></b> تومان.</p>
</div>

<?php else: ?>
<div class="wrap">
  <div class="grid cols-2" style="align-items:start">
    <div class="card">
      <div class="card-head"><h2>نوبت‌های من</h2><a class="sub" href="panel.php?tab=services">رزرو جدید ←</a></div>
      <div class="card-body tight table-wrap">
        <table>
          <thead><tr><th>خدمت</th><th>زمان نوبت</th><th>قیمت</th><th>وضعیت</th></tr></thead>
          <tbody>
          <?php if (!$myRes): ?><tr><td colspan="4"><div class="empty"><span class="glyph">📅</span>نوبتی نداری.</div></td></tr><?php endif; ?>
          <?php foreach ($myRes as $r): ?>
          <tr>
            <td><?= e($r['service_name']) ?></td>
            <td class="num"><?= jdate((int) $r['reserved_at']) ?></td>
            <td class="num"><?= money($r['price']) ?></td>
            <td><span class="badge <?= $r['status'] === 'done' ? 'green' : ($r['status'] === 'canceled' ? 'red' : ($r['status'] === 'pending' ? 'amber' : 'accent')) ?>"><?= e($resLabels[$r['status']] ?? $r['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>سفارش‌های من</h2><a class="sub" href="panel.php?tab=store">فروشگاه ←</a></div>
      <div class="card-body tight table-wrap">
        <table>
          <thead><tr><th>اقلام</th><th>مبلغ</th><th>پرداخت</th><th>وضعیت</th><th>زمان</th></tr></thead>
          <tbody>
          <?php if (!$myOrders): ?><tr><td colspan="5"><div class="empty"><span class="glyph">🧾</span>سفارشی نداری.</div></td></tr><?php endif; ?>
          <?php foreach ($myOrders as $o): ?>
          <tr>
            <td style="white-space:normal"><?php foreach ($orderItems[(int) $o['id']] ?? [] as $it): ?><?= e($it['name']) ?> ×<?= fa_num((string) $it['qty']) ?><br><?php endforeach; ?></td>
            <td class="num"><?= money($o['total']) ?></td>
            <td><?= $o['pay_method'] === 'wallet' ? '<span class="badge green">کیف پول</span>' : '<span class="badge gray">نقدی</span>' ?></td>
            <td><span class="badge <?= $o['status'] === 'done' ? 'green' : ($o['status'] === 'canceled' ? 'red' : ($o['status'] === 'pending' ? 'amber' : 'accent')) ?>"><?= e($orderLabels[$o['status']] ?? $o['status']) ?></span></td>
            <td class="num"><?= jday((int) $o['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
</body>
</html>
