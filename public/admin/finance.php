<?php
declare(strict_types=1);
/** Managerial finance: income/expense book (proposal §22). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('finance.view');
$pdo = Database::connection();
$now = Http::now();
$monthStart = (int) (mktime(0, 0, 0, (int) gmdate('n'), 1, (int) gmdate('Y')) * 1000);

const INCOME_CATEGORIES = ['membership' => 'عضویت', 'service' => 'خدمات', 'coach' => 'مربی', 'cafe' => 'کافه', 'store' => 'فروشگاه', 'massage' => 'ماساژ', 'salon' => 'آرایشگاه', 'other' => 'سایر درآمد'];
const EXPENSE_CATEGORIES = ['salary' => 'حقوق', 'rent' => 'اجاره', 'purchase' => 'خرید', 'equipment' => 'تجهیزات', 'current' => 'هزینه جاری', 'other' => 'سایر هزینه'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'finance.manage')) {
        AdminAuth::flash('error', 'برای ثبت تراکنش مالی دسترسی نداری.');
        admin_redirect('finance.php');
    }
    if (($_POST['action'] ?? '') === 'create') {
        $kind = ($_POST['kind'] ?? '') === 'expense' ? 'expense' : 'income';
        $cats = $kind === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;
        $category = array_key_exists($_POST['category'] ?? '', $cats) ? $_POST['category'] : 'other';
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($amount <= 0 || $amount > 9_999_999_999) {
            AdminAuth::flash('error', 'مبلغ معتبر وارد کن.');
        } else {
            $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) ($_POST['branch_id'] ?? 0) ?: null, $kind, $category, $amount, trim(Http::text($_POST['note'] ?? '', 300)), $admin['id'], $now]);
            AdminAuth::log('finance_added', 'finance', (int) $pdo->lastInsertId(), ($kind === 'income' ? 'درآمد' : 'هزینه') . ' · ' . number_format($amount) . ' تومان');
            AdminAuth::flash('success', 'تراکنش ثبت شد.');
        }
    }
    admin_redirect('finance.php');
}

$summary = $pdo->query(
    'SELECT
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'income\') AS income_all,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'expense\') AS expense_all,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'income\' AND created_at >= ' . $monthStart . ') AS income_month,
        (SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE kind = \'expense\' AND created_at >= ' . $monthStart . ') AS expense_month'
)->fetch();

$kindFilter = in_array($_GET['kind'] ?? '', ['income', 'expense'], true) ? $_GET['kind'] : '';
$where = ' WHERE 1=1';
if ($kindFilter !== '') $where .= ' AND f.kind = \'' . $kindFilter . '\'';
$total = (int) $pdo->query('SELECT COUNT(*) FROM finance_transactions f' . $where)->fetchColumn();
$page = (int) ($_GET['page'] ?? 1);
[$limit, $offset, $pages] = paginate_params($total, 20, $page);
$rows = $pdo->query('SELECT f.*, b.name AS branch_name, a.username AS actor FROM finance_transactions f
    LEFT JOIN branches b ON b.id = f.branch_id
    LEFT JOIN admins a ON a.id = f.created_by' . $where . '
    ORDER BY f.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();
$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();

admin_header($admin, 'مالی و حسابداری', 'finance');
?>
<div class="grid cols-4" style="margin-bottom:16px">
  <div class="stat green"><div class="label">درآمد این ماه</div><div class="value num" style="font-size:20px"><?= fa_num(number_format((float) $summary['income_month'])) ?></div><div class="hint">تومان</div></div>
  <div class="stat red"><div class="label">هزینه این ماه</div><div class="value num" style="font-size:20px"><?= fa_num(number_format((float) $summary['expense_month'])) ?></div><div class="hint">تومان</div></div>
  <div class="stat <?= $summary['income_month'] - $summary['expense_month'] >= 0 ? 'green' : 'red' ?>"><div class="label">سود این ماه</div><div class="value num" style="font-size:20px"><?= fa_num(number_format((float) ($summary['income_month'] - $summary['expense_month']))) ?></div><div class="hint">تومان</div></div>
  <div class="stat accent"><div class="label">سود کل</div><div class="value num" style="font-size:20px"><?= fa_num(number_format((float) ($summary['income_all'] - $summary['expense_all']))) ?></div><div class="hint">درآمد <?= fa_num(number_format((float) $summary['income_all'])) ?> − هزینه <?= fa_num(number_format((float) $summary['expense_all'])) ?></div></div>
</div>

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head">
      <h2>دفتر تراکنش‌ها <span class="sub">(<?= fa_num($total) ?>)</span></h2>
      <div class="searchbar">
        <a class="btn small <?= $kindFilter === '' ? 'btn-soft' : '' ?>" href="finance.php">همه</a>
        <a class="btn small <?= $kindFilter === 'income' ? 'btn-soft' : '' ?>" href="finance.php?kind=income">درآمد</a>
        <a class="btn small <?= $kindFilter === 'expense' ? 'btn-soft' : '' ?>" href="finance.php?kind=expense">هزینه</a>
      </div>
    </div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>تاریخ</th><th>نوع</th><th>دسته</th><th>مبلغ (تومان)</th><th>شعبه</th><th>ثبت‌کننده</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><span class="glyph">💰</span>تراکنشی ثبت نشده است.</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $cats = $r['kind'] === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES; ?>
          <tr>
            <td class="num"><?= jdate((int) $r['created_at']) ?></td>
            <td><?= $r['kind'] === 'income' ? '<span class="badge green">درآمد</span>' : '<span class="badge red">هزینه</span>' ?></td>
            <td><?= e($cats[$r['category']] ?? $r['category']) ?><span class="sub"><?= e($r['note']) ?></span></td>
            <td class="num"><?= fa_num(number_format((float) $r['amount'])) ?></td>
            <td><?= e($r['branch_name'] ?? 'همه') ?></td>
            <td><?= e($r['actor'] ?? 'سیستم') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($page, $pages, $kindFilter !== '' ? 'kind=' . $kindFilter : '') ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>ثبت تراکنش جدید</h2></div>
    <div class="card-body">
      <?php if (!AdminAuth::can($admin, 'finance.manage')): ?>
        <div class="empty"><span class="glyph">🔐</span>دسترسی ثبت تراکنش نداری.</div>
      <?php else: ?>
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="kind">نوع</label>
            <select id="kind" name="kind" onchange="toggleCats(this.value)">
              <option value="income">درآمد</option>
              <option value="expense">هزینه</option>
            </select>
          </div>
          <div class="field"><label for="category">دسته</label>
            <select id="category" name="category"><?php foreach (INCOME_CATEGORIES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field full"><label for="amount">مبلغ (تومان)</label><input id="amount" name="amount" type="number" min="1" max="999999999" required dir="ltr" placeholder="500000"></div>
          <div class="field"><label for="branch_id">شعبه</label>
            <select id="branch_id" name="branch_id"><option value="">همه شعب</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label for="note">توضیح</label><input id="note" name="note" maxlength="300"></div>
        </div>
        <button class="btn btn-primary" type="submit">ثبت تراکنش</button>
      </form>
      <script>
        function toggleCats(kind) {
          var cats = kind === 'expense'
            ? <?= json_encode(array_values(EXPENSE_CATEGORIES), JSON_UNESCAPED_UNICODE) ?>
            : <?= json_encode(array_values(INCOME_CATEGORIES), JSON_UNESCAPED_UNICODE) ?>;
          var keys = kind === 'expense'
            ? <?= json_encode(array_keys(EXPENSE_CATEGORIES)) ?>
            : <?= json_encode(array_keys(INCOME_CATEGORIES)) ?>;
          var el = document.getElementById('category');
          el.innerHTML = '';
          for (var i = 0; i < keys.length; i++) {
            var o = document.createElement('option');
            o.value = keys[i]; o.textContent = cats[i];
            el.appendChild(o);
          }
        }
      </script>
      <p class="auth-note" style="text-align:right">فروش پلن‌های عضویت به‌صورت خودکار با دسته «عضویت» ثبت می‌شود. اسناد، فاکتور و اتصال بانکی در فازهای بعدی اضافه می‌شود.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php admin_footer();
