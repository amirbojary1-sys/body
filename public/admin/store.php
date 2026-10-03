<?php
declare(strict_types=1);
/** Store & cafe: products, stock and orders (proposal §8, §27). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\ApiException;
use FitBot\Database;
use FitBot\Http;
use FitBot\Ledger;

$admin = AdminAuth::requireAdmin('store.view');
$pdo = Database::connection();
$now = Http::now();

const DEPARTMENTS = ['store' => 'فروشگاه', 'cafe' => 'کافه'];
const ORDER_STATUSES = ['pending' => 'در انتظار', 'paid' => 'پرداخت‌شده', 'preparing' => 'در حال آماده‌سازی', 'done' => 'تحویل‌شده', 'canceled' => 'لغوشده'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'store.manage')) {
        AdminAuth::flash('error', 'برای مدیریت فروشگاه دسترسی نداری.');
        admin_redirect('store.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'product_create') {
            $name = trim(Http::text($_POST['name'] ?? '', 100));
            $dept = array_key_exists($_POST['department'] ?? '', DEPARTMENTS) ? $_POST['department'] : 'store';
            if (mb_strlen($name) < 2) {
                AdminAuth::flash('error', 'نام محصول لازم است.');
            } else {
                $pdo->prepare('INSERT INTO products(department, name, category, price, stock, low_stock, is_active, created_at) VALUES(?, ?, ?, ?, 0, ?, 1, ?)')
                    ->execute([$dept, $name, trim(Http::text($_POST['category'] ?? '', 50)), max(0, (int) ($_POST['price'] ?? 0)), max(0, (int) ($_POST['low_stock'] ?? 5)), $now]);
                $pid = (int) $pdo->lastInsertId();
                $opening = (int) ($_POST['stock'] ?? 0);
                if ($opening > 0) Ledger::stock($pid, $opening, 'purchase', 'موجودی اولیه', $admin['id']);
                AdminAuth::log('product_created', 'product', $pid, $name);
                AdminAuth::flash('success', 'محصول «' . $name . '» اضافه شد.');
            }
        } elseif ($action === 'stock') {
            $qty = (int) ($_POST['qty'] ?? 0);
            $kind = in_array($_POST['kind'] ?? '', ['purchase', 'waste', 'adjust'], true) ? $_POST['kind'] : 'adjust';
            if ($qty === 0) {
                AdminAuth::flash('error', 'تعداد را مثبت (افزودن) یا منفی (کاهش) وارد کن.');
            } else {
                Ledger::stock((int) ($_POST['product_id'] ?? 0), $qty, $kind, trim(Http::text($_POST['note'] ?? '', 200)), $admin['id']);
                AdminAuth::log('stock_adjusted', 'product', (int) ($_POST['product_id'] ?? 0), $kind . ' ' . $qty);
                AdminAuth::flash('success', 'موجودی به‌روزرسانی شد.');
            }
        } elseif ($action === 'product_toggle') {
            $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($_POST['product_id'] ?? 0)]);
            AdminAuth::log('product_deactivated', 'product', (int) ($_POST['product_id'] ?? 0));
            AdminAuth::flash('success', 'وضعیت محصول تغییر کرد.');
        } elseif ($action === 'order_status') {
            $status = (string) ($_POST['status'] ?? '');
            if (in_array($status, ['preparing', 'done', 'canceled'], true)) {
                Ledger::setOrderStatus((int) ($_POST['order_id'] ?? 0), $status, $admin['id']);
                AdminAuth::log('order_' . ($status === 'done' ? 'done' : ($status === 'canceled' ? 'canceled' : 'preparing')), 'order', (int) ($_POST['order_id'] ?? 0), ORDER_STATUSES[$status]);
                AdminAuth::flash('success', 'وضعیت سفارش به «' . ORDER_STATUSES[$status] . '» تغییر کرد.');
            }
        }
    } catch (ApiException $e) {
        AdminAuth::flash('error', $e->getMessage());
    }
    admin_redirect('store.php' . (($_POST['tab'] ?? '') !== '' ? '?tab=' . urlencode((string) $_POST['tab']) : ''));
}

$tab = ($_GET['tab'] ?? '') === 'orders' ? 'orders' : 'products';
$deptFilter = array_key_exists($_GET['dept'] ?? '', DEPARTMENTS) ? $_GET['dept'] : '';

// products + stats
$where = ' WHERE 1=1';
$args = [];
if ($deptFilter !== '') { $where = ' WHERE department = ?'; $args[] = $deptFilter; }
$stmt = $pdo->prepare('SELECT COUNT(*) FROM products' . $where);
$stmt->execute($args);
$total = (int) $stmt->fetchColumn();
$page = (int) ($_GET['page'] ?? 1);
[$limit, $offset, $pages] = paginate_params($total, 20, $page);
$stmt = $pdo->prepare('SELECT * FROM products' . $where . ' ORDER BY department, is_active DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
$stmt->execute($args);
$products = $stmt->fetchAll();

$storeStats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM orders WHERE status IN (\'pending\', \'paid\', \'preparing\')) AS open_orders,
    (SELECT COALESCE(SUM(total),0) FROM orders WHERE status = \'done\') AS sales_done,
    (SELECT COUNT(*) FROM products WHERE is_active = 1 AND stock <= low_stock) AS low_stock_count'
)->fetch();

// orders + items
$oFilter = array_key_exists($_GET['ostatus'] ?? '', ORDER_STATUSES) ? $_GET['ostatus'] : '';
$owhere = ' WHERE 1=1';
$oargs = [];
if ($oFilter !== '') { $owhere = ' WHERE o.status = ?'; $oargs[] = $oFilter; }
$stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o' . $owhere);
$stmt->execute($oargs);
$totalOrders = (int) $stmt->fetchColumn();
$opage = (int) ($_GET['opage'] ?? 1);
[$olimit, $ooffset, $opages] = paginate_params($totalOrders, 15, $opage);
$stmt = $pdo->prepare('SELECT o.*, u.name AS user_name FROM orders o JOIN users u ON u.id = o.user_id' . $owhere . ' ORDER BY o.created_at DESC LIMIT ' . $olimit . ' OFFSET ' . $ooffset);
$stmt->execute($oargs);
$orders = $stmt->fetchAll();
$orderItems = [];
foreach ($orders as $o) {
    $stmt = $pdo->prepare('SELECT oi.qty, oi.unit_price, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $stmt->execute([(int) $o['id']]);
    $orderItems[(int) $o['id']] = $stmt->fetchAll();
}

admin_header($admin, 'فروشگاه و کافه', 'store');
?>
<div class="grid cols-3" style="margin-bottom:16px">
  <div class="stat amber"><div class="label">سفارش‌های باز</div><div class="value num"><?= fa_num((string) $storeStats['open_orders']) ?></div><div class="hint">در انتظار پرداخت/آماده‌سازی/تحویل</div></div>
  <div class="stat green"><div class="label">فروش تحویل‌شده</div><div class="value num" style="font-size:20px"><?= fa_num(number_format((float) $storeStats['sales_done'])) ?></div><div class="hint">تومان — فروشگاه + کافه</div></div>
  <div class="stat <?= (int) $storeStats['low_stock_count'] > 0 ? 'red' : '' ?>"><div class="label">محصولات کم‌موجودی</div><div class="value num"><?= fa_num((string) $storeStats['low_stock_count']) ?></div><div class="hint">رسیده به آستانه هشدار</div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>
      <a href="store.php" style="text-decoration:none <?= $tab === 'products' ? ';color:var(--accent)' : '' ?>">محصولات</a>
      <span style="color:var(--muted)"> · </span>
      <a href="store.php?tab=orders" style="text-decoration:none <?= $tab === 'orders' ? ';color:var(--accent)' : '' ?>">سفارش‌ها</a>
    </h2>
    <?php if ($tab === 'products'): ?>
    <div class="searchbar">
      <a class="btn small <?= $deptFilter === '' ? 'btn-soft' : '' ?>" href="store.php">همه</a>
      <a class="btn small <?= $deptFilter === 'store' ? 'btn-soft' : '' ?>" href="store.php?dept=store">فروشگاه</a>
      <a class="btn small <?= $deptFilter === 'cafe' ? 'btn-soft' : '' ?>" href="store.php?dept=cafe">کافه</a>
    </div>
    <?php else: ?>
    <div class="searchbar">
      <a class="btn small <?= $oFilter === '' ? 'btn-soft' : '' ?>" href="store.php?tab=orders">همه</a>
      <?php foreach (ORDER_STATUSES as $key => $label): ?><a class="btn small <?= $oFilter === $key ? 'btn-soft' : '' ?>" href="store.php?tab=orders&ostatus=<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

<?php if ($tab === 'products'): ?>
  <div class="grid cols-2" style="padding:20px;align-items:start">
    <div class="table-wrap">
      <table>
        <thead><tr><th>محصول</th><th>بخش</th><th>قیمت (تومان)</th><th>موجودی</th><th>وضعیت</th><th>تغییر موجودی</th></tr></thead>
        <tbody>
        <?php if (!$products): ?><tr><td colspan="6"><div class="empty"><span class="glyph">🛒</span>محصولی نیست.</div></td></tr><?php endif; ?>
        <?php foreach ($products as $p): $low = (int) $p['stock'] <= (int) $p['low_stock']; ?>
          <tr>
            <td><strong><?= e($p['name']) ?></strong><span class="sub"><?= e($p['category']) ?></span></td>
            <td><span class="badge <?= $p['department'] === 'cafe' ? 'accent' : 'gray' ?>"><?= e(DEPARTMENTS[$p['department']] ?? $p['department']) ?></span></td>
            <td class="num"><?= fa_num(number_format((float) $p['price'])) ?></td>
            <td class="num"><span class="badge <?= $low ? 'red' : 'green' ?>"><?= fa_num((string) $p['stock']) ?></span></td>
            <td>
              <?php if (AdminAuth::can($admin, 'store.manage')): ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="product_toggle"><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><button class="btn small <?= (int) $p['is_active'] === 1 ? 'btn-soft' : '' ?>" type="submit"><?= (int) $p['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></button></form>
              <?php else: ?>
                <?= (int) $p['is_active'] === 1 ? '<span class="badge green">فعال</span>' : '<span class="badge gray">غیرفعال</span>' ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if (AdminAuth::can($admin, 'store.manage')): ?>
              <form method="post" style="display:flex;gap:5px;align-items:center">
                <?= AdminAuth::csrfField() ?>
                <input type="hidden" name="action" value="stock">
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <input type="number" name="qty" placeholder="±10" required style="width:70px;background:#17191e;border:1px solid var(--line);border-radius:8px;color:var(--text);padding:5px 8px;font-family:inherit" dir="ltr">
                <select name="kind" style="background:#17191e;border:1px solid var(--line);border-radius:8px;color:var(--text);padding:5px;font-family:inherit;font-size:12px">
                  <option value="purchase">خرید</option><option value="waste">ضایعات</option><option value="adjust">اصلاح</option>
                </select>
                <button class="btn small" type="submit">ثبت</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?= pagination_links($page, $pages, $deptFilter !== '' ? 'dept=' . $deptFilter : '') ?>
    </div>
    <div class="card" style="box-shadow:none">
      <div class="card-head"><h2>افزودن محصول</h2></div>
      <div class="card-body">
        <?php if (!AdminAuth::can($admin, 'store.manage')): ?>
          <div class="empty"><span class="glyph">🔐</span>دسترسی افزودن محصول نداری.</div>
        <?php else: ?>
        <form method="post">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="product_create">
          <div class="form-grid">
            <div class="field"><label for="name">نام محصول</label><input id="name" name="name" required maxlength="100"></div>
            <div class="field"><label for="department">بخش</label>
              <select id="department" name="department"><?php foreach (DEPARTMENTS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label for="category">دسته</label><input id="category" name="category" maxlength="50" placeholder="مکمل / نوشیدنی / لوازم"></div>
            <div class="field"><label for="price">قیمت (تومان)</label><input id="price" name="price" type="number" min="0" max="999999999" required dir="ltr"></div>
            <div class="field"><label for="stock">موجودی اولیه</label><input id="stock" name="stock" type="number" min="0" max="100000" value="0" dir="ltr"></div>
            <div class="field"><label for="low_stock">آستانه هشدار</label><input id="low_stock" name="low_stock" type="number" min="0" max="10000" value="5" dir="ltr"></div>
          </div>
          <button class="btn btn-primary" type="submit">افزودن محصول</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card-body tight table-wrap">
    <table>
      <thead><tr><th>#</th><th>عضو</th><th>اقلام</th><th>مبلغ (تومان)</th><th>پرداخت</th><th>وضعیت</th><th>زمان</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php if (!$orders): ?><tr><td colspan="8"><div class="empty"><span class="glyph">🧾</span>سفارشی در این وضعیت نیست.</div></td></tr><?php endif; ?>
      <?php foreach ($orders as $o): $open = in_array($o['status'], ['pending', 'paid', 'preparing'], true); ?>
        <tr>
          <td class="num"><?= fa_num((string) $o['id']) ?></td>
          <td><?= e($o['user_name']) ?></td>
          <td style="white-space:normal"><?php foreach ($orderItems[(int) $o['id']] ?? [] as $it): ?><?= e($it['name']) ?> ×<?= fa_num((string) $it['qty']) ?><br><?php endforeach; ?></td>
          <td class="num"><?= fa_num(number_format((float) $o['total'])) ?></td>
          <td><?= $o['pay_method'] === 'wallet' ? '<span class="badge green">کیف پول</span>' : '<span class="badge gray">نقدی</span>' ?></td>
          <td><span class="badge <?= $o['status'] === 'done' ? 'green' : ($o['status'] === 'canceled' ? 'red' : ($o['status'] === 'pending' ? 'amber' : 'accent')) ?>"><?= e(ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span></td>
          <td class="num"><?= jago((int) $o['created_at']) ?></td>
          <td>
            <?php if ($open && AdminAuth::can($admin, 'store.manage')): ?>
            <div style="display:flex;gap:6px">
              <?php if (in_array($o['status'], ['pending', 'paid'], true)): ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="order_status"><input type="hidden" name="status" value="preparing"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="tab" value="orders"><button class="btn small" type="submit">آماده‌سازی</button></form>
              <?php endif; ?>
              <form method="post"><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="order_status"><input type="hidden" name="status" value="done"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="tab" value="orders"><button class="btn small btn-soft" type="submit">تحویل شد</button></form>
              <form method="post" data-confirm="سفارش لغو شود؟ در صورت پرداخت با کیف پول، مبلغ برمی‌گردد."><?= AdminAuth::csrfField() ?><input type="hidden" name="action" value="order_status"><input type="hidden" name="status" value="canceled"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="tab" value="orders"><button class="btn small btn-danger-ghost" type="submit">لغو</button></form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= pagination_links($opage, $opages, 'tab=orders' . ($oFilter !== '' ? '&ostatus=' . urlencode($oFilter) : '')) ?>
  </div>
<?php endif; ?>
</div>
<?php admin_footer();
