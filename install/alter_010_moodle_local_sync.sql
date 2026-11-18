-- Migration: 010_moodle_local_sync.sql
-- Enables Local MySQL First Architecture for Onboarding
-- Stores courses, module progress, scores, and attempt history locally inside onboard_ tables

-- 1. Extend onboard_modules to catalog Moodle course mappings
ALTER TABLE `onboard_modules` 
  MODIFY COLUMN `type` ENUM('scorm', 'assessment', 'video', 'pdf', 'document') DEFAULT 'scorm',
  ADD COLUMN IF NOT EXISTS `moodle_course_id` INT NULL COMMENT 'Moodle course ID (e.g. 2, 3, 4)',
  ADD COLUMN IF NOT EXISTS `moodle_cmid` INT NULL COMMENT 'Moodle course module ID',
  ADD COLUMN IF NOT EXISTS `moodle_scoid` INT NULL COMMENT 'Moodle launchable SCO ID',
  ADD COLUMN IF NOT EXISTS `passing_score` INT DEFAULT 80,
  ADD INDEX IF NOT EXISTS `ix_moodle_course` (`moodle_course_id`);

-- 2. Extend onboard_module_progress for detailed runtime SCORM tracking & slide bookmarking
ALTER TABLE `onboard_module_progress`
  ADD COLUMN IF NOT EXISTS `status` ENUM('not_started', 'in_progress', 'completed', 'failed') DEFAULT 'not_started',
  ADD COLUMN IF NOT EXISTS `progress_percent` INT DEFAULT 0 COMMENT '0 to 100%',
  ADD COLUMN IF NOT EXISTS `highest_progress_percent` INT DEFAULT 0 COMMENT 'Monotonic high-water mark',
  ADD COLUMN IF NOT EXISTS `score` DECIMAL(5,2) NULL COMMENT 'Latest quiz/assessment score',
  ADD COLUMN IF NOT EXISTS `highest_score` DECIMAL(5,2) NULL COMMENT 'Best assessment score achieved',
  ADD COLUMN IF NOT EXISTS `passing_score` INT DEFAULT 80,
  ADD COLUMN IF NOT EXISTS `total_time` VARCHAR(32) DEFAULT '00:00:00' COMMENT 'Time spent e.g. 00:31:32',
  ADD COLUMN IF NOT EXISTS `total_time_seconds` INT UNSIGNED DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `attempts` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `suspend_data` TEXT NULL COMMENT 'SCORM bookmark & slide tracking state',
  ADD COLUMN IF NOT EXISTS `lesson_location` VARCHAR(255) NULL COMMENT 'Last slide or location visited',
  ADD COLUMN IF NOT EXISTS `slides_viewed` INT UNSIGNED DEFAULT 0 COMMENT 'Number of slides viewed',
  ADD COLUMN IF NOT EXISTS `total_slides` INT UNSIGNED DEFAULT 0 COMMENT 'Total slides in module',
  ADD COLUMN IF NOT EXISTS `last_checked_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `last_synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 3. Extend onboard_assessment_attempts for full multi-attempt auditing (Attempt 1, 2, 3...)
ALTER TABLE `onboard_assessment_attempts`
  MODIFY COLUMN `score` DECIMAL(5,2) NULL,
  ADD COLUMN IF NOT EXISTS `module_id` INT NULL COMMENT 'Links to onboard_modules.id',
  ADD COLUMN IF NOT EXISTS `attempt_number` INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `result` ENUM('Passed', 'Failed', 'Incomplete') DEFAULT 'Incomplete',
  ADD COLUMN IF NOT EXISTS `total_time` VARCHAR(32) DEFAULT '00:00:00',
  ADD COLUMN IF NOT EXISTS `total_time_seconds` INT UNSIGNED DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `attempted_at` DATETIME DEFAULT CURRENT_TIMESTAMP;

-- 4. Create onboard_module_sessions for SCORM session & slide checkpoint history
CREATE TABLE IF NOT EXISTS `onboard_module_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `module_id` INT NOT NULL,
  `session_time` VARCHAR(32) DEFAULT '00:00:00',
  `session_seconds` INT UNSIGNED DEFAULT 0,
  `slides_viewed` INT UNSIGNED DEFAULT 0,
  `progress_percent` INT DEFAULT 0,
  `score` DECIMAL(5,2) NULL,
  `status` VARCHAR(32) DEFAULT 'in_progress',
  `lesson_location` VARCHAR(255) NULL,
  `suspend_data` TEXT NULL,
  `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `ix_user_module_sess` (`user_id`, `module_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores audit log of each SCORM study session without overwriting';
