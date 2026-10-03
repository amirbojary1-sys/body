-- ============================================================
--  FitBot — فایل دیتابیس MySQL (نسخه ۳٫۱)
--  روش ایمپورت با phpMyAdmin:
--    1) یک دیتابیس با نام fitbot بساز (یا نام دلخواه؛ آن را در .env هم وارد کن)
--    2) وارد تب Import شو، همین فایل را انتخاب کن و Go بزن
--  روش ایمپورت با خط فرمان:
--    mysql -u root -p < fitbot_mysql.sql
--
--  حساب مدیر پیش‌فرض (حتماً بعد از اولین ورود رمز آن را عوض کن):
--    نام کاربری: admin
--    رمز عبور : Admin@12345
-- ============================================================

CREATE DATABASE IF NOT EXISTS `fitbot`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `fitbot`;

-- ------------------------------------------------------------
-- کاربران سایت
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(254)   NOT NULL,
  `name`          VARCHAR(100)   NOT NULL,
  `password_hash` VARCHAR(255)   NOT NULL,
  `is_active`     TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '1=فعال، 0=مسدودشده توسط مدیر',
  `last_login_at` BIGINT UNSIGNED NULL     COMMENT 'آخرین ورود موفق (میلی‌ثانیه)',
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- داده/سوابق ورزشی هر کاربر (snapshot با شماره نسخه)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_states` (
  `user_id`    INT UNSIGNED     NOT NULL,
  `data`       LONGTEXT         NOT NULL,
  `revision`   INT UNSIGNED     NOT NULL DEFAULT 0,
  `updated_at` BIGINT UNSIGNED  NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_states_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- محدودیت نرخ درخواست‌ها (rate limit)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`      VARCHAR(64)     NOT NULL,
  `window_start` BIGINT UNSIGNED NOT NULL,
  `hits`        INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- مدیران سایت (جدا از کاربران عادی)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)     NOT NULL,
  `email`         VARCHAR(254)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `is_super`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '۱ = مدیر کل (مدیریت مدیران)',
  `last_login_at` BIGINT UNSIGNED NULL,
  `created_at`    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- گزارش فعالیت مدیران (audit log)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_activity` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED     NULL,
  `admin_name` VARCHAR(100)     NOT NULL,
  `action`     VARCHAR(50)      NOT NULL COMMENT 'login, login_failed, logout, user_banned, ...',
  `entity`     VARCHAR(30)      NOT NULL DEFAULT '',
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
-- مدیر پیش‌فرض: admin / Admin@12345  (بلافاصله رمز را تغییر بده!)
-- ------------------------------------------------------------
INSERT INTO `admins` (`username`, `email`, `password_hash`, `is_super`, `created_at`)
SELECT 'admin', 'admin@fitbot.local',
       '$2y$12$FOqfQ6obhYUyZCY0sGG8De.iPfyQVUgB39FMRblSthkvItO.1F6ii', 1,
       UNIX_TIMESTAMP() * 1000
WHERE NOT EXISTS (SELECT 1 FROM `admins` WHERE `username` = 'admin');
