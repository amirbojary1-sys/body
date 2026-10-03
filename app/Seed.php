<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

/**
 * One-time demo/sample data so a fresh installation looks alive:
 * plans, services, store & cafe products, a demo member with wallet,
 * tokens, subscription, visits, reservations and orders, sample leads
 * and finance entries. Runs only when the relevant tables are empty.
 *
 * Demo member login: demo@fitbot.ir / Demo@12345
 */
final class Seed
{
    public static function demo(PDO $pdo): void
    {
        if (Config::get('FITBOT_NO_DEMO') === '1') return; // tests / CI
        if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) return;
        if ((int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() > 0) return;
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return;
        $now = Http::now();
        $day = 86_400_000;
        $branch = (int) $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();

        // ---------- membership plans (§5) ----------
        $plans = [
            ['ماهانه نامحدود', 'monthly', 25_000, 30, 0],
            ['سه‌ماهه ویژه', 'monthly', 650_000, 90, 0],
            ['۱۲ جلسه‌ای', 'session', 3_000, 90, 12],
            ['VIP سالانه', 'vip', 2_400_000, 365, 0],
            ['دانشجویی ماهانه', 'monthly', 180_000, 30, 0],
            ['اشتراک اپ پلاس', 'app', 29_000, 30, 0],
        ];
        $stmt = $pdo->prepare('INSERT INTO membership_plans(branch_id, name, kind, price, duration_days, total_sessions, is_active, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($plans as [$name, $kind, $price, $days, $sessions]) $stmt->execute([$branch, $name, $kind, $price, $days, $sessions, $now - 60 * $day]);

        // ---------- services (§7) ----------
        $services = [
            ['تمرین با مربی خصوصی', 'coach', 60, 80_000, 1],
            ['کلاس گروهی بدنسازی', 'class', 75, 35_000, 15],
            ['ماساژ ورزشی', 'massage', 45, 95_000, 2],
            ['آرایش مردانه', 'salon', 30, 4_000, 1],
            ['فیزیوتراپی', 'physio', 45, 120_000, 1],
            ['مشاوره پزشک ورزشی', 'med', 30, 15_000, 1],
            ['استخر شنا', 'pool', 60, 3_000, 8],
            ['دستگاه لاغری', 'slimming', 40, 70_000, 3],
            ['پارکینگ باشگاه', 'parking', 120, 2_000, 40],
            ['کمد روزانه', 'locker', 120, 3_000, 30],
        ];
        $stmt = $pdo->prepare('INSERT INTO services(branch_id, name, category, duration_minutes, price, capacity, is_active, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($services as [$name, $cat, $min, $price, $cap]) $stmt->execute([$branch, $name, $cat, $min, $price, $cap, $now - 60 * $day]);

        // ---------- products: store & cafe (§8, §27) ----------
        // [department, name, category, price, stock, low]
        $products = [
            ['store', 'وی پروتئین ایزوله ۲ کیلو', 'مکمل', 485_000, 12, 4],
            ['store', 'کراتین مونوهیدرات ۵۰۰ گرم', 'مکمل', 220_000, 18, 5],
            ['store', 'پروتئین بار (بسته ۱۲ عددی)', 'مکمل', 98_000, 15, 5],
            ['store', 'شیکر ۷۵۰ میلی‌لیتر', 'لوازم', 35_000, 40, 10],
            ['store', 'دستکش تمرین', 'لوازم', 29_000, 25, 8],
            ['store', 'کمربند وزنه', 'لوازم', 85_000, 8, 3],
            ['store', 'تی‌شرت تمرین تن‌پوش', 'پوشاک', 62_000, 30, 10],
            ['store', 'روبنده مچ', 'لوازم', 15_000, 50, 15],
            ['cafe', 'پروتئین شیک موز', 'نوشیدنی', 32_000, 59, 20],
            ['cafe', 'لاته پروتئینی', 'نوشیدنی', 38_000, 80, 20],
            ['cafe', 'آمریکانو', 'نوشیدنی', 18_000, 98, 30],
            ['cafe', 'اسموتی توت‌فرنگی', 'نوشیدنی', 42_000, 50, 15],
            ['cafe', 'انرژی زِرو', 'نوشیدنی', 25_000, 90, 25],
            ['cafe', 'آب معدنی', 'نوشیدنی', 4_000, 200, 50],
        ];
        $stmt = $pdo->prepare('INSERT INTO products(department, name, category, price, stock, low_stock, is_active, created_at) VALUES(?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($products as [$dept, $name, $cat, $price, $stock, $low]) $stmt->execute([$dept, $name, $cat, $price, $stock, $low, $now - 45 * $day]);
        // opening-stock movements (purchase = displayed stock + what demo orders consumed)
        $open = ['پروتئین شیک موز' => 60, 'آمریکانو' => 100];
        $stmt = $pdo->prepare('INSERT INTO inventory_movements(product_id, qty, kind, note, admin_id, created_at) VALUES(?, ?, \'purchase\', \'موجودی اولیه\', 1, ?)');
        foreach ($pdo->query('SELECT id, name, stock FROM products') as $prod) {
            $base = (int) $prod['stock'] + ($open[$prod['name']] ?? 0);
            $stmt->execute([(int) $prod['id'], $base, $now - 45 * $day]);
        }

        // ---------- demo member ----------
        $pdo->prepare('INSERT INTO users(email, name, password_hash, is_active, last_login_at, branch_id, created_at) VALUES(?, ?, ?, 1, ?, ?, ?)')
            ->execute(['demo@fitbot.ir', 'مهدی نمونه', '$2y$12$kPtOKutDBrwr6vtf3ziKaueTz.8/Hhl2n5jR4g0T/caCBX3LdE5Ie', $now - $day, $branch, $now - 90 * $day]);
        $member = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_states(user_id, data, revision, updated_at) VALUES(?, \'null\', 0, ?)')->execute([$member, $now]);

        // wallet: 200,000 credit - 75,000 cafe purchase = 125,000 (تومان)
        $pdo->prepare('INSERT INTO wallets(user_id, balance, updated_at) VALUES(?, 125000, ?)')->execute([$member, $now - 10 * $day]);
        $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, 200000, \'credit\', 200000, \'شارژ اولیه حساب\', 1, ?)')->execute([$member, $now - 30 * $day]);
        $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, -75000, \'debit\', 125000, \'خرید از کافه\', NULL, ?)')->execute([$member, $now - 10 * $day]);

        // tokens: 500 welcome - 160 game = 340
        $pdo->prepare('INSERT INTO token_ledger(user_id, amount, kind, balance_after, note, created_at) VALUES(?, 500, \'issue\', 500, \'هدیه خوش‌آمدگویی\', ?)')->execute([$member, $now - 90 * $day]);
        $pdo->prepare('INSERT INTO token_ledger(user_id, amount, kind, balance_after, note, created_at) VALUES(?, -160, \'spend\', 340, \'شرکت در بازی دوستانه\', ?)')->execute([$member, $now - 12 * $day]);

        // subscription: سه‌ماهه ویژه (30 days used, 60 left)
        $plan = (int) $pdo->query('SELECT id FROM membership_plans WHERE name = \'سه‌ماهه ویژه\'')->fetchColumn();
        $pdo->prepare('INSERT INTO subscriptions(user_id, plan_id, branch_id, starts_at, expires_at, sessions_total, sessions_used, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, 0, 0, \'active\', 650000, \'ثبت در پذیرش\', 1, ?)')
            ->execute([$member, $plan, $branch, $now - 30 * $day, $now + 60 * $day, $now - 30 * $day]);

        // visits over the past 3 weeks (in/out pairs, card & qr)
        $stmt = $pdo->prepare('INSERT INTO visit_logs(user_id, branch_id, method, direction, created_at) VALUES(?, ?, ?, ?, ?)');
        for ($i = 20; $i >= 2; $i -= 2) {
            $at = $now - $i * $day;
            $stmt->execute([$member, $branch, $i % 4 === 0 ? 'qr' : 'card', 'in', $at - 6 * 3600_000]);
            $stmt->execute([$member, $branch, $i % 4 === 0 ? 'qr' : 'card', 'out', $at - 1 * 3600_000]);
        }

        // reservations: one confirmed (private coach), one pending (massage), one done (class)
        $coach = (int) $pdo->query('SELECT id FROM services WHERE category = \'coach\'')->fetchColumn();
        $massage = (int) $pdo->query('SELECT id FROM services WHERE category = \'massage\'')->fetchColumn();
        $class = (int) $pdo->query('SELECT id FROM services WHERE category = \'class\'')->fetchColumn();
        $pdo->prepare('INSERT INTO reservations(service_id, user_id, branch_id, reserved_at, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, \'confirmed\', 80000, \'جلسه تمرین با مربی\', 1, ?)')
            ->execute([$coach, $member, $branch, $now + $day, $now - 2 * $day]);
        $pdo->prepare('INSERT INTO reservations(service_id, user_id, branch_id, reserved_at, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, \'pending\', 95000, \'درخواست از پنل عضو\', NULL, ?)')
            ->execute([$massage, $member, $branch, $now + 3 * $day, $now - 4 * 3600_000]);
        $pdo->prepare('INSERT INTO reservations(service_id, user_id, branch_id, reserved_at, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, \'done\', 35000, \'کلاس کامل شده\', 1, ?)')
            ->execute([$class, $member, $branch, $now - 5 * $day, $now - 7 * $day]);

        // orders: paid wallet shake (done) + pending cash americano
        $shake = (int) $pdo->query('SELECT id FROM products WHERE name = \'پروتئین شیک موز\'')->fetchColumn();
        $americano = (int) $pdo->query('SELECT id FROM products WHERE name = \'آمریکانو\'')->fetchColumn();
        $pdo->prepare('INSERT INTO orders(user_id, department, status, total, pay_method, created_at) VALUES(?, \'cafe\', \'done\', 32000, \'wallet\', ?)')->execute([$member, $now - 2 * $day]);
        $pdo->prepare('INSERT INTO order_items(order_id, product_id, qty, unit_price) VALUES(?, ?, 1, 32000)')->execute([(int) $pdo->lastInsertId(), $shake]);
        $pdo->prepare('INSERT INTO orders(user_id, department, status, total, pay_method, created_at) VALUES(?, \'cafe\', \'pending\', 36000, \'cash\', ?)')->execute([$member, $now - 4 * 3600_000]);
        $pdo->prepare('INSERT INTO order_items(order_id, product_id, qty, unit_price) VALUES(?, ?, 2, 18000)')->execute([(int) $pdo->lastInsertId(), $americano]);

        // ---------- sample leads (§3, §9) ----------
        $leads = [
            ['علی رضایی', '09121234567', 'ali@test.ir', 'instagram', 'consult', 1, 'برای برنامه کاهش وزن پرسید', $now + $day],
            ['سارا کریمی', '09351112233', 'sara@test.ir', 'phone', 'new', null, 'از طریق تماس؛ علاقه‌مند به کلاس صبح', null],
            ['حسین موسوی', '09127778899', '', 'walk_in', 'won', 1, 'مراجعه حضوری؛ عضو شد', null],
            ['نگار احمدی', '09109876543', 'negar@test.ir', 'ads', 'lost', 1, 'قیمت مناسب نمی‌دانست', null],
        ];
        $stmt = $pdo->prepare('INSERT INTO leads(branch_id, full_name, phone, email, source, status, assigned_admin_id, note, follow_up_at, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($leads as $i => [$name, $phone, $email, $source, $status, $assignee, $note, $follow]) {
            $stmt->execute([$branch, $name, $phone, $email, $source, $status, $assignee, $note, $follow, $now - (12 - 2 * $i) * $day, $now - (10 - 2 * $i) * $day]);
        }
        $pdo->prepare('INSERT INTO lead_events(lead_id, admin_id, kind, outcome, created_at) VALUES(1, 1, \'call\', \'تماس اولیه؛ فردا برای مشاوره می‌آید\', ?)')->execute([$now - 6 * $day]);

        // ---------- finance entries (§22) ----------
        $fin = $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, 1, ?)');
        $fin->execute([$branch, 'income', 'membership', 650_000, 'فروش پلن سه‌ماهه ویژه', $now - 30 * $day]);
        $fin->execute([$branch, 'income', 'service', 35_000, 'کلاس گروهی بدنسازی', $now - 5 * $day]);
        $fin->execute([$branch, 'income', 'cafe', 32_000, 'پروتئین شیک موز', $now - 2 * $day]);
        $fin->execute([$branch, 'expense', 'rent', 4_500_000, 'اجاره ماهانه مجموعه', $now - 25 * $day]);
        $fin->execute([$branch, 'expense', 'salary', 6_200_000, 'حقوق پرسنل', $now - 20 * $day]);
        $fin->execute([$branch, 'expense', 'purchase', 85_000, 'خرید مواد اولیه کافه', $now - 15 * $day]);
        $fin->execute([$branch, 'expense', 'current', 32_000, 'هزینه‌های جاری', $now - 10 * $day]);
    }
}
