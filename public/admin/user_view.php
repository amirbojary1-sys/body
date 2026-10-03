<?php
declare(strict_types=1);
/** Read-oriented member profile: account, state summary, memberships, wallet, visits (§6, §4, §5). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('users.view');
$pdo = Database::connection();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0) admin_redirect('users.php');
$now = Http::now();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['ban', 'unban', 'delete', 'visit_log'], true) && !AdminAuth::can($admin, 'users.manage')) {
        AdminAuth::flash('error', 'برای این عملیات دسترسی نداری.');
        admin_redirect('user_view.php?id=' . $id);
    }
    if ($action === 'ban' || $action === 'unban' || $action === 'delete') {
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
    } elseif ($action === 'wallet_adjust' && AdminAuth::can($admin, 'finance.manage')) {
        $amount = (int) ($_POST['amount'] ?? 0);
        $note = trim(Http::text($_POST['note'] ?? '', 300));
        if ($amount !== 0 && abs($amount) <= 100_000_000) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    $pdo->prepare('INSERT INTO wallets(user_id, balance, updated_at) VALUES(?, 0, ?)')->execute([$id, $now]);
                    $balance = 0;
                } else {
                    $balance = (int) $row['balance'];
                }
                $newBalance = $balance + $amount;
                $pdo->prepare('UPDATE wallets SET balance = ?, updated_at = ? WHERE user_id = ?')->execute([$newBalance, $now, $id]);
                $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $amount, $amount > 0 ? 'credit' : 'debit', $newBalance, $note, $admin['id'], $now]);
                $pdo->commit();
                AdminAuth::log('wallet_adjusted', 'user', $id, ($amount > 0 ? '+' : '') . $amount . ' تومان · مانده: ' . $newBalance);
                AdminAuth::flash('success', 'کیف پول عضو به‌روزرسانی شد.');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                AdminAuth::flash('error', 'به‌روزرسانی کیف پول انجام نشد.');
            }
        } else {
            AdminAuth::flash('error', 'مبلغ نامعتبر است (۱ تا ۱۰۰,۰۰۰,۰۰۰ تومان، مثبت یا منفی).');
        }
    } elseif ($action === 'visit_log') {
        $direction = ($_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
        $method = in_array($_POST['method'] ?? '', ['manual', 'qr', 'card', 'otp', 'biometric', 'face'], true) ? $_POST['method'] : 'manual';
        $pdo->prepare('INSERT INTO visit_logs(user_id, branch_id, method, direction, created_at) VALUES(?, ?, ?, ?, ?)')
            ->execute([$id, (int) ($_POST['branch_id'] ?? 0) ?: null, $method, $direction, $now]);
        AdminAuth::log('visit_logged', 'user', $id, $direction === 'in' ? 'ورود' : 'خروج');
        AdminAuth::flash('success', 'تردد ثبت شد.');
    }
    admin_redirect('user_view.php?id=' . $id);
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
$planKinds = ['monthly' => 'ماهانه', 'session' => 'جلسه‌ای', 'time' => 'زمانی', 'vip' => 'VIP', 'honorary' => 'افتخاری', 'coach' => 'خدمات مربی', 'combo' => 'ترکیبی', 'app' => 'اپلیکیشن', 'other' => 'سایر'];

$counts = ['weights' => 0, 'sessions' => 0, 'favorites' => 0, 'chat' => 0];
if (is_array($state)) {
    $counts['weights'] = is_array($state['weights'] ?? null) ? count($state['weights']) : 0;
    $counts['sessions'] = is_array($state['sessions'] ?? null) ? count($state['sessions']) : 0;
    $counts['favorites'] = is_array($state['favorites'] ?? null) ? count($state['favorites']) : 0;
    foreach (['coordinator', 'trainer', 'nutrition', 'recovery'] as $role) $counts['chat'] += is_array($state['chat'][$role] ?? null) ? count($state['chat'][$role]) : 0;
}
$active = (int) $u['is_active'] === 1;

// platform data
$stmt = $pdo->prepare('SELECT s.*, p.name AS plan_name, p.kind AS plan_kind FROM subscriptions s JOIN membership_plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 10');
$stmt->execute([$id]);
$subs = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT COALESCE((SELECT balance FROM wallets WHERE user_id = ?), 0)');
$stmt->execute([$id]);
$wallet = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM token_ledger WHERE user_id = ?');
$stmt->execute([$id]);
$tokens = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM visit_logs WHERE user_id = ?');
$stmt->execute([$id]);
$visitCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT v.*, b.name AS branch_name FROM visit_logs v LEFT JOIN branches b ON b.id = v.branch_id WHERE v.user_id = ? ORDER BY v.created_at DESC LIMIT 5');
$stmt->execute([$id]);
$visits = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT amount, kind, balance_after, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$id]);
$walletTx = $stmt->fetchAll();
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();

admin_header($admin, 'کاربر: ' . $u['name'], 'users');
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-head">
    <h2>حساب کاربری <span class="sub">#<?= fa_num((string) $u['id']) ?></span></h2>
    <div style="display:flex;gap:8px">
      <?php if (AdminAuth::can($admin, 'users.manage')): ?>
      <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="<?= $active ? 'ban' : 'unban' ?>"><button class="btn small <?= $active ? 'btn-danger-ghost' : 'btn-soft' ?>" type="submit"><?= $active ? 'مسدودسازی' : 'فعال‌سازی' ?></button></form>
      <form method="post" data-confirm="کاربر «<?= e($u['name']) ?>» و همه سوابقش برای همیشه حذف شود؟"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="delete"><button class="btn small btn-danger" type="submit">حذف کاربر</button></form>
      <?php endif; ?>
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
      <div>کیف پول</div><div class="num"><?= fa_num(number_format($wallet)) ?> تومان<?= $tokens !== 0 ? ' · ' . fa_num((string) $tokens) . ' توکن' : '' ?></div>
      <div>تعداد تردد</div><div class="num"><?= fa_num((string) $visitCount) ?> بار</div>
    </div>
  </div>
</div>

<div class="grid cols-4" style="margin-bottom:16px">
  <div class="stat accent"><div class="label">اشتراک فعال</div><div class="value num"><?= fa_num((string) count(array_filter($subs, fn($s) => $s['status'] === 'active' && ($s['expires_at'] === null || (int) $s['expires_at'] >= $now)))) ?></div><div class="hint">عضویت جاری</div></div>
  <div class="stat"><div class="label">ثبت‌های وزن</div><div class="value num"><?= fa_num($counts['weights']) ?></div><div class="hint">در اپلیکیشن</div></div>
  <div class="stat green"><div class="label">جلسات تمرین</div><div class="value num"><?= fa_num($counts['sessions']) ?></div><div class="hint">ثبت‌شده</div></div>
  <div class="stat"><div class="label">پیام‌های ایجنت‌ها</div><div class="value num"><?= fa_num($counts['chat']) ?></div><div class="hint">هر ۴ نقش</div></div>
</div>

<div class="grid cols-2" style="align-items:start">
  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>اشتراک‌ها و عضویت‌ها</h2><a class="sub" href="memberships.php?tab=subs">مدیریت ←</a></div>
      <div class="card-body tight table-wrap">
        <table>
          <thead><tr><th>پلن</th><th>اعتبار</th><th>جلسات</th><th>وضعیت</th></tr></thead>
          <tbody>
          <?php if (!$subs): ?><tr><td colspan="4"><div class="empty"><span class="glyph">🎫</span>این عضو اشتراکی ندارد.</div></td></tr><?php endif; ?>
          <?php foreach ($subs as $s): $expired = $s['expires_at'] !== null && (int) $s['expires_at'] < $now; ?>
          <tr>
            <td><?= e($s['plan_name']) ?><span class="sub"><?= e($planKinds[$s['plan_kind']] ?? '') ?></span></td>
            <td class="num"><?= $s['expires_at'] ? jdate((int) $s['expires_at']) : 'بدون انقضا' ?></td>
            <td class="num"><?= $s['sessions_total'] > 0 ? fa_num((string) $s['sessions_used']) . ' / ' . fa_num((string) $s['sessions_total']) : 'نامحدود' ?></td>
            <td><?= $s['status'] !== 'active' ? '<span class="badge gray">لغو</span>' : ($expired ? '<span class="badge red">منقضی</span>' : '<span class="badge green">فعال</span>') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>مشخصات بدنی ثبت‌شده در اپلیکیشن</h2></div>
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
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>کیف پول</h2></div>
      <div class="card-body">
        <div class="stat <?= $wallet >= 0 ? 'green' : 'red' ?>" style="margin-bottom:14px"><div class="label">مانده فعلی</div><div class="value num" style="font-size:22px"><?= fa_num(number_format($wallet)) ?></div><div class="hint">تومان</div></div>
        <?php if (AdminAuth::can($admin, 'finance.manage')): ?>
        <form method="post" style="margin-bottom:12px">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="wallet_adjust">
          <div class="form-grid">
            <div class="field"><label for="amount">مبلغ (تومان)</label><input id="amount" name="amount" type="number" min="-100000000" max="100000000" required dir="ltr" placeholder="مثبت=شارژ، منفی=برداشت"></div>
            <div class="field"><label for="wnote">توضیح</label><input id="wnote" name="note" maxlength="300"></div>
          </div>
          <button class="btn btn-primary" type="submit">ثبت تغییر کیف پول</button>
        </form>
        <?php endif; ?>
        <?php if ($walletTx): ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>زمان</th><th>نوع</th><th>مبلغ</th><th>مانده</th></tr></thead>
            <tbody>
            <?php foreach ($walletTx as $t): ?>
            <tr><td class="num"><?= jago((int) $t['created_at']) ?></td><td><?= $t['kind'] === 'credit' ? '<span class="badge green">شارژ</span>' : '<span class="badge red">برداشت</span>' ?></td><td class="num"><?= fa_num(number_format($t['amount'])) ?></td><td class="num"><?= fa_num(number_format($t['balance_after'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>تردد (ورود/خروج)</h2></div>
      <div class="card-body">
        <?php if (AdminAuth::can($admin, 'users.manage')): ?>
        <form method="post" style="margin-bottom:12px">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="visit_log">
          <div class="form-grid">
            <div class="field"><label for="direction">نوع</label>
              <select id="direction" name="direction"><option value="in">ورود</option><option value="out">خروج</option></select>
            </div>
            <div class="field"><label for="method">روش</label>
              <select id="method" name="method"><option value="manual">دستی (پذیرش)</option><option value="qr">QR</option><option value="card">کارت عضویت</option><option value="otp">موبایل/OTP</option><option value="biometric">اثر انگشت</option><option value="face">تشخیص چهره</option></select>
            </div>
            <div class="field full"><label for="vbranch">شعبه</label>
              <select id="vbranch" name="branch_id"><option value="">—</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <button class="btn" type="submit">ثبت تردد</button>
        </form>
        <?php endif; ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>زمان</th><th>نوع</th><th>روش</th><th>شعبه</th></tr></thead>
            <tbody>
            <?php if (!$visits): ?><tr><td colspan="4"><div class="empty"><span class="glyph">🚪</span>ترددی ثبت نشده است.</div></td></tr><?php endif; ?>
            <?php foreach ($visits as $v): ?>
            <tr><td class="num"><?= jdate((int) $v['created_at']) ?></td><td><?= $v['direction'] === 'in' ? '<span class="badge green">ورود</span>' : '<span class="badge gray">خروج</span>' ?></td><td><?= e($v['method']) ?></td><td><?= e($v['branch_name'] ?? '—') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<p class="auth-note" style="text-align:right">داده‌های تمرینی عضو فقط-خواندنی است و از داخل پنل تغییر نمی‌کند. کیف پول و تردد با دسترسی مربوطه قابل ثبت‌اند؛ اتصال به گیت و کارت‌خوان در فاز IoT فعال می‌شود.</p>
<?php admin_footer();
