-- A tenant can customise a built-in role: the tenant gets its own copy (based_on = system role id).
ALTER TABLE roles ADD COLUMN based_on INT UNSIGNED NULL AFTER is_system;
