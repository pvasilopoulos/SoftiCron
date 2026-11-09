-- CronApp schema. All DATETIME values are stored in UTC.

CREATE TABLE tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Athens',
  brand_color CHAR(7) NOT NULL DEFAULT '#4F46E5',
  logo_path VARCHAR(255) NULL,
  max_jobs INT UNSIGNED NOT NULL DEFAULT 50,
  min_interval_minutes INT UNSIGNED NOT NULL DEFAULT 1,
  log_retention_days INT UNSIGNED NOT NULL DEFAULT 30,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  last_tenant_id INT UNSIGNED NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_roles_tenant (tenant_id),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission VARCHAR(64) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE memberships (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_membership (tenant_id, user_id),
  KEY ix_membership_user (user_id),
  CONSTRAINT fk_m_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_m_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_m_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  invited_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invite_token (token_hash),
  KEY ix_invite_tenant (tenant_id),
  CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_la_email (email, created_at),
  KEY ix_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE connectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  type ENUM('smtp','m365','telegram') NOT NULL,
  name VARCHAR(120) NOT NULL,
  config_enc TEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  last_test_at DATETIME NULL,
  last_test_ok TINYINT(1) NULL,
  last_test_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_conn_tenant (tenant_id, type),
  CONSTRAINT fk_conn_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  event VARCHAR(30) NOT NULL,
  channel ENUM('email','telegram','push') NOT NULL,
  subject VARCHAR(255) NULL,
  body MEDIUMTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tpl_tenant (tenant_id, event, channel),
  CONSTRAINT fk_tpl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  method VARCHAR(10) NOT NULL DEFAULT 'GET',
  url VARCHAR(2048) NOT NULL,
  headers_enc TEXT NULL,
  body_type ENUM('none','json','form','text','xml') NOT NULL DEFAULT 'none',
  body MEDIUMTEXT NULL,
  cron_expr VARCHAR(120) NOT NULL,
  schedule_ui TEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Athens',
  timeout_sec SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  max_retries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  retry_strategy ENUM('exponential','fixed') NOT NULL DEFAULT 'exponential',
  retry_delay_sec INT UNSIGNED NOT NULL DEFAULT 10,
  overlap_policy ENUM('skip','queue','allow') NOT NULL DEFAULT 'skip',
  success_codes VARCHAR(100) NOT NULL DEFAULT '200-299',
  success_contains VARCHAR(255) NULL,
  slow_threshold_ms INT UNSIGNED NULL,
  active_from DATE NULL,
  active_until DATE NULL,
  notify_emails VARCHAR(500) NULL,
  notify_telegram_chat VARCHAR(100) NULL,
  email_connector_id INT UNSIGNED NULL,
  telegram_connector_id INT UNSIGNED NULL,
  next_run_at DATETIME NULL,
  last_run_at DATETIME NULL,
  last_status VARCHAR(20) NULL,
  last_duration_ms INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_jobs_tenant (tenant_id),
  KEY ix_jobs_due (enabled, next_run_at),
  CONSTRAINT fk_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_notifications (
  job_id INT UNSIGNED NOT NULL,
  event VARCHAR(30) NOT NULL,
  channel ENUM('push','email','telegram') NOT NULL,
  template_id INT UNSIGNED NULL,
  PRIMARY KEY (job_id, event, channel),
  CONSTRAINT fk_jn_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NOT NULL,
  trigger_type ENUM('schedule','manual','missed') NOT NULL DEFAULT 'schedule',
  status ENUM('queued','running','retrying','success','failed','skipped','missed') NOT NULL DEFAULT 'queued',
  scheduled_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  http_status SMALLINT UNSIGNED NULL,
  duration_ms INT UNSIGNED NULL,
  is_slow TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  error VARCHAR(1000) NULL,
  request_method VARCHAR(10) NULL,
  request_url VARCHAR(2048) NULL,
  response_headers TEXT NULL,
  response_body MEDIUMTEXT NULL,
  response_size INT UNSIGNED NULL,
  triggered_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_run_slot (job_id, scheduled_at, trigger_type),
  KEY ix_runs_tenant (tenant_id, created_at),
  KEY ix_runs_work (status, next_attempt_at),
  KEY ix_runs_job (job_id, id),
  CONSTRAINT fk_runs_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_run_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  attempt_no TINYINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  duration_ms INT UNSIGNED NULL,
  http_status SMALLINT UNSIGNED NULL,
  error VARCHAR(1000) NULL,
  KEY ix_att_run (run_id),
  CONSTRAINT fk_att_run FOREIGN KEY (run_id) REFERENCES job_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NULL,
  run_id BIGINT UNSIGNED NULL,
  event VARCHAR(30) NOT NULL,
  channel VARCHAR(20) NOT NULL,
  target VARCHAR(255) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_nl_run (run_id),
  KEY ix_nl_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_success_at DATETIME NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_notification_prefs (
  user_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  events VARCHAR(255) NOT NULL,
  PRIMARY KEY (user_id, tenant_id),
  CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_unp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id VARCHAR(50) NULL,
  summary VARCHAR(255) NULL,
  changes LONGTEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_tenant (tenant_id, created_at),
  KEY ix_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
  k VARCHAR(80) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (tenant_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
