-- ==============================================================================
-- HexaLearn Onboarding Platform: Official Production Schema (MySQL)
-- Unified DDL for the 7 active tables used by Moodle LMS & Employee Portal
-- Database: hex-timesheet / u413479707_timesheet
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Moodle Single Sign-On (SSO) Bridging Table
CREATE TABLE IF NOT EXISTS `onboard_moodle_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `hexa_uid` INT(11) NOT NULL UNIQUE COMMENT 'Foreign key to user.uid',
  `username` VARCHAR(100) DEFAULT NULL COMMENT 'Reference HexaOne username',
  `moodle_user_id` INT(11) DEFAULT NULL UNIQUE COMMENT 'Moodle LMS user ID',
  `sync_status` ENUM('Not linked', 'Pending verification', 'Linked', 'Error') DEFAULT 'Not linked',
  `last_synced_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_moodle_uid` (`moodle_user_id`),
  INDEX `idx_sync_status` (`sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Onboarding Modules Catalog
CREATE TABLE IF NOT EXISTS `onboard_modules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` VARCHAR(50) DEFAULT 'scorm',
  `duration_minutes` INT(11) DEFAULT 30,
  `video_url` VARCHAR(512) DEFAULT NULL,
  `file_url` VARCHAR(512) DEFAULT NULL,
  `department_id` INT(11) DEFAULT 0 COMMENT '0 or NULL = all departments',
  `designation_id` INT(11) DEFAULT 0 COMMENT '0 or NULL = all designations',
  `roadmap_day` INT(11) NOT NULL DEFAULT 1 COMMENT 'Induction phase: Day 1, 30, 60, 90',
  `sort_order` INT(11) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `moodle_course_id` INT(11) DEFAULT NULL,
  `moodle_cmid` INT(11) DEFAULT NULL,
  `moodle_scoid` INT(11) DEFAULT NULL,
  `passing_score` INT(11) DEFAULT 80,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_moodle_course` (`moodle_course_id`),
  INDEX `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Module Learning Progress (Real-time SCORM sync)
CREATE TABLE IF NOT EXISTS `onboard_module_progress` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL COMMENT 'Foreign key to user.uid',
  `module_id` INT(11) NOT NULL COMMENT 'Foreign key to onboard_modules.id',
  `status` ENUM('not_started', 'in_progress', 'completed', 'failed') DEFAULT 'not_started',
  `is_completed` TINYINT(1) DEFAULT 0,
  `progress_percent` INT(11) DEFAULT 0,
  `highest_progress_percent` INT(11) DEFAULT 0,
  `score` DECIMAL(5,2) DEFAULT NULL,
  `highest_score` DECIMAL(5,2) DEFAULT NULL,
  `passing_score` INT(11) DEFAULT 80,
  `total_time` VARCHAR(32) DEFAULT '00:00:00',
  `total_time_seconds` INT(10) UNSIGNED DEFAULT 0,
  `attempts` INT(11) DEFAULT 0,
  `suspend_data` TEXT DEFAULT NULL,
  `lesson_location` VARCHAR(255) DEFAULT NULL,
  `slides_viewed` INT(10) UNSIGNED DEFAULT 0,
  `total_slides` INT(10) UNSIGNED DEFAULT 0,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_user_module` (`user_id`, `module_id`),
  INDEX `idx_user_progress` (`user_id`, `is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Course Launch Telemetry & Activity Sessions
CREATE TABLE IF NOT EXISTS `onboard_module_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `module_id` INT(11) NOT NULL,
  `session_time` VARCHAR(32) DEFAULT '00:00:00',
  `session_seconds` INT(10) UNSIGNED DEFAULT 0,
  `slides_viewed` INT(10) UNSIGNED DEFAULT 0,
  `progress_percent` INT(11) DEFAULT 0,
  `score` DECIMAL(5,2) DEFAULT NULL,
  `status` VARCHAR(32) DEFAULT NULL,
  `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_session` (`user_id`, `module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Assessment Attempts Audit Log
CREATE TABLE IF NOT EXISTS `onboard_assessment_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `assessment_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `score` DECIMAL(5,2) DEFAULT NULL,
  `max_score` INT(11) DEFAULT 100,
  `is_passed` TINYINT(1) DEFAULT NULL,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` DATETIME DEFAULT NULL,
  INDEX `idx_user_assessment` (`user_id`, `assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Moodle Integration & System Settings
CREATE TABLE IF NOT EXISTS `onboard_settings` (
  `key` VARCHAR(191) NOT NULL PRIMARY KEY,
  `value` LONGTEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Question Types Lookup Reference
CREATE TABLE IF NOT EXISTS `onboard_question_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `type_code` VARCHAR(50) NOT NULL UNIQUE,
  `type_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed question types reference values
INSERT IGNORE INTO `onboard_question_types` (`type_code`, `type_name`, `description`, `is_active`) VALUES
('single_choice', 'Single Choice (Radio)', 'Single correct answer from radio options', 1),
('multiple_choice', 'Multiple Choice (Checkbox)', 'Multiple correct answers from checkbox options', 1),
('true_false', 'True / False', 'Binary true/false choice', 1),
('text', 'Short Text Answer', 'Freeform short text response', 1),
('matching', 'Drag-and-Drop Matching', 'Match pairs from two columns', 1),
('reorder', 'Sequence / Reorder', 'Arrange items in the correct order', 1);

SET FOREIGN_KEY_CHECKS = 1;
