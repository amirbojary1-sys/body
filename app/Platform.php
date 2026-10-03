<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

/**
 * Platform core (proposal §33, §35): one place that declares every module's
 * database schema for both drivers (MySQL / SQLite) and keeps existing
 * installations up to date. Adding a future module (Store, Cafe, IoT, Game,
 * Social, ...) means appending its tables here — the core never rewrites.
 *
 * Schema groups installed by this class:
 *   Branch      : branches                                   (§29 multi-branch)
 *   Access      : roles, role_permissions, admin_roles,
 *                 user_roles, users.branch_id                (§2 RBAC)
 *   CRM         : leads, lead_events                         (§3, §9)
 *   Membership  : membership_plans, subscriptions            (§5)
 *   Payments    : wallets, wallet_transactions               (§6 کیف پول)
 *   Token       : token_ledger                               (§11)
 *   Check-in    : visit_logs                                 (§4 تردد)
 *   Finance     : finance_transactions                       (§22)
 */
final class Platform
{
    public static function migrate(PDO $pdo, string $driver): void
    {
        $mysql = $driver === 'mysql';
        foreach (self::schema($mysql) as $ddl) $pdo->exec($ddl);
        self::addColumnIfNeeded($pdo, $mysql, 'users', 'branch_id', $mysql ? 'INT UNSIGNED NULL' : 'INTEGER NULL');
        self::seedDefaultBranch($pdo);
        Rbac::ensureDefaults($pdo);
    }

    /** @return string[] DDL statements, all idempotent (IF NOT EXISTS). */
    private static function schema(bool $mysql): array
    {
        $s = [];
        // column-type helpers (driver aware)
        $pk    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $bigpk = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int   = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $intN  = $mysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $ts    = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $ms    = $mysql ? 'BIGINT UNSIGNED NULL' : 'INTEGER';
        $money = $mysql ? 'BIGINT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $signed = $mysql ? 'BIGINT NOT NULL' : 'INTEGER NOT NULL';
        $v = static fn(int $n): string => $mysql ? "VARCHAR({$n})" : 'TEXT';
        $txt500 = $mysql ? 'VARCHAR(500) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'';
        $txt300 = $mysql ? 'VARCHAR(300) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'';
        $txt200 = $mysql ? 'VARCHAR(200) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'';
        $bool  = $mysql ? 'TINYINT(1)' : 'INTEGER';
        $tail  = $mysql ? ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ')';
        // foreign key (MySQL only; SQLite relies on its pragma with plain columns)
        $fk = static fn(string $owner, string $col, string $ref, string $action = 'CASCADE'): string =>
            $mysql ? ", CONSTRAINT fk_{$owner}_{$col} FOREIGN KEY ({$col}) REFERENCES {$ref}(id) ON DELETE {$action}" : '';

        // ----- Branches (§29) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS branches (
            id ' . $pk . ',
            name ' . $v(100) . ' NOT NULL,
            code ' . $v(20) . ' NOT NULL' . ($mysql ? '' : ' UNIQUE') . ',
            address ' . $v(255) . ' NOT NULL DEFAULT \'\',
            phone ' . $v(30) . ' NOT NULL DEFAULT \'\',
            is_active ' . $bool . ' NOT NULL DEFAULT 1,
            created_at ' . $ts . ($mysql ? ',
            UNIQUE KEY uq_branches_code (code)' : '') . $tail;

        // ----- RBAC (§2) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS roles (
            id ' . $pk . ',
            slug ' . $v(50) . ' NOT NULL' . ($mysql ? '' : ' UNIQUE') . ',
            title ' . $v(100) . ' NOT NULL,
            is_active ' . $bool . ' NOT NULL DEFAULT 1,
            is_system ' . $bool . ' NOT NULL DEFAULT 0,
            perms_seeded ' . $bool . ' NOT NULL DEFAULT 0,
            created_at ' . $ts . ($mysql ? ',
            UNIQUE KEY uq_roles_slug (slug)' : '') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id ' . $int . ' NOT NULL,
            permission ' . $v(80) . ' NOT NULL,
            PRIMARY KEY (role_id, permission)' . $fk('role_permissions', 'role_id', 'roles') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS admin_roles (
            admin_id ' . $int . ' NOT NULL,
            role_id ' . $int . ' NOT NULL,
            PRIMARY KEY (admin_id, role_id)' . $fk('admin_roles', 'admin_id', 'admins') . $fk('admin_roles', 'role_id', 'roles') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS user_roles (
            user_id ' . $int . ' NOT NULL,
            role_id ' . $int . ' NOT NULL,
            PRIMARY KEY (user_id, role_id)' . $fk('user_roles', 'user_id', 'users') . $fk('user_roles', 'role_id', 'roles') . $tail;

        // ----- CRM (§3, §9) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS leads (
            id ' . $pk . ',
            branch_id ' . $intN . ',
            full_name ' . $v(100) . ' NOT NULL,
            phone ' . $v(20) . ' NOT NULL,
            email ' . $v(254) . ' NOT NULL DEFAULT \'\',
            source ' . $v(30) . ' NOT NULL DEFAULT \'other\',
            status ' . $v(20) . ' NOT NULL DEFAULT \'new\',
            assigned_admin_id ' . $intN . ',
            note ' . $txt500 . ',
            follow_up_at ' . $ms . ',
            created_at ' . $ts . ',
            updated_at ' . $ts . ($mysql ? ',
            KEY idx_leads_status (status),
            KEY idx_leads_created (created_at)' : '') . $fk('leads', 'branch_id', 'branches', 'SET NULL') . $fk('leads', 'assigned_admin_id', 'admins', 'SET NULL') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS lead_events (
            id ' . $pk . ',
            lead_id ' . $int . ' NOT NULL,
            admin_id ' . $intN . ',
            kind ' . $v(20) . ' NOT NULL,
            outcome ' . $txt200 . ',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_lead_events_lead (lead_id)' : '') . $fk('lead_events', 'lead_id', 'leads') . $fk('lead_events', 'admin_id', 'admins', 'SET NULL') . $tail;

        // ----- Membership (§5) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS membership_plans (
            id ' . $pk . ',
            branch_id ' . $intN . ',
            name ' . $v(100) . ' NOT NULL,
            kind ' . $v(20) . ' NOT NULL DEFAULT \'monthly\',
            price ' . $money . ',
            duration_days ' . $int . ' NOT NULL DEFAULT 30,
            total_sessions ' . $int . ' NOT NULL DEFAULT 0,
            is_active ' . $bool . ' NOT NULL DEFAULT 1,
            created_at ' . $ts . $fk('membership_plans', 'branch_id', 'branches', 'SET NULL') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS subscriptions (
            id ' . $pk . ',
            user_id ' . $int . ' NOT NULL,
            plan_id ' . $int . ' NOT NULL,
            branch_id ' . $intN . ',
            starts_at ' . $ts . ',
            expires_at ' . $ms . ',
            sessions_total ' . $int . ' NOT NULL DEFAULT 0,
            sessions_used ' . $int . ' NOT NULL DEFAULT 0,
            status ' . $v(20) . ' NOT NULL DEFAULT \'active\',
            price ' . $money . ',
            note ' . $txt300 . ',
            created_by ' . $intN . ',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_subs_user (user_id),
            KEY idx_subs_status (status)' : '') . $fk('subscriptions', 'user_id', 'users') . $fk('subscriptions', 'plan_id', 'membership_plans', 'RESTRICT') . $fk('subscriptions', 'branch_id', 'branches', 'SET NULL') . $fk('subscriptions', 'created_by', 'admins', 'SET NULL') . $tail;

        // ----- Wallet (§6 کیف پول) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS wallets (
            user_id ' . $int . ' NOT NULL,
            balance ' . $signed . ' NOT NULL DEFAULT 0,
            updated_at ' . $ts . ',
            PRIMARY KEY (user_id)' . $fk('wallets', 'user_id', 'users') . $tail;

        $s[] = 'CREATE TABLE IF NOT EXISTS wallet_transactions (
            id ' . $bigpk . ',
            user_id ' . $int . ' NOT NULL,
            amount ' . $signed . ',
            kind ' . $v(20) . ' NOT NULL,
            balance_after ' . $signed . ',
            note ' . $txt300 . ',
            created_by ' . $intN . ',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_wtx_user (user_id)' : '') . $fk('wallet_transactions', 'user_id', 'users') . $fk('wallet_transactions', 'created_by', 'admins', 'SET NULL') . $tail;

        // ----- Token ledger (§11) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS token_ledger (
            id ' . $bigpk . ',
            user_id ' . $int . ' NOT NULL,
            amount ' . $signed . ',
            kind ' . $v(30) . ' NOT NULL,
            balance_after ' . $signed . ',
            note ' . $txt300 . ',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_token_user (user_id)' : '') . $fk('token_ledger', 'user_id', 'users') . $tail;

        // ----- Check-in / visits (§4) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS visit_logs (
            id ' . $bigpk . ',
            user_id ' . $int . ' NOT NULL,
            branch_id ' . $intN . ',
            method ' . $v(20) . ' NOT NULL DEFAULT \'manual\',
            direction ' . $v(5) . ' NOT NULL DEFAULT \'in\',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_visits_user (user_id),
            KEY idx_visits_created (created_at)' : '') . $fk('visit_logs', 'user_id', 'users') . $fk('visit_logs', 'branch_id', 'branches', 'SET NULL') . $tail;

        // ----- Finance (§22) -----
        $s[] = 'CREATE TABLE IF NOT EXISTS finance_transactions (
            id ' . $bigpk . ',
            branch_id ' . $intN . ',
            kind ' . $v(10) . ' NOT NULL,
            category ' . $v(30) . ' NOT NULL DEFAULT \'other\',
            amount ' . $money . ',
            note ' . $txt300 . ',
            created_by ' . $intN . ',
            created_at ' . $ts . ($mysql ? ',
            KEY idx_finance_created (created_at),
            KEY idx_finance_kind (kind)' : '') . $fk('finance_transactions', 'branch_id', 'branches', 'SET NULL') . $fk('finance_transactions', 'created_by', 'admins', 'SET NULL') . $tail;

        if (!$mysql) {
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_lead_events_lead ON lead_events(lead_id)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_subs_user ON subscriptions(user_id)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_subs_status ON subscriptions(status)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_wtx_user ON wallet_transactions(user_id)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_token_user ON token_ledger(user_id)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_visits_user ON visit_logs(user_id)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_visits_created ON visit_logs(created_at)';
            $s[] = 'CREATE INDEX IF NOT EXISTS idx_finance_created ON finance_transactions(created_at)';
        }
        return $s;
    }

    /** Lightweight column migration for pre-platform installations. */
    private static function addColumnIfNeeded(PDO $pdo, bool $mysql, string $table, string $column, string $definition): void
    {
        if ($mysql) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            $exists = (int) $stmt->fetchColumn() > 0;
        } else {
            $exists = false;
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
                if (($row['name'] ?? '') === $column) $exists = true;
            }
        }
        if (!$exists) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function seedDefaultBranch(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn() > 0) return;
        $pdo->prepare('INSERT INTO branches(name, code, address, phone, is_active, created_at) VALUES(?, ?, ?, ?, 1, ?)')
            ->execute(['شعبه مرکزی', 'MAIN', '', '', Http::now()]);
    }
}
