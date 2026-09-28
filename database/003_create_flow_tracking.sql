-- ---------------------------------------------------------------------------
-- 003: funnel progress tracking (lib/FlowTracker.php). Safe to run on a live DB: only creates new tables.
--
--   flow_tracking         one row per visitor per funnel: where they are now / where they stopped
--   flow_tracking_events  every step change, in order (step1 → step2 → … → checkout → completed)
--
-- tracking_id comes from the `fid` cookie (1 year), set on the first page load — before any email or phone.
-- lead_id / prospect_id are filled in once the contact screen has saved the dynamic_remedy row.
-- No personal data is stored here.
-- Works on MySQL 8 and MariaDB (the database default collation is used).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flow_tracking` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tracking_id` char(32) NOT NULL,
  `funnel_slug` varchar(100) NOT NULL,
  `lead_id` int DEFAULT NULL COMMENT 'dynamic_remedy.id',
  `prospect_id` varchar(30) DEFAULT NULL COMMENT 'Vrio prospect',
  `last_step` varchar(50) NOT NULL COMMENT 'step1…stepN (quiz screens, index.json order), product, checkout',
  `last_step_key` varchar(64) DEFAULT NULL COMMENT 'first question key of the quiz screen, e.g. dob',
  `last_step_index` smallint NOT NULL DEFAULT 0 COMMENT 'quiz screen number; product = 900, checkout = 910',
  `max_step_index` smallint NOT NULL DEFAULT 0 COMMENT 'furthest step reached (Back does not lower it)',
  `last_page` varchar(50) NOT NULL COMMENT 'index, product, checkout',
  `status` enum('in_progress','disqualified','completed') NOT NULL DEFAULT 'in_progress' COMMENT 'completed = checkout payment approved',
  `affid` varchar(50) DEFAULT NULL,
  `c1` varchar(255) DEFAULT NULL,
  `started_at` datetime NOT NULL COMMENT 'UTC',
  `last_activity_at` datetime NOT NULL COMMENT 'UTC',
  `completed_at` datetime DEFAULT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking` (`tracking_id`, `funnel_slug`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_report` (`status`, `last_step_index`, `last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `flow_tracking_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tracking_id` char(32) NOT NULL,
  `funnel_slug` varchar(100) NOT NULL,
  `step` varchar(50) NOT NULL COMMENT 'step1…stepN, product, checkout, disqualified, completed',
  `step_key` varchar(64) DEFAULT NULL,
  `step_index` smallint NOT NULL DEFAULT 0,
  `page` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_tracking` (`tracking_id`, `id`),
  KEY `idx_step` (`funnel_slug`, `step_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Reports
-- ---------------------------------------------------------------------------

-- Where visitors stopped (not completed, no activity for 30 minutes):
-- SELECT last_step, last_step_key, last_step_index, COUNT(*) AS visitors
-- FROM flow_tracking
-- WHERE status <> 'completed' AND last_activity_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE
-- GROUP BY last_step, last_step_key, last_step_index
-- ORDER BY last_step_index;

-- How many reached each step (funnel):
-- SELECT step, step_key, step_index, COUNT(DISTINCT tracking_id) AS reached
-- FROM flow_tracking_events
-- GROUP BY step, step_key, step_index
-- ORDER BY step_index;

-- Totals by status:
-- SELECT status, COUNT(*) FROM flow_tracking GROUP BY status;

-- One visitor's path:
-- SELECT GROUP_CONCAT(step ORDER BY id SEPARATOR ' → ') AS path
-- FROM flow_tracking_events WHERE tracking_id = '…';
