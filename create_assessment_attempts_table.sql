-- SQL Migration: onboard_induction_assessment_attempts
-- Stores Storyline assessment tracking data including Quiz_score, attempt, and lockout email logs

CREATE TABLE IF NOT EXISTS `onboard_induction_assessment_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'Hexa UID from user table',
  `emp_code` VARCHAR(50) DEFAULT NULL COMMENT 'Employee Code e.g. HL039',
  `emp_name` VARCHAR(150) NOT NULL COMMENT 'Firstname + Lastname',
  `ofc_mail` VARCHAR(100) NOT NULL COMMENT 'Official office email only (user.ofc_mail)',
  `department_id` INT(10) DEFAULT NULL COMMENT 'Department ID (1-8)',
  `department_name` VARCHAR(100) DEFAULT NULL COMMENT 'E-learning, Software, QA, etc.',
  `course_id` INT(11) NOT NULL DEFAULT 3 COMMENT 'Moodle Course ID',
  `module_id` INT(11) DEFAULT NULL COMMENT 'onboard_modules ID',
  `student_id` VARCHAR(100) DEFAULT NULL COMMENT 'SCORM cmi.core.student_id',
  `attempt` INT(11) NOT NULL DEFAULT 1 COMMENT 'Storyline attempt variable (1, 2)',
  `Quiz_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Storyline Quiz_score variable',
  `passing_score` INT(11) NOT NULL DEFAULT 80 COMMENT 'Pass mark threshold (80)',
  `result` ENUM('Passed', 'Failed') NOT NULL COMMENT 'Passed if Quiz_score >= 80, Failed if < 80',
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if locked after 2 failed attempts',
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if lockout alert email sent',
  `email_to` VARCHAR(255) DEFAULT NULL COMMENT 'TO recipients',
  `email_cc` VARCHAR(255) DEFAULT NULL COMMENT 'CC recipients',
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_attempt` (`user_id`, `attempt`),
  KEY `idx_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
