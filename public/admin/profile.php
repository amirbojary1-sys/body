<?php
declare(strict_types=1);
/** Own admin profile + password change. */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;

$admin = AdminAuth::requireAdmin();
$pdo = Database::connection();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$admin['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (strlen($current) > 72 || !password_verify($current, $hash)) $error = 'رمز فعلی درست نیست.';
    elseif (mb_strlen($new) < 10 || strlen($new) > 72) $error = 'رمز جدید باید حداقل ۱۰ نویسه و حداکثر ۷۲ بایت باشد.';
    elseif ($new !== $confirm) $error = 'تکرار رمز جدید با خود رمز یکی نیست.';
    elseif ($new === $current) $error = 'رمز جدید باید با رمز فعلی فرق داشته باشد.';
    else {
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        AdminAuth::log('password_changed');
        AdminAuth::flash('success', 'رمز عبور با موفقیت تغییر کرد.');
        admin_redirect('index.php');
    }
}

admin_header($admin, 'پروفایل من', 'profile');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>اطلاعات حساب مدیریتی</h2></div>
    <div class="card-body">
      <div class="kv">
        <div>نام کاربری</div><div><?= e($admin['username']) ?></div>
        <div>ایمیل</div><div dir="ltr" style="text-align:right"><?= e($admin['email']) ?></div>
        <div>سطح دسترسی</div><div><?= $admin['isSuper'] ? '<span class="badge accent">مدیر کل</span>' : '<span class="badge gray">مدیر</span>' ?></div>
        <div>تاریخ ساخت</div><div class="num"><?= jdate($admin['createdAt']) ?></div>
        <div>آخرین ورود</div><div class="num"><?= jdate($admin['lastLoginAt']) ?></div>
      </div>
      <?php if ($admin['defaultPassword']): ?><div class="alert alert-warning" style="margin:16px 0 0">⚠ رمز این حساب هنوز پیش‌فرض (<code>Admin@12345</code>) است. همین‌جا عوضش کن.</div><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>تغییر رمز عبور</h2></div>
    <div class="card-body">
      <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <div class="field"><label for="current">رمز فعلی</label><input id="current" name="current" type="password" required maxlength="72" autocomplete="current-password" dir="ltr"></div>
        <div class="field"><label for="new">رمز جدید</label><input id="new" name="new" type="password" required minlength="10" maxlength="72" autocomplete="new-password" dir="ltr"><small>حداقل ۱۰ نویسه.</small></div>
        <div class="field"><label for="confirm">تکرار رمز جدید</label><input id="confirm" name="confirm" type="password" required minlength="10" maxlength="72" autocomplete="new-password" dir="ltr"></div>
        <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
      </form>
    </div>
  </div>
</div>
<?php admin_footer();
