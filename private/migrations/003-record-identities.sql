-- Durable business-key identity. FileMaker ROWID remains the source locator,
-- while a configured primary key identifies the logical cached record.
CREATE TABLE IF NOT EXISTS rgcCache_record_identities (
  installation_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_name VARCHAR(191) NOT NULL,
  table_name VARCHAR(191) NOT NULL,
  primary_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  primary_key_field VARCHAR(191) NOT NULL,
  primary_key_value TEXT NOT NULL,
  fm_record_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (installation_id, file_name, table_name, primary_key_hash),
  UNIQUE KEY identity_locator_unique (installation_id, file_name, table_name, fm_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
