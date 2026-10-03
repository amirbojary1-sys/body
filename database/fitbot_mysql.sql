-- ============================================================
--  FitBot Platform — فایل دیتابیس MySQL (نسخه ۵ پلتفرم باشگاه)
--
--  ★ این نسخه شامل جداول پلتفرم مدیریت باشگاه است:
--      شعب، نقش‌ها و دسترسی‌ها (RBAC)، CRM و لیدها،
--      پلن‌ها و اشتراک‌های عضویت، کیف پول، دفتر کل توکن،
--      تردد اعضا، تراکنش‌های مالی، خدمات و رزروها،
--      محصولات فروشگاه/کافه، سفارش‌ها، انبار و متادیتا
--
--  ★ داده‌های نمونه (پلن‌ها، خدمات، محصولات فروشگاه و کافه،
--      عضو دمو با کیف پول/توکن/اشتراک/تردد/رزرو/سفارش و لیدها)
--      در اولین اجرای برنامه به‌صورت خودکار ساخته می‌شوند.
--      عضو نمونه برای ورود: demo@fitbot.ir / Demo@12345
--
--  روش ایمپورت با phpMyAdmin:
--    1) یک دیتابیس بساز (مثلاً fitbot) — اگر نسخه قبلی را داشته‌ای
--       و می‌خواهی از صفر شروع کنی، اول جدول‌های قدیمی را Drop کن
--    2) تب Import → انتخاب همین فایل → Go
--  روش ایمپورت با خط فرمان:
--    mysql -u root -p < fitbot_mysql.sql
--
--  نکته‌ها:
--    - نقش‌های سیستمی (مدیرعامل، پذیرش، مالی و…) در اولین اجرای
--      برنامه به‌صورت خودکار ساخته و به‌روز می‌شوند؛ در این فایل
--      فقط ساختار آمده است.
--    - اگر دیتابیس نسخه قبلی را ایمپورت کرده‌ای، لازم نیست این
--      فایل را دوباره ایمپورت کنی؛ برنامه جداول جدید را در اولین
--      اجرا خودش می‌سازد (مهاجرت خودکار).
--
--  حساب مدیر پیش‌فرض (بعد از اولین ورود رمز آن را عوض کن):
--    نام کاربری: admin
--    رمز عبور : Admin@12345
-- ============================================================

CREATE DATABASE IF NOT EXISTS `fitbot`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `fitbot`;

-- ------------------------------------------------------------
-- شعب (§29 چند-شعبه)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `branches` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)    NOT NULL,
  `code`       VARCHAR(20)     NOT NULL,
  `address`    VARCHAR(255)    NOT NULL DEFAULT '',
  `phone`      VARCHAR(30)     NOT NULL DEFAULT '',
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- کاربران (اعضای سایت/اپلیکیشن)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(254)    NOT NULL,
  `name`          VARCHAR(100)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1=فعال، 0=مسدودشده توسط مدیر',
  `last_login_at` BIGINT UNSIGNED NULL COMMENT 'آخرین ورود موفق (میلی‌ثانیه)',
  `branch_id`     INT UNSIGNED    NULL COMMENT 'شعبه ثبت‌نام/فعالیت عضو',
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- داده/سوابق ورزشی هر کاربر (snapshot با شماره نسخه)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_states` (
  `user_id`    INT UNSIGNED    NOT NULL,
  `data`       LONGTEXT        NOT NULL,
  `revision`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `updated_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_states_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- محدودیت نرخ درخواست‌ها (rate limit)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`       VARCHAR(64)     NOT NULL,
  `window_start` BIGINT UNSIGNED NOT NULL,
  `hits`         INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- کارکنان پنل مدیریت (جدا از کاربران عادی)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)     NOT NULL,
  `email`         VARCHAR(254)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `is_super`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '۱ = مدیر کل (عبور از همه محدودیت‌ها)',
  `last_login_at` BIGINT UNSIGNED NULL,
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- گزارش فعالیت کارکنان (audit log §32)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_activity` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED     NULL,
  `admin_name` VARCHAR(100)     NOT NULL,
  `action`     VARCHAR(50)      NOT NULL COMMENT 'login, user_banned, lead_created, ...',
  `entity`     VARCHAR(30)      NOT NULL DEFAULT '' COMMENT 'user/lead/role/branch/plan/...',
  `entity_id`  INT UNSIGNED     NULL,
  `details`    VARCHAR(500)     NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)      NOT NULL DEFAULT '',
  `created_at` BIGINT UNSIGNED  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  CONSTRAINT `fk_activity_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- نقش‌ها و دسترسی‌ها (RBAC §2)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(50)     NOT NULL,
  `title`        VARCHAR(100)    NOT NULL,
  `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
  `is_system`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'نقش‌های سیستمی پروپوزال',
  `perms_seeded` TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '۱ = دسترسی‌های پیش‌فرد اعمال شده',
  `created_at`   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`    INT UNSIGNED NOT NULL,
  `permission` VARCHAR(80)  NOT NULL,
  PRIMARY KEY (`role_id`, `permission`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_roles` (
  `admin_id` INT UNSIGNED NOT NULL,
  `role_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`admin_id`, `role_id`),
  CONSTRAINT `fk_admin_roles_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- CRM: لیدها و رویدادهای پیگیری (§3, §9)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `branch_id`         INT UNSIGNED    NULL,
  `full_name`         VARCHAR(100)    NOT NULL,
  `phone`             VARCHAR(20)     NOT NULL,
  `email`             VARCHAR(254)    NOT NULL DEFAULT '',
  `source`            VARCHAR(30)     NOT NULL DEFAULT 'other' COMMENT 'instagram/ads/phone/walk_in/referral/other',
  `status`            VARCHAR(20)     NOT NULL DEFAULT 'new' COMMENT 'new/contacted/consult/follow_up/won/lost',
  `assigned_admin_id` INT UNSIGNED    NULL COMMENT 'اپراتور پیگیری',
  `note`              VARCHAR(500)    NOT NULL DEFAULT '',
  `follow_up_at`      BIGINT UNSIGNED NULL COMMENT 'یادآوری پیگیری (میلی‌ثانیه)',
  `created_at`        BIGINT UNSIGNED NOT NULL,
  `updated_at`        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_created` (`created_at`),
  CONSTRAINT `fk_leads_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_admin` FOREIGN KEY (`assigned_admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_events` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `lead_id`    INT UNSIGNED    NOT NULL,
  `admin_id`   INT UNSIGNED    NULL,
  `kind`       VARCHAR(20)     NOT NULL COMMENT 'call/note/meeting/other',
  `outcome`    VARCHAR(200)    NOT NULL DEFAULT '',
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lead_events_lead` (`lead_id`),
  CONSTRAINT `fk_lead_events_lead` FOREIGN KEY (`lead_id`)
    REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lead_events_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- عضویت: پلن‌ها و اشتراک اعضا (§5)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `membership_plans` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `branch_id`      INT UNSIGNED    NULL,
  `name`           VARCHAR(100)    NOT NULL,
  `kind`           VARCHAR(20)     NOT NULL DEFAULT 'monthly' COMMENT 'monthly/session/time/vip/honorary/coach/combo/app/other',
  `price`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تومان',
  `duration_days`  INT UNSIGNED    NOT NULL DEFAULT 30 COMMENT '۰ = بدون انقضا',
  `total_sessions` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '۰ = نامحدود',
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_plans_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED    NOT NULL,
  `plan_id`        INT UNSIGNED    NOT NULL,
  `branch_id`      INT UNSIGNED    NULL,
  `starts_at`      BIGINT UNSIGNED NOT NULL,
  `expires_at`     BIGINT UNSIGNED NULL,
  `sessions_total` INT UNSIGNED    NOT NULL DEFAULT 0,
  `sessions_used`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active/canceled',
  `price`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مبلغ فروش (تومان)',
  `note`           VARCHAR(300)    NOT NULL DEFAULT '',
  `created_by`     INT UNSIGNED    NULL,
  `created_at`     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subs_user` (`user_id`),
  KEY `idx_subs_status` (`status`),
  CONSTRAINT `fk_subs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subs_plan` FOREIGN KEY (`plan_id`)
    REFERENCES `membership_plans` (`id`),
  CONSTRAINT `fk_subs_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subs_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- کیف پول اعضا و تراکنش‌ها (§6)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
  `user_id`    INT UNSIGNED    NOT NULL,
  `balance`    BIGINT          NOT NULL DEFAULT 0 COMMENT 'تومان؛ می‌تواند منفی شود (بدهی)',
  `updated_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED    NOT NULL,
  `amount`        BIGINT          NOT NULL COMMENT 'مثبت=شارژ، منفی=برداشت',
  `kind`          VARCHAR(20)     NOT NULL COMMENT 'credit/debit',
  `balance_after` BIGINT          NOT NULL,
  `note`          VARCHAR(300)    NOT NULL DEFAULT '',
  `created_by`    INT UNSIGNED    NULL,
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wtx_user` (`user_id`),
  CONSTRAINT `fk_wtx_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wtx_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- دفتر کل توکن/اعتبار داخلی (§11)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `token_ledger` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED    NOT NULL,
  `amount`        BIGINT          NOT NULL COMMENT 'مثبت=دریافت، منفی=مصرف',
  `kind`          VARCHAR(30)     NOT NULL COMMENT 'issue/earn/spend/refund/adjust/transfer',
  `balance_after` BIGINT          NOT NULL,
  `note`          VARCHAR(300)    NOT NULL DEFAULT '',
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_token_user` (`user_id`),
  CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- تردد اعضا (§4 احراز هویت و ورود/خروج)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `branch_id`  INT UNSIGNED    NULL,
  `method`     VARCHAR(20)     NOT NULL DEFAULT 'manual' COMMENT 'manual/qr/card/otp/biometric/face',
  `direction`  VARCHAR(5)      NOT NULL DEFAULT 'in' COMMENT 'in/out',
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_visits_user` (`user_id`),
  KEY `idx_visits_created` (`created_at`),
  CONSTRAINT `fk_visits_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- مالی: درآمد و هزینه مجموعه (§22)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finance_transactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`  INT UNSIGNED    NULL,
  `kind`       VARCHAR(10)     NOT NULL COMMENT 'income/expense',
  `category`   VARCHAR(30)     NOT NULL DEFAULT 'other' COMMENT 'membership/service/coach/cafe/... | salary/rent/...',
  `amount`     BIGINT UNSIGNED NOT NULL COMMENT 'تومان',
  `note`       VARCHAR(300)    NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED    NULL,
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finance_created` (`created_at`),
  KEY `idx_finance_kind` (`kind`),
  CONSTRAINT `fk_finance_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_finance_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- خدمات مجموعه و رزروها (§7)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `branch_id`       INT UNSIGNED    NULL,
  `name`            VARCHAR(100)    NOT NULL,
  `category`        VARCHAR(20)     NOT NULL DEFAULT 'other' COMMENT 'gym/coach/class/massage/salon/cafe/parking/locker/physio/med/pool/slimming/other',
  `duration_minutes` INT UNSIGNED   NOT NULL DEFAULT 60,
  `price`           BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تومان',
  `capacity`        INT UNSIGNED    NOT NULL DEFAULT 1,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_services_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservations` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `service_id`  INT UNSIGNED    NOT NULL,
  `user_id`     INT UNSIGNED    NOT NULL,
  `branch_id`   INT UNSIGNED    NULL,
  `reserved_at` BIGINT UNSIGNED NOT NULL COMMENT 'زمان نوبت (میلی‌ثانیه)',
  `status`      VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending/confirmed/done/canceled',
  `price`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `note`        VARCHAR(300)    NOT NULL DEFAULT '',
  `created_by`  INT UNSIGNED    NULL,
  `created_at`  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_res_status` (`status`),
  KEY `idx_res_user` (`user_id`),
  KEY `idx_res_at` (`reserved_at`),
  CONSTRAINT `fk_res_service` FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_res_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_res_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- محصولات فروشگاه و کافه (§8, §27)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `department` VARCHAR(10)     NOT NULL DEFAULT 'store' COMMENT 'store/cafe',
  `name`       VARCHAR(100)    NOT NULL,
  `category`   VARCHAR(50)     NOT NULL DEFAULT '',
  `price`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تومان',
  `stock`      INT             NOT NULL DEFAULT 0,
  `low_stock`  INT UNSIGNED    NOT NULL DEFAULT 5 COMMENT 'آستانه هشدار موجودی',
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_products_dept` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `department` VARCHAR(10)     NOT NULL DEFAULT 'store',
  `status`     VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/preparing/done/canceled',
  `total`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `pay_method` VARCHAR(10)     NOT NULL DEFAULT 'cash' COMMENT 'wallet/cash',
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_status` (`status`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED    NOT NULL,
  `product_id` INT UNSIGNED    NOT NULL,
  `qty`        INT UNSIGNED    NOT NULL DEFAULT 1,
  `unit_price` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED    NOT NULL,
  `qty`        INT             NOT NULL COMMENT 'مثبت=ورود، منفی=خروج',
  `kind`       VARCHAR(20)     NOT NULL COMMENT 'purchase/sale/waste/return/adjust',
  `note`       VARCHAR(200)    NOT NULL DEFAULT '',
  `admin_id`   INT UNSIGNED    NULL,
  `created_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_product` (`product_id`),
  CONSTRAINT `fk_inv_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- متادیتای پلتفرم (نسخه seed و تنظیمات داخلی)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platform_meta` (
  `name`  VARCHAR(50)  NOT NULL,
  `value` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- داده‌های اولیه
-- ------------------------------------------------------------
INSERT INTO `branches` (`name`, `code`, `address`, `phone`, `is_active`, `created_at`)
SELECT 'شعبه مرکزی', 'MAIN', '', '', 1, UNIX_TIMESTAMP() * 1000
WHERE NOT EXISTS (SELECT 1 FROM `branches`);

-- مدیر پیش‌فرض: admin / Admin@12345  (بلافاصله رمز را تغییر بده!)
INSERT INTO `admins` (`username`, `email`, `password_hash`, `is_super`, `created_at`)
SELECT 'admin', 'admin@fitbot.local',
       '$2y$12$FOqfQ6obhYUyZCY0sGG8De.iPfyQVUgB39FMRblSthkvItO.1F6ii', 1,
       UNIX_TIMESTAMP() * 1000
WHERE NOT EXISTS (SELECT 1 FROM `admins` WHERE `username` = 'admin');
