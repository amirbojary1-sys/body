<?php
declare(strict_types=1);
/** Membership plans & member subscriptions (proposal §5). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('memberships.view');
$pdo = Database::connection();
$now = Http::now();

const PLAN_KINDS = [
    'monthly' => 'ماهانه', 'session' => 'جلسه‌ای', 'time' => 'زمانی', 'vip' => 'VIP',
    'honorary' => 'افتخاری', 'coach' => 'خدمات مربی', 'combo' => 'پکیج ترکیبی',
    'app' => 'اشتراک اپلیکیشن', 'other' => 'سایر',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'memberships.manage')) {
        AdminAuth::flash('error', 'برای مدیریت عضویت‌ها دسترسی نداری.');
        admin_redirect('memberships.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'plan_create') {
        $name = trim(Http::text($_POST['name'] ?? '', 100));
        $kind = array_key_exists($_POST['kind'] ?? '', PLAN_KINDS) ? $_POST['kind'] : 'other';
        $price = max(0, (int) ($_POST['price'] ?? 0));
        $days = min(3650, max(0, (int) ($_POST['duration_days'] ?? 30)));
        $sessions = min(1000, max(0, (int) ($_POST['total_sessions'] ?? 0)));
        $branch = (int) ($_POST['branch_id'] ?? 0) ?: null;
        if (mb_strlen($name) < 2) {
            AdminAuth::flash('error', 'نام پلن لازم است.');
        } else {
            $pdo->prepare('INSERT INTO membership_plans(branch_id, name, kind, price, duration_days, total_sessions, is_active, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$branch, $name, $kind, $price, $days, $sessions, $now]);
            AdminAuth::log('plan_created', 'plan', (int) $pdo->lastInsertId(), $name);
            AdminAuth::flash('success', 'پلن «' . $name . '» ساخته شد.');
        }
    } elseif ($action === 'plan_toggle') {
        $pdo->prepare('UPDATE membership_plans SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($_POST['plan_id'] ?? 0)]);
        AdminAuth::log('plan_deactivated', 'plan', (int) ($_POST['plan_id'] ?? 0));
        AdminAuth::flash('success', 'وضعیت پلن تغییر کرد.');
    } elseif ($action === 'plan_delete') {
        $id = (int) ($_POST['plan_id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM membership_plans WHERE id = ?')->execute([$id]);
            AdminAuth::log('plan_updated', 'plan', $id, 'حذف پلن');
            AdminAuth::flash('success', 'پلن حذف شد.');
        } catch (\PDOException $e) {
            AdminAuth::flash('error', (string) $e->getCode() === '23000' ? 'این پلن برای اعضا ثبت شده و قابل حذف نیست؛ غیرفعالش کن.' : 'حذف پلن ممکن نشد.');
        }
    } elseif ($action === 'sub_create') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT p.*, u.id AS uid FROM membership_plans p JOIN users u ON u.id = ? WHERE p.id = ?');
        $stmt->execute([$userId, $planId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            AdminAuth::flash('error', 'عضو یا پلن معتبر نیست.');
        } else {
            $expires = $plan['duration_days'] > 0 ? $now + $plan['duration_days'] * 86_400_000 : null;
            $pdo->prepare('INSERT INTO subscriptions(user_id, plan_id, branch_id, starts_at, expires_at, sessions_total, sessions_used, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, 0, \'active\', ?, ?, ?, ?)')
                ->execute([$userId, $planId, $plan['branch_id'], $now, $expires, $plan['total_sessions'], $plan['price'], trim(Http::text($_POST['note'] ?? '', 300)), $admin['id'], $now]);
            $subId = (int) $pdo->lastInsertId();
            // Finance hook: membership sale is booked as income automatically (§22).
            if ($plan['price'] > 0) {
                $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(?, \'income\', \'membership\', ?, ?, ?, ?)')
                    ->execute([$plan['branch_id'], $plan['price'], 'فروش پلن «' . $plan['name'] . '» (اشتراک #' . $subId . ')', $admin['id'], $now]);
            }
            AdminAuth::log('subscription_created', 'subscription', $subId, 'پلن: ' . $plan['name']);
            AdminAuth::flash('success', 'اشتراک با موفقیت ثبت شد' . ($plan['price'] > 0 ? ' و درآمد آن در بخش مالی ثبت گردید.' : '.'));
        }
    } elseif ($action === 'sub_cancel') {
        $pdo->prepare('UPDATE subscriptions SET status = \'canceled\' WHERE id = ? AND status = \'active\'')->execute([(int) ($_POST['sub_id'] ?? 0)]);
        AdminAuth::log('subscription_canceled', 'subscription', (int) ($_POST['sub_id'] ?? 0));
        AdminAuth::flash('success', 'اشتراک لغو شد.');
    } elseif ($action === 'sub_session') {
        $pdo->prepare('UPDATE subscriptions SET sessions_used = sessions_used + 1 WHERE id = ? AND status = \'active\' AND sessions_total > 0')->execute([(int) ($_POST['sub_id'] ?? 0)]);
        AdminAuth::flash('success', 'یک جلسه از اشتراک کم شد.');
    }
    admin_redirect('memberships.php' . (($_POST['tab'] ?? '') === 'subs' ? '?tab=subs' : ''));
}

$tab = ($_GET['tab'] ?? '') === 'subs' ? 'subs' : 'plans';
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();
$plans = $pdo->query('SELECT p.*, b.name AS branch_name,
    (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status = \'active\') AS active_subs
    FROM membership_plans p LEFT JOIN branches b ON b.id = p.branch_id ORDER BY p.is_active DESC, p.id DESC')->fetchAll();

$subFilter = ($_GET['filter'] ?? '') === 'expired' ? 'expired' : '';
$where = ' WHERE 1=1';
if ($subFilter === 'expired') $where .= ' AND s.status = \'active\' AND s.expires_at IS NOT NULL AND s.expires_at < ' . $now;
else $where .= ' AND s.status = \'active\'';
$total = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions s' . $where)->fetchColumn();
$page = (int) ($_GET['page'] ?? 1);
[$limit, $offset, $pages] = paginate_params($total, 15, $page);
$subs = $pdo->query('SELECT s.*, u.name AS user_name, u.email AS user_email, p.name AS plan_name, p.kind AS plan_kind
    FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN membership_plans p ON p.id = s.plan_id' . $where . '
    ORDER BY s.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();

$activePlans = $pdo->query('SELECT id, name, total_sessions FROM membership_plans WHERE is_active = 1 ORDER BY name')->fetchAll();
$recentUsers = $pdo->query('SELECT id, name, email FROM users ORDER BY created_at DESC LIMIT 300')->fetchAll();

admin_header($admin, 'عضویت‌ها', 'memberships');
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-head">
    <h2>
      <a href="memberships.php" style="color:inherit;text-decoration:none <?= $tab === 'plans' ? ';color:var(--accent)' : '' ?>">پلن‌ها</a>
      <span style="color:var(--muted)"> · </span>
      <a href="memberships.php?tab=subs" style="color:inherit;text-decoration:none <?= $tab === 'subs' ? ';color:var(--accent)' : '' ?>">اشتراک اعضا</a>
    </h2>
    <span class="sub"><?= $tab === 'plans' ? 'انواع عضویت و پکیج قابل فروش' : 'اشتراک‌های فعال اعضا' ?></span>
  </div>

<?php if ($tab === 'plans'): ?>
  <div class="grid cols-2" style="padding:20px;align-items:start">
    <div class="table-wrap">
      <table>
        <thead><tr><th>پلن</th><th>نوع</th><th>قیمت (تومان)</th><th>مدت</th><th>جلسات</th><th>فعال</th><th></th></tr></thead>
        <tbody>
        <?php if (!$plans): ?><tr><td colspan="7"><div class="empty"><span class="glyph">🎫</span>هنوز پلنی ساخته نشده است.</div></td></tr><?php endif; ?>
        <?php foreach ($plans as $p): ?>
          <tr>
            <td><strong><?= e($p['name']) ?></strong><span class="sub"><?= e($p['branch_name'] ?? 'همه شعب') ?> · <?= fa_num((string) $p['active_subs']) ?> اشتراک فعال</span></td>
            <td><span class="badge <?= $p['kind'] === 'app' ? 'accent' : 'gray' ?>"><?= e(PLAN_KINDS[$p['kind']] ?? $p['kind']) ?></span></td>
            <td class="num"><?= fa_num(number_format((float) $p['price'])) ?></td>
            <td class="num"><?= $p['duration_days'] > 0 ? fa_num((string) $p['duration_days']) . ' روز' : '—' ?></td>
            <td class="num"><?= $p['total_sessions'] > 0 ? fa_num((string) $p['total_sessions']) : 'نامحدود' ?></td>
            <td><?= (int) $p['is_active'] === 1 ? '<span class="badge green">فعال</span>' : '<span class="badge gray">غیرفعال</span>' ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="plan_toggle"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn small <?= (int) $p['is_active'] === 1 ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= (int) $p['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?></button></form>
                <form method="post" data-confirm="پلن حذف شود؟"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="plan_delete"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn small btn-danger" type="submit">حذف</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card" style="box-shadow:none">
      <div class="card-head"><h2>ساخت پلن جدید</h2></div>
      <div class="card-body">
        <?php if (!AdminAuth::can($admin, 'memberships.manage')): ?>
          <div class="empty"><span class="glyph">🔐</span>دسترسی ساخت پلن نداری.</div>
        <?php else: ?>
        <form method="post">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="plan_create">
          <div class="form-grid">
            <div class="field"><label for="name">نام پلن</label><input id="name" name="name" required maxlength="100" placeholder="مثلاً: سه‌ماهه صبح"></div>
            <div class="field"><label for="kind">نوع عضویت</label>
              <select id="kind" name="kind"><?php foreach (PLAN_KINDS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label for="price">قیمت (تومان)</label><input id="price" name="price" type="number" min="0" max="999999999" value="0" dir="ltr"></div>
            <div class="field"><label for="duration_days">مدت (روز)</label><input id="duration_days" name="duration_days" type="number" min="0" max="3650" value="30" dir="ltr"><small>۰ = بدون انقضا</small></div>
            <div class="field"><label for="total_sessions">تعداد جلسات</label><input id="total_sessions" name="total_sessions" type="number" min="0" max="1000" value="0" dir="ltr"><small>۰ = نامحدود (ماهانه)</small></div>
            <div class="field"><label for="branch_id">شعبه</label>
              <select id="branch_id" name="branch_id"><option value="">همه شعب</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <button class="btn btn-primary" type="submit">ساخت پلن</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="grid cols-2" style="padding:20px;align-items:start">
    <div>
      <div class="searchbar" style="margin-bottom:12px">
        <a class="btn small <?= $subFilter === '' ? 'btn-soft' : '' ?>" href="memberships.php?tab=subs">فعال</a>
        <a class="btn small <?= $subFilter === 'expired' ? 'btn-soft' : '' ?>" href="memberships.php?tab=subs&filter=expired">منقضی‌شده</a>
        <span class="sub" style="margin-inline-start:auto"><?= fa_num($total) ?> اشتراک</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>عضو</th><th>پلن</th><th>اعتبار</th><th>جلسات</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          <?php if (!$subs): ?><tr><td colspan="6"><div class="empty"><span class="glyph">🎫</span>اشتراکی در این وضعیت نیست.</div></td></tr><?php endif; ?>
          <?php foreach ($subs as $s): $expired = $s['expires_at'] !== null && (int) $s['expires_at'] < $now; ?>
            <tr>
              <td><a href="user_view.php?id=<?= (int) $s['user_id'] ?>"><strong><?= e($s['user_name']) ?></strong></a><span class="sub" dir="ltr"><?= e($s['user_email']) ?></span></td>
              <td><?= e($s['plan_name']) ?><span class="sub"><?= e(PLAN_KINDS[$s['plan_kind']] ?? '') ?></span></td>
              <td class="num"><?= $s['expires_at'] ? jdate((int) $s['expires_at']) : 'بدون انقضا' ?></td>
              <td class="num"><?= $s['sessions_total'] > 0 ? fa_num((string) $s['sessions_used']) . ' / ' . fa_num((string) $s['sessions_total']) : 'نامحدود' ?></td>
              <td><?= $expired ? '<span class="badge red">منقضی</span>' : '<span class="badge green">فعال</span>' ?></td>
              <td>
                <div style="display:flex;gap:6px">
                  <?php if (!$expired): ?>
                  <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="sub_session"><input type="hidden" name="sub_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="tab" value="subs"><button class="btn small" type="submit" title="ثبت مصرف یک جلسه">+جلسه</button></form>
                  <form method="post" data-confirm="این اشتراک لغو شود؟"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="sub_cancel"><input type="hidden" name="sub_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="tab" value="subs"><button class="btn small btn-danger-ghost" type="submit">لغو</button></form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination_links($page, $pages, 'tab=subs' . ($subFilter !== '' ? '&filter=expired' : '')) ?>
    </div>
    <div class="card" style="box-shadow:none">
      <div class="card-head"><h2>ثبت اشتراک برای عضو</h2></div>
      <div class="card-body">
        <?php if (!AdminAuth::can($admin, 'memberships.manage')): ?>
          <div class="empty"><span class="glyph">🔐</span>دسترسی ثبت اشتراک نداری.</div>
        <?php else: ?>
        <form method="post">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="sub_create">
          <input type="hidden" name="tab" value="subs">
          <div class="field"><label for="user_id">عضو</label>
            <select id="user_id" name="user_id" required>
              <option value="">— انتخاب عضو —</option>
              <?php foreach ($recentUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option><?php endforeach; ?>
            </select>
            <small>آخرین ۳۰۰ عضو ثبت‌شده؛ برای اعضای قدیمی‌تر از صفحه کاربران وارد شو.</small>
          </div>
          <div class="field"><label for="plan_id">پلن</label>
            <select id="plan_id" name="plan_id" required>
              <option value="">— انتخاب پلن —</option>
              <?php foreach ($activePlans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="note">یادداشت (اختیاری)</label><input id="note" name="note" maxlength="300"></div>
          <button class="btn btn-primary" type="submit">ثبت اشتراک</button>
          <p class="auth-note" style="text-align:right">با ثبت اشتراک پولی، درآمد آن به‌صورت خودکار در بخش مالی (دسته «عضویت») ثبت می‌شود.</p>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>
<?php admin_footer();
