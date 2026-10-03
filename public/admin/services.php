<?php
declare(strict_types=1);
/** Service catalog management (proposal §7). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('services.view');
$pdo = Database::connection();
$now = Http::now();

const SERVICE_CATEGORIES = [
    'gym' => 'باشگاه', 'coach' => 'مربی خصوصی', 'class' => 'کلاس گروهی', 'massage' => 'ماساژ',
    'salon' => 'آرایشگاه', 'cafe' => 'کافه', 'parking' => 'پارکینگ', 'locker' => 'کمد',
    'physio' => 'فیزیوتراپی', 'med' => 'پزشکی ورزشی', 'pool' => 'استخر', 'slimming' => 'دستگاه لاغری', 'other' => 'سایر',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'services.manage')) {
        AdminAuth::flash('error', 'برای مدیریت خدمات دسترسی نداری.');
        admin_redirect('services.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim(Http::text($_POST['name'] ?? '', 100));
        $category = array_key_exists($_POST['category'] ?? '', SERVICE_CATEGORIES) ? $_POST['category'] : 'other';
        $price = max(0, (int) ($_POST['price'] ?? 0));
        $minutes = min(600, max(5, (int) ($_POST['duration_minutes'] ?? 60)));
        $capacity = min(200, max(1, (int) ($_POST['capacity'] ?? 1)));
        $branch = (int) ($_POST['branch_id'] ?? 0) ?: null;
        if (mb_strlen($name) < 2) {
            AdminAuth::flash('error', 'نام خدمت لازم است.');
        } else {
            $pdo->prepare('INSERT INTO services(branch_id, name, category, duration_minutes, price, capacity, is_active, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$branch, $name, $category, $minutes, $price, $capacity, $now]);
            AdminAuth::log('service_created', 'service', (int) $pdo->lastInsertId(), $name);
            AdminAuth::flash('success', 'خدمت «' . $name . '» اضافه شد.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($_POST['service_id'] ?? 0)]);
        AdminAuth::log('service_deactivated', 'service', (int) ($_POST['service_id'] ?? 0));
        AdminAuth::flash('success', 'وضعیت خدمت تغییر کرد.');
    }
    admin_redirect('services.php');
}

$services = $pdo->query('SELECT s.*, b.name AS branch_name,
    (SELECT COUNT(*) FROM reservations r WHERE r.service_id = s.id AND r.status IN (\'pending\', \'confirmed\')) AS upcoming,
    (SELECT COUNT(*) FROM reservations r WHERE r.service_id = s.id AND r.status = \'done\') AS completed
    FROM services s LEFT JOIN branches b ON b.id = s.branch_id ORDER BY s.is_active DESC, s.id')->fetchAll();
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();

admin_header($admin, 'خدمات و رزرو', 'services');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>خدمات مجموعه <span class="sub">(<?= fa_num(count($services)) ?> خدمت)</span></h2><a class="sub" href="reservations.php">مدیریت رزروها ←</a></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>خدمت</th><th>دسته</th><th>قیمت (تومان)</th><th>مدت</th><th>ظرفیت</th><th>رزرو</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php if (!$services): ?><tr><td colspan="8"><div class="empty"><span class="glyph">🧖</span>هنوز خدمتی ثبت نشده است.</div></td></tr><?php endif; ?>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><strong><?= e($s['name']) ?></strong><span class="sub"><?= e($s['branch_name'] ?? 'همه شعب') ?></span></td>
            <td><span class="badge gray"><?= e(SERVICE_CATEGORIES[$s['category']] ?? $s['category']) ?></span></td>
            <td class="num"><?= fa_num(number_format((float) $s['price'])) ?></td>
            <td class="num"><?= fa_num((string) $s['duration_minutes']) ?> دقیقه</td>
            <td class="num"><?= fa_num((string) $s['capacity']) ?> نفر</td>
            <td class="num"><span class="badge accent"><?= fa_num((string) $s['upcoming']) ?> در راه</span> <span class="badge green"><?= fa_num((string) $s['completed']) ?> انجام</span></td>
            <td><?= (int) $s['is_active'] === 1 ? '<span class="badge green">فعال</span>' : '<span class="badge gray">غیرفعال</span>' ?></td>
            <td>
              <?php if (AdminAuth::can($admin, 'services.manage')): ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>"><button class="btn small <?= (int) $s['is_active'] === 1 ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= (int) $s['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>افزودن خدمت جدید</h2></div>
    <div class="card-body">
      <?php if (!AdminAuth::can($admin, 'services.manage')): ?>
        <div class="empty"><span class="glyph">🔐</span>دسترسی افزودن خدمت نداری.</div>
      <?php else: ?>
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="name">نام خدمت</label><input id="name" name="name" required maxlength="100" placeholder="مثلاً: ماساژ با سنگ داغ"></div>
          <div class="field"><label for="category">دسته</label>
            <select id="category" name="category"><?php foreach (SERVICE_CATEGORIES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label for="price">قیمت (تومان)</label><input id="price" name="price" type="number" min="0" max="999999999" value="0" dir="ltr"></div>
          <div class="field"><label for="duration_minutes">مدت (دقیقه)</label><input id="duration_minutes" name="duration_minutes" type="number" min="5" max="600" value="60" dir="ltr"></div>
          <div class="field"><label for="capacity">ظرفیت هر نوبت</label><input id="capacity" name="capacity" type="number" min="1" max="200" value="1" dir="ltr"></div>
          <div class="field"><label for="branch_id">شعبه</label>
            <select id="branch_id" name="branch_id"><option value="">همه شعب</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <button class="btn btn-primary" type="submit">افزودن خدمت</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<p class="auth-note" style="text-align:right">چرخه هر خدمت: درخواست (از پنل عضو یا پذیرش) ← تأیید ← ارائه ← ثبت انجام (درآمد خودکار در مالی) ← پرداخت. نظرسنجی در فاز بعدی اضافه می‌شود.</p>
<?php admin_footer();
