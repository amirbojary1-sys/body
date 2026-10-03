<?php
declare(strict_types=1);
/**
 * Standalone user sign-in / sign-up page (the in-app dialog keeps working).
 * On success the browser is redirected straight into the app.
 */
use FitBot\Auth;
use FitBot\ApiException;
use FitBot\Http;

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    Auth::start();
} catch (Throwable $e) {
    error_log('FitBot login page error: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>راه‌اندازی فیت‌بات</title><body style="font-family:Tahoma;background:#151618;color:#eee;padding:8vw;line-height:2"><h1>یک قدم تا راه‌اندازی فیت‌بات</h1><p>PHP 8.2 یا جدیدتر، افزونه‌های دیتابیس و mbstring و دسترسی نوشتن در پوشه storage لازم است. راهنمای README.fa.md را بررسی کن.</p></body></html>');
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');

if (Auth::user()) { header('Location: index.php'); exit; }

$error = '';
$tab = ($_GET['tab'] ?? 'login') === 'register' ? 'register' : 'login';
$oldName = $oldEmail = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = $_POST['csrf'] ?? '';
    $tab = ($_POST['tab'] ?? 'login') === 'register' ? 'register' : 'login';
    $oldName = trim(Http::text($_POST['name'] ?? '', 50));
    $oldEmail = trim(Http::text($_POST['email'] ?? '', 254));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!is_string($sent) || !hash_equals(Auth::csrf(), $sent)) {
        $error = 'نشست صفحه منقضی شده است؛ دوباره تلاش کن.';
    } else {
        try {
            if ($tab === 'register') {
                Auth::register(['name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'password' => $password, 'consent' => ($_POST['consent'] ?? '') === 'on']);
            } else {
                Auth::login(['email' => $_POST['email'] ?? '', 'password' => $password]);
            }
            header('Location: index.php');
            exit;
        } catch (ApiException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FitBot auth form error: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
            $error = 'ورود ممکن نشد؛ تنظیمات دیتابیس سرور را بررسی کن.';
        }
    }
}
$csrf = Auth::csrf();
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود / ثبت‌نام — فیت‌بات</title>
<style>
@font-face{font-family:Vazirmatn;src:url(assets/fonts/vazirmatn.woff2) format("woff2");font-weight:100 900;font-display:swap}
:root{--bg:#141519;--panel:#1d1f24;--line:#2f333b;--text:#eceef2;--muted:#9aa1ad;--accent:#ff743e;--soft:rgba(255,116,62,.14);--red:#ff5d5d}
*{box-sizing:border-box;margin:0}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:radial-gradient(900px 500px at 50% -10%,rgba(255,116,62,.09),transparent 60%),var(--bg);color:var(--text);min-height:100vh;display:grid;place-items:center;padding:24px;font-size:14.5px;line-height:1.9}
a{color:var(--accent);text-decoration:none}
.card{width:min(440px,100%);background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:34px 32px;box-shadow:0 30px 70px rgba(0,0,0,.45);animation:rise .4s ease}
@keyframes rise{from{transform:translateY(16px);opacity:0}}
.logo{width:58px;height:58px;border-radius:17px;margin:0 auto 14px;background:linear-gradient(135deg,#ff8a5c,#ff5d2e);display:grid;place-items:center;font-size:26px;box-shadow:0 10px 26px rgba(255,116,62,.4)}
h1{font-size:19px;text-align:center}
.sub{color:var(--muted);text-align:center;font-size:13px;margin:4px 0 20px}
.tabs{display:flex;background:#17191e;border:1px solid var(--line);border-radius:12px;padding:4px;margin-bottom:20px}
.tabs a{flex:1;text-align:center;padding:8px;border-radius:9px;font-weight:600;color:var(--muted);font-size:13.5px}
.tabs a.active{background:var(--soft);color:var(--accent)}
.err{background:rgba(255,93,93,.13);border:1px solid rgba(255,93,93,.35);color:var(--red);border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.field{margin-bottom:14px;display:flex;flex-direction:column;gap:6px}
.field label{font-size:12.5px;color:var(--muted);font-weight:600}
.field input{background:#17191e;border:1px solid var(--line);border-radius:10px;color:var(--text);padding:10px 14px;font-family:inherit;font-size:14px;width:100%}
.field input:focus{outline:none;border-color:var(--accent)}
.field small{color:var(--muted);font-size:11.5px}
.chk{display:flex;gap:9px;align-items:flex-start;margin:4px 0 16px;font-size:12.5px;color:var(--muted);cursor:pointer}
.chk input{width:16px;height:16px;margin-top:5px;accent-color:var(--accent)}
.btn{width:100%;padding:11px;border:0;border-radius:11px;background:linear-gradient(135deg,#ff8a5c,#ff5d2e);color:#fff;font-family:inherit;font-size:14.5px;font-weight:700;cursor:pointer}
.btn:hover{filter:brightness(1.08)}
.note{color:var(--muted);font-size:11.5px;text-align:center;margin-top:18px;line-height:2}
</style>
</head>
<body>
  <div class="card">
    <div class="logo">⚡</div>
    <h1>فیت‌بات</h1>
    <p class="sub">هر روز، قوی‌تر. ورود به فضای شخصی تمرین و تغذیه.</p>
    <div class="tabs">
      <a class="<?= $tab === 'login' ? 'active' : '' ?>" href="?tab=login">ورود</a>
      <a class="<?= $tab === 'register' ? 'active' : '' ?>" href="?tab=register">ساخت حساب</a>
    </div>
    <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <?php if ($tab === 'register'): ?>
      <div class="field">
        <label for="name">نام نمایشی</label>
        <input id="name" name="name" required minlength="2" maxlength="50" value="<?= htmlspecialchars($oldName) ?>" placeholder="نام تو">
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="email">ایمیل</label>
        <input id="email" name="email" type="email" required maxlength="254" dir="ltr" value="<?= htmlspecialchars($oldEmail) ?>" placeholder="you@example.com">
      </div>
      <div class="field">
        <label for="password">رمز عبور</label>
        <input id="password" name="password" type="password" required maxlength="72" dir="ltr"
               autocomplete="<?= $tab === 'register' ? 'new-password' : 'current-password' ?>"
               placeholder="<?= $tab === 'register' ? 'حداقل ۱۰ نویسه' : 'رمز حساب تو' ?>">
        <?php if ($tab === 'register'): ?><small>حداقل ۱۰ نویسه و حداکثر ۷۲ بایت؛ برای حروف فارسی طول کمتری انتخاب کن.</small><?php endif; ?>
      </div>
      <?php if ($tab === 'register'): ?>
      <label class="chk"><input type="checkbox" name="consent" required><span>می‌دانم اطلاعات حساب و داده‌های ورزشی در دیتابیس همین سرور ذخیره می‌شوند.</span></label>
      <?php endif; ?>
      <button class="btn" type="submit"><?= $tab === 'register' ? 'ساخت حساب و ورود' : 'ورود به فیت‌بات' ?></button>
    </form>
    <p class="note">
      <?= $tab === 'register' ? 'حساب جدید با داده‌های خالی شروع می‌شود؛ برای انتقال اطلاعات مهمان، از پنجره حساب داخل برنامه ثبت‌نام کن.' : 'اگر رمز را فراموش کرده‌ای، در این نسخه بازیابی ایمیلی وجود ندارد؛ از مدیر سایت کمک بگیر.' ?><br>
      <a href="index.php">↩ ادامه به‌عنوان مهمان / بازگشت به برنامه</a>
    </p>
  </div>
</body>
</html>
