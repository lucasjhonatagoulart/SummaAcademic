-- Execute uma única vez em phpMyAdmin antes de substituir os arquivos da aplicação.
-- Faça antes uma cópia do banco. A migração mantém o acesso atual dos usuários já existentes.
-- admin_permission_seeded impede que uma reexecução restaure permissões já revogadas no Painel.

CREATE TABLE IF NOT EXISTS admin_permissions (
  admin_id INT NOT NULL,
  permission VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, permission),
  KEY admin_permissions_permission_idx (permission),
  CONSTRAINT admin_permissions_admin_fk FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_permission_seeded (
  admin_id INT NOT NULL,
  seeded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id),
  CONSTRAINT admin_permission_seeded_admin_fk FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_permissions (admin_id, permission)
SELECT a.id, p.permission
FROM admins AS a
LEFT JOIN admin_permission_seeded AS seeded ON seeded.admin_id = a.id
CROSS JOIN (
  SELECT 'edit_invoices' AS permission
  UNION ALL SELECT 'edit_assets'
  UNION ALL SELECT 'manage_catalogs'
  UNION ALL SELECT 'manage_users'
  UNION ALL SELECT 'export_data'
  UNION ALL SELECT 'restore_backup'
) AS p
WHERE seeded.admin_id IS NULL;

INSERT IGNORE INTO admin_permission_seeded (admin_id)
SELECT id FROM admins;
