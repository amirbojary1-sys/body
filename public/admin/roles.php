<?php
declare(strict_types=1);
/** Role & permission management (RBAC — proposal §2). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Database;
use FitBot\Rbac;

$admin = AdminAuth::requireAdmin('roles.view');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'roles.manage')) {
        AdminAuth::flash('error', 'برای ویرایش نقش‌ها دسترسی نداری.');
        admin_redirect('roles.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        try {
            $id = Rbac::createRole($_POST['title'] ?? '', $_POST['slug'] ?? '', array_keys($_POST['perms'] ?? []));
            AdminAuth::log('role_created', 'role', $id, trim((string) ($_POST['title'] ?? '')));
            AdminAuth::flash('success', 'نقش جدید ساخته شد.');
        } catch (ApiException $e) {
            AdminAuth::flash('error', $e->getMessage());
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['role_id'] ?? 0);
        $role = Rbac::role($id);
        if (!$role) AdminAuth::flash('error', 'نقش پیدا نشد.');
        elseif ($role['slug'] === 'ceo') AdminAuth::flash('error', 'نقش «مدیرعامل» همیشه به همه بخش‌ها دسترسی دارد و قابل محدودکردن نیست.');
        else {
            Rbac::renameRole($id, (string) ($_POST['title'] ?? $role['title']));
            Rbac::syncPermissions($id, array_keys($_POST['perms'] ?? []));
            AdminAuth::log('role_updated', 'role', $id, $role['title']);
            AdminAuth::flash('success', 'دسترسی‌های نقش «' . $role['title'] . '» ذخیره شد.');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['role_id'] ?? 0);
        try {
            $role = Rbac::role($id);
            Rbac::deleteRole($id);
            AdminAuth::log('role_deleted', 'role', $id, $role['title'] ?? '');
            AdminAuth::flash('success', 'نقش حذف شد.');
        } catch (ApiException $e) {
            AdminAuth::flash('error', $e->getMessage());
        }
    }
    admin_redirect('roles.php');
}

$editRole = isset($_GET['edit']) ? Rbac::role((int) $_GET['edit']) : null;
$roles = Rbac::roles();
$catalog = Rbac::catalog();

admin_header($admin, 'نقش‌ها و دسترسی‌ها', 'roles');
?>
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>نقش‌های سیستم <span class="sub">(<?= fa_num(count($roles)) ?> نقش)</span></h2></div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>نقش</th><th>شناسه</th><th>نوع</th><th>دسترسی‌ها</th><th>کارکنان</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): $isCeo = $r['slug'] === 'ceo'; ?>
          <tr>
            <td><strong><?= e($r['title']) ?></strong><?= $isCeo ? ' <span class="badge accent">همه دسترسی‌ها</span>' : '' ?></td>
            <td class="num" dir="ltr"><?= e($r['slug']) ?></td>
            <td><?= (int) $r['is_system'] === 1 ? '<span class="badge gray">سیستمی</span>' : '<span class="badge green">سفارشی</span>' ?></td>
            <td class="num"><?= fa_num((string) $r['perms']) ?> مورد</td>
            <td class="num"><?= fa_num((string) $r['admins']) ?> نفر</td>
            <td>
              <div style="display:flex;gap:6px">
                <?php if (!$isCeo): ?><a class="btn small" href="roles.php?edit=<?= (int) $r['id'] ?>">ویرایش دسترسی</a><?php endif; ?>
                <?php if ((int) $r['is_system'] !== 1 && (int) $r['admins'] === 0): ?>
                <form method="post" data-confirm="نقش «<?= e($r['title']) ?>» حذف شود؟">
                  <?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="role_id" value="<?= (int) $r['id'] ?>">
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
    <?php if ($editRole): ?>
    <div class="card-head"><h2>ویرایش نقش: <?= e($editRole['title']) ?></h2><a class="sub" href="roles.php">انصراف ←</a></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="role_id" value="<?= (int) $editRole['id'] ?>">
        <div class="field"><label for="title">عنوان نقش</label><input id="title" name="title" required maxlength="80" value="<?= e($editRole['title']) ?>"></div>
        <?php foreach ($catalog as $group): ?>
        <div class="perm-group">
          <h3><?= e($group['title']) ?></h3>
          <?php foreach ($group['perms'] as $code => $label): ?>
          <label><input type="checkbox" name="perms[<?= e($code) ?>]" value="1" <?= in_array($code, $editRole['permissions'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">ذخیره دسترسی‌ها</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card-head"><h2>ساخت نقش جدید</h2></div>
    <div class="card-body">
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="title">عنوان نقش</label><input id="title" name="title" required maxlength="80" placeholder="مثلاً: نوبت‌دهیدار"></div>
          <div class="field"><label for="slug">شناسه (لاتین)</label><input id="slug" name="slug" maxlength="50" dir="ltr" placeholder="receptionist"><small>اگر خالی بگذاری خودکار ساخته می‌شود.</small></div>
        </div>
        <?php foreach ($catalog as $group): ?>
        <div class="perm-group">
          <h3><?= e($group['title']) ?></h3>
          <?php foreach ($group['perms'] as $code => $label): ?>
          <label><input type="checkbox" name="perms[<?= e($code) ?>]" value="1"><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">ساخت نقش</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<p class="auth-note" style="text-align:right">نقش «مدیرعامل» همیشه به همه بخش‌ها دسترسی دارد. نقش‌های سیستمی قابل حذف نیستند اما دسترسی‌هایشان قابل محدودکردن است؛ نقش‌های سفارشی از صفر قابل ساخت هستند. دسترسی هر کارمند از صفحه «کارکنان» تعیین می‌شود.</p>
<?php admin_footer();
