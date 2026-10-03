<?php
declare(strict_types=1);
/** Service reservations management (proposal §7). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Database;
use FitBot\Http;
use FitBot\Ledger;

$admin = AdminAuth::requireAdmin('services.view');
$pdo = Database::connection();
$now = Http::now();

const SERVICE_CATEGORIES = [
    'gym' => 'باشگاه', 'coach' => 'مربی خصوصی', 'class' => 'کلاس گروهی', 'massage' => 'ماساژ',
    'salon' => 'آرایشگاه', 'cafe' => 'کافه', 'parking' => 'پارکینگ', 'locker' => 'کمد',
    'physio' => 'فیزیوتراپی', 'med' => 'پزشکی ورزشی', 'pool' => 'استخر', 'slimming' => 'دستگاه لاغری', 'other' => 'سایر',
];
const RES_STATUSES = ['pending' => 'در انتظار تأیید', 'confirmed' => 'تأییدشده', 'done' => 'انجام‌شده', 'canceled' => 'لغوشده'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'services.manage')) {
        AdminAuth::flash('error', 'برای مدیریت رزروها دسترسی نداری.');
        admin_redirect('reservations.php');
    }
    $id = (int) ($_POST['reservation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $status = $action === 'confirm' ? 'confirmed' : ($action === 'complete' ? 'done' : ($action === 'cancel' ? 'canceled' : ''));
    if ($status !== '') {
        try {
            Ledger::setReservationStatus($id, $status, $admin['id']);
            AdminAuth::log('reservation_' . ($status === 'done' ? 'done' : ($status === 'canceled' ? 'canceled' : 'confirmed')), 'reservation', $id, RES_STATUSES[$status]);
            AdminAuth::flash('success', 'وضعیت رزرو به «' . RES_STATUSES[$status] . '» تغییر کرد' . ($status === 'done' ? ' و درآمد آن در مالی ثبت شد.' : '.'));
        } catch (ApiException $e) {
            AdminAuth::flash('error', $e->getMessage());
        }
    }
    admin_redirect('reservations.php' . (($_POST['filter'] ?? '') !== '' ? '?status=' . urlencode((string) $_POST['filter']) : ''));
}

$filter = array_key_exists($_GET['status'] ?? '', RES_STATUSES) ? $_GET['status'] : '';
$where = ' WHERE 1=1';
$args = [];
if ($filter !== '') { $where = ' WHERE r.status = ?'; $args[] = $filter; }
$stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations r' . $where);
$stmt->execute($args);
$total = (int) $stmt->fetchColumn();
$page = (int) ($_GET['page'] ?? 1);
[$limit, $offset, $pages] = paginate_params($total, 20, $page);
$stmt = $pdo->prepare('SELECT r.*, u.name AS user_name, u.email AS user_email, s.name AS service_name, s.category AS service_cat
    FROM reservations r JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id' . $where . '
    ORDER BY r.reserved_at ASC LIMIT ' . $limit . ' OFFSET ' . $offset);
$stmt->execute($args);
$rows = $stmt->fetchAll();

admin_header($admin, 'رزروهای خدمات', 'services');
?>
<div class="card">
  <div class="card-head">
    <h2>رزروها <span class="sub">(<?= fa_num($total) ?> مورد)</span></h2>
    <div class="searchbar">
      <a class="btn small <?= $filter === '' ? 'btn-soft' : '' ?>" href="reservations.php">همه</a>
      <?php foreach (RES_STATUSES as $key => $label): ?>
      <a class="btn small <?= $filter === $key ? 'btn-soft' : '' ?>" href="reservations.php?status=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body tight table-wrap">
    <table>
      <thead><tr><th>عضو</th><th>خدمت</th><th>زمان نوبت</th><th>قیمت (تومان)</th><th>وضعیت</th><th>ثبت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><span class="glyph">📅</span>رزروی در این وضعیت نیست.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $upcoming = in_array($r['status'], ['pending', 'confirmed'], true); ?>
        <tr>
          <td><a href="user_view.php?id=<?= (int) $r['user_id'] ?>"><strong><?= e($r['user_name']) ?></strong></a><span class="sub" dir="ltr"><?= e($r['user_email']) ?></span></td>
          <td><?= e($r['service_name']) ?><span class="sub"><?= e(SERVICE_CATEGORIES[$r['service_cat']] ?? '') ?></span></td>
          <td class="num"><?= jdate((int) $r['reserved_at']) ?></td>
          <td class="num"><?= fa_num(number_format((float) $r['price'])) ?></td>
          <td><span class="badge <?= $r['status'] === 'done' ? 'green' : ($r['status'] === 'canceled' ? 'red' : ($r['status'] === 'pending' ? 'amber' : 'accent')) ?>"><?= e(RES_STATUSES[$r['status']] ?? $r['status']) ?></span></td>
          <td class="num"><?= jago((int) $r['created_at']) ?></td>
          <td>
            <?php if ($upcoming && AdminAuth::can($admin, 'services.manage')): ?>
            <div style="display:flex;gap:6px">
              <?php if ($r['status'] === 'pending'): ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="btn small btn-soft" type="submit">تأیید</button></form>
              <?php endif; ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="btn small" type="submit">انجام شد</button></form>
              <form method="post" data-confirm="این رزرو لغو شود؟"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="btn small btn-danger-ghost" type="submit">لغو</button></form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($page, $pages, $filter !== '' ? 'status=' . urlencode($filter) : '') ?>
</div>
<?php admin_footer();
