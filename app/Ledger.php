<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

/**
 * Transactional service layer shared by the admin panel and the member
 * portal: wallet, token ledger, store/cafe orders and service reservations.
 * Every money/stock mutation happens here inside a DB transaction so the
 * admin pages and panel.php can never diverge in business rules.
 */
final class Ledger
{
    // ---------------------------------------------------------------- wallet

    /** @return int new balance. Throws on insufficient funds for debits. */
    public static function wallet(int $userId, int $amount, string $note = '', ?int $adminId = null, bool $requireBalance = true): int
    {
        if ($amount === 0 || abs($amount) > 1_000_000_000) throw new ApiException(422, 'مبلغ کیف پول نامعتبر است.', 'invalid_amount');
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->prepare('INSERT INTO wallets(user_id, balance, updated_at) VALUES(?, 0, ?)')->execute([$userId, $now]);
                $balance = 0;
            } else {
                $balance = (int) $row['balance'];
            }
            $newBalance = $balance + $amount;
            if ($requireBalance && $newBalance < 0) throw new ApiException(422, 'اعتبار کیف پول کافی نیست.', 'insufficient_wallet');
            $pdo->prepare('UPDATE wallets SET balance = ?, updated_at = ? WHERE user_id = ?')->execute([$newBalance, $now, $userId]);
            $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $amount, $amount > 0 ? 'credit' : 'debit', $newBalance, $note, $adminId, $now]);
            $pdo->commit();
            return $newBalance;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function walletBalance(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE((SELECT balance FROM wallets WHERE user_id = ?), 0)');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------- tokens

    public static function tokens(int $userId, int $amount, string $kind, string $note = ''): int
    {
        if ($amount === 0) throw new ApiException(422, 'مقدار توکن نامعتبر است.', 'invalid_amount');
        if (!in_array($kind, ['issue', 'earn', 'spend', 'refund', 'adjust', 'transfer'], true)) $kind = 'adjust';
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM token_ledger WHERE user_id = ?');
            $stmt->execute([$userId]);
            $balance = (int) $stmt->fetchColumn() + $amount;
            if ($balance < 0) throw new ApiException(422, 'توکن کافی نیست.', 'insufficient_tokens');
            $pdo->prepare('INSERT INTO token_ledger(user_id, amount, kind, balance_after, note, created_at) VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $amount, $kind, $balance, $note, $now]);
            $pdo->commit();
            return $balance;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function tokenBalance(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(amount), 0) FROM token_ledger WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------- orders

    /** Places a store/cafe order. Wallet payment debits immediately; cash is collected at the counter. */
    public static function placeOrder(int $userId, int $productId, int $qty, string $payMethod): array
    {
        if ($qty < 1 || $qty > 20) throw new ApiException(422, 'تعداد باید بین ۱ تا ۲۰ باشد.', 'invalid_qty');
        if (!in_array($payMethod, ['wallet', 'cash'], true)) throw new ApiException(422, 'روش پرداخت نامعتبر است.', 'invalid_pay_method');
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product || (int) $product['is_active'] !== 1) throw new ApiException(404, 'این محصول فعال نیست.', 'product_not_found');
            if ((int) $product['stock'] < $qty) throw new ApiException(422, 'موجودی این محصول کافی نیست.', 'out_of_stock');
            $total = (int) $product['price'] * $qty;
            if ($payMethod === 'wallet') {
                $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
                $stmt->execute([$userId]);
                $balance = $row = $stmt->fetch();
                $balance = $row ? (int) $row['balance'] : 0;
                if ($balance < $total) throw new ApiException(422, 'اعتبار کیف پول برای این خرید کافی نیست.', 'insufficient_wallet');
            }
            // reserve stock + sale movement
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$qty, $productId]);
            $pdo->prepare('INSERT INTO inventory_movements(product_id, qty, kind, note, admin_id, created_at) VALUES(?, ?, \'sale\', ?, NULL, ?)')
                ->execute([$productId, -$qty, 'سفارش عضو', $now]);
            $status = $payMethod === 'wallet' ? 'paid' : 'pending';
            $pdo->prepare('INSERT INTO orders(user_id, department, status, total, pay_method, created_at) VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $product['department'], $status, $total, $payMethod, $now]);
            $orderId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO order_items(order_id, product_id, qty, unit_price) VALUES(?, ?, ?, ?)')
                ->execute([$orderId, $productId, $qty, (int) $product['price']]);
            if ($payMethod === 'wallet') {
                // debit wallet inside the same transaction
                $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                $balance = $row ? (int) $row['balance'] : 0;
                $newBalance = $balance - $total;
                if ($row) $pdo->prepare('UPDATE wallets SET balance = ?, updated_at = ? WHERE user_id = ?')->execute([$newBalance, $now, $userId]);
                else $pdo->prepare('INSERT INTO wallets(user_id, balance, updated_at) VALUES(?, ?, ?)')->execute([$userId, $newBalance, $now]);
                $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, ?, \'debit\', ?, ?, NULL, ?)')
                    ->execute([$userId, -$total, $newBalance, $product['department'] === 'cafe' ? 'خرید از کافه' : 'خرید از فروشگاه', $now]);
                $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(NULL, \'income\', ?, ?, ?, NULL, ?)')
                    ->execute([$product['department'], $total, 'فروش ' . $product['name'] . ' (سفارش #' . $orderId . ')', $now]);
            }
            $pdo->commit();
            return ['orderId' => $orderId, 'total' => $total, 'status' => $status];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Admin: pending → preparing → done; income for cash orders is booked on completion. */
    public static function setOrderStatus(int $orderId, string $status, ?int $adminId): void
    {
        if (!in_array($status, ['preparing', 'done', 'canceled'], true)) throw new ApiException(422, 'وضعیت نامعتبر است.', 'invalid_status');
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) throw new ApiException(404, 'سفارش پیدا نشد.', 'order_not_found');
            if ($order['status'] === 'done' || $order['status'] === 'canceled') throw new ApiException(422, 'این سفارش بسته شده است.', 'order_closed');
            if ($status === 'canceled') {
                // restock items + refund a wallet payment
                foreach ($pdo->query('SELECT product_id, qty FROM order_items WHERE order_id = ' . $orderId)->fetchAll() as $item) {
                    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([(int) $item['qty'], (int) $item['product_id']]);
                    $pdo->prepare('INSERT INTO inventory_movements(product_id, qty, kind, note, admin_id, created_at) VALUES(?, ?, \'return\', \'لغو سفارش\', ?, ?)')
                        ->execute([(int) $item['product_id'], (int) $item['qty'], $adminId, $now]);
                }
                if ($order['pay_method'] === 'wallet') {
                    $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ?');
                    $stmt->execute([(int) $order['user_id']]);
                    $row = $stmt->fetch();
                    $balance = $row ? (int) $row['balance'] : 0;
                    $newBalance = $balance + (int) $order['total'];
                    if ($row) $pdo->prepare('UPDATE wallets SET balance = ?, updated_at = ? WHERE user_id = ?')->execute([$newBalance, $now, (int) $order['user_id']]);
                    else $pdo->prepare('INSERT INTO wallets(user_id, balance, updated_at) VALUES(?, ?, ?)')->execute([(int) $order['user_id'], $newBalance, $now]);
                    $pdo->prepare('INSERT INTO wallet_transactions(user_id, amount, kind, balance_after, note, created_by, created_at) VALUES(?, ?, \'credit\', ?, ?, ?, ?)')
                        ->execute([(int) $order['user_id'], (int) $order['total'], $newBalance, 'برگشت وجه لغو سفارش #' . $orderId, $adminId, $now]);
                    $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(NULL, \'expense\', \'other\', ?, ?, ?, ?)')
                        ->execute([(int) $order['total'], 'برگشت وجه لغو سفارش #' . $orderId, $adminId, $now]);
                }
            } elseif ($status === 'done' && $order['pay_method'] === 'cash') {
                $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(NULL, \'income\', ?, ?, ?, ?, ?)')
                    ->execute([$order['department'], (int) $order['total'], 'تحویل سفارش نقدی #' . $orderId, $adminId, $now]);
            }
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------ reservations

    /** Member or reception books a service slot (pending until confirmation). */
    public static function reserve(int $userId, int $serviceId, int $reservedAt, string $note = '', ?int $adminId = null): int
    {
        $pdo = Database::connection();
        $now = Http::now();
        if ($reservedAt < $now - 3_600_000 || $reservedAt > $now + 60 * 86_400_000) throw new ApiException(422, 'زمان رزرو باید از اکنون تا حداکثر ۶۰ روز آینده باشد.', 'invalid_time');
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();
        if (!$service || (int) $service['is_active'] !== 1) throw new ApiException(404, 'این خدمت فعال نیست.', 'service_not_found');
        $pdo->prepare('INSERT INTO reservations(service_id, user_id, branch_id, reserved_at, status, price, note, created_by, created_at) VALUES(?, ?, ?, ?, \'pending\', ?, ?, ?, ?)')
            ->execute([$serviceId, $userId, $service['branch_id'], $reservedAt, (int) $service['price'], trim(Http::text($note, 300)), $adminId, $now]);
        return (int) $pdo->lastInsertId();
    }

    /** Admin transitions a reservation; completing it books the service income. */
    public static function setReservationStatus(int $reservationId, string $status, ?int $adminId): void
    {
        if (!in_array($status, ['confirmed', 'done', 'canceled'], true)) throw new ApiException(422, 'وضعیت نامعتبر است.', 'invalid_status');
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT r.*, s.name AS service_name FROM reservations r JOIN services s ON s.id = r.service_id WHERE r.id = ?');
            $stmt->execute([$reservationId]);
            $r = $stmt->fetch();
            if (!$r) throw new ApiException(404, 'رزرو پیدا نشد.', 'reservation_not_found');
            if ($r['status'] === 'done' || $r['status'] === 'canceled') throw new ApiException(422, 'این رزرو بسته شده است.', 'reservation_closed');
            if ($status === 'done' && (int) $r['price'] > 0) {
                $pdo->prepare('INSERT INTO finance_transactions(branch_id, kind, category, amount, note, created_by, created_at) VALUES(?, \'income\', \'service\', ?, ?, ?, ?)')
                    ->execute([$r['branch_id'], (int) $r['price'], 'ارائه خدمت «' . $r['service_name'] . '» (رزرو #' . $reservationId . ')', $adminId, $now]);
            }
            $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$status, $reservationId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------ inventory

    /** Stock movement (purchase/waste/adjust) with signed quantity. */
    public static function stock(int $productId, int $qty, string $kind, string $note, ?int $adminId): void
    {
        if ($qty === 0 || abs($qty) > 100_000) throw new ApiException(422, 'تعداد نامعتبر است.', 'invalid_qty');
        if (!in_array($kind, ['purchase', 'waste', 'adjust'], true)) $kind = 'adjust';
        $pdo = Database::connection();
        $now = Http::now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new ApiException(404, 'محصول پیدا نشد.', 'product_not_found');
            $newStock = (int) $product['stock'] + $qty;
            if ($newStock < 0) throw new ApiException(422, 'موجودی منفی نمی‌شود.', 'negative_stock');
            $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$newStock, $productId]);
            $pdo->prepare('INSERT INTO inventory_movements(product_id, qty, kind, note, admin_id, created_at) VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$productId, $qty, $kind, trim(Http::text($note, 200)), $adminId, $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
