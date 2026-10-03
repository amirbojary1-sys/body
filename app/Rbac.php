<?php
declare(strict_types=1);
namespace FitBot;
use PDO;

/**
 * Role Based Access Control core of the platform (proposal §2, §32, §33).
 *
 * - The permission catalog is versioned in code: every module declares its
 *   capabilities, so installing a new module instantly exposes permissions.
 * - Roles live in the database: managers can build custom roles and adjust
 *   each system role from the admin panel (roles.php).
 * - `ceo` is the all-access system role; admins with is_super bypass checks.
 * - Permissions are also prepared for member accounts (user_roles) so roles
 *   like "عضو افتخاری" can carry member-facing capabilities later.
 */
final class Rbac
{
    /** System roles seeded on install (proposal §2). slug => [title, permissions|'*'] */
    public const SYSTEM_ROLES = [
        'ceo' => ['مدیرعامل', '*'],
        'internal_manager' => ['مدیر داخلی', ['users.view', 'users.manage', 'crm.view', 'crm.manage', 'memberships.view', 'memberships.manage', 'branches.view', 'staff.view', 'staff.manage', 'roles.view', 'reports.view']],
        'finance' => ['مالی و حسابداری', ['finance.view', 'finance.manage', 'memberships.view', 'reports.view']],
        'head_coach' => ['سرمربی', ['users.view', 'memberships.view', 'reports.view']],
        'coach' => ['مربی', ['users.view']],
        'hr' => ['منابع انسانی', ['staff.view', 'reports.view']],
        'reception' => ['پذیرش', ['users.view', 'users.manage', 'crm.view', 'crm.manage', 'memberships.view', 'memberships.manage']],
        'crm_operator' => ['اپراتور CRM', ['crm.view', 'crm.manage']],
        'warehouse' => ['انباردار', []],
        'cafe_manager' => ['مسئول کافه', []],
        'store_manager' => ['مسئول فروشگاه', []],
        'salon_manager' => ['مسئول آرایشگاه', []],
        'massage_manager' => ['مسئول ماساژ', []],
        'member' => ['عضو', []],
        'honorary_member' => ['عضو افتخاری', []],
        'guest' => ['مهمان', []],
    ];

    /** Roles that belong to member accounts, never offered for staff. */
    public const MEMBER_ROLE_SLUGS = ['member', 'honorary_member', 'guest'];

    /** module_key => ['title' => group label, 'perms' => [code => label]] */
    public static function catalog(): array
    {
        return [
            'members' => ['title' => 'اعضا و کاربران', 'perms' => [
                'users.view' => 'مشاهده فهرست اعضا',
                'users.manage' => 'مسدودسازی، فعال‌سازی و حذف اعضا',
            ]],
            'crm' => ['title' => 'CRM و پیگیری مشتری', 'perms' => [
                'crm.view' => 'مشاهده لیدها و پرونده مشتری',
                'crm.manage' => 'ثبت لید، تماس و پیگیری',
            ]],
            'memberships' => ['title' => 'عضویت و اشتراک', 'perms' => [
                'memberships.view' => 'مشاهده پلن‌ها و اشتراک اعضا',
                'memberships.manage' => 'ساخت پلن، ثبت و لغو اشتراک',
            ]],
            'finance' => ['title' => 'مالی و حسابداری', 'perms' => [
                'finance.view' => 'مشاهده تراکنش‌های مالی',
                'finance.manage' => 'ثبت درآمد و هزینه',
            ]],
            'access' => ['title' => 'سازمان، نقش‌ها و شعب', 'perms' => [
                'roles.view' => 'مشاهده نقش‌ها و دسترسی‌ها',
                'roles.manage' => 'ساخت و ویرایش نقش‌ها',
                'branches.view' => 'مشاهده شعب',
                'branches.manage' => 'مدیریت شعب',
                'staff.view' => 'مشاهده کارکنان',
                'staff.manage' => 'مدیریت کارکنان و حساب‌های پنل',
            ]],
            'reports' => ['title' => 'گزارش‌ها', 'perms' => [
                'reports.view' => 'مشاهده گزارش فعالیت و آمار',
            ]],
        ];
    }

    /** Flat list of every valid permission code. */
    public static function permissionCodes(): array
    {
        $codes = [];
        foreach (self::catalog() as $group) foreach ($group['perms'] as $code => $label) $codes[$code] = true;
        return array_keys($codes);
    }

    /** Idempotent seeding of the system roles and their default permissions. */
    public static function ensureDefaults(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        foreach (self::SYSTEM_ROLES as $slug => [$title, $perms]) {
            $stmt = $pdo->prepare('SELECT id, perms_seeded FROM roles WHERE slug = ?');
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->prepare('INSERT INTO roles(slug, title, is_system, perms_seeded, created_at) VALUES(?, ?, 1, 0, ?)')->execute([$slug, $title, Http::now()]);
                $row = ['id' => (int) $pdo->lastInsertId(), 'perms_seeded' => 0];
            }
            if ($slug === 'ceo') {
                // The CEO role always mirrors the full catalog (new modules included).
                self::syncPermissions((int) $row['id'], self::permissionCodes(), $pdo);
                continue;
            }
            if ((int) $row['perms_seeded'] === 1) continue; // keep manager customizations
            self::syncPermissions((int) $row['id'], $perms === '*' ? self::permissionCodes() : $perms, $pdo);
            $pdo->prepare('UPDATE roles SET perms_seeded = 1 WHERE id = ?')->execute([(int) $row['id']]);
        }
    }

    /** All roles with permission/assignment counters. */
    public static function roles(): array
    {
        return Database::connection()->query(
            'SELECT r.id, r.slug, r.title, r.is_system,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms,
                (SELECT COUNT(*) FROM admin_roles ar WHERE ar.role_id = r.id) AS admins,
                (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS members
             FROM roles r ORDER BY r.is_system DESC, r.id ASC'
        )->fetchAll();
    }

    /** One role plus its permission codes. */
    public static function role(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, slug, title, is_system, perms_seeded FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        if (!$role) return null;
        $stmt = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
        $stmt->execute([$id]);
        $role['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $role;
    }

    /** Creates a custom role. Returns the new id. */
    public static function createRole(string $title, string $slug, array $permissions): int
    {
        $title = trim(Http::text($title, 80));
        $slug = strtolower(trim($slug));
        if (mb_strlen($title) < 2) throw new ApiException(422, 'عنوان نقش باید دست‌کم ۲ نویسه باشد.', 'invalid_title');
        if ($slug === '') $slug = 'role-' . bin2hex(random_bytes(3));
        if (!preg_match('/^[a-z0-9_-]{2,50}$/', $slug)) throw new ApiException(422, 'شناسه نقش فقط می‌تواند حروف لاتین کوچک، عدد، خط تیره و زیرخط باشد.', 'invalid_slug');
        $pdo = Database::connection();
        try {
            $pdo->prepare('INSERT INTO roles(slug, title, is_system, perms_seeded, created_at) VALUES(?, ?, 0, 1, ?)')->execute([$slug, $title, Http::now()]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') throw new ApiException(422, 'این شناسه نقش قبلاً استفاده شده است.', 'slug_taken');
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        self::syncPermissions($id, $permissions);
        return $id;
    }

    public static function renameRole(int $id, string $title): void
    {
        $title = trim(Http::text($title, 80));
        if (mb_strlen($title) < 2) throw new ApiException(422, 'عنوان نقش باید دست‌کم ۲ نویسه باشد.', 'invalid_title');
        Database::connection()->prepare('UPDATE roles SET title = ? WHERE id = ?')->execute([$title, $id]);
    }

    /** Replaces the permission set of a role (admin edits are never overwritten by seeding). */
    public static function syncPermissions(int $roleId, array $permissions, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $valid = [];
        foreach (self::permissionCodes() as $code) $valid[$code] = true;
        $keep = [];
        foreach ($permissions as $code) if (is_string($code) && isset($valid[$code])) $keep[$code] = true;
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $stmt = $pdo->prepare('INSERT INTO role_permissions(role_id, permission) VALUES(?, ?)');
        foreach (array_keys($keep) as $code) $stmt->execute([$roleId, $code]);
        $pdo->prepare('UPDATE roles SET perms_seeded = 1 WHERE id = ?')->execute([$roleId]);
    }

    public static function deleteRole(int $roleId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, is_system FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();
        if (!$role) throw new ApiException(404, 'چنین نقشی وجود ندارد.', 'role_not_found');
        if ((int) $role['is_system'] === 1) throw new ApiException(422, 'نقش‌های سیستمی قابل حذف نیستند؛ فقط دسترسی‌هایشان را محدود کن.', 'system_role');
        $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
    }

    /** Syncs the whole role set of one staff account. */
    public static function assignAdminRoles(int $adminId, array $roleIds): void
    {
        $pdo = Database::connection();
        $ids = [];
        foreach ($roleIds as $rid) {
            $rid = filter_var($rid, FILTER_VALIDATE_INT);
            if ($rid !== false && $rid > 0) $ids[(int) $rid] = true;
        }
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug NOT IN (\'member\', \'honorary_member\', \'guest\')');
        $stmt->execute();
        $allowed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) $allowed[(int) $rid] = true;
        $pdo->prepare('DELETE FROM admin_roles WHERE admin_id = ?')->execute([$adminId]);
        $insert = $pdo->prepare('INSERT INTO admin_roles(admin_id, role_id) VALUES(?, ?)');
        foreach (array_keys($ids) as $rid) if (isset($allowed[$rid])) $insert->execute([$adminId, $rid]);
    }

    /** @return array<int, array{id:int,slug:string,title:string}> */
    public static function rolesOfAdmin(int $adminId): array
    {
        $stmt = Database::connection()->prepare('SELECT r.id, r.slug, r.title FROM admin_roles ar JOIN roles r ON r.id = ar.role_id WHERE ar.admin_id = ? ORDER BY r.id');
        $stmt->execute([$adminId]);
        return $stmt->fetchAll();
    }

    /** Distinct permission codes of one staff account; the ceo role implies everything. */
    public static function permissionsOfAdmin(int $adminId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.slug FROM admin_roles ar JOIN roles r ON r.id = ar.role_id WHERE ar.admin_id = ?');
        $stmt->execute([$adminId]);
        if (in_array('ceo', $stmt->fetchAll(PDO::FETCH_COLUMN), true)) return self::permissionCodes();
        $stmt = $pdo->prepare('SELECT DISTINCT rp.permission FROM admin_roles ar JOIN role_permissions rp ON rp.role_id = ar.role_id WHERE ar.admin_id = ?');
        $stmt->execute([$adminId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
