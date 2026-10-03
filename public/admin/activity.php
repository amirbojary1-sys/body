<?php
declare(strict_types=1);
/** Admin audit trail (proposal §32). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;

$admin = AdminAuth::requireAdmin('reports.view');
$pdo = Database::connection();

$page = (int) ($_GET['page'] ?? 1);
$perPage = 25;
$total = (int) $pdo->query('SELECT COUNT(*) FROM admin_activity')->fetchColumn();
[$limit, $offset, $pages] = paginate_params($total, $perPage, $page);
$rows = $pdo->query('SELECT admin_name, action, entity, entity_id, details, ip, created_at FROM admin_activity ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();

$labels = [
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
$entities = ['user' => 'کاربر', 'lead' => 'لید', 'admin' => 'کارمند', 'role' => 'نقش', 'branch' => 'شعبه', 'plan' => 'پلن', 'subscription' => 'اشتراک', 'finance' => 'مالی', 'service' => 'خدمت', 'reservation' => 'رزرو', 'product' => 'محصول', 'order' => 'سفارش'];

admin_header($admin, 'گزارش فعالیت', 'activity');
?>
<div class="card">
  <div class="card-head"><h2>گزارش فعالیت مدیران <span class="sub">(<?= fa_num($total) ?> رکورد)</span></h2><span class="sub">ثبت خودکار همه اقدام‌های پنل</span></div>
  <div class="card-body tight table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>کارمند</th><th>اقدام</th><th>موضوع</th><th>جزئیات</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="empty"><span class="glyph">📋</span>هنوز فعالیتی ثبت نشده است.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): [$label, $color] = $labels[$r['action']] ?? [$r['action'], 'gray']; ?>
        <tr>
          <td class="num"><?= jdate((int) $r['created_at']) ?></td>
          <td><strong><?= e($r['admin_name']) ?></strong></td>
          <td><span class="badge <?= $color ?>"><?= e($label) ?></span></td>
          <td class="num"><?= $r['entity'] !== '' ? e($entities[$r['entity']] ?? $r['entity']) . ' #' . fa_num((string) ($r['entity_id'] ?? '')) : '—' ?></td>
          <td style="white-space:normal;max-width:280px"><?= e($r['details'] !== '' ? $r['details'] : '—') ?></td>
          <td class="num" dir="ltr"><?= e($r['ip'] !== '' ? $r['ip'] : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($page, $pages) ?>
</div>
<?php admin_footer();
