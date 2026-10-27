-- Versioned, application-managed mapping documents.
-- id also identifies the cache installation built from this mapping.
CREATE TABLE IF NOT EXISTS rgcCache_mappings (
  id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  mapping_json MEDIUMTEXT NOT NULL,
  mapping_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  activated_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY mapping_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
