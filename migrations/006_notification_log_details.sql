-- Keep the rendered message of every notification, and record skipped sends (with the reason).
ALTER TABLE notification_log
  ADD COLUMN subject VARCHAR(255) NULL AFTER target,
  ADD COLUMN body TEXT NULL AFTER subject,
  MODIFY COLUMN status ENUM('sent','failed','skipped') NOT NULL;
ALTER TABLE notification_log ADD KEY ix_nl_job (job_id, id);
