CREATE TABLE IF NOT EXISTS probe_evaluations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id BINARY(16) NOT NULL,
    dataset_import_id BIGINT UNSIGNED NOT NULL,
    requested_samples INT UNSIGNED NOT NULL,
    eligible_samples INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'running',
    started_at DATETIME(3) NOT NULL,
    finished_at DATETIME(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_probe_evaluations_import FOREIGN KEY (dataset_import_id) REFERENCES dataset_imports(id) ON DELETE RESTRICT,
    CONSTRAINT chk_probe_evaluations_status CHECK (status IN ('running', 'completed', 'failed')),
    UNIQUE KEY uq_probe_evaluations_public_id (public_id),
    KEY ix_probe_evaluations_import_created (dataset_import_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS probe_evaluation_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    probe_evaluation_id BIGINT UNSIGNED NOT NULL,
    dataset_sample_id BIGINT UNSIGNED NOT NULL,
    architecture_id CHAR(1) NOT NULL,
    expected_identity_id BIGINT UNSIGNED NOT NULL,
    predicted_identity_id BIGINT UNSIGNED NULL,
    outcome VARCHAR(24) NOT NULL,
    recognition_result VARCHAR(24) NOT NULL,
    distance_squared DECIMAL(12,8) NULL,
    threshold_squared DECIMAL(12,8) NOT NULL,
    compared_embeddings INT UNSIGNED NOT NULL DEFAULT 0,
    comparison_total_ms DECIMAL(16,4) NULL,
    failure_code VARCHAR(80) NULL,
    created_at DATETIME(3) NOT NULL,
    CONSTRAINT fk_probe_evaluation_results_evaluation FOREIGN KEY (probe_evaluation_id) REFERENCES probe_evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_probe_evaluation_results_sample FOREIGN KEY (dataset_sample_id) REFERENCES dataset_samples(id) ON DELETE RESTRICT,
    CONSTRAINT fk_probe_evaluation_results_architecture FOREIGN KEY (architecture_id) REFERENCES architectures(id) ON DELETE RESTRICT,
    CONSTRAINT fk_probe_evaluation_results_expected FOREIGN KEY (expected_identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
    CONSTRAINT fk_probe_evaluation_results_predicted FOREIGN KEY (predicted_identity_id) REFERENCES identities(id) ON DELETE SET NULL,
    CONSTRAINT chk_probe_evaluation_results_outcome CHECK (outcome IN ('correct', 'incorrect', 'not_recognized', 'technical_failure')),
    UNIQUE KEY uq_probe_evaluation_sample_architecture (probe_evaluation_id, dataset_sample_id, architecture_id),
    KEY ix_probe_evaluation_results_evaluation_outcome (probe_evaluation_id, architecture_id, outcome),
    KEY ix_probe_evaluation_results_sample (dataset_sample_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version) VALUES ('004_probe_evaluations')
ON DUPLICATE KEY UPDATE version = VALUES(version);
