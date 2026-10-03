<?php
declare(strict_types=1);
/** Admin sign-in. Standalone page, isolated from the user-facing app. */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Http;

$admin = AdminAuth::admin();
if ($admin) admin_redirect('index.php');

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        AdminAuth::attemptLogin(trim(Http::text($_POST['login'] ?? '', 254)), is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
        admin_redirect('index.php');
    } catch (ApiException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('FitBot admin login error: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
        $error = 'ورود ممکن نشد؛ تنظیمات دیتابیس و اتصال MySQL را بررسی کن.';
    }
}
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>ورود مدیر — پنل فیت‌بات</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="auth-logo">⚡</div>
    <h1>پنل مدیریت فیت‌بات</h1>
    <p class="auth-sub">ورود مدیران سایت؛ این صفحه برای کاربران عادی نیست.</p>
    <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= AdminAuth::csrfField() ?>
      <div class="field">
        <label for="login">نام کاربری یا ایمیل</label>
        <input id="login" name="login" type="text" required maxlength="254" autofocus
               value="<?= e($_POST['login'] ?? '') ?>" placeholder="admin">
      </div>
      <div class="field">
        <label for="password">رمز عبور</label>
        <input id="password" name="password" type="password" required maxlength="72" autocomplete="current-password" placeholder="••••••••••">
      </div>
      <button class="btn btn-primary btn-block" type="submit" style="padding:11px">ورود به پنل مدیریت</button>
    </form>
    <p class="auth-note">حساب مدیر پیش‌فرض: <code>admin</code> / <code>Admin@12345</code><br>بعد از اولین ورود حتماً از «پروفایل من» رمز را تغییر بده.</p>
    <p class="auth-note"><a href="../index.php">↩ بازگشت به سایت فیت‌بات</a></p>
  </div>
</body>
</html>
