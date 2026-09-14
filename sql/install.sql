-- rcloneCWP Database Schema
-- Version: 1.0.0
-- Compatible with PHP 7.1+ and MySQL/MariaDB

-- Table 1: Destinations
CREATE TABLE IF NOT EXISTS rclone_destinations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type ENUM('local', 's3', 'gs', 'azure', 'b2', 'onedrive', 'dropbox', 'webdav', 'sftp', 'azblob', 'swift', 'tencent') NOT NULL,
  config TEXT NOT NULL COMMENT 'JSON with provider-specific settings (including encrypted credentials)',
  enabled TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: Backup Jobs
CREATE TABLE IF NOT EXISTS rclone_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  source_path VARCHAR(500) NOT NULL,
  destination_id INT NOT NULL,
  job_type ENUM('full', 'incremental', 'selective') DEFAULT 'incremental',
  retention_days INT DEFAULT 7,
  compression TINYINT(1) DEFAULT 1,
  notify_on_success TINYINT(1) DEFAULT 0,
  notify_on_failure TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (destination_id) REFERENCES rclone_destinations(id) ON DELETE CASCADE,
  INDEX idx_destination (destination_id),
  INDEX idx_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 3: Schedules
CREATE TABLE IF NOT EXISTS rclone_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  cron_expression VARCHAR(100) NOT NULL,
  timezone VARCHAR(100) DEFAULT 'UTC',
  next_run DATETIME NULL,
  last_run DATETIME NULL,
  active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES rclone_jobs(id) ON DELETE CASCADE,
  INDEX idx_job (job_id),
  INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 4: Backup History (NOT rclone_backups)
CREATE TABLE IF NOT EXISTS rclone_backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NULL,
  destination_id INT NULL,
  backup_type ENUM('full', 'incremental', 'selective', 'restore') NOT NULL,
  status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  files_count INT DEFAULT 0,
  bytes_transferred BIGINT DEFAULT 0,
  duration_seconds INT NULL,
  error_message TEXT NULL,
  config_id VARCHAR(255) NULL,
  notes TEXT NULL,
  FOREIGN KEY (job_id) REFERENCES rclone_jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (destination_id) REFERENCES rclone_destinations(id) ON DELETE SET NULL,
  INDEX idx_job (job_id),
  INDEX idx_status (status),
  INDEX idx_created_at (started_at DESC),
  INDEX idx_config (config_id),
  UNIQUE KEY unique_backup_run (job_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 5: Hooks
CREATE TABLE IF NOT EXISTS rclone_hooks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  event ENUM('backup_start', 'backup_complete', 'backup_fail', 'restore_start', 'restore_complete', 'restore_fail') NOT NULL,
  type ENUM('shell', 'php', 'python', 'url') NOT NULL DEFAULT 'shell',
  command TEXT NOT NULL,
  timeout INT NOT NULL DEFAULT 300,
  run_order INT NOT NULL DEFAULT 10,
  enabled TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_event (event),
  INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 6: Logs
CREATE TABLE IF NOT EXISTS rclone_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level ENUM('debug', 'info', 'warning', 'error') NOT NULL,
  category VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  context JSON NULL,
  destination_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (destination_id) REFERENCES rclone_destinations(id) ON DELETE SET NULL,
  INDEX idx_level (level),
  INDEX idx_category (category),
  INDEX idx_created_at (created_at DESC),
  INDEX idx_compound (created_at DESC, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 7: API Keys
CREATE TABLE IF NOT EXISTS rclone_api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  api_key CHAR(64) NOT NULL COMMENT 'SHA256 hash of the actual key',
  permissions JSON NOT NULL COMMENT 'Array of allowed permissions',
  expires_at DATETIME NULL,
  last_used DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_name (name),
  UNIQUE KEY unique_api_key (api_key),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 8: Notifications
CREATE TABLE IF NOT EXISTS rclone_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type ENUM('email', 'telegram', 'webhook') NOT NULL,
  config TEXT NOT NULL COMMENT 'JSON with provider-specific settings',
  active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Data: API key placeholder row (name 'default')
-- The installer fills api_key (SHA256 of random_bytes(32)) and permissions;
-- the seed row guarantees a stable target for the idempotent UPDATE.
INSERT INTO rclone_api_keys (name, api_key, permissions, expires_at) VALUES
  ('default', '', '[]', NULL)
ON DUPLICATE KEY UPDATE id = id;

-- End of schema
