-- Phase: Moodle Integration - Employee Mapping Table
-- Enforces strict 1:1 mapping between HexaOne UID and Moodle User ID

CREATE TABLE IF NOT EXISTS onboard_moodle_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hexa_uid INT NOT NULL,
  username VARCHAR(100) NULL,
  moodle_user_id INT NULL,
  sync_status ENUM('Not linked', 'Pending verification', 'Linked', 'Error') DEFAULT 'Not linked',
  last_synced_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hexa_uid (hexa_uid),
  UNIQUE KEY uq_moodle_user_id (moodle_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
