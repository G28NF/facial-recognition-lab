CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS architectures (
    id CHAR(1) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    extraction_runtime VARCHAR(40) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    name_ciphertext VARBINARY(512) NOT NULL,
    name_nonce BINARY(12) NOT NULL,
    name_auth_tag BINARY(16) NOT NULL,
    name_key_id VARCHAR(100) NOT NULL,
    name_lookup_hash BINARY(32) NOT NULL,
    consented_at DATETIME NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_identities_public_id (public_id),
    UNIQUE KEY uq_identities_name_hash (name_lookup_hash),
    KEY ix_identities_active_id (active, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biometric_models (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    architecture_id CHAR(1) NOT NULL,
    model_key VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    version VARCHAR(60) NOT NULL,
    vector_space_key VARCHAR(160) NOT NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    distance_metric VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_models_architecture FOREIGN KEY (architecture_id) REFERENCES architectures(id) ON DELETE RESTRICT,
    CONSTRAINT chk_models_dimensions CHECK (dimensions > 0),
    UNIQUE KEY uq_models_key (model_key),
    UNIQUE KEY uq_models_vector_space (vector_space_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS embeddings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identity_id BIGINT UNSIGNED NOT NULL,
    biometric_model_id SMALLINT UNSIGNED NOT NULL,
    sample_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    ciphertext MEDIUMBLOB NOT NULL,
    nonce BINARY(12) NOT NULL,
    auth_tag BINARY(16) NOT NULL,
    key_id VARCHAR(100) NOT NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    quality_metadata JSON NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_embeddings_identity FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE CASCADE,
    CONSTRAINT fk_embeddings_model FOREIGN KEY (biometric_model_id) REFERENCES biometric_models(id) ON DELETE RESTRICT,
    CONSTRAINT chk_embeddings_dimensions CHECK (dimensions > 0),
    UNIQUE KEY uq_embeddings_sample (identity_id, biometric_model_id, sample_number),
    KEY ix_embeddings_model_active (biometric_model_id, active, identity_id),
    KEY ix_embeddings_identity_model (identity_id, biometric_model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS datasets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_datasets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experiments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    dataset_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    metadata JSON NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_experiments_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
    UNIQUE KEY uq_experiments_public_id (public_id),
    KEY ix_experiments_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    experiment_id BIGINT UNSIGNED NULL,
    architecture_id CHAR(1) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
    CONSTRAINT fk_sessions_architecture FOREIGN KEY (architecture_id) REFERENCES architectures(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_sessions_public_id (public_id),
    KEY ix_sessions_architecture_status (architecture_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recognition_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    video_session_id BIGINT UNSIGNED NULL,
    architecture_id CHAR(1) NOT NULL,
    matched_identity_id BIGINT UNSIGNED NULL,
    frame_sequence BIGINT UNSIGNED NULL,
    result VARCHAR(30) NOT NULL,
    score DECIMAL(12,8) NULL,
    threshold DECIMAL(12,8) NULL,
    occurred_at DATETIME(3) NOT NULL,
    metadata JSON NULL,
    CONSTRAINT fk_events_session FOREIGN KEY (video_session_id) REFERENCES video_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_architecture FOREIGN KEY (architecture_id) REFERENCES architectures(id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_identity FOREIGN KEY (matched_identity_id) REFERENCES identities(id) ON DELETE SET NULL,
    KEY ix_events_session_time (video_session_id, occurred_at),
    KEY ix_events_identity_time (matched_identity_id, occurred_at),
    KEY ix_events_architecture_result (architecture_id, result, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS execution_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recognition_event_id BIGINT UNSIGNED NULL,
    video_session_id BIGINT UNSIGNED NULL,
    metric_name VARCHAR(80) NOT NULL,
    metric_value DECIMAL(16,4) NOT NULL,
    unit VARCHAR(24) NOT NULL,
    recorded_at DATETIME(3) NOT NULL,
    CONSTRAINT fk_metrics_event FOREIGN KEY (recognition_event_id) REFERENCES recognition_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_metrics_session FOREIGN KEY (video_session_id) REFERENCES video_sessions(id) ON DELETE CASCADE,
    KEY ix_metrics_session_name_time (video_session_id, metric_name, recorded_at),
    KEY ix_metrics_name_time (metric_name, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO architectures (id, name, extraction_runtime) VALUES
    ('A', 'Face API.js + PHP', 'browser-javascript'),
    ('B', 'YuNet + SFace + PHP', 'python')
ON DUPLICATE KEY UPDATE name = VALUES(name), extraction_runtime = VALUES(extraction_runtime);

INSERT INTO biometric_models (architecture_id, model_key, name, version, vector_space_key, dimensions, distance_metric) VALUES
    ('A', 'faceapi-recognition', 'Face API.js', '0.22.2', 'faceapi-0.22.2-recognition-v1', 128, 'squared_l2'),
    ('B', 'opencv-sface', 'OpenCV SFace', '2021dec', 'opencv-sface-2021dec-normalized-v1', 128, 'squared_l2')
ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), dimensions = VALUES(dimensions), distance_metric = VALUES(distance_metric);

INSERT INTO schema_migrations (version) VALUES ('001_initial_schema')
ON DUPLICATE KEY UPDATE version = VALUES(version);
