<?php
declare(strict_types=1);
/** User management: search, paginate, suspend/activate, delete. */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;

$admin = AdminAuth::requireAdmin('users.view');
$pdo = Database::connection();

// ---- actions (POST + CSRF, already verified in inc/admin.php) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'users.manage')) { AdminAuth::flash('error', 'برای تغییر وضعیت اعضا دسترسی نداری.'); admin_redirect('users.php'); }
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
    if ($id !== false && $id > 0) {
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target) {
            if ($action === 'ban' || $action === 'unban') {
                $active = $action === 'ban' ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active, $id]);
                AdminAuth::log($action === 'ban' ? 'user_banned' : 'user_unbanned', 'user', $id, $target['email']);
                AdminAuth::flash('success', ($action === 'ban' ? 'کاربر «' . $target['name'] . '» مسدود شد.' : 'کاربر «' . $target['name'] . '» فعال شد.'));
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                AdminAuth::log('user_deleted', 'user', $id, $target['email']);
                AdminAuth::flash('success', 'کاربر «' . $target['name'] . '» و همه داده‌هایش حذف شد.');
            }
        }
    }
    // Post/Redirect/Get
    $q = trim((string) ($_POST['q'] ?? '')); $p = (string) ($_POST['page'] ?? '');
    admin_redirect('users.php' . ($q !== '' ? '?q=' . urlencode($q) : '') . ($p !== '' && $p !== '1' ? '&page=' . (int) $p : ''));
}

// ---- listing ----
$q = trim((string) ($_GET['q'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$perPage = 20;
$where = ''; $args = [];
if ($q !== '') {
    $where = ' WHERE u.name LIKE :q OR u.email LIKE :q';
    $args['q'] = '%' . $q . '%';
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users u' . $where);
$stmt->execute($args);
$total = (int) $stmt->fetchColumn();
[$limit, $offset, $pages] = paginate_params($total, $perPage, $page);

$stmt = $pdo->prepare('SELECT u.id, u.name, u.email, u.is_active, u.created_at, u.last_login_at, s.revision, s.updated_at,
    (SELECT COUNT(*) FROM subscriptions x WHERE x.user_id = u.id AND x.status = \'active\') AS active_subs
    FROM users u LEFT JOIN user_states s ON s.user_id = u.id' . $where . '
    ORDER BY u.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
$stmt->execute($args);
$users = $stmt->fetchAll();

admin_header($admin, 'کاربران', 'users');
?>
<div class="card">
  <div class="card-head">
    <h2>کاربران سایت <span class="sub">(<?= fa_num($total) ?> نفر)</span></h2>
    <form method="get" class="searchbar">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="جستجو بر اساس نام یا ایمیل…">
      <button class="btn" type="submit">جستجو</button>
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="users.php">پاک‌کردن</a><?php endif; ?>
    </form>
  </div>
  <div class="card-body tight table-wrap">
    <table>
      <thead><tr><th>#</th><th>کاربر</th><th>وضعیت</th><th>اشتراک</th><th>ثبت‌نام</th><th>آخرین ورود</th><th>آخرین ذخیره</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="8"><div class="empty"><span class="glyph">🔍</span><?= $q !== '' ? 'کاربری با این عبارت پیدا نشد.' : 'هنوز کاربری ثبت‌نام نکرده است.' ?></div></td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): $active = (int) $u['is_active'] === 1; ?>
        <tr>
          <td class="num"><?= fa_num((string) $u['id']) ?></td>
          <td><a href="user_view.php?id=<?= (int) $u['id'] ?>"><strong><?= e($u['name']) ?></strong></a><span class="sub" dir="ltr"><?= e($u['email']) ?></span></td>
          <td><?= $active ? '<span class="badge green">فعال</span>' : '<span class="badge red">مسدود</span>' ?></td>
          <td class="num"><?= (int) $u['active_subs'] > 0 ? '<span class="badge green">' . fa_num((string) $u['active_subs']) . ' فعال</span>' : '<span class="badge gray">بدون اشتراک</span>' ?></td>
          <td class="num"><?= jdate((int) $u['created_at']) ?></td>
          <td class="num"><?= jago($u['last_login_at'] ? (int) $u['last_login_at'] : null) ?></td>
          <td class="num"><?= jago($u['updated_at'] ? (int) $u['updated_at'] : null) ?></td>
          <td>
            <div style="display:flex;gap:6px">
              <a class="btn small" href="user_view.php?id=<?= (int) $u['id'] ?>">مشاهده</a>
              <form method="post" style="display:inline">
                <?= AdminAuth::csrfField() ?>
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="action" value="<?= $active ? 'ban' : 'unban' ?>">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <button class="btn small <?= $active ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= $active ? 'مسدودسازی' : 'فعال‌سازی' ?></button>
              </form>
              <form method="post" style="display:inline" data-confirm="کاربر «<?= e($u['name']) ?>» و همه سوابقش برای همیشه حذف شود؟">
                <?= AdminAuth::csrfField() ?>
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <button class="btn small btn-danger" type="submit">حذف</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($page, $pages, $q !== '' ? 'q=' . urlencode($q) : '') ?>
</div>
<p class="auth-note" style="text-align:right; margin-top:14px">
  «مسدودسازی» مانع ورود کاربر می‌شود و نشست فعال او را باطل می‌کند؛ داده‌هایش حفظ می‌شود. «حذف» قابل بازگشت نیست.
</p>
<?php admin_footer();
