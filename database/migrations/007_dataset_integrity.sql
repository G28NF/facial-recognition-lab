ALTER TABLE identities
    DROP INDEX IF EXISTS uq_identities_name_hash,
    ADD KEY IF NOT EXISTS ix_identities_name_hash (name_lookup_hash);

ALTER TABLE dataset_import_identities
    DROP INDEX uq_import_identity_label,
    ADD COLUMN source_namespace_hash BINARY(32) NULL AFTER source_label_hash,
    ADD COLUMN source_identity_key_hash BINARY(32) NULL AFTER source_namespace_hash,
    ADD COLUMN source_identity_is_stable BOOLEAN NOT NULL DEFAULT FALSE AFTER source_identity_key_hash,
    ADD COLUMN integrity_status VARCHAR(32) NOT NULL DEFAULT 'clear' AFTER source_identity_is_stable,
    ADD COLUMN integrity_reason VARCHAR(80) NULL AFTER integrity_status,
    ADD UNIQUE KEY uq_import_identity_source (dataset_import_id, source_namespace_hash, source_identity_key_hash),
    ADD KEY ix_import_identities_integrity (integrity_status, identity_id);

ALTER TABLE dataset_samples
    ADD COLUMN integrity_status VARCHAR(32) NOT NULL DEFAULT 'clear' AFTER failure_code,
    ADD COLUMN integrity_reason VARCHAR(80) NULL AFTER integrity_status,
    ADD KEY ix_dataset_samples_integrity (dataset_import_id, integrity_status, split_name);

CREATE TABLE IF NOT EXISTS dataset_identity_source_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_namespace_hash BINARY(32) NOT NULL,
    source_identity_key_hash BINARY(32) NOT NULL,
    identity_id BIGINT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'unresolved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dataset_source_links_identity FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
    CONSTRAINT chk_dataset_source_links_status CHECK (status IN ('resolved', 'unresolved', 'ambiguous')),
    UNIQUE KEY uq_dataset_source_links_source (source_namespace_hash, source_identity_key_hash),
    KEY ix_dataset_source_links_identity (identity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dataset_integrity_findings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identity_id BIGINT UNSIGNED NOT NULL,
    matched_identity_id BIGINT UNSIGNED NOT NULL,
    architecture_id CHAR(1) NOT NULL,
    distance_squared DECIMAL(12,8) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    created_at DATETIME(3) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_integrity_findings_identity FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
    CONSTRAINT fk_integrity_findings_match FOREIGN KEY (matched_identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
    CONSTRAINT fk_integrity_findings_architecture FOREIGN KEY (architecture_id) REFERENCES architectures(id) ON DELETE RESTRICT,
    CONSTRAINT chk_integrity_findings_order CHECK (identity_id < matched_identity_id),
    CONSTRAINT chk_integrity_findings_status CHECK (status IN ('open', 'reviewed')),
    UNIQUE KEY uq_integrity_findings_pair (identity_id, matched_identity_id, architecture_id),
    KEY ix_integrity_findings_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('007_dataset_integrity')
ON DUPLICATE KEY UPDATE version = VALUES(version);
