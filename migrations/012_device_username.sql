-- Keep who registered each push device on the device itself (login username = email, plus display name).
ALTER TABLE push_subscriptions
  ADD COLUMN username VARCHAR(190) NULL AFTER user_id,
  ADD COLUMN user_display VARCHAR(150) NULL AFTER username;

-- Existing devices: fill in from their current user.
UPDATE push_subscriptions s JOIN users u ON u.id = s.user_id
SET s.username = u.email, s.user_display = u.name
WHERE s.username IS NULL;
