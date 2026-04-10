-- QR Redirect Analytics schema

CREATE TABLE IF NOT EXISTS counselors (
  id INT AUTO_INCREMENT,
  code VARCHAR(16) NOT NULL,
  name VARCHAR(128) NULL,
  remarks TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @col_active := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselors' AND COLUMN_NAME = 'active');
SET @sql_active := IF(@col_active = 0, 'ALTER TABLE counselors ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER remarks', 'SELECT 1');
PREPARE stmt_active FROM @sql_active; EXECUTE stmt_active; DEALLOCATE PREPARE stmt_active;

SET @col_hash := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselors' AND COLUMN_NAME = 'password_hash');
SET @sql_hash := IF(@col_hash = 0, 'ALTER TABLE counselors ADD COLUMN password_hash VARCHAR(255) NULL AFTER active', 'SELECT 1');
PREPARE stmt_hash FROM @sql_hash; EXECUTE stmt_hash; DEALLOCATE PREPARE stmt_hash;

SET @col_change := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselors' AND COLUMN_NAME = 'must_change_password');
SET @sql_change := IF(@col_change = 0, 'ALTER TABLE counselors ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash', 'SELECT 1');
PREPARE stmt_change FROM @sql_change; EXECUTE stmt_change; DEALLOCATE PREPARE stmt_change;

SET @col_login := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselors' AND COLUMN_NAME = 'last_login_at');
SET @sql_login := IF(@col_login = 0, 'ALTER TABLE counselors ADD COLUMN last_login_at DATETIME NULL AFTER must_change_password', 'SELECT 1');
PREPARE stmt_login FROM @sql_login; EXECUTE stmt_login; DEALLOCATE PREPARE stmt_login;

CREATE TABLE IF NOT EXISTS scans_raw (
  id BIGINT AUTO_INCREMENT,
  counselor_code VARCHAR(16) NULL,
  device_fingerprint VARCHAR(128) NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_counselor_code (counselor_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unique_counts (
  id BIGINT AUTO_INCREMENT,
  counselor_code VARCHAR(16) NULL,
  device_fingerprint VARCHAR(128) NULL,
  first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_counselor_device (counselor_code, device_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_submissions (
  id BIGINT AUTO_INCREMENT,
  submission_no VARCHAR(32) NULL,
  counselor_code VARCHAR(16) NOT NULL,
  student_name VARCHAR(128) NOT NULL,
  parent_contact VARCHAR(128) NOT NULL,
  grade VARCHAR(32) NOT NULL,
  interest_fields TEXT NOT NULL,
  background_level VARCHAR(64) NOT NULL,
  device_fingerprint VARCHAR(128) NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_submission_no (submission_no),
  INDEX idx_lead_counselor (counselor_code),
  INDEX idx_lead_submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @lead_submission_no := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_submissions' AND COLUMN_NAME = 'submission_no');
SET @sql_submission_no := IF(@lead_submission_no = 0, 'ALTER TABLE lead_submissions ADD COLUMN submission_no VARCHAR(32) NULL AFTER id', 'SELECT 1');
PREPARE stmt_submission_no FROM @sql_submission_no; EXECUTE stmt_submission_no; DEALLOCATE PREPARE stmt_submission_no;

SET @lead_submission_no_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_submissions' AND INDEX_NAME = 'uniq_submission_no'
);
SET @sql_submission_no_index := IF(@lead_submission_no_index = 0, 'ALTER TABLE lead_submissions ADD UNIQUE KEY uniq_submission_no (submission_no)', 'SELECT 1');
PREPARE stmt_submission_no_index FROM @sql_submission_no_index; EXECUTE stmt_submission_no_index; DEALLOCATE PREPARE stmt_submission_no_index;
