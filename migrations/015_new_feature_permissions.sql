-- Permissions for the November 2026 features. Existing roles keep the access they effectively had.

-- Runs calendar: everyone who can see runs.
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'calendar.view' FROM role_permissions WHERE permission = 'runs.view';

-- Response assertions: everyone who can edit jobs.
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'jobs.assertions' FROM role_permissions WHERE permission = 'jobs.edit';

-- Undo someone else's acknowledgement: roles that manage users (Owner/Admin and their copies).
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'incidents.unack' FROM role_permissions WHERE permission = 'users.manage';

-- Owner (built-in role 1) always has everything.
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES
  (1, 'calendar.view'), (1, 'jobs.assertions'), (1, 'incidents.ack'), (1, 'incidents.unack');
