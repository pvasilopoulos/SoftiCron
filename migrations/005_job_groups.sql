-- Free-text group used to organise jobs in the list.
ALTER TABLE jobs ADD COLUMN group_name VARCHAR(100) NULL AFTER name;
ALTER TABLE jobs ADD KEY ix_jobs_group (tenant_id, group_name);
