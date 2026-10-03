<?php
declare(strict_types=1);
/** Single lead profile: follow-ups, call log and status funnel (proposal §9). */
require __DIR__ . '/inc/admin.php';
use FitBot\AdminAuth;
use FitBot\Database;
use FitBot\Http;

$admin = AdminAuth::requireAdmin('crm.view');
$pdo = Database::connection();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0) admin_redirect('leads.php');

const LEAD_SOURCES = ['instagram' => 'اینستاگرام', 'ads' => 'تبلیغات', 'phone' => 'تماس تلفنی', 'walk_in' => 'مراجعه حضوری', 'referral' => 'معرفی دوستان', 'other' => 'سایر'];
const LEAD_STATUSES = ['new' => 'جدید', 'contacted' => 'تماس‌خورده', 'consult' => 'مشاوره', 'follow_up' => 'پیگیری', 'won' => 'تبدیل به عضو', 'lost' => 'منصرف'];
const EVENT_KINDS = ['call' => 'تماس تلفنی', 'note' => 'یادداشت', 'meeting' => 'جلسه/مشاوره', 'other' => 'سایر'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AdminAuth::can($admin, 'crm.manage')) {
        AdminAuth::flash('error', 'برای پیگیری لید دسترسی نداری.');
        admin_redirect('lead_view.php?id=' . $id);
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        $kind = array_key_exists($_POST['kind'] ?? '', EVENT_KINDS) ? $_POST['kind'] : 'note';
        $outcome = trim(Http::text($_POST['outcome'] ?? '', 200));
        if ($outcome !== '') {
            $pdo->prepare('INSERT INTO lead_events(lead_id, admin_id, kind, outcome, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$id, $admin['id'], $kind, $outcome, Http::now()]);
            $pdo->prepare('UPDATE leads SET updated_at = ? WHERE id = ?')->execute([Http::now(), $id]);
            AdminAuth::log('lead_event', 'lead', $id, EVENT_KINDS[$kind] . ': ' . $outcome);
            AdminAuth::flash('success', 'رویداد در پرونده لید ثبت شد.');
        }
    } elseif ($action === 'set_status') {
        $status = array_key_exists($_POST['status'] ?? '', LEAD_STATUSES) ? $_POST['status'] : '';
        if ($status !== '') {
            $assignee = (int) ($_POST['assigned_admin_id'] ?? 0) ?: null;
            $pdo->prepare('UPDATE leads SET status = ?, assigned_admin_id = ?, updated_at = ? WHERE id = ?')->execute([$status, $assignee, Http::now(), $id]);
            AdminAuth::log('lead_updated', 'lead', $id, 'وضعیت: ' . LEAD_STATUSES[$status]);
            AdminAuth::flash('success', 'وضعیت لید به «' . LEAD_STATUSES[$status] . '» تغییر کرد.');
        }
    } elseif ($action === 'assign') {
        $assignee = (int) ($_POST['assigned_admin_id'] ?? 0) ?: null;
        $pdo->prepare('UPDATE leads SET assigned_admin_id = ?, updated_at = ? WHERE id = ?')->execute([$assignee, Http::now(), $id]);
        AdminAuth::flash('success', 'اپراتور پیگیری لید به‌روزرسانی شد.');
    } elseif ($action === 'follow_up') {
        $days = (int) ($_POST['days'] ?? 0);
        if ($days >= 0 && $days <= 90) {
            $at = Http::now() + $days * 86_400_000;
            $pdo->prepare('UPDATE leads SET follow_up_at = ?, status = \'follow_up\', updated_at = ? WHERE id = ?')->execute([$at, Http::now(), $id]);
            AdminAuth::flash('success', 'یادآوری پیگیری تنظیم شد.');
        }
    }
    admin_redirect('lead_view.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT l.*, a.username AS assignee, b.name AS branch_name FROM leads l
    LEFT JOIN admins a ON a.id = l.assigned_admin_id
    LEFT JOIN branches b ON b.id = l.branch_id WHERE l.id = ?');
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) { AdminAuth::flash('error', 'چنین لیدی پیدا نشد.'); admin_redirect('leads.php'); }

$stmt = $pdo->prepare('SELECT le.*, a.username AS actor FROM lead_events le LEFT JOIN admins a ON a.id = le.admin_id WHERE le.lead_id = ? ORDER BY le.created_at DESC, le.id DESC LIMIT 40');
$stmt->execute([$id]);
$events = $stmt->fetchAll();
$staff = $pdo->query('SELECT id, username FROM admins ORDER BY id')->fetchAll();

admin_header($admin, 'پرونده لید: ' . $lead['full_name'], 'leads');
?>
<div class="grid cols-2" style="align-items:start">
  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>اطلاعات لید #<?= fa_num((string) $lead['id']) ?></h2><a class="sub" href="leads.php">→ بازگشت به فهرست</a></div>
      <div class="card-body">
        <div class="kv">
          <div>نام</div><div><?= e($lead['full_name']) ?></div>
          <div>موبایل</div><div class="num" dir="ltr" style="text-align:right"><?= e($lead['phone']) ?></div>
          <div>ایمیل</div><div dir="ltr" style="text-align:right"><?= e($lead['email'] !== '' ? $lead['email'] : '—') ?></div>
          <div>منبع آشنایی</div><div><?= e(LEAD_SOURCES[$lead['source']] ?? $lead['source']) ?></div>
          <div>شعبه</div><div><?= e($lead['branch_name'] ?? '—') ?></div>
          <div>وضعیت</div><div><span class="badge <?= $lead['status'] === 'won' ? 'green' : ($lead['status'] === 'lost' ? 'red' : 'accent') ?>"><?= e(LEAD_STATUSES[$lead['status']] ?? $lead['status']) ?></span></div>
          <div>اپراتور</div><div><?= e($lead['assignee'] ?? 'تعیین‌نشده') ?></div>
          <div>یادآوری پیگیری</div><div class="num"><?= $lead['follow_up_at'] ? jdate((int) $lead['follow_up_at']) : '—' ?></div>
          <div>ثبت اولیه</div><div class="num"><?= jdate((int) $lead['created_at']) ?></div>
          <div>یادداشت اولیه</div><div><?= e($lead['note'] !== '' ? $lead['note'] : '—') ?></div>
        </div>
      </div>
    </div>

    <?php if (AdminAuth::can($admin, 'crm.manage')): ?>
    <div class="card">
      <div class="card-head"><h2>اقدام‌های پیگیری</h2></div>
      <div class="card-body">
        <form method="post" style="margin-bottom:14px">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="set_status">
          <div class="form-grid">
            <div class="field"><label>تغییر وضعیت</label>
              <select name="status"><?php foreach (LEAD_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= $lead['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>اپراتور پیگیری</label>
              <select name="assigned_admin_id"><option value="">تعیین‌نشده</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $lead['assigned_admin_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['username']) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <button class="btn btn-primary" type="submit">ذخیره وضعیت و اپراتور</button>
        </form>
        <form method="post">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="follow_up">
          <div class="field"><label>تنظیم یادآوری پیگیری</label>
            <select name="days">
              <option value="0">امروز</option><option value="1">فردا</option><option value="3">۳ روز بعد</option>
              <option value="7">یک هفته بعد</option><option value="14">دو هفته بعد</option><option value="30">یک ماه بعد</option>
            </select>
          </div>
          <button class="btn" type="submit">تنظیم یادآوری</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if (AdminAuth::can($admin, 'crm.manage')): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>ثبت تماس / رویداد جدید</h2></div>
      <div class="card-body">
        <form method="post">
          <?= AdminAuth::csrfField() ?>
          <input type="hidden" name="action" value="add_event">
          <div class="form-grid">
            <div class="field"><label for="kind">نوع رویداد</label>
              <select id="kind" name="kind"><?php foreach (EVENT_KINDS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label for="outcome">نتیجه / توضیح</label><input id="outcome" name="outcome" required maxlength="200" placeholder="مثلاً: تماس گرفت، فردا برای مشاوره می‌آید"></div>
          </div>
          <button class="btn btn-primary" type="submit">ثبت در پرونده</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h2>تاریخچه پیگیری‌ها <span class="sub">(<?= fa_num(count($events)) ?> رویداد)</span></h2></div>
      <div class="card-body tight table-wrap">
        <table>
          <thead><tr><th>زمان</th><th>نوع</th><th>نتیجه</th><th>ثبت‌کننده</th></tr></thead>
          <tbody>
          <?php if (!$events): ?>
            <tr><td colspan="4"><div class="empty"><span class="glyph">📞</span>هنوز پیگیری‌ای ثبت نشده است.</div></td></tr>
          <?php endif; ?>
          <?php foreach ($events as $ev): ?>
            <tr>
              <td class="num"><?= jdate((int) $ev['created_at']) ?></td>
              <td><span class="badge gray"><?= e(EVENT_KINDS[$ev['kind']] ?? $ev['kind']) ?></span></td>
              <td style="white-space:normal"><?= e($ev['outcome']) ?></td>
              <td><?= e($ev['actor'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php admin_footer();
