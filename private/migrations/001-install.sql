-- Select your EXISTING database in phpMyAdmin before importing.
-- Replace rgcCache_ consistently if configuring another table prefix.
-- CREATE IF NOT EXISTS does not upgrade or validate existing tables.
-- Run scripts/database.php check afterward. No DROP, ALTER or CREATE DATABASE.
CREATE TABLE IF NOT EXISTS rgcCache_installations (
  id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schema_version INT NOT NULL DEFAULT 1,
  config_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  initialized TINYINT NOT NULL DEFAULT 0,
  importing TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS rgcCache_inbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  installation_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  delivery_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  body_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY delivery_unique (installation_id, delivery_id),
  KEY pending_order (installation_id, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS rgcCache_records (
  installation_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_name VARCHAR(191) NOT NULL,
  table_name VARCHAR(191) NOT NULL,
  fm_record_id BIGINT UNSIGNED NOT NULL,
  primary_key TEXT NULL,
  source_modified_at TEXT NULL,
  payload MEDIUMTEXT NOT NULL,
  synced_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (installation_id, file_name, table_name, fm_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS rgcCache_import_progress (
  installation_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  table_name VARCHAR(191) NOT NULL,
  after_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  done TINYINT NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (installation_id, table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
