<?php
declare(strict_types=1);
/** Multi-branch management (proposal §29). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('branches.view');
$pdo = Database::connection();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'branches.manage')) {
        AdminAuth::flash('error', 'برای مدیریت شعب دسترسی نداری.');
        admin_redirect('branches.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim(Http::text($_POST['name'] ?? '', 100));
        $code = strtoupper(trim(Http::text($_POST['code'] ?? '', 20)));
        if (mb_strlen($name) < 2 || !preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
            AdminAuth::flash('error', 'نام شعبه و کد (۲ تا ۲۰ نویسه لاتین/عدد) لازم است.');
        } else {
            try {
                $pdo->prepare('INSERT INTO branches(name, code, address, phone, is_active, created_at) VALUES(?, ?, ?, ?, 1, ?)')
                    ->execute([$name, $code, trim(Http::text($_POST['address'] ?? '', 255)), trim(Http::text($_POST['phone'] ?? '', 30)), Http::now()]);
                AdminAuth::log('branch_created', 'branch', (int) $pdo->lastInsertId(), $name);
                AdminAuth::flash('success', 'شعبه «' . $name . '» ساخته شد.');
            } catch (\PDOException $e) {
                AdminAuth::flash('error', (string) $e->getCode() === '23000' ? 'این کد شعبه قبلاً استفاده شده است.' : 'ساخت شعبه ممکن نشد.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['branch_id'] ?? 0);
        $name = trim(Http::text($_POST['name'] ?? '', 100));
        if ($id > 0 && mb_strlen($name) >= 2) {
            $pdo->prepare('UPDATE branches SET name = ?, address = ?, phone = ? WHERE id = ?')
                ->execute([$name, trim(Http::text($_POST['address'] ?? '', 255)), trim(Http::text($_POST['phone'] ?? '', 30)), $id]);
            AdminAuth::log('branch_updated', 'branch', $id, $name);
            AdminAuth::flash('success', 'اطلاعات شعبه به‌روزرسانی شد.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['branch_id'] ?? 0);
        $pdo->prepare('UPDATE branches SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        AdminAuth::log('branch_deactivated', 'branch', $id);
        AdminAuth::flash('success', 'وضعیت شعبه تغییر کرد.');
    }
    admin_redirect('branches.php');
}

$editBranch = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editBranch = $stmt->fetch() ?: null;
}
$branches = $pdo->query(
    'SELECT b.*,
        (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS members,
        (SELECT COUNT(*) FROM subscriptions s WHERE s.branch_id = b.id AND s.status = \'active\') AS subs,
        (SELECT COUNT(*) FROM leads l WHERE l.branch_id = b.id) AS leads
     FROM branches b ORDER BY b.id ASC'
)->fetchAll();

admin_header($admin, 'شعب', 'branches');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>شعب مجموعه <span class="sub">(<?= fa_num(count($branches)) ?> شعبه)</span></h2></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>شعبه</th><th>کد</th><th>اعضا</th><th>اشتراک فعال</th><th>لیدها</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): ?>
          <tr>
            <td><strong><?= e($b['name']) ?></strong><span class="sub"><?= e($b['address'] !== '' ? $b['address'] : '—') ?></span></td>
            <td class="num" dir="ltr"><?= e($b['code']) ?></td>
            <td class="num"><?= fa_num((string) $b['members']) ?></td>
            <td class="num"><?= fa_num((string) $b['subs']) ?></td>
            <td class="num"><?= fa_num((string) $b['leads']) ?></td>
            <td><?= (int) $b['is_active'] === 1 ? '<span class="badge green">فعال</span>' : '<span class="badge gray">غیرفعال</span>' ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <a class="btn small" href="branches.php?edit=<?= (int) $b['id'] ?>">ویرایش</a>
                <?php if (AdminAuth::can($admin, 'branches.manage')): ?>
                <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>"><button class="btn small <?= (int) $b['is_active'] === 1 ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= (int) $b['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?></button></form>
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
    <?php if ($editBranch): ?>
    <div class="card-head"><h2>ویرایش شعبه: <?= e($editBranch['name']) ?></h2><a class="sub" href="branches.php">انصراف ←</a></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="branch_id" value="<?= (int) $editBranch['id'] ?>">
        <div class="field"><label for="name">نام شعبه</label><input id="name" name="name" required maxlength="100" value="<?= e($editBranch['name']) ?>"></div>
        <div class="field"><label for="address">نشانی</label><input id="address" name="address" maxlength="255" value="<?= e($editBranch['address']) ?>"></div>
        <div class="field"><label for="phone">تلفن</label><input id="phone" name="phone" maxlength="30" dir="ltr" value="<?= e($editBranch['phone']) ?>"></div>
        <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card-head"><h2>افزودن شعبه جدید</h2></div>
    <div class="card-body">
      <?php if (!AdminAuth::can($admin, 'branches.manage')): ?>
        <div class="empty"><span class="glyph">🔐</span>مشاهده‌ای؛ برای افزودن شعبه به دسترسی «مدیریت شعب» نیاز داری.</div>
      <?php else: ?>
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="field"><label for="name">نام شعبه</label><input id="name" name="name" required maxlength="100" placeholder="مثلاً: شعبه سعادت‌آباد"></div>
        <div class="field"><label for="code">کد شعبه</label><input id="code" name="code" required maxlength="20" dir="ltr" placeholder="SAADAT"><small>۲ تا ۲۰ نویسه لاتین یا عدد؛ یکتا.</small></div>
        <div class="field"><label for="address">نشانی</label><input id="address" name="address" maxlength="255"></div>
        <div class="field"><label for="phone">تلفن</label><input id="phone" name="phone" maxlength="30" dir="ltr"></div>
        <button class="btn btn-primary" type="submit">ساخت شعبه</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<p class="auth-note" style="text-align:right">هر شعبه می‌تواند کاربران، پلن‌ها، لیدها و تراکنش‌های خود را داشته باشد؛ گزارش تجمیعی و انتقال عضو بین شعب در فازهای بعدی فعال می‌شود.</p>
<?php admin_footer();
