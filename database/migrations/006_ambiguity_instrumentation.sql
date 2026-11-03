ALTER TABLE probe_evaluation_results
    ADD COLUMN best_candidate_identity_id BIGINT UNSIGNED NULL AFTER predicted_identity_id,
    ADD COLUMN best_candidate_distance_squared DECIMAL(12,8) NULL AFTER distance_squared,
    ADD COLUMN second_candidate_identity_id BIGINT UNSIGNED NULL AFTER best_candidate_identity_id,
    ADD COLUMN second_candidate_distance_squared DECIMAL(12,8) NULL AFTER best_candidate_distance_squared,
    ADD COLUMN candidate_distance_delta_squared DECIMAL(12,8) NULL AFTER second_candidate_distance_squared,
    ADD COLUMN ambiguity_margin_squared DECIMAL(12,8) NULL AFTER threshold_squared,
    ADD COLUMN decision_reason VARCHAR(24) NULL AFTER failure_code,
    ADD CONSTRAINT fk_probe_evaluation_results_best_candidate FOREIGN KEY (best_candidate_identity_id) REFERENCES identities(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_probe_evaluation_results_second_candidate FOREIGN KEY (second_candidate_identity_id) REFERENCES identities(id) ON DELETE SET NULL;

INSERT INTO schema_migrations (version) VALUES ('006_ambiguity_instrumentation')
ON DUPLICATE KEY UPDATE version = VALUES(version);
