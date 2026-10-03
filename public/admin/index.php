<?php
declare(strict_types=1);
/** CEO dashboard: permission-aware live stats (proposal §28). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin();
$pdo = Database::connection();
$now = Http::now();
$weekAgo = $now - 7 * 86_400_000;
$monthStart = (int) (mktime(0, 0, 0, (int) gmdate('n'), 1, (int) gmdate('Y')) * 1000);
$canUsers = AdminAuth::can($admin, 'users.view');
$canCrm = AdminAuth::can($admin, 'crm.view');
$canSubs = AdminAuth::can($admin, 'memberships.view');
$canFinance = AdminAuth::can($admin, 'finance.view');
$canServices = AdminAuth::can($admin, 'services.view');
$canStore = AdminAuth::can($admin, 'store.view');

$stats = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 0) AS banned_users,
        (SELECT COUNT(*) FROM users WHERE created_at >= ' . $weekAgo . ') AS new_users,
        (SELECT COUNT(*) FROM user_states WHERE revision > 0) AS saving_users,
        (SELECT COUNT(*) FROM admins) AS total_admins,
        (SELECT COUNT(*) FROM admin_activity WHERE created_at >= ' . $weekAgo . ') AS actions_week,
        (SELECT MAX(last_login_at) FROM users) AS last_user_login,
        (SELECT COUNT(*) FROM leads) AS total_leads,
        (SELECT COUNT(*) FROM leads WHERE status = \'new\') AS new_leads,
        (SELECT COUNT(*) FROM leads WHERE status = \'won\') AS won_leads,
        (SELECT COUNT(*) FROM subscriptions WHERE status = \'active\') AS active_subs,
        (SELECT COUNT(*) FROM subscriptions WHERE status = \'active\' AND expires_at IS NOT NULL AND expires_at >= ' . $now . ' AND expires_at < ' . ($now + 7 * 86400000) . ') AS expiring_subs,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'income\' AND created_at >= ' . $monthStart . ') AS income_month,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'expense\' AND created_at >= ' . $monthStart . ') AS expense_month,
        ' . "(SELECT COUNT(*) FROM reservations WHERE status = 'pending') AS pending_res,
        (SELECT COUNT(*) FROM reservations WHERE status = 'confirmed' AND reserved_at >= $now) AS upcoming_res,
        (SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'paid', 'preparing')) AS open_orders,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = 'income' AND category IN ('store', 'cafe') AND created_at >= $monthStart) AS shop_income_month"
)->fetch();

$recentUsers = $canUsers ? $pdo->query(
    'SELECT u.id, u.name, u.email, u.is_active, u.created_at, u.last_login_at FROM users u ORDER BY u.created_at DESC LIMIT 6'
)->fetchAll() : [];

$recentLeads = $canCrm ? $pdo->query(
    'SELECT l.id, l.full_name, l.status, l.created_at, l.follow_up_at FROM leads l ORDER BY l.created_at DESC LIMIT 6'
)->fetchAll() : [];

$recentActivity = $pdo->query(
    'SELECT admin_name, action, entity, entity_id, created_at FROM admin_activity ORDER BY created_at DESC, id DESC LIMIT 7'
)->fetchAll();

$actionLabels = [
    'login' => ['ورود مدیر', 'green'], 'login_failed' => ['ورود ناموفق', 'red'], 'logout' => ['خروج', 'gray'],
    'user_banned' => ['مسدودسازی کاربر', 'red'], 'user_unbanned' => ['فعال‌سازی کاربر', 'green'],
    'user_deleted' => ['حذف کاربر', 'red'], 'admin_created' => ['افزودن کارمند', 'accent'],
    'admin_deleted' => ['حذف کارمند', 'red'], 'admin_roles_changed' => ['تغییر نقش کارمند', 'amber'],
    'password_changed' => ['تغییر رمز', 'amber'],
    'role_created' => ['ساخت نقش', 'accent'], 'role_updated' => ['ویرایش نقش', 'amber'], 'role_deleted' => ['حذف نقش', 'red'],
    'branch_created' => ['ساخت شعبه', 'accent'], 'branch_updated' => ['ویرایش شعبه', 'amber'], 'branch_deactivated' => ['تغییر وضعیت شعبه', 'gray'],
    'lead_created' => ['ثبت لید', 'accent'], 'lead_updated' => ['به‌روزرسانی لید', 'amber'], 'lead_event' => ['پیگیری لید', 'green'],
    'plan_created' => ['ساخت پلن', 'accent'], 'plan_updated' => ['ویرایش پلن', 'amber'], 'plan_deactivated' => ['تغییر وضعیت پلن', 'gray'],
    'subscription_created' => ['ثبت اشتراک', 'green'], 'subscription_canceled' => ['لغو اشتراک', 'red'],
    'finance_added' => ['ثبت تراکنش مالی', 'amber'], 'wallet_adjusted' => ['تغییر کیف پول', 'amber'],
    'visit_logged' => ['ثبت تردد', 'green'],
    'service_created' => ['ساخت خدمت', 'accent'], 'service_deactivated' => ['تغییر وضعیت خدمت', 'gray'],
    'reservation_confirmed' => ['تأیید رزرو', 'green'], 'reservation_done' => ['انجام رزرو', 'green'], 'reservation_canceled' => ['لغو رزرو', 'red'],
    'product_created' => ['افزودن محصول', 'accent'], 'product_deactivated' => ['تغییر وضعیت محصول', 'gray'], 'stock_adjusted' => ['تغییر موجودی', 'amber'],
    'order_preparing' => ['آماده‌سازی سفارش', 'amber'], 'order_done' => ['تحویل سفارش', 'green'], 'order_canceled' => ['لغو سفارش', 'red'],
];
$leadStatuses = ['new' => 'جدید', 'contacted' => 'تماس‌خورده', 'consult' => 'مشاوره', 'follow_up' => 'پیگیری', 'won' => 'عضو شد', 'lost' => 'منصرف'];

admin_header($admin, 'داشبورد', 'dashboard');
?>
<?php if ($canUsers): ?>
<div class="grid cols-4">
  <div class="stat accent"><div class="label">کل اعضا</div><div class="value num"><?= fa_num($stats['total_users']) ?></div><div class="hint">ثبت‌نام‌شده در مجموع</div></div>
  <div class="stat green"><div class="label">اعضای فعال</div><div class="value num"><?= fa_num($stats['active_users']) ?></div><div class="hint">قابل ورود به سایت</div></div>
  <div class="stat red"><div class="label">اعضای مسدود</div><div class="value num"><?= fa_num($stats['banned_users']) ?></div><div class="hint">غیرفعال‌شده</div></div>
  <div class="stat amber"><div class="label">ثبت‌نام هفته</div><div class="value num"><?= fa_num($stats['new_users']) ?></div><div class="hint">۷ روز گذشته</div></div>
</div>
<?php endif; ?>

<?php if ($canSubs || $canCrm): ?>
<div class="grid cols-4" style="margin-top:16px">
  <?php if ($canSubs): ?>
  <div class="stat green"><div class="label">اشتراک‌های فعال</div><div class="value num"><?= fa_num($stats['active_subs']) ?></div><div class="hint">عضویت جاری اعضا</div></div>
  <div class="stat amber"><div class="label">در حال انقضا</div><div class="value num"><?= fa_num($stats['expiring_subs']) ?></div><div class="hint">۷ روز آینده — فرصت تمدید</div></div>
  <?php endif; ?>
  <?php if ($canCrm): ?>
  <div class="stat accent"><div class="label">لیدهای جدید</div><div class="value num"><?= fa_num($stats['new_leads']) ?></div><div class="hint">از <?= fa_num($stats['total_leads']) ?> لید ثبت‌شده</div></div>
  <div class="stat"><div class="label">تبدیل به عضو</div><div class="value num"><?= fa_num($stats['won_leads']) ?></div><div class="hint">لیدهای موفق</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canServices || $canStore): ?>
<div class="grid cols-4" style="margin-top:16px">
  <?php if ($canServices): ?>
  <div class="stat amber"><div class="label">رزروهای در انتظار تأیید</div><div class="value num"><?= fa_num($stats['pending_res']) ?></div><div class="hint"><a href="reservations.php?status=pending">بررسی ←</a></div></div>
  <div class="stat"><div class="label">نوبت‌های پیش‌رو</div><div class="value num"><?= fa_num($stats['upcoming_res']) ?></div><div class="hint">تأییدشده</div></div>
  <?php endif; ?>
  <?php if ($canStore): ?>
  <div class="stat accent"><div class="label">سفارش‌های باز</div><div class="value num"><?= fa_num($stats['open_orders']) ?></div><div class="hint"><a href="store.php?tab=orders">پیگیری ←</a></div></div>
  <div class="stat green"><div class="label">فروش فروشگاه و کافه (ماه)</div><div class="value num" style="font-size:19px"><?= fa_num(number_format((float) $stats['shop_income_month'])) ?></div><div class="hint">تومان</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canFinance): ?>
<div class="grid cols-3" style="margin-top:16px">
  <div class="stat green"><div class="label">درآمد این ماه</div><div class="value num" style="font-size:21px"><?= fa_num(number_format((float) $stats['income_month'])) ?></div><div class="hint">تومان</div></div>
  <div class="stat red"><div class="label">هزینه این ماه</div><div class="value num" style="font-size:21px"><?= fa_num(number_format((float) $stats['expense_month'])) ?></div><div class="hint">تومان</div></div>
  <div class="stat <?= $stats['income_month'] - $stats['expense_month'] >= 0 ? 'green' : 'red' ?>"><div class="label">سود این ماه</div><div class="value num" style="font-size:21px"><?= fa_num(number_format((float) ($stats['income_month'] - $stats['expense_month']))) ?></div><div class="hint">تومان</div></div>
</div>
<?php endif; ?>

<div class="grid cols-4" style="margin-top:16px">
  <div class="stat"><div class="label">کارکنان پنل</div><div class="value num"><?= fa_num($stats['total_admins']) ?></div><div class="hint"><a href="admins.php">مدیریت کارکنان</a></div></div>
  <div class="stat"><div class="label">اقدام‌های هفته</div><div class="value num"><?= fa_num($stats['actions_week']) ?></div><div class="hint">۷ روز گذشته</div></div>
  <div class="stat"><div class="label">اعضای دارای داده</div><div class="value num"><?= fa_num($stats['saving_users']) ?></div><div class="hint">ذخیره در اپلیکیشن</div></div>
  <div class="stat"><div class="label">آخرین ورود عضو</div><div class="value" style="font-size:15px;margin-top:9px"><?= jago($stats['last_user_login'] ? (int) $stats['last_user_login'] : null) ?></div></div>
</div>

<div class="grid cols-2" style="margin-top:22px">
  <?php if ($canUsers): ?>
  <div class="card">
    <div class="card-head"><h2>آخرین ثبت‌نام‌ها</h2><a class="sub" href="users.php">همه اعضا ←</a></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>عضو</th><th>وضعیت</th><th>ثبت‌نام</th><th>آخرین ورود</th></tr></thead>
        <tbody>
        <?php if (!$recentUsers): ?>
          <tr><td colspan="4"><div class="empty"><span class="glyph">🏋️</span>هنوز عضوی ثبت‌نام نکرده است.</div></td></tr>
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
  <?php endif; ?>

  <?php if ($canCrm): ?>
  <div class="card">
    <div class="card-head"><h2>آخرین لیدها</h2><a class="sub" href="leads.php">CRM کامل ←</a></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>لید</th><th>وضعیت</th><th>ثبت</th><th>پیگیری</th></tr></thead>
        <tbody>
        <?php if (!$recentLeads): ?>
          <tr><td colspan="4"><div class="empty"><span class="glyph">📝</span>هنوز لیدی ثبت نشده است.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($recentLeads as $l): ?>
          <tr>
            <td><a href="lead_view.php?id=<?= (int) $l['id'] ?>"><strong><?= e($l['full_name']) ?></strong></a></td>
            <td><span class="badge <?= $l['status'] === 'won' ? 'green' : ($l['status'] === 'lost' ? 'red' : 'accent') ?>"><?= e($leadStatuses[$l['status']] ?? $l['status']) ?></span></td>
            <td class="num"><?= jago((int) $l['created_at']) ?></td>
            <td class="num"><?= $l['follow_up_at'] ? jago((int) $l['follow_up_at']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-head"><h2>آخرین فعالیت‌های پنل</h2><a class="sub" href="activity.php">گزارش کامل ←</a></div>
  <div class="card-body tight table-wrap">
    <table>
      <thead><tr><th>اقدام</th><th>مدیر</th><th>موضوع</th><th>زمان</th></tr></thead>
      <tbody>
      <?php if (!$recentActivity): ?>
        <tr><td colspan="4"><div class="empty"><span class="glyph">📋</span>فعالیتی ثبت نشده است.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($recentActivity as $a): ?>
        <?php [$label, $color] = $actionLabels[$a['action']] ?? [$a['action'], 'gray']; ?>
        <tr>
          <td><span class="badge <?= $color ?>"><?= e($label) ?></span></td>
          <td><?= e($a['admin_name']) ?></td>
          <td class="num"><?= $a['entity'] !== '' ? e($a['entity'] === 'user' ? 'کاربر' : ($a['entity'] === 'lead' ? 'لید' : ($a['entity'] === 'admin' ? 'کارمند' : ($a['entity'] === 'role' ? 'نقش' : ($a['entity'] === 'branch' ? 'شعبه' : ($a['entity'] === 'plan' ? 'پلن' : ($a['entity'] === 'subscription' ? 'اشتراک' : $a['entity']))))))) . ' #' . fa_num((string) ($a['entity_id'] ?? '')) : '—' ?></td>
          <td class="num"><?= jago((int) $a['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer();
