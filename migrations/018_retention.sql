-- History retention chosen by each organization (within its plan's maximum, tenants.log_retention_days).

-- Per-job override: keep this job's runs for its own number of days.
ALTER TABLE jobs ADD COLUMN retention_days INT UNSIGNED NULL AFTER sla_target;

-- Permission to change the retention settings: roles that manage the organisation's settings.
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'settings.retention' FROM role_permissions WHERE permission = 'settings.manage';
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (1, 'settings.retention');
