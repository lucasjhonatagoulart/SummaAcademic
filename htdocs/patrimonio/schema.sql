CREATE TABLE IF NOT EXISTS admins (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY admins_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS login_attempts (
  ip_hash CHAR(64) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_attempt DATETIME NOT NULL,
  blocked_until DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id INT NOT NULL AUTO_INCREMENT,
  accessKey VARCHAR(44) NULL DEFAULT NULL,
  number VARCHAR(32) NOT NULL DEFAULT '',
  series VARCHAR(16) NOT NULL DEFAULT '',
  issuedAt VARCHAR(40) NOT NULL DEFAULT '',
  supplier VARCHAR(320) NOT NULL DEFAULT '',
  supplierCnpj VARCHAR(32) NOT NULL DEFAULT '',
  recipient VARCHAR(320) NOT NULL DEFAULT '',
  recipientCnpj VARCHAR(32) NOT NULL DEFAULT '',
  total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','registered') NOT NULL DEFAULT 'draft',
  source ENUM('manual','xml') NOT NULL DEFAULT 'manual',
  xmlStatus VARCHAR(255) NOT NULL DEFAULT '',
  xml MEDIUMTEXT NULL,
  notes TEXT NOT NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY invoices_access_key_unique (accessKey),
  KEY invoices_status_idx (status),
  KEY invoices_number_idx (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  invoiceId INT NOT NULL,
  id VARCHAR(96) NOT NULL,
  originalDescription TEXT NOT NULL,
  name TEXT NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  unit VARCHAR(32) NOT NULL DEFAULT '',
  unitValue DECIMAL(15,6) NOT NULL DEFAULT 0.000000,
  total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  ncm VARCHAR(32) NOT NULL DEFAULT '',
  cfop VARCHAR(32) NOT NULL DEFAULT '',
  classification ENUM('fixed','small','skip') NOT NULL DEFAULT 'fixed',
  category VARCHAR(255) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  patrimony TEXT NOT NULL,
  brand VARCHAR(255) NOT NULL DEFAULT '',
  color VARCHAR(255) NOT NULL DEFAULT '',
  type VARCHAR(255) NOT NULL DEFAULT '',
  `condition` VARCHAR(64) NOT NULL DEFAULT 'Novo',
  usefulLife INT NOT NULL DEFAULT 5,
  notes TEXT NOT NULL,
  PRIMARY KEY (invoiceId, id),
  KEY invoice_items_invoice_idx (invoiceId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
  id INT NOT NULL AUTO_INCREMENT,
  sourceSheet VARCHAR(128) NOT NULL DEFAULT 'manual',
  sourceRow INT NULL DEFAULT NULL,
  legacyId VARCHAR(128) NULL DEFAULT NULL,
  classification ENUM('fixed','small') NOT NULL,
  name TEXT NOT NULL,
  originalDescription TEXT NOT NULL,
  category VARCHAR(255) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  patrimony TEXT NOT NULL,
  brand VARCHAR(255) NOT NULL DEFAULT '',
  color VARCHAR(255) NOT NULL DEFAULT '',
  type VARCHAR(255) NOT NULL DEFAULT '',
  `condition` VARCHAR(64) NOT NULL DEFAULT 'Novo',
  invoiceNumber VARCHAR(64) NOT NULL DEFAULT '',
  invoiceId INT NULL DEFAULT NULL,
  acquiredAt VARCHAR(40) NULL DEFAULT NULL,
  quantity DECIMAL(12,3) NULL DEFAULT NULL,
  value DECIMAL(15,2) NULL DEFAULT NULL,
  usefulLife INT NULL DEFAULT NULL,
  purchaseYear INT NULL DEFAULT NULL,
  status ENUM('active','disposed') NOT NULL DEFAULT 'active',
  disposalDate VARCHAR(40) NULL DEFAULT NULL,
  disposalReason TEXT NOT NULL,
  notes TEXT NOT NULL,
  original MEDIUMTEXT NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY assets_class_status_idx (classification, status),
  KEY assets_category_idx (category),
  KEY assets_location_idx (location),
  KEY assets_invoice_idx (invoiceId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_patrimony_registry (
  patrimony VARCHAR(255) NOT NULL,
  assetId INT NOT NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (patrimony),
  KEY asset_registry_asset_idx (assetId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS catalogs (
  kind ENUM('category','location') NOT NULL,
  value VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (kind, value),
  KEY catalogs_kind_idx (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_imports (
  id INT NOT NULL AUTO_INCREMENT,
  sourceFile VARCHAR(255) NOT NULL,
  importedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restore_stage (
  batchId CHAR(32) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  chunkNo INT NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batchId, kind, chunkNo),
  KEY restore_stage_created_idx (createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
