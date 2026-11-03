ALTER TABLE identities
    MODIFY consented_at DATETIME NULL,
    ADD COLUMN enrollment_basis VARCHAR(40) NOT NULL DEFAULT 'explicit_consent' AFTER name_lookup_hash;

CREATE TABLE IF NOT EXISTS dataset_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    dataset_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(16) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'running',
    total_samples INT UNSIGNED NOT NULL DEFAULT 0,
    processed_samples INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_samples INT UNSIGNED NOT NULL DEFAULT 0,
    configuration JSON NOT NULL,
    started_at DATETIME(3) NOT NULL,
    finished_at DATETIME(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dataset_imports_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE RESTRICT,
    CONSTRAINT chk_dataset_imports_source CHECK (source_type IN ('folder', 'zip')),
    CONSTRAINT chk_dataset_imports_status CHECK (status IN ('running', 'paused', 'completed', 'cancelled')),
    UNIQUE KEY uq_dataset_imports_public_id (public_id),
    KEY ix_dataset_imports_dataset_created (dataset_id, created_at),
    KEY ix_dataset_imports_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS dataset_import_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dataset_import_id BIGINT UNSIGNED NOT NULL,
    identity_id BIGINT UNSIGNED NULL,
    source_label_hash BINARY(32) NOT NULL,
    label_ciphertext VARBINARY(512) NOT NULL,
    label_nonce BINARY(12) NOT NULL,
    label_auth_tag BINARY(16) NOT NULL,
    label_key_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_identities_import FOREIGN KEY (dataset_import_id) REFERENCES dataset_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_import_identities_identity FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_import_identity_label (dataset_import_id, source_label_hash),
    KEY ix_import_identities_identity (identity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS dataset_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    dataset_import_id BIGINT UNSIGNED NOT NULL,
    import_identity_id BIGINT UNSIGNED NOT NULL,
    source_key_hash BINARY(32) NOT NULL,
    file_fingerprint BINARY(32) NOT NULL,
    split_name VARCHAR(16) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    failure_code VARCHAR(60) NULL,
    mime_type VARCHAR(80) NULL,
    byte_size INT UNSIGNED NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    temporary_name VARCHAR(80) NULL,
    processed_at DATETIME(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dataset_samples_import FOREIGN KEY (dataset_import_id) REFERENCES dataset_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_samples_identity FOREIGN KEY (import_identity_id) REFERENCES dataset_import_identities(id) ON DELETE CASCADE,
    CONSTRAINT chk_dataset_samples_split CHECK (split_name IN ('gallery', 'probe')),
    CONSTRAINT chk_dataset_samples_status CHECK (status IN ('pending', 'processed', 'rejected')),
    UNIQUE KEY uq_dataset_samples_public_id (public_id),
    UNIQUE KEY uq_dataset_samples_source (dataset_import_id, source_key_hash),
    UNIQUE KEY uq_dataset_samples_fingerprint (dataset_import_id, file_fingerprint),
    KEY ix_dataset_samples_import_status (dataset_import_id, status, id),
    KEY ix_dataset_samples_identity_split (import_identity_id, split_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS dataset_sample_embeddings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dataset_sample_id BIGINT UNSIGNED NOT NULL,
    biometric_model_id SMALLINT UNSIGNED NOT NULL,
    usage_type VARCHAR(16) NOT NULL,
    gallery_embedding_id BIGINT UNSIGNED NULL,
    ciphertext MEDIUMBLOB NULL,
    nonce BINARY(12) NULL,
    auth_tag BINARY(16) NULL,
    key_id VARCHAR(100) NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sample_embeddings_sample FOREIGN KEY (dataset_sample_id) REFERENCES dataset_samples(id) ON DELETE CASCADE,
    CONSTRAINT fk_sample_embeddings_model FOREIGN KEY (biometric_model_id) REFERENCES biometric_models(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sample_embeddings_gallery FOREIGN KEY (gallery_embedding_id) REFERENCES embeddings(id) ON DELETE RESTRICT,
    CONSTRAINT chk_sample_embeddings_usage CHECK (usage_type IN ('gallery', 'probe')),
    CONSTRAINT chk_sample_embeddings_storage CHECK (
        (usage_type = 'gallery' AND gallery_embedding_id IS NOT NULL AND ciphertext IS NULL)
        OR (usage_type = 'probe' AND gallery_embedding_id IS NULL AND ciphertext IS NOT NULL AND nonce IS NOT NULL AND auth_tag IS NOT NULL AND key_id IS NOT NULL)
    ),
    UNIQUE KEY uq_sample_embedding_model (dataset_sample_id, biometric_model_id),
    KEY ix_sample_embeddings_gallery (gallery_embedding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version) VALUES ('003_dataset_import')
ON DUPLICATE KEY UPDATE version = VALUES(version);
