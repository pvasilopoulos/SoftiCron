-- Announcements / scheduled maintenance / incident updates shown on the public status page.
CREATE TABLE status_notices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  kind ENUM('info','maintenance','incident','resolved') NOT NULL DEFAULT 'info',
  title VARCHAR(200) NOT NULL,
  body TEXT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_notice_tenant (tenant_id, starts_at),
  CONSTRAINT fk_notice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
