-- ---------------------------------------------------------------------
-- 1) USERS  (50K+ sinxron foydalanuvchi uchun yengillashtirilgan ustunlar)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fullname`      VARCHAR(150)    NOT NULL,
    `phone`         VARCHAR(20)     NOT NULL,
    `password`      VARCHAR(255)    NOT NULL COMMENT 'argon2id hash',
    `role`          ENUM('student','admin','moderator') NOT NULL DEFAULT 'student',
    `tg_user_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
    `balance`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `mock_quota`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'qolgan imtihon soni',
    `last_login_ip` VARBINARY(16)   NULL DEFAULT NULL,
    `last_login_at` TIMESTAMP       NULL DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `totp_secret`   VARCHAR(255)    NULL DEFAULT NULL COMMENT 'AES-GCM encrypted Base32 secret',
    `totp_enabled`  TINYINT(1)      NOT NULL DEFAULT 0,
    `totp_recovery` JSON            NULL DEFAULT NULL COMMENT 'array of bcrypt-hashed recovery codes',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_phone` (`phone`),
    UNIQUE KEY `uniq_users_tg`    (`tg_user_id`),
    KEY `idx_users_role`          (`role`),
    KEY `idx_users_active`        (`is_active`),
    KEY `idx_users_created`       (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- 2) EXAMS
-- ---------------------------------------------------------------------
CREATE TABLE `exams` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(255)    NOT NULL,
    `subject`    VARCHAR(100)    NOT NULL DEFAULT 'Fizika',
    `duration`   INT UNSIGNED    NOT NULL DEFAULT 120 COMMENT 'minutes',
    `total_qty`  INT UNSIGNED    NOT NULL DEFAULT 30,
    `status`     ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_exams_status`  (`status`),
    KEY `idx_exams_created` (`created_at`),
    CONSTRAINT `fk_exams_creator`
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) TARIFFS
-- ---------------------------------------------------------------------
CREATE TABLE `tariffs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(120)    NOT NULL,
    `price`       DECIMAL(12,2)   NOT NULL,
    `mock_count`  INT UNSIGNED    NOT NULL DEFAULT 1,
    `description` TEXT            NULL,
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `sort_order`  INT             NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tariffs_active` (`is_active`),
    KEY `idx_tariffs_sort`   (`sort_order`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) PAYMENTS  (P2P chek tasdiqlash uchun)
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED NOT NULL,
    `tariff_id`       BIGINT UNSIGNED NOT NULL,
    `amount`          DECIMAL(12,2)   NOT NULL,
    `screenshot_path` VARCHAR(500)    NOT NULL,
    `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`     BIGINT UNSIGNED NULL,
    `reviewed_at`     TIMESTAMP       NULL,
    `note`            VARCHAR(500)    NULL,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pay_status`  (`status`),
    KEY `idx_pay_user`    (`user_id`),
    KEY `idx_pay_created` (`created_at`),
    CONSTRAINT `fk_pay_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pay_tariff`
        FOREIGN KEY (`tariff_id`) REFERENCES `tariffs`(`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pay_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) USER_EXAMS  (har bir o'quvchining sessiyalari)
-- ---------------------------------------------------------------------
CREATE TABLE `user_exams` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `exam_id`          BIGINT UNSIGNED NOT NULL,
    `is_paid`          TINYINT(1)      NOT NULL DEFAULT 0,
    `score`            DECIMAL(6,2)    NULL DEFAULT NULL,
    `correct_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `wrong_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `skipped_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `section_analysis` JSON            NULL COMMENT 'bo`limlar bo`yicha tahlil',
    `answers_snapshot` JSON            NULL COMMENT 'foydalanuvchi javoblari',
    `started_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`      TIMESTAMP       NULL DEFAULT NULL,
    `status`           ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
    PRIMARY KEY (`id`),
    KEY `idx_uex_user`      (`user_id`),
    KEY `idx_uex_exam`      (`exam_id`),
    KEY `idx_uex_status`    (`status`),
    KEY `idx_uex_started`   (`started_at`),
    CONSTRAINT `fk_uex_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_uex_exam`
        FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) QUESTIONS
-- ---------------------------------------------------------------------
CREATE TABLE `questions` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`        BIGINT UNSIGNED NOT NULL,
    `section`        VARCHAR(100)    NOT NULL DEFAULT 'Umumiy' COMMENT 'Mexanika, Optika, Termodinamika...',
    `type`           ENUM('mcq','open','matching') NOT NULL DEFAULT 'mcq',
    `difficulty`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1..5',
    `weight`         DECIMAL(5,2)    NOT NULL DEFAULT 1.00 COMMENT 'BMBA koeffitsienti',
    `question_text`  MEDIUMTEXT      NOT NULL,
    `options`        JSON            NULL,
    `correct_answer` JSON            NOT NULL,
    `solution_text`  MEDIUMTEXT      NULL,
    `video_url`      VARCHAR(500)    NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_q_exam`       (`exam_id`),
    KEY `idx_q_section`    (`section`),
    KEY `idx_q_type`       (`type`),
    CONSTRAINT `fk_q_exam`
        FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) SYSTEM_SETTINGS  (key/value store: logo, sliderlar, kartalar)
-- ---------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `key`        VARCHAR(120) NOT NULL,
    `value`      TEXT         NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`),
    CONSTRAINT `fk_settings_user`
        FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AUXILIARY: rate-limit bucket (Redis bo'lmagan hostlar uchun fallback)
-- ---------------------------------------------------------------------
CREATE TABLE `rate_limit_buckets` (
    `bucket_key` VARCHAR(190) NOT NULL,
    `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`bucket_key`),
    KEY `idx_rl_expires` (`expires_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- AUXILIARY: telegram bot dialog state
-- ---------------------------------------------------------------------
CREATE TABLE `tg_sessions` (
    `tg_user_id` BIGINT UNSIGNED NOT NULL,
    `state`      VARCHAR(60)  NOT NULL DEFAULT 'idle',
    `payload`    JSON         NULL,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tg_user_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- AUXILIARY: SMS OTP challenges (registration & sensitive ops)
-- ---------------------------------------------------------------------
CREATE TABLE `otp_challenges` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone`        VARCHAR(20)     NOT NULL,
    `code_hash`    CHAR(64)        NOT NULL COMMENT 'hash_hmac(sha256, code, app_key)',
    `purpose`      VARCHAR(40)     NOT NULL DEFAULT 'register',
    `payload`      JSON            NULL,
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `consumed_at`  TIMESTAMP       NULL DEFAULT NULL,
    `expires_at`   TIMESTAMP       NOT NULL,
    `ip`           VARCHAR(45)     NULL,
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_otp_phone`   (`phone`),
    KEY `idx_otp_expires` (`expires_at`),
    KEY `idx_otp_purpose` (`purpose`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AUXILIARY: Notification queue (Telegram async dispatch, retries)
-- ---------------------------------------------------------------------
CREATE TABLE `notification_jobs` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type`     VARCHAR(50)     NOT NULL COMMENT 'tg_send | email_send | ...',
    `payload`      JSON            NOT NULL,
    `status`       ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `available_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'earliest run time (backoff)',
    `locked_at`    TIMESTAMP       NULL DEFAULT NULL,
    `locked_by`    VARCHAR(64)     NULL DEFAULT NULL COMMENT 'worker id',
    `last_error`   TEXT            NULL,
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jobs_status_avail` (`status`, `available_at`),
    KEY `idx_jobs_type`         (`job_type`),
    KEY `idx_jobs_updated`      (`updated_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BOSHLANG'ICH MA'LUMOTLAR
-- ---------------------------------------------------------------------
INSERT INTO `system_settings` (`key`, `value`) VALUES
    ('site_logo',     '/uploads/logo/default.png'),
    ('slider_images', JSON_ARRAY()),
    ('humo_card',     '9860 0101 0000 0000'),
    ('visa_card',     '4000 0000 0000 0000'),
    ('card_holder',   'PHYSICS CERT LLC'),
    ('bot_username',  'physics_cert_bot'),
    ('platform_name', 'Physics National Certificate');

INSERT INTO `tariffs` (`name`, `price`, `mock_count`, `description`, `sort_order`) VALUES
    ('Boshlang''ich',  49000.00,  3,  '3 ta to''liq mock imtihon', 1),
    ('Standart',      129000.00, 10, '10 ta mock + bo''limlar tahlili', 2),
    ('PRO',           249000.00, 30, '30 ta mock + video yechimlar', 3);

SET FOREIGN_KEY_CHECKS = 1;
