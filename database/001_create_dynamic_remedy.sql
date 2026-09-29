-- ---------------------------------------------------------------------------
-- 001: the `dynamic_remedy` lead table — ONE file for both cases (safe to run again):
--   * no table yet            → creates it
--   * old table from the demo dump (quiz varchar, no updated_at / idx_email) → upgrades it in place, data kept
--   * table already current   → changes nothing
-- Works on MySQL 8 and MariaDB (XAMPP): no server-specific collation, the database default is used.
-- Import in phpMyAdmin (select the database → Import), or: mysql -u <user> -p <database> < 001_create_dynamic_remedy.sql
-- Back up an existing table first:  CREATE TABLE `dynamic_remedy_backup` AS SELECT * FROM `dynamic_remedy`;
--
-- Compared with the demo dump (rdynamic_emedy.sql): table name `dynamic_remedy`; `quiz` varchar(7000) → json (all answers,
-- keyed by API question key); new `updated_at` (last save from the quiz); new `idx_email` index (not UNIQUE — a returning
-- buyer gets a new row). The other tables: 003_create_flow_tracking.sql, 004_create_error_logs.sql.
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- 1. Fresh install --------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `dynamic_remedy` (
  `id` int NOT NULL AUTO_INCREMENT,
  `firstName` varchar(100) NOT NULL,
  `lastName` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `source` varchar(25) NOT NULL,
  `tags` varchar(100) NOT NULL,
  `quiz` json NOT NULL COMMENT 'All quiz answers: {"question_key": "answer" | ["a","b"] | {...}}',
  `address1` varchar(100) DEFAULT NULL,
  `postalCode` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `processed` tinyint DEFAULT NULL,
  `purchased` tinyint DEFAULT NULL,
  `prospectid` varchar(30) DEFAULT NULL,
  `vrio_order_id` varchar(50) DEFAULT NULL,
  `product` varchar(255) DEFAULT NULL,
  `timing` datetime DEFAULT NULL,
  `processedtiming` datetime DEFAULT NULL,
  `affid` varchar(50) DEFAULT NULL,
  `c1` varchar(255) DEFAULT NULL,
  `combined_order_id` varchar(255) DEFAULT NULL,
  `portal_processed` enum('0','1') NOT NULL DEFAULT '0' COMMENT '"1"=>Yes, "0"=>No',
  `portal_processed_payload` json DEFAULT NULL,
  `portal_processed_response` json DEFAULT NULL,
  `info_vouched_job_id` text,
  `vouched_crosscheck_persent` varchar(255) DEFAULT NULL,
  `info_vouched_dob_id` varchar(255) DEFAULT NULL,
  `vouched_dob_status` varchar(255) DEFAULT NULL,
  `info_vouched_status` varchar(455) DEFAULT NULL,
  `idcheck_vouched_job_id` varchar(255) DEFAULT NULL,
  `idcheck_vouched_status` varchar(255) DEFAULT NULL,
  `customer_id` varchar(255) DEFAULT NULL,
  `portal_process_response` json DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Upgrade an older table (each step runs only when it is needed) ----------------

-- 2a. Empty quiz values become '{}' so the column can turn into JSON.
UPDATE `dynamic_remedy` SET `quiz` = '{}' WHERE `quiz` IS NULL OR TRIM(`quiz`) = '';

-- 2b. Rows whose quiz is not valid JSON would stop step 2c. This must show 0; if not, fix those rows and run the file again:
--     SELECT `id`, `email`, LEFT(`quiz`, 200) FROM `dynamic_remedy` WHERE JSON_VALID(`quiz`) = 0;
SELECT COUNT(*) AS `invalid_quiz_rows__must_be_0` FROM `dynamic_remedy` WHERE JSON_VALID(`quiz`) = 0;

-- 2c. quiz → json (MySQL reports the type as "json", MariaDB as "longtext": both are already fine).
SET @sql = IF((SELECT DATA_TYPE FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND COLUMN_NAME = 'quiz') IN ('json', 'longtext'),
    'SELECT ''quiz is already json'' AS `step_2c`',
    'ALTER TABLE `dynamic_remedy` MODIFY `quiz` json NOT NULL COMMENT ''All quiz answers: {"question_key": "answer" | ["a","b"] | {...}}''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2d. Columns the demo dump doesn't have.
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND COLUMN_NAME = 'updated_at'),
    'SELECT ''updated_at exists'' AS `step_2d`',
    'ALTER TABLE `dynamic_remedy` ADD COLUMN `updated_at` datetime DEFAULT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND COLUMN_NAME = 'portal_process_response'),
    'SELECT ''portal_process_response exists'' AS `step_2d2`',
    'ALTER TABLE `dynamic_remedy` ADD COLUMN `portal_process_response` json DEFAULT NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2e. The email index.
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND INDEX_NAME = 'idx_email'),
    'SELECT ''idx_email exists'' AS `step_2e`',
    'ALTER TABLE `dynamic_remedy` ADD KEY `idx_email` (`email`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2f. Primary key + auto increment (the demo dump's lines for them pointed at another table name).
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND INDEX_NAME = 'PRIMARY'),
    'SELECT ''primary key exists'' AS `step_2f`',
    'ALTER TABLE `dynamic_remedy` ADD PRIMARY KEY (`id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT EXTRA FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dynamic_remedy' AND COLUMN_NAME = 'id') LIKE '%auto_increment%',
    'SELECT ''id is auto_increment'' AS `step_2f2`',
    'ALTER TABLE `dynamic_remedy` MODIFY `id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
