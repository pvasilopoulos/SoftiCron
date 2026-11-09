-- Detailed permissions, API run trigger and in-app notification center.

-- 1) Runs started through the REST API.
ALTER TABLE job_runs MODIFY COLUMN trigger_type ENUM('schedule','manual','missed','ping','api') NOT NULL DEFAULT 'schedule';

-- 2) Granular permissions. Every role keeps what it could do before:
--    each old permission is expanded to the detailed ones that replace it.
CREATE TEMPORARY TABLE perm_map (old_perm VARCHAR(60) NOT NULL, new_perm VARCHAR(60) NOT NULL);
INSERT INTO perm_map (old_perm, new_perm) VALUES
  ('jobs.view', 'jobs.versions'),
  ('jobs.manage', 'jobs.create'), ('jobs.manage', 'jobs.edit'), ('jobs.manage', 'jobs.delete'),
  ('jobs.manage', 'jobs.toggle'), ('jobs.manage', 'jobs.export'), ('jobs.manage', 'jobs.import'),
  ('jobs.manage', 'jobs.versions'), ('jobs.manage', 'jobs.restore'),
  ('jobs.run', 'runs.retry'),
  ('runs.view', 'runs.export'), ('runs.view', 'notifications.history'),
  ('reports.view', 'reports.export'),
  ('connectors.manage', 'connectors.view'), ('connectors.manage', 'connectors.test'),
  ('templates.manage', 'templates.view'), ('templates.manage', 'templates.test'),
  ('variables.manage', 'variables.view'),
  ('settings.manage', 'settings.notifications'), ('settings.manage', 'settings.reports'),
  ('settings.manage', 'settings.branding'), ('settings.manage', 'settings.menu'),
  ('settings.manage', 'settings.status_page'), ('settings.manage', 'reports.email'),
  ('users.manage', 'users.view'), ('users.manage', 'users.delete'),
  ('roles.manage', 'users.view'),
  ('audit.view', 'audit.export');

INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT rp.role_id, m.new_perm FROM role_permissions rp JOIN perm_map m ON m.old_perm = rp.permission;

-- "jobs.manage" is replaced by the detailed job permissions.
DELETE FROM role_permissions WHERE permission = 'jobs.manage';
DROP TEMPORARY TABLE perm_map;

-- Owner (built-in role 1) always has everything.
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES
  (1, 'jobs.view'), (1, 'jobs.create'), (1, 'jobs.edit'), (1, 'jobs.delete'), (1, 'jobs.toggle'), (1, 'jobs.run'),
  (1, 'jobs.export'), (1, 'jobs.import'), (1, 'jobs.versions'), (1, 'jobs.restore'),
  (1, 'runs.view'), (1, 'runs.retry'), (1, 'runs.export'),
  (1, 'reports.view'), (1, 'reports.export'), (1, 'reports.email'),
  (1, 'notifications.history'),
  (1, 'connectors.view'), (1, 'connectors.manage'), (1, 'connectors.test'),
  (1, 'templates.view'), (1, 'templates.manage'), (1, 'templates.test'),
  (1, 'variables.view'), (1, 'variables.manage'), (1, 'api.manage'),
  (1, 'settings.manage'), (1, 'settings.notifications'), (1, 'settings.reports'), (1, 'settings.branding'),
  (1, 'settings.menu'), (1, 'settings.status_page'),
  (1, 'users.view'), (1, 'users.manage'), (1, 'users.delete'), (1, 'roles.manage'),
  (1, 'audit.view'), (1, 'audit.export');

-- 3) In-app notification center (bell): one row per user and event.
CREATE TABLE user_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NULL,
  run_id BIGINT UNSIGNED NULL,
  event VARCHAR(30) NOT NULL,
  level ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  title VARCHAR(255) NOT NULL,
  body VARCHAR(1000) NULL,
  url VARCHAR(500) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_un_user (user_id, tenant_id, read_at, id),
  KEY ix_un_created (created_at),
  CONSTRAINT fk_un_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_un_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
