-- Variables & secrets, job auth (OAuth2), escalation, heartbeat, SLA, status page,
-- API tokens and job version history.

CREATE TABLE tenant_variables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  value_enc TEXT NOT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_var (tenant_id, name),
  CONSTRAINT fk_var_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE jobs
  ADD COLUMN type ENUM('http','heartbeat') NOT NULL DEFAULT 'http' AFTER tenant_id,
  ADD COLUMN auth_type ENUM('none','basic','bearer','oauth2') NOT NULL DEFAULT 'none' AFTER headers_enc,
  ADD COLUMN auth_enc TEXT NULL AFTER auth_type,
  ADD COLUMN ping_token CHAR(32) NULL,
  ADD COLUMN grace_minutes INT UNSIGNED NOT NULL DEFAULT 5,
  ADD COLUMN last_ping_at DATETIME NULL,
  ADD COLUMN notify_after_failures TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN escalate_after_minutes INT UNSIGNED NULL,
  ADD COLUMN escalate_channels VARCHAR(100) NULL,
  ADD COLUMN failing_since DATETIME NULL,
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN sla_target DECIMAL(5,2) NULL,
  ADD COLUMN show_on_status TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN status_name VARCHAR(100) NULL,
  ADD UNIQUE KEY uq_jobs_ping (ping_token);

ALTER TABLE job_runs MODIFY COLUMN trigger_type ENUM('schedule','manual','missed','ping') NOT NULL DEFAULT 'schedule';

CREATE TABLE oauth_tokens (
  job_id INT UNSIGNED NOT NULL PRIMARY KEY,
  token_enc TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  CONSTRAINT fk_oauth_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  prefix VARCHAR(16) NOT NULL,
  scopes VARCHAR(100) NOT NULL DEFAULT 'read',
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_token (token_hash),
  KEY ix_api_tenant (tenant_id),
  CONSTRAINT fk_api_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_api_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  data LONGTEXT NOT NULL,
  comment VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job_version (job_id, version_no),
  CONSTRAINT fk_ver_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New permissions for built-in roles and for tenant copies of them.
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES
  (1, 'reports.view'), (2, 'reports.view'), (3, 'reports.view'), (4, 'reports.view'),
  (1, 'variables.manage'), (2, 'variables.manage'),
  (1, 'api.manage'), (2, 'api.manage');
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'reports.view' FROM roles WHERE based_on IN (1, 2, 3, 4);
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'variables.manage' FROM roles WHERE based_on IN (1, 2);
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'api.manage' FROM roles WHERE based_on IN (1, 2);
