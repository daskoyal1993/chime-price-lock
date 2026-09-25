-- ---------------------------------------------------------------------------
-- 001: create `dynamic_remedy` (fresh install).
-- Same columns as the demo dump (rdynamic_emedy.sql), with these changes:
--   * table name `dynamic_remedy` (the dump mixed `chime-health` / `dynamic_remedy`)
--   * `quiz`       varchar(7000) -> json   (all quiz answers, keyed by API question key)
--   * `updated_at` new                    (last save from the quiz)
--   * `idx_email`  new index              (lookup; not UNIQUE — a returning buyer gets a new row)
-- If the table already exists, run 002_upgrade_dynamic_remedy.sql instead.
-- Target: MySQL 8.0 (as in the dump). On MariaDB use COLLATE utf8mb4_unicode_ci.
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
