-- Execute uma única vez pelo phpMyAdmin antes de substituir os arquivos da aplicação.
-- Faça uma cópia do banco antes da atualização. Esta migração não apaga nem altera ativos, NF-es ou permissões.
CREATE TABLE IF NOT EXISTS asset_deletion_audit (
  id INT NOT NULL AUTO_INCREMENT,
  assetId INT NOT NULL,
  invoiceId INT NULL DEFAULT NULL,
  assetName TEXT NOT NULL,
  classification ENUM('fixed','small') NOT NULL,
  category VARCHAR(255) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  patrimony TEXT NOT NULL,
  invoiceNumber VARCHAR(64) NOT NULL DEFAULT '',
  assetStatus ENUM('active','disposed') NOT NULL,
  reason TEXT NOT NULL,
  deletedByAdminId INT NULL DEFAULT NULL,
  deletedByUsername VARCHAR(80) NOT NULL DEFAULT '',
  deletedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY asset_deletion_audit_asset_idx (assetId),
  KEY asset_deletion_audit_date_idx (deletedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
