<?php
declare(strict_types=1);
/** CRM leads: acquisition funnel (proposal §3, §9). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('crm.view');
$pdo = Database::connection();

const LEAD_SOURCES = ['instagram' => 'اینستاگرام', 'ads' => 'تبلیغات', 'phone' => 'تماس تلفنی', 'walk_in' => 'مراجعه حضوری', 'referral' => 'معرفی دوستان', 'other' => 'سایر'];
const LEAD_STATUSES = ['new' => 'جدید', 'contacted' => 'تماس‌خورده', 'consult' => 'مشاوره', 'follow_up' => 'پیگیری', 'won' => 'تبدیل به عضو', 'lost' => 'منصرف'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'crm.manage')) {
        AdminAuth::flash('error', 'برای ثبت و ویرایش لید دسترسی نداری.');
        admin_redirect('leads.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim(Http::text($_POST['full_name'] ?? '', 100));
        $phone = trim(Http::text($_POST['phone'] ?? '', 20));
        if (mb_strlen($name) < 2 || $phone === '') {
            AdminAuth::flash('error', 'نام و شماره موبایل لید لازم است.');
        } else {
            $branch = (int) ($_POST['branch_id'] ?? 0) ?: null;
            $assignee = (int) ($_POST['assigned_admin_id'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO leads(branch_id, full_name, phone, email, source, status, assigned_admin_id, note, created_at, updated_at) VALUES(?, ?, ?, ?, ?, \'new\', ?, ?, ?, ?)')
                ->execute([$branch, $name, $phone, strtolower(trim(Http::text($_POST['email'] ?? '', 254))), array_key_exists($_POST['source'] ?? '', LEAD_SOURCES) ? $_POST['source'] : 'other', $assignee, trim(Http::text($_POST['note'] ?? '', 500)), Http::now(), Http::now()]);
            $id = (int) $pdo->lastInsertId();
            AdminAuth::log('lead_created', 'lead', $id, $name . ' · ' . $phone);
            AdminAuth::flash('success', 'لید «' . $name . '» ثبت شد.');
            admin_redirect('lead_view.php?id=' . $id);
        }
    }
    admin_redirect('leads.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = array_key_exists($_GET['status'] ?? '', LEAD_STATUSES) ? $_GET['status'] : '';
$page = (int) ($_GET['page'] ?? 1);
$perPage = 20;
$where = ' WHERE 1=1';
$args = [];
if ($q !== '') { $where .= ' AND (l.full_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q)'; $args['q'] = '%' . $q . '%'; }
if ($status !== '') { $where .= ' AND l.status = :st'; $args['st'] = $status; }
$stmt = $pdo->prepare('SELECT COUNT(*) FROM leads l' . $where);
$stmt->execute($args);
$total = (int) $stmt->fetchColumn();
[$limit, $offset, $pages] = paginate_params($total, $perPage, $page);

$stmt = $pdo->prepare('SELECT l.*, a.username AS assignee, b.name AS branch_name FROM leads l
    LEFT JOIN admins a ON a.id = l.assigned_admin_id
    LEFT JOIN branches b ON b.id = l.branch_id' . $where . '
    ORDER BY l.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
$stmt->execute($args);
$leads = $stmt->fetchAll();

$funnel = $pdo->query('SELECT status, COUNT(*) AS c FROM leads GROUP BY status')->fetchAll();
$funnelCounts = [];
foreach ($funnel as $f) $funnelCounts[$f['status']] = (int) $f['c'];

$branches = $pdo->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();
$staff = $pdo->query('SELECT id, username FROM admins ORDER BY id')->fetchAll();

admin_header($admin, 'CRM و لیدها', 'leads');
?>
<div class="grid cols-4" style="margin-bottom:16px">
  <?php foreach (LEAD_STATUSES as $key => $label): ?>
  <div class="stat <?= $key === 'won' ? 'green' : ($key === 'lost' ? 'red' : ($key === 'new' ? 'accent' : '')) ?>">
    <div class="label"><?= e($label) ?></div><div class="value num"><?= fa_num((string) ($funnelCounts[$key] ?? 0)) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="card-head">
      <h2>لیدها <span class="sub">(<?= fa_num($total) ?> مورد)</span></h2>
      <form method="get" class="searchbar">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="جستجوی نام، موبایل یا ایمیل…">
        <select name="status" onchange="this.form.submit()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach (LEAD_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">جستجو</button>
      </form>
    </div>
    <div class="card-body tight table-wrap">
      <table>
        <thead><tr><th>لید</th><th>منبع</th><th>وضعیت</th><th>اپراتور</th><th>پیگیری</th><th>ثبت</th><th></th></tr></thead>
        <tbody>
        <?php if (!$leads): ?>
          <tr><td colspan="7"><div class="empty"><span class="glyph">📝</span>لیدی پیدا نشد؛ اولین لید را از فرم روبه‌رو ثبت کن.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($leads as $l): ?>
          <tr>
            <td><a href="lead_view.php?id=<?= (int) $l['id'] ?>"><strong><?= e($l['full_name']) ?></strong></a><span class="sub" dir="ltr"><?= e($l['phone']) ?></span></td>
            <td><span class="badge gray"><?= e(LEAD_SOURCES[$l['source']] ?? $l['source']) ?></span></td>
            <td><span class="badge <?= $l['status'] === 'won' ? 'green' : ($l['status'] === 'lost' ? 'red' : ($l['status'] === 'new' ? 'accent' : 'amber')) ?>"><?= e(LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span></td>
            <td><?= e($l['assignee'] ?? '—') ?></td>
            <td class="num"><?= $l['follow_up_at'] ? jago((int) $l['follow_up_at']) : '—' ?></td>
            <td class="num"><?= jago((int) $l['created_at']) ?></td>
            <td><a class="btn small" href="lead_view.php?id=<?= (int) $l['id'] ?>">پرونده</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($page, $pages, ($q !== '' ? 'q=' . urlencode($q) : '') . ($status !== '' ? ($q !== '' ? '&' : '') . 'status=' . urlencode($status) : '')) ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>ثبت لید جدید</h2><span class="sub">ورود لید ← ثبت اطلاعات ← پیگیری</span></div>
    <div class="card-body">
      <?php if (!AdminAuth::can($admin, 'crm.manage')): ?>
        <div class="empty"><span class="glyph">🔐</span>برای ثبت لید به دسترسی «ثبت لید و پیگیری» نیاز داری.</div>
      <?php else: ?>
      <form method="post">
        <?= AdminAuth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field"><label for="full_name">نام و نام خانوادگی</label><input id="full_name" name="full_name" required maxlength="100"></div>
          <div class="field"><label for="phone">شماره موبایل</label><input id="phone" name="phone" required maxlength="20" dir="ltr" placeholder="09121234567"></div>
          <div class="field"><label for="email">ایمیل (اختیاری)</label><input id="email" name="email" type="email" maxlength="254" dir="ltr"></div>
          <div class="field"><label for="source">منبع آشنایی</label>
            <select id="source" name="source"><?php foreach (LEAD_SOURCES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label for="branch_id">شعبه</label>
            <select id="branch_id" name="branch_id"><option value="">—</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label for="assigned_admin_id">اپراتور پیگیری</label>
            <select id="assigned_admin_id" name="assigned_admin_id"><option value="">تعیین‌نشده</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['username']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="field full"><label for="note">یادداشت اولیه / درخواست مشتری</label><input id="note" name="note" maxlength="500" placeholder="مثلاً: برای کاهش وزن مشاوره می‌خواست"></div>
        </div>
        <button class="btn btn-primary" type="submit">ثبت لید</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php admin_footer();
