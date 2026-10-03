<?php
declare(strict_types=1);
/** Dashboard: user statistics, recent signups and admin activity. */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Http;
use FitBot\Database;

$admin = AdminAuth::requireAdmin();
$pdo = Database::connection();
$now = Http::now();
$weekAgo = $now - 7 * 86_400_000;

$stats = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 0) AS banned_users,
        (SELECT COUNT(*) FROM users WHERE created_at >= ' . $weekAgo . ') AS new_users,
        (SELECT COUNT(*) FROM user_states WHERE revision > 0) AS saving_users,
        (SELECT COUNT(*) FROM admins) AS total_admins,
        (SELECT COUNT(*) FROM admin_activity WHERE created_at >= ' . $weekAgo . ') AS actions_week,
        (SELECT MAX(last_login_at) FROM users) AS last_user_login'
)->fetch();

$recentUsers = $pdo->query(
    'SELECT u.id, u.name, u.email, u.is_active, u.created_at, u.last_login_at, s.revision
     FROM users u LEFT JOIN user_states s ON s.user_id = u.id
     ORDER BY u.created_at DESC LIMIT 6'
)->fetchAll();

$recentActivity = $pdo->query(
    'SELECT admin_name, action, entity, entity_id, created_at FROM admin_activity ORDER BY created_at DESC, id DESC LIMIT 7'
)->fetchAll();

$actionLabels = [
    'login' => ['ورود مدیر', 'green'], 'login_failed' => ['ورود ناموفق', 'red'], 'logout' => ['خروج', 'gray'],
    'user_banned' => ['مسدودسازی کاربر', 'red'], 'user_unbanned' => ['فعال‌سازی کاربر', 'green'],
    'user_deleted' => ['حذف کاربر', 'red'], 'admin_created' => ['افزودن مدیر', 'accent'],
    'admin_deleted' => ['حذف مدیر', 'red'], 'password_changed' => ['تغییر رمز', 'amber'],
];

admin_header($admin, 'داشبورد', 'dashboard');
?>
<div class="grid cols-4">
  <div class="stat accent"><div class="label">کل کاربران</div><div class="value num"><?= fa_num($stats['total_users']) ?></div><div class="hint">ثبت‌نام‌شده در مجموع</div></div>
  <div class="stat green"><div class="label">کاربران فعال</div><div class="value num"><?= fa_num($stats['active_users']) ?></div><div class="hint">قابل ورود به سایت</div></div>
  <div class="stat red"><div class="label">کاربران مسدود</div><div class="value num"><?= fa_num($stats['banned_users']) ?></div><div class="hint">غیرفعال‌شده توسط مدیر</div></div>
  <div class="stat amber"><div class="label">ثبت‌نام هفته اخیر</div><div class="value num"><?= fa_num($stats['new_users']) ?></div><div class="hint">۷ روز گذشته</div></div>
</div>

<div class="grid cols-4" style="margin-top:16px">
  <div class="stat"><div class="label">کاربران دارای داده ذخیره‌شده</div><div class="value num"><?= fa_num($stats['saving_users']) ?></div><div class="hint">حداقل یک بار ذخیره کرده‌اند</div></div>
  <div class="stat"><div class="label">مدیران سایت</div><div class="value num"><?= fa_num($stats['total_admins']) ?></div><div class="hint"><a href="admins.php">مدیریت مدیران</a></div></div>
  <div class="stat"><div class="label">اقدام‌های مدیریتی هفته</div><div class="value num"><?= fa_num($stats['actions_week']) ?></div><div class="hint">۷ روز گذشته</div></div>
  <div class="stat"><div class="label">آخرین ورود کاربر</div><div class="value" style="font-size:16px;margin-top:10px"><?= jago($stats['last_user_login'] ? (int) $stats['last_user_login'] : null) ?></div></div>
</div>

<div class="grid cols-2" style="margin-top:22px">
  <div class="card">
    <div class="card-head"><h2>آخرین ثبت‌نام‌ها</h2><a class="sub" href="users.php">همه کاربران ←</a></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>کاربر</th><th>وضعیت</th><th>ثبت‌نام</th><th>آخرین ورود</th></tr></thead>
        <tbody>
        <?php if (!$recentUsers): ?>
          <tr><td colspan="4"><div class="empty"><span class="glyph">🏋️</span>هنوز کاربری ثبت‌نام نکرده است.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($recentUsers as $u): ?>
          <tr>
            <td><a href="user_view.php?id=<?= (int) $u['id'] ?>"><strong><?= e($u['name']) ?></strong></a><span class="sub" dir="ltr"><?= e($u['email']) ?></span></td>
            <td><?= (int) $u['is_active'] === 1 ? '<span class="badge green">فعال</span>' : '<span class="badge red">مسدود</span>' ?></td>
            <td class="num"><?= jago((int) $u['created_at']) ?></td>
            <td class="num"><?= jago($u['last_login_at'] ? (int) $u['last_login_at'] : null) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>آخرین فعالیت‌های پنل</h2><a class="sub" href="activity.php">گزارش کامل ←</a></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>اقدام</th><th>مدیر</th><th>زمان</th></tr></thead>
        <tbody>
        <?php if (!$recentActivity): ?>
          <tr><td colspan="3"><div class="empty"><span class="glyph">📋</span>فعالیتی ثبت نشده است.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($recentActivity as $a): ?>
          <?php [$label, $color] = $actionLabels[$a['action']] ?? [$a['action'], 'gray']; ?>
          <tr>
            <td><span class="badge <?= $color ?>"><?= e($label) ?></span><?= $a['entity'] === 'user' && $a['entity_id'] ? '<span class="sub">کاربر #' . fa_num((string) $a['entity_id']) . '</span>' : '' ?></td>
            <td><?= e($a['admin_name']) ?></td>
            <td class="num"><?= jago((int) $a['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php admin_footer();
