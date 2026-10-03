<?php
declare(strict_types=1);
/** Manage admin accounts (super admin only). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Http;
use FitBot\Database;

$admin = AdminAuth::requireAdmin();
if (!$admin['isSuper']) { AdminAuth::flash('error', 'مدیریت مدیران فقط برای «مدیر کل» مجاز است.'); admin_redirect('index.php'); }
$pdo = Database::connection();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim(Http::text($_POST['username'] ?? '', 50));
        $email = strtolower(trim(Http::text($_POST['email'] ?? '', 254)));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $isSuper = ($_POST['is_super'] ?? '') === '1';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            AdminAuth::flash('error', 'نام کاربری باید ۳ تا ۵۰ نویسه لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            AdminAuth::flash('error', 'ایمیل معتبر وارد کن.');
        } elseif (mb_strlen($password) < 10 || strlen($password) > 72) {
            AdminAuth::flash('error', 'رمز مدیر باید حداقل ۱۰ نویسه و حداکثر ۷۲ بایت باشد.');
        } else {
            try {
                $pdo->prepare('INSERT INTO admins(username, email, password_hash, is_super, created_at) VALUES(?, ?, ?, ?, ?)')
                    ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $isSuper ? 1 : 0, Http::now()]);
                AdminAuth::log('admin_created', 'admin', (int) $pdo->lastInsertId(), $username . ' <' . $email . '>');
                AdminAuth::flash('success', 'مدیر جدید «' . $username . '» ساخته شد.');
            } catch (PDOException $e) {
                AdminAuth::flash('error', (string) $e->getCode() === '23000' ? 'این نام کاربری قبلاً استفاده شده است.' : 'ساخت مدیر ممکن نشد.');
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['admin_id'] ?? '', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT id, username, is_super FROM admins WHERE id = ?');
        $stmt->execute([(int) $id]);
        $target = $stmt->fetch();
        if (!$target) { AdminAuth::flash('error', 'چنین مدیری وجود ندارد.'); }
        elseif ((int) $target['id'] === $admin['id']) { AdminAuth::flash('error', 'نمی‌توانی حساب خودت را حذف کنی.'); }
        else {
            $supers = (int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_super = 1')->fetchColumn();
            if ((int) $target['is_super'] === 1 && $supers <= 1) {
                AdminAuth::flash('error', 'حداقل یک مدیر کل باید بماند؛ اول مدیر کل جدید بساز.');
            } else {
                $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([(int) $target['id']]);
                AdminAuth::log('admin_deleted', 'admin', (int) $target['id'], $target['username']);
                AdminAuth::flash('success', 'مدیر «' . $target['username'] . '» حذف شد.');
            }
        }
    }
    admin_redirect('admins.php');
}

$admins = $pdo->query('SELECT id, username, email, is_super, last_login_at, created_at FROM admins ORDER BY created_at ASC')->fetchAll();

admin_header($admin, 'مدیران', 'admins');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>فهرست مدیران <span class="sub">(<?= fa_num(count($admins)) ?> نفر)</span></h2></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>نام کاربری</th><th>سطح</th><th>آخرین ورود</th><th>ساخت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): $self = (int) $a['id'] === $admin['id']; ?>
          <tr>
            <td><strong><?= e($a['username']) ?></strong><?= $self ? ' <span class="badge gray">تو</span>' : '' ?><span class="sub" dir="ltr"><?= e($a['email']) ?></span></td>
            <td><?= (int) $a['is_super'] === 1 ? '<span class="badge accent">مدیر کل</span>' : '<span class="badge gray">مدیر</span>' ?></td>
            <td class="num"><?= jago($a['last_login_at'] ? (int) $a['last_login_at'] : null) ?></td>
            <td class="num"><?= jdate((int) $a['created_at']) ?></td>
            <td>
              <?php if (!$self): ?>
              <form method="post" data-confirm="مدیر «<?= e($a['username']) ?>» حذف شود؟">
                <?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                <button class="btn small btn-danger" type="submit">حذف</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>افزودن مدیر جدید</h2></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="username">نام کاربری</label><input id="username" name="username" required maxlength="50" dir="ltr" placeholder="manager1"><small>۳ تا ۵۰ نویسه لاتین؛ مثل sara_92</small></div>
          <div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" required maxlength="254" dir="ltr" placeholder="sara@example.com"></div>
          <div class="field full"><label for="password">رمز عبور</label><input id="password" name="password" type="password" required minlength="10" maxlength="72" dir="ltr" autocomplete="new-password"><small>حداقل ۱۰ نویسه؛ این رمز را به مدیر جدید برسان.</small></div>
        </div>
        <label class="checkbox-line"><input type="checkbox" name="is_super" value="1"><span>دسترسی مدیر کل (مدیریت مدیران و حذف کاربران حساس)</span></label>
        <button class="btn btn-primary" type="submit">ساخت حساب مدیر</button>
      </form>
      <p class="auth-note" style="text-align:right">مدیر عادی به داشبورد، کاربران و گزارش‌ها دسترسی دارد؛ فقط مدیر کل می‌تواند مدیر بسازد یا حذف کند.</p>
    </div>
  </div>
</div>
<?php admin_footer();
