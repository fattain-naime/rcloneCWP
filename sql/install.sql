-- rcloneCWP — Database Schema
-- Version: 1.0.0
-- Database: root_cwp

-- -------------------------------------------------------
-- Table: rclone_destinations
-- Stores backup destination configurations
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_destinations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('local','ftp','sftp','s3','gdrive','gcs','b2','wasabi','dropbox','onedrive','azure','webdav') NOT NULL,
    `config` TEXT NOT NULL COMMENT 'JSON encrypted config',
    `bandwidth_limit` VARCHAR(20) DEFAULT NULL COMMENT 'e.g., 10M, 1G',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `test_status` TINYINT(1) DEFAULT NULL COMMENT '0=fail, 1=pass, NULL=untested',
    `test_message` TEXT DEFAULT NULL,
    `last_test` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_jobs
-- Stores backup job definitions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_jobs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `destinations` TEXT NOT NULL COMMENT 'JSON array of destination IDs',
    `users` TEXT DEFAULT NULL COMMENT 'JSON array of users, NULL=all',
    `components` TEXT NOT NULL COMMENT 'JSON: files,databases,emails,dns,ssl,cron,ftp',
    `backup_type` ENUM('full','incremental','selective') NOT NULL DEFAULT 'full',
    `compression` ENUM('gzip','bzip2','zstd','none') NOT NULL DEFAULT 'gzip',
    `compression_level` TINYINT NOT NULL DEFAULT 6 COMMENT '1-9 for gzip/bzip2, 1-22 for zstd',
    `encryption` TINYINT(1) NOT NULL DEFAULT 0,
    `encryption_password` VARCHAR(255) DEFAULT NULL COMMENT 'Encrypted',
    `exclude_paths` TEXT DEFAULT NULL COMMENT 'JSON array of paths to exclude',
    `retention_count` INT DEFAULT NULL COMMENT 'Keep N backups',
    `retention_days` INT DEFAULT NULL COMMENT 'Keep N days',
    `retention_months` INT DEFAULT NULL COMMENT 'Keep N months',
    `retention_years` INT DEFAULT NULL COMMENT 'Keep N years',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` DATETIME DEFAULT NULL,
    `last_status` ENUM('success','failure','running','never') DEFAULT NULL,
    `last_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_last_run` (`last_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_schedules
-- Stores backup schedules
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_schedules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `job_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` ENUM('daily','weekly','monthly','custom','interval') NOT NULL,
    `time` TIME NOT NULL DEFAULT '00:00:00',
    `days` VARCHAR(50) DEFAULT NULL COMMENT 'JSON array for weekly: [0,1,2,3,4,5,6]',
    `dates` VARCHAR(50) DEFAULT NULL COMMENT 'JSON array for monthly: [1,15,30]',
    `cron_expression` VARCHAR(100) DEFAULT NULL COMMENT 'For custom type',
    `interval_value` INT DEFAULT NULL COMMENT 'For interval type',
    `interval_unit` ENUM('minutes','hours') DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` DATETIME DEFAULT NULL,
    `next_run` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_next_run` (`next_run`),
    CONSTRAINT `fk_schedule_job` FOREIGN KEY (`job_id`) REFERENCES `rclone_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rcloneCWPs
-- Stores backup records/history
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rcloneCWPs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `job_id` INT(11) NOT NULL,
    `destination_id` INT(11) NOT NULL,
    `user` VARCHAR(255) NOT NULL,
    `backup_type` ENUM('full','incremental') NOT NULL,
    `components` TEXT NOT NULL COMMENT 'JSON array',
    `remote_path` VARCHAR(500) NOT NULL COMMENT 'Path on destination',
    `local_path` VARCHAR(500) DEFAULT NULL COMMENT 'Local temp path',
    `size_bytes` BIGINT DEFAULT NULL,
    `file_count` INT DEFAULT NULL,
    `status` ENUM('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
    `progress` TINYINT NOT NULL DEFAULT 0 COMMENT '0-100',
    `message` TEXT DEFAULT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    `duration_seconds` INT DEFAULT NULL,
    `checksum` VARCHAR(128) DEFAULT NULL COMMENT 'SHA256 of backup',
    `encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `compressed` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_destination_id` (`destination_id`),
    KEY `idx_user` (`user`),
    KEY `idx_status` (`status`),
    KEY `idx_started_at` (`started_at`),
    CONSTRAINT `fk_backup_job` FOREIGN KEY (`job_id`) REFERENCES `rclone_jobs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_backup_destination` FOREIGN KEY (`destination_id`) REFERENCES `rclone_destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_hooks
-- Stores hook definitions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_hooks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `hook_point` ENUM('pre_backup','post_backup','pre_restore','post_restore','on_failure','on_success') NOT NULL,
    `type` ENUM('shell','php','python','url') NOT NULL,
    `content` TEXT NOT NULL COMMENT 'Script content or URL',
    `timeout` INT NOT NULL DEFAULT 300 COMMENT 'Seconds',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `run_order` INT NOT NULL DEFAULT 100,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hook_point` (`hook_point`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_logs
-- Stores log entries
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_logs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `level` ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `job_id` INT DEFAULT NULL,
    `backup_id` INT DEFAULT NULL,
    `message` TEXT NOT NULL,
    `context` TEXT DEFAULT NULL COMMENT 'JSON context data',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_level` (`level`),
    KEY `idx_category` (`category`),
    KEY `idx_job_id` (`job_id`),
    KEY `idx_backup_id` (`backup_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_api_keys
-- Stores API keys
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_api_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `permissions` TEXT NOT NULL COMMENT 'JSON array of permissions',
    `ip_whitelist` TEXT DEFAULT NULL COMMENT 'JSON array of IPs',
    `rate_limit` INT NOT NULL DEFAULT 100 COMMENT 'Requests per minute',
    `last_used` DATETIME DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_key` (`api_key`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Table: rclone_notifications
-- Stores notification configurations
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rclone_notifications` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `type` ENUM('email','slack','telegram','webhook') NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `config` TEXT NOT NULL COMMENT 'JSON config (SMTP, webhook URL, etc.)',
    `events` TEXT NOT NULL COMMENT 'JSON array: backup_success, backup_failure, etc.',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sent` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Insert default data
-- -------------------------------------------------------

-- Default API key (change this!)
INSERT INTO `rclone_api_keys` (`name`, `api_key`, `permissions`, `rate_limit`)
VALUES ('Default Key', REPLACE(UUID(), '-', ''), '["*"]', 100);

-- Default notification (email)
INSERT INTO `rclone_notifications` (`type`, `name`, `config`, `events`)
VALUES (
    'email',
    'Admin Email',
    '{"host":"localhost","port":25,"from":"root@localhost","to":"root@localhost"}',
    '["backup_failure"]'
);
