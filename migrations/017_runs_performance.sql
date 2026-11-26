-- Performance of the pages that read run history (dashboard, jobs, runs, calendar, reports, status page).
-- run_at = when the run happened (started, or scheduled if it never started). Queries used
-- COALESCE(started_at, scheduled_at), which no index can serve, and read whole rows including the
-- stored response body. The covering indexes below answer them without touching the rows.
-- On a large job_runs table this rebuilds the table once (it can take a minute).

ALTER TABLE job_runs
  ADD COLUMN run_at DATETIME AS (COALESCE(started_at, scheduled_at)) STORED,
  DROP KEY ix_runs_tenant,
  ADD KEY ix_runs_tenant (tenant_id, created_at, status, duration_ms, job_id, trigger_type, http_status, is_slow),
  ADD KEY ix_runs_tenant_time (tenant_id, run_at, status, duration_ms, job_id),
  ADD KEY ix_runs_job_time (job_id, run_at, status, duration_ms, is_slow, http_status),
  ADD KEY ix_runs_job_created (job_id, created_at, status, duration_ms);
