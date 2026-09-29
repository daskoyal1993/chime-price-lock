-- ---------------------------------------------------------------------------
-- 004: QA error log table (lib/ErrorLogger.php). Optional — the same entries are always written to
-- storage/logs/qa-errors.log; when this table exists they are stored here too. Safe on a live DB: only creates a table.
-- Messages and context are masked before they are written (no keys, tokens, card numbers, CVV; emails / phones masked).
-- Works on MySQL 8 and MariaDB.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `error_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `test_id` varchar(20) NOT NULL DEFAULT 'no-test' COMMENT 'TEST-YYYYMMDD-NNN from ?qa_test=start, else no-test',
  `environment` varchar(20) NOT NULL COMMENT 'FUNNEL_ENV: local | staging | live',
  `level` enum('ERROR','WARNING','NOTICE','INFO') NOT NULL DEFAULT 'ERROR',
  `error_type` varchar(40) NOT NULL COMMENT 'PHP_WARNING, EXCEPTION, FATAL_ERROR, API_ERROR, DATABASE_ERROR, PAYMENT_ERROR, JS_ERROR, AJAX_ERROR, …',
  `service` varchar(100) NOT NULL DEFAULT '' COMMENT 'GHL, Vrio, Vouched, Portal, xverify, DB, Browser, …',
  `page` varchar(255) NOT NULL DEFAULT '' COMMENT 'e.g. submit.php?action=checkout',
  `message` text NOT NULL,
  `file` varchar(255) NOT NULL DEFAULT '',
  `line` int NOT NULL DEFAULT 0,
  `http_status` smallint DEFAULT NULL,
  `context` json DEFAULT NULL COMMENT 'masked: response snippet, error code, trace, browser',
  `created_at` datetime NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_test` (`test_id`, `id`),
  KEY `idx_type` (`error_type`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Queries
-- ---------------------------------------------------------------------------

-- Every error of one QA run:
-- SELECT created_at, level, error_type, service, page, http_status, message, file, line
-- FROM error_logs WHERE test_id = 'TEST-20260929-001' ORDER BY id;

-- Runs of the last 7 days with their error counts:
-- SELECT test_id, MIN(created_at) AS started, SUM(level = 'ERROR') AS errors, SUM(level = 'WARNING') AS warnings
-- FROM error_logs WHERE created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY GROUP BY test_id ORDER BY started DESC;

-- Most frequent errors today:
-- SELECT error_type, service, message, COUNT(*) AS n FROM error_logs
-- WHERE level = 'ERROR' AND created_at >= UTC_DATE() GROUP BY error_type, service, message ORDER BY n DESC LIMIT 20;

-- Clean up old entries:
-- DELETE FROM error_logs WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY;
