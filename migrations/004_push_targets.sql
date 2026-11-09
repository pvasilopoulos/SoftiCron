-- Per-device name and on/off switch.
ALTER TABLE push_subscriptions
  ADD COLUMN label VARCHAR(100) NULL AFTER user_agent,
  ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER label;

-- Per-job push recipients: 'all' = every member who wants the event, 'selected' = job_push_targets.
ALTER TABLE jobs ADD COLUMN push_target ENUM('all','selected') NOT NULL DEFAULT 'all' AFTER telegram_connector_id;

-- A row targets either a whole user (all of their devices) or one specific device.
CREATE TABLE job_push_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  subscription_id INT UNSIGNED NULL,
  KEY ix_jpt_job (job_id),
  CONSTRAINT fk_jpt_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_jpt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_jpt_sub FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
