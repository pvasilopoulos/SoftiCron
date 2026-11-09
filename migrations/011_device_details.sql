-- Richer push device information (who / what / where) and delivery health.
ALTER TABLE push_subscriptions
  ADD COLUMN provider VARCHAR(30) NULL AFTER label,
  ADD COLUMN platform VARCHAR(60) NULL AFTER provider,
  ADD COLUMN browser VARCHAR(60) NULL AFTER platform,
  ADD COLUMN full_user_agent VARCHAR(500) NULL AFTER browser,
  ADD COLUMN is_pwa TINYINT(1) NOT NULL DEFAULT 0 AFTER full_user_agent,
  ADD COLUMN language VARCHAR(20) NULL AFTER is_pwa,
  ADD COLUMN timezone VARCHAR(64) NULL AFTER language,
  ADD COLUMN screen VARCHAR(20) NULL AFTER timezone,
  ADD COLUMN created_ip VARCHAR(45) NULL AFTER screen,
  ADD COLUMN last_ip VARCHAR(45) NULL AFTER created_ip,
  ADD COLUMN last_seen_at DATETIME NULL AFTER last_ip,
  ADD COLUMN last_error VARCHAR(255) NULL AFTER last_success_at,
  ADD COLUMN last_error_at DATETIME NULL AFTER last_error,
  ADD COLUMN fail_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_error_at;

-- Provider of the devices registered before this migration.
UPDATE push_subscriptions SET provider = CASE
  WHEN endpoint LIKE 'https://fcm.googleapis.com/%' THEN 'fcm'
  WHEN endpoint LIKE 'https://web.push.apple.com/%' THEN 'apple'
  WHEN endpoint LIKE '%mozilla.com/%' THEN 'mozilla'
  WHEN endpoint LIKE '%notify.windows.com/%' THEN 'wns'
  ELSE 'other' END
WHERE provider IS NULL;

-- New permissions: see / manage the devices of all members (admin page).
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'devices.view' FROM role_permissions WHERE permission = 'users.manage';
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'devices.manage' FROM role_permissions WHERE permission = 'users.manage';
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (1, 'devices.view'), (1, 'devices.manage');
