-- ---------------------------------------------------------------------------
-- 002: upgrade an EXISTING `dynamic_remedy` table (imported from the demo dump).
-- Run the steps in order. Back up the table first:
--   CREATE TABLE `dynamic_remedy_backup` AS SELECT * FROM `dynamic_remedy`;
-- ---------------------------------------------------------------------------

-- Step 1 — check. Every existing `quiz` value must be valid JSON before the type change.
-- This must return 0. If not, fix or clear those rows first (e.g. UPDATE ... SET quiz = '{}' WHERE id IN (...)).
SELECT COUNT(*) AS invalid_quiz_rows
FROM `dynamic_remedy`
WHERE `quiz` IS NULL OR `quiz` = '' OR JSON_VALID(`quiz`) = 0;

-- To see them:
-- SELECT `id`, `email`, LEFT(`quiz`, 200) FROM `dynamic_remedy` WHERE `quiz` IS NULL OR `quiz` = '' OR JSON_VALID(`quiz`) = 0;

-- Step 2 — change `quiz` to JSON, add `updated_at` and the email index.
ALTER TABLE `dynamic_remedy`
  MODIFY `quiz` json NOT NULL COMMENT 'All quiz answers: {"question_key": "answer" | ["a","b"] | {...}}',
  ADD COLUMN `updated_at` datetime DEFAULT NULL AFTER `portal_process_response`,
  ADD KEY `idx_email` (`email`);

-- Step 3 — only if the dump's PRIMARY KEY / AUTO_INCREMENT lines were not applied
-- (they referenced `dynamic_remedy` while the CREATE said `chime-health`):
-- ALTER TABLE `dynamic_remedy` ADD PRIMARY KEY (`id`);
-- ALTER TABLE `dynamic_remedy` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- Rollback (loses nothing as long as quiz values fit 7000 chars):
-- ALTER TABLE `dynamic_remedy`
--   MODIFY `quiz` varchar(7000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
--   DROP COLUMN `updated_at`,
--   DROP KEY `idx_email`;
