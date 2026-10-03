<?php
declare(strict_types=1);
/** Staff (admin) accounts & their RBAC roles (proposal §2, §24). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Database;
use FitBot\Http;
use FitBot\Rbac;

$admin = AdminAuth::requireAdmin('staff.manage');
$pdo = Database::connection();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim(Http::text($_POST['username'] ?? '', 50));
        $email = strtolower(trim(Http::text($_POST['email'] ?? '', 254)));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            AdminAuth::flash('error', 'نام کاربری باید ۳ تا ۵۰ نویسه لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            AdminAuth::flash('error', 'ایمیل معتبر وارد کن.');
        } elseif (mb_strlen($password) < 10 || strlen($password) > 72) {
            AdminAuth::flash('error', 'رمز باید حداقل ۱۰ نویسه و حداکثر ۷۲ بایت باشد.');
        } else {
            try {
                $pdo->prepare('INSERT INTO admins(username, email, password_hash, is_super, created_at) VALUES(?, ?, ?, 0, ?)')
                    ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), Http::now()]);
                $newId = (int) $pdo->lastInsertId();
                Rbac::assignAdminRoles($newId, $_POST['roles'] ?? []);
                AdminAuth::log('admin_created', 'admin', $newId, $username . ' <' . $email . '>');
                AdminAuth::flash('success', 'حساب کارمندی «' . $username . '» با نقش‌های انتخاب‌شده ساخته شد.');
            } catch (\PDOException $e) {
                AdminAuth::flash('error', (string) $e->getCode() === '23000' ? 'این نام کاربری قبلاً استفاده شده است.' : 'ساخت حساب ممکن نشد.');
            }
        }
    } elseif ($action === 'set_roles') {
        $id = (int) ($_POST['admin_id'] ?? 0);
        Rbac::assignAdminRoles($id, $_POST['roles'] ?? []);
        $stmt = $pdo->prepare('SELECT username FROM admins WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) {
            AdminAuth::log('admin_roles_changed', 'admin', $id, (string) implode(',', array_map('strval', $_POST['roles'] ?? [])));
            AdminAuth::flash('success', 'نقش‌های کارمند به‌روزرسانی شد.');
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['admin_id'] ?? '', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT id, username, is_super FROM admins WHERE id = ?');
        $stmt->execute([(int) $id]);
        $target = $stmt->fetch();
        if (!$target) AdminAuth::flash('error', 'چنین کارمندی وجود ندارد.');
        elseif ((int) $target['id'] === $admin['id']) AdminAuth::flash('error', 'نمی‌توانی حساب خودت را حذف کنی.');
        else {
            $supers = (int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_super = 1')->fetchColumn();
            if ((int) $target['is_super'] === 1 && $supers <= 1) {
                AdminAuth::flash('error', 'حداقل یک مدیر کل باید بماند؛ اول مدیر کل جدید بساز.');
            } else {
                $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([(int) $target['id']]);
                AdminAuth::log('admin_deleted', 'admin', (int) $target['id'], $target['username']);
                AdminAuth::flash('success', 'کارمند «' . $target['username'] . '» حذف شد.');
            }
        }
    }
    admin_redirect('admins.php');
}

$editAdmin = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT id, username, email, is_super FROM admins WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editAdmin = $stmt->fetch() ?: null;
    if ($editAdmin) {
        $editRoles = [];
        foreach (Rbac::rolesOfAdmin((int) $editAdmin['id']) as $r) $editRoles[(int) $r['id']] = true;
    }
}
$admins = $pdo->query('SELECT a.id, a.username, a.email, a.is_super, a.last_login_at, a.created_at FROM admins a ORDER BY a.created_at ASC')->fetchAll();
$roleList = $pdo->query('SELECT id, slug, title FROM roles WHERE slug NOT IN (\'member\', \'honorary_member\', \'guest\') AND is_active = 1 ORDER BY is_system DESC, id')->fetchAll();

admin_header($admin, 'کارکنان و دسترسی', 'staff');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>کارکنان پنل <span class="sub">(<?= fa_num(count($admins)) ?> نفر)</span></h2></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>کارمند</th><th>نقش‌ها</th><th>آخرین ورود</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): $roles = Rbac::rolesOfAdmin((int) $a['id']); ?>
          <tr>
            <td><strong><?= e($a['username']) ?></strong><?= (int) $a['is_super'] === 1 ? ' <span class="badge accent">مدیر کل</span>' : '' ?><span class="sub" dir="ltr"><?= e($a['email']) ?></span></td>
            <td>
              <span class="badge-row">
                <?php if (!$roles && (int) $a['is_super'] !== 1): ?><span class="badge gray">بدون نقش</span><?php endif; ?>
                <?php foreach ($roles as $r): ?><span class="badge <?= $r['slug'] === 'ceo' ? 'accent' : 'green' ?>"><?= e($r['title']) ?></span><?php endforeach; ?>
              </span>
            </td>
            <td class="num"><?= jago($a['last_login_at'] ? (int) $a['last_login_at'] : null) ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <a class="btn small" href="admins.php?edit=<?= (int) $a['id'] ?>">نقش‌ها</a>
                <?php if ((int) $a['id'] !== $admin['id']): ?>
                <form method="post" data-confirm="حساب «<?= e($a['username']) ?>» حذف شود؟">
                  <?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                  <button class="btn small btn-danger" type="submit">حذف</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <?php if ($editAdmin): ?>
    <div class="card-head"><h2>نقش‌های: <?= e($editAdmin['username']) ?></h2><a class="sub" href="admins.php">انصراف ←</a></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="set_roles">
        <input type="hidden" name="admin_id" value="<?= (int) $editAdmin['id'] ?>">
        <?php foreach ($roleList as $r): ?>
        <label class="checkbox-line"><input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" <?= isset($editRoles[(int) $r['id']]) ? 'checked' : '' ?>><span><?= e($r['title']) ?> <span class="sub" dir="ltr">(<?= e($r['slug']) ?>)</span></span></label>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit" style="margin-top:8px">ذخیره نقش‌ها</button>
        <p class="auth-note" style="text-align:right">نقش‌ها تجمعی هستند؛ دسترسی نهایی، اجتماع دسترسی همه نقش‌های انتخاب‌شده است.</p>
      </form>
    </div>
    <?php else: ?>
    <div class="card-head"><h2>افزودن کارمند جدید</h2></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="username">نام کاربری</label><input id="username" name="username" required maxlength="50" dir="ltr" placeholder="sara92"><small>۳ تا ۵۰ نویسه لاتین.</small></div>
          <div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" required maxlength="254" dir="ltr"></div>
          <div class="field full"><label for="password">رمز عبور</label><input id="password" name="password" type="password" required minlength="10" maxlength="72" dir="ltr" autocomplete="new-password"><small>حداقل ۱۰ نویسه؛ این رمز را به کارمند برسان.</small></div>
        </div>
        <strong style="font-size:13px;display:block;margin-bottom:6px">نقش‌های این کارمند:</strong>
        <?php foreach ($roleList as $r): ?>
        <label class="checkbox-line"><input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>"><span><?= e($r['title']) ?></span></label>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit" style="margin-top:8px">ساخت حساب کارمندی</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<p class="auth-note" style="text-align:right">«مدیر کل» (حساب پیش‌فرض admin) از همه چیز عبور می‌کند و نقش لازم ندارد. برای بقیه کارکنان حداقل یک نقش انتخاب کن؛ بدون نقش، کارمند فقط داشبورد و پروفایل خودش را می‌بیند.</p>
<?php admin_footer();
