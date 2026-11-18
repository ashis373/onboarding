-- ==============================================================================
-- Onboarding Portal - Database Structure Optimization Script
-- Safe to execute in phpMyAdmin, MySQL Workbench, or p.php SQL tab.
-- STRICT SAFETY: Touches ONLY `onboard_*` tables. Never touches the `user` table.
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. DE-DUPLICATE `onboard_module_progress` (if any duplicate rows exist)
-- Keeps the record with the highest progress or most recent activity
-- ------------------------------------------------------------------------------
DELETE p1 FROM onboard_module_progress p1
INNER JOIN onboard_module_progress p2 
WHERE p1.id < p2.id 
  AND p1.user_id = p2.user_id 
  AND p1.module_id = p2.module_id;

-- ------------------------------------------------------------------------------
-- 2. ADD UNIQUE CONSTRAINT ON `onboard_module_progress`
-- Ensures exactly ONE active progress record per trainee per module.
-- Enables native MySQL atomic upsert (ON DUPLICATE KEY UPDATE).
-- ------------------------------------------------------------------------------
-- Drop old individual indexes if they exist (optional cleanup, safe to run)
ALTER TABLE `onboard_module_progress` 
  DROP INDEX IF EXISTS `user_id`,
  DROP INDEX IF EXISTS `module_id`,
  DROP INDEX IF EXISTS `uk_user_module`;

-- Create the unique compound constraint and supporting lookup index
ALTER TABLE `onboard_module_progress` 
  ADD UNIQUE KEY `uk_user_module` (`user_id`, `module_id`),
  ADD INDEX `idx_omp_status_completed` (`user_id`, `status`, `is_completed`);

-- ------------------------------------------------------------------------------
-- 3. OPTIMIZE `onboard_module_sessions` (190+ rows)
-- Speeds up latest session lookup: WHERE user_id = ? AND module_id = ? ORDER BY id DESC LIMIT 1
-- ------------------------------------------------------------------------------
ALTER TABLE `onboard_module_sessions` 
  DROP INDEX IF EXISTS `idx_user_mod_session`;

ALTER TABLE `onboard_module_sessions` 
  ADD INDEX `idx_user_mod_session` (`user_id`, `module_id`, `id`);

-- ------------------------------------------------------------------------------
-- 4. OPTIMIZE `onboard_assessment_attempts`
-- Speeds up lookup: WHERE user_id = ? AND (module_id = ? OR assessment_id = ?)
-- ------------------------------------------------------------------------------
ALTER TABLE `onboard_assessment_attempts` 
  DROP INDEX IF EXISTS `idx_user_module`,
  DROP INDEX IF EXISTS `idx_user_assessment`;

ALTER TABLE `onboard_assessment_attempts` 
  ADD INDEX `idx_user_module` (`user_id`, `module_id`),
  ADD INDEX `idx_user_assessment` (`user_id`, `assessment_id`);

-- ------------------------------------------------------------------------------
-- 5. OPTIMIZE `onboard_modules`
-- Speeds up Moodle course and module ID mappings
-- ------------------------------------------------------------------------------
ALTER TABLE `onboard_modules` 
  DROP INDEX IF EXISTS `idx_moodle_ids`;

ALTER TABLE `onboard_modules` 
  ADD INDEX `idx_moodle_ids` (`moodle_course_id`, `moodle_cmid`, `moodle_scoid`);

-- ------------------------------------------------------------------------------
-- 6. POPULATE `onboard_module_progress` FROM EXISTING SESSIONS & ATTEMPTS
-- Fills onboard_module_progress with real data for trainees who have completed sessions
-- ------------------------------------------------------------------------------
INSERT INTO `onboard_module_progress` 
  (`user_id`, `module_id`, `status`, `progress_percent`, `highest_progress_percent`, `score`, `highest_score`, `passing_score`, `total_time`, `total_time_seconds`, `attempts`, `suspend_data`, `is_completed`, `last_synced_at`)
SELECT 
  s.user_id,
  s.module_id,
  s.status,
  s.progress_percent,
  s.progress_percent,
  s.score,
  IFNULL(s.score, 0),
  80,
  s.session_time,
  s.session_seconds,
  1,
  s.suspend_data,
  IF(s.status = 'completed' OR s.progress_percent >= 100, 1, 0),
  NOW()
FROM (
  SELECT s1.* 
  FROM `onboard_module_sessions` s1
  JOIN (
    SELECT user_id, module_id, MAX(id) AS max_id 
    FROM `onboard_module_sessions` 
    GROUP BY user_id, module_id
  ) s2 ON s1.id = s2.max_id
) s
ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  progress_percent = GREATEST(onboard_module_progress.progress_percent, VALUES(progress_percent)),
  highest_progress_percent = GREATEST(onboard_module_progress.highest_progress_percent, VALUES(progress_percent)),
  score = COALESCE(VALUES(score), onboard_module_progress.score),
  highest_score = GREATEST(IFNULL(onboard_module_progress.highest_score, 0), IFNULL(VALUES(score), 0)),
  total_time = VALUES(total_time),
  total_time_seconds = GREATEST(onboard_module_progress.total_time_seconds, VALUES(total_time_seconds)),
  is_completed = IF(onboard_module_progress.is_completed = 1 OR VALUES(is_completed) = 1, 1, 0),
  last_synced_at = NOW();

SET FOREIGN_KEY_CHECKS = 1;

-- Finished successfully!
