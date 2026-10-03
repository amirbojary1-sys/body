<?php
declare(strict_types=1);
/** Read-only detail of one user: account info + summarized fitness state. */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;

$admin = AdminAuth::requireAdmin();
$pdo = Database::connection();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0) admin_redirect('users.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['action'] ?? '', ['ban', 'unban', 'delete'], true)) {
    $action = $_POST['action'];
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target) {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            AdminAuth::log('user_deleted', 'user', $id, $target['email']);
            AdminAuth::flash('success', 'کاربر «' . $target['name'] . '» حذف شد.');
            admin_redirect('users.php');
        }
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$action === 'ban' ? 0 : 1, $id]);
        AdminAuth::log($action === 'ban' ? 'user_banned' : 'user_unbanned', 'user', $id, $target['email']);
        AdminAuth::flash('success', $action === 'ban' ? 'کاربر مسدود شد.' : 'کاربر فعال شد.');
        admin_redirect('user_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT u.*, s.data, s.revision, s.updated_at FROM users u LEFT JOIN user_states s ON s.user_id = u.id WHERE u.id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch();
if (!$u) { AdminAuth::flash('error', 'چنین کاربری پیدا نشد.'); admin_redirect('users.php'); }

$state = null;
try { $state = $u['data'] !== null ? json_decode($u['data'], true, 64, JSON_THROW_ON_ERROR) : null; } catch (Throwable) { $state = null; }
$profile = is_array($state['profile'] ?? null) && ($state['profile']['age'] ?? 0) > 0 ? $state['profile'] : null;
$genderLabels = ['male' => 'مرد', 'female' => 'زن'];
$goalLabels = ['lose' => 'کاهش وزن', 'maintain' => 'حفظ وزن', 'gain' => 'افزایش وزن'];
$levelLabels = ['beginner' => 'مبتدی', 'intermediate' => 'متوسط'];
$equipLabels = ['home' => 'خانه (بدون وسیله)', 'dumbbell' => 'دمبل', 'gym' => 'باشگاه'];

$counts = ['weights' => 0, 'sessions' => 0, 'favorites' => 0, 'waterDays' => 0, 'mealDays' => 0, 'chat' => 0];
$recentWeights = $recentSessions = [];
if (is_array($state)) {
    $counts['weights'] = is_array($state['weights'] ?? null) ? count($state['weights']) : 0;
    $counts['sessions'] = is_array($state['sessions'] ?? null) ? count($state['sessions']) : 0;
    $counts['favorites'] = is_array($state['favorites'] ?? null) ? count($state['favorites']) : 0;
    $counts['waterDays'] = is_array($state['water'] ?? null) ? count((array) $state['water']) : 0;
    $counts['mealDays'] = is_array($state['meals'] ?? null) ? count((array) $state['meals']) : 0;
    foreach (['coordinator', 'trainer', 'nutrition', 'recovery'] as $role) $counts['chat'] += is_array($state['chat'][$role] ?? null) ? count($state['chat'][$role]) : 0;
    $recentWeights = array_slice(array_reverse(is_array($state['weights'] ?? null) ? $state['weights'] : []), 0, 6);
    $recentSessions = array_slice(array_reverse(is_array($state['sessions'] ?? null) ? $state['sessions'] : []), 0, 6);
}
$active = (int) $u['is_active'] === 1;

admin_header($admin, 'کاربر: ' . $u['name'], 'users');
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-head">
    <h2>حساب کاربری <span class="sub">#<?= fa_num((string) $u['id']) ?></span></h2>
    <div style="display:flex;gap:8px">
      <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="<?= $active ? 'ban' : 'unban' ?>"><button class="btn small <?= $active ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= $active ? 'مسدودسازی' : 'فعال‌سازی' ?></button></form>
      <form method="post" data-confirm="کاربر «<?= e($u['name']) ?>» و همه سوابقش برای همیشه حذف شود؟"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="delete"><button class="btn small btn-danger" type="submit">حذف کاربر</button></form>
      <a class="btn small" href="users.php">→ بازگشت به فهرست</a>
    </div>
  </div>
  <div class="card-body">
    <div class="kv">
      <div>نام نمایشی</div><div><?= e($u['name']) ?></div>
      <div>ایمیل</div><div dir="ltr" style="text-align:right"><?= e($u['email']) ?></div>
      <div>وضعیت</div><div><?= $active ? '<span class="badge green">فعال</span>' : '<span class="badge red">مسدود</span>' ?></div>
      <div>تاریخ ثبت‌نام</div><div class="num"><?= jdate((int) $u['created_at']) ?></div>
      <div>آخرین ورود</div><div class="num"><?= jago($u['last_login_at'] ? (int) $u['last_login_at'] : null) ?></div>
      <div>نسخه داده (revision)</div><div class="num"><?= fa_num((string) ($u['revision'] ?? 0)) ?></div>
      <div>آخرین ذخیره‌سازی</div><div class="num"><?= jago($u['updated_at'] ? (int) $u['updated_at'] : null) ?></div>
    </div>
  </div>
</div>

<div class="grid cols-3" style="margin-bottom:16px">
  <div class="stat accent"><div class="label">ثبت‌های وزن</div><div class="value num"><?= fa_num($counts['weights']) ?></div><div class="hint">سقف مجاز: ۱۰۰۰</div></div>
  <div class="stat green"><div class="label">جلسات تمرین</div><div class="value num"><?= fa_num($counts['sessions']) ?></div><div class="hint">ثبت‌شده روی سرور</div></div>
  <div class="stat"><div class="label">پیام‌های ایجنت‌ها</div><div class="value num"><?= fa_num($counts['chat']) ?></div><div class="hint">هر ۴ نقش</div></div>
  <div class="stat"><div class="label">روزهای ثبت آب</div><div class="value num"><?= fa_num($counts['waterDays']) ?></div><div class="hint">تا ۳۶۵ روز</div></div>
  <div class="stat"><div class="label">روزهای ثبت وعده</div><div class="value num"><?= fa_num($counts['mealDays']) ?></div><div class="hint">تا ۳۶۵ روز</div></div>
  <div class="stat"><div class="label">حرکات علاقه‌مندی</div><div class="value num"><?= fa_num($counts['favorites']) ?></div><div class="hint">از ۲۴ حرکت</div></div>
</div>

<div class="grid cols-2">
  <div class="card">
    <div class="card-head"><h2>مشخصات بدنی ثبت‌شده</h2></div>
    <div class="card-body">
      <?php if (!$profile): ?><div class="empty"><span class="glyph">📝</span>این کاربر هنوز پروفایل تمرینی نساخته است.</div>
      <?php else: ?>
      <div class="kv">
        <div>جنسیت</div><div><?= e($genderLabels[$profile['gender'] ?? ''] ?? '—') ?></div>
        <div>سن</div><div class="num"><?= fa_num((string) ($profile['age'] ?? '—')) ?> سال</div>
        <div>قد</div><div class="num"><?= fa_num((string) ($profile['height'] ?? '—')) ?> سانتی‌متر</div>
        <div>وزن</div><div class="num"><?= fa_num((string) ($profile['weight'] ?? '—')) ?> کیلوگرم</div>
        <div>هدف</div><div><?= e($goalLabels[$profile['goal'] ?? ''] ?? '—') ?></div>
        <div>سطح تجربه</div><div><?= e($levelLabels[$profile['level'] ?? ''] ?? '—') ?></div>
        <div>تجهیزات</div><div><?= e($equipLabels[$profile['equipment'] ?? ''] ?? '—') ?></div>
        <div>روزهای تمرین</div><div class="num"><?= fa_num((string) ($profile['days'] ?? '—')) ?> روز در هفته</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>آخرین وزن‌ها و جلسات</h2></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>آخرین وزن‌ها</th><th>تاریخ</th><th>آخرین جلسات</th><th>مدت</th></tr></thead>
        <tbody>
        <?php if (!$recentWeights && !$recentSessions): ?>
          <tr><td colspan="4"><div class="empty"><span class="glyph">📊</span>داده‌ای ثبت نشده است.</div></td></tr>
        <?php else: for ($i = 0; $i < max(count($recentWeights), count($recentSessions)); $i++): ?>
          <tr>
            <td class="num"><?= isset($recentWeights[$i]) ? fa_num((string) $recentWeights[$i]['value']) . ' کیلوگرم' : '—' ?></td>
            <td class="num"><?= isset($recentWeights[$i]) ? e($recentWeights[$i]['date']) : '—' ?></td>
            <td><?= isset($recentSessions[$i]) ? e($recentSessions[$i]['name'] !== '' ? $recentSessions[$i]['name'] : 'جلسه تمرین') : '—' ?></td>
            <td class="num"><?= isset($recentSessions[$i]) ? fa_num((string) intdiv((int) $recentSessions[$i]['duration'], 60)) . ' دقیقه' : '—' ?></td>
          </tr>
        <?php endfor; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<p class="auth-note" style="text-align:right">این صفحه فقط خواندنی است؛ محتوای داده کاربر از داخل پنل تغییر نمی‌کند. داده‌های حالت مهمان هرگز روی سرور ذخیره نمی‌شوند.</p>
<?php admin_footer();
