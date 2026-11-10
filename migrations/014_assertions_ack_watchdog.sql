-- Response assertions, incident acknowledgement ("Το είδα") and scheduler watchdog.

ALTER TABLE jobs
  ADD COLUMN assertions TEXT NULL AFTER success_contains,
  ADD COLUMN acked_at DATETIME NULL AFTER escalated_at,
  ADD COLUMN acked_by INT UNSIGNED NULL AFTER acked_at,
  ADD COLUMN acked_name VARCHAR(120) NULL AFTER acked_by,
  ADD COLUMN ack_note VARCHAR(500) NULL AFTER acked_name;

-- Per-run outcome of each assertion: [{label, ok, actual}]
ALTER TABLE job_runs ADD COLUMN assertion_results TEXT NULL AFTER error;

-- Acknowledging incidents: everyone who can see jobs, by default.
INSERT IGNORE INTO role_permissions (role_id, permission)
  SELECT DISTINCT role_id, 'incidents.ack' FROM role_permissions WHERE permission = 'jobs.view';
