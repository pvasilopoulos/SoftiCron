-- Backup & restore permissions: roles that manage the organisation's settings get them.
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'backup.manage' FROM role_permissions WHERE permission = 'settings.manage';
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'backup.restore' FROM role_permissions WHERE permission = 'settings.manage';

-- Owner (built-in role 1) always has everything.
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (1, 'backup.manage'), (1, 'backup.restore');
