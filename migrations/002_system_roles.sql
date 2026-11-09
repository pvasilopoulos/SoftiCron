-- Built-in roles shared by every tenant (tenant_id NULL). Tenants can add custom roles.

INSERT INTO roles (id, tenant_id, name, description, is_system) VALUES
  (1, NULL, 'Owner', 'Πλήρης πρόσβαση στον tenant', 1),
  (2, NULL, 'Admin', 'Διαχείριση jobs, connectors, χρηστών και ρυθμίσεων', 1),
  (3, NULL, 'Operator', 'Δημιουργία και εκτέλεση jobs', 1),
  (4, NULL, 'Viewer', 'Μόνο προβολή', 1);

INSERT INTO role_permissions (role_id, permission) VALUES
  (1, 'jobs.view'), (1, 'jobs.manage'), (1, 'jobs.run'), (1, 'runs.view'),
  (1, 'connectors.manage'), (1, 'templates.manage'), (1, 'users.manage'),
  (1, 'roles.manage'), (1, 'audit.view'), (1, 'settings.manage'),
  (2, 'jobs.view'), (2, 'jobs.manage'), (2, 'jobs.run'), (2, 'runs.view'),
  (2, 'connectors.manage'), (2, 'templates.manage'), (2, 'users.manage'),
  (2, 'audit.view'), (2, 'settings.manage'),
  (3, 'jobs.view'), (3, 'jobs.manage'), (3, 'jobs.run'), (3, 'runs.view'),
  (4, 'jobs.view'), (4, 'runs.view');

ALTER TABLE roles AUTO_INCREMENT = 100;
