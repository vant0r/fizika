-- =====================================================================
--  Migration #001 — yangi xususiyatlar
--  Bu faylni phpMyAdmin orqali databasega bir marta import qiling
--  (faqat AVVAL schema_shared.sql import qilingandan keyin)
-- =====================================================================

USE `dkxdttwx_fizik`;

-- ---------------------------------------------------------------------
-- 1) Banner uchun system_settings ga yangi kalit
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `system_settings` (`key`, `value`) VALUES
    ('site_banner', ''),
    ('about_text',  'Physics National Certificate — Fizika fanidan Milliy Sertifikatga tayyorlash uchun professional platforma.');

-- ---------------------------------------------------------------------
-- 2) Yangi savol turlari: closed (ochiq javob) va combined (33-34-35)
-- ---------------------------------------------------------------------
ALTER TABLE `questions`
    MODIFY COLUMN `type` ENUM('mcq','open','matching','closed','combined') NOT NULL DEFAULT 'mcq',
    ADD COLUMN `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `exam_id`,
    ADD COLUMN `sub_label` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Birlashtirilgan testda raqam (33, 34, 35)' AFTER `parent_id`,
    ADD COLUMN `sample_answer` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'closed type uchun namunaviy javob' AFTER `correct_answer`,
    ADD COLUMN `max_points` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'maksimal ball' AFTER `sample_answer`,
    ADD INDEX `idx_q_parent` (`parent_id`);

-- Foreign key for parent_id (self-reference)
ALTER TABLE `questions`
    ADD CONSTRAINT `fk_q_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `questions`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- 3) Admin tomonidan tekshirilishi kerak bo'lgan javoblar uchun jadval
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_answer_reviews` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_exam_id`  BIGINT UNSIGNED NOT NULL,
    `question_id`   BIGINT UNSIGNED NOT NULL,
    `user_answer`   MEDIUMTEXT NULL,
    `auto_score`    DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'tizim avtomatik bergan ball',
    `admin_score`   DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'admin yakuniy ball',
    `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`   BIGINT UNSIGNED NULL DEFAULT NULL,
    `reviewed_at`   TIMESTAMP NULL DEFAULT NULL,
    `note`          VARCHAR(500) NULL DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uar_status`   (`status`),
    KEY `idx_uar_userexam` (`user_exam_id`),
    KEY `idx_uar_question` (`question_id`),
    CONSTRAINT `fk_uar_userexam`
        FOREIGN KEY (`user_exam_id`) REFERENCES `user_exams`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_uar_question`
        FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_uar_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) tariffs jadvalini bezatish (banner uchun rasm bo'lishi mumkin)
-- ---------------------------------------------------------------------
ALTER TABLE `tariffs`
    ADD COLUMN IF NOT EXISTS `icon`        VARCHAR(50) NULL DEFAULT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `is_featured` TINYINT(1)  NOT NULL DEFAULT 0 AFTER `is_active`;
