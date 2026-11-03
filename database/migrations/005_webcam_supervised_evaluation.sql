CREATE TABLE webcam_evaluation_segments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_group_id BINARY(16) NOT NULL,
 expected_identity_id BIGINT UNSIGNED NOT NULL, segment_number INT UNSIGNED NOT NULL,
 started_at DATETIME(3) NOT NULL, ended_at DATETIME(3) NULL,
 CONSTRAINT fk_webcam_segment_identity FOREIGN KEY (expected_identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_webcam_segment (session_group_id, segment_number), KEY ix_webcam_segment_open (session_group_id, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE webcam_evaluation_results (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, webcam_evaluation_segment_id BIGINT UNSIGNED NOT NULL,
 recognition_event_id BIGINT UNSIGNED NOT NULL, expected_identity_id BIGINT UNSIGNED NOT NULL, predicted_identity_id BIGINT UNSIGNED NULL,
 outcome VARCHAR(24) NOT NULL, created_at DATETIME(3) NOT NULL,
 CONSTRAINT fk_webcam_result_segment FOREIGN KEY (webcam_evaluation_segment_id) REFERENCES webcam_evaluation_segments(id) ON DELETE CASCADE,
 CONSTRAINT fk_webcam_result_event FOREIGN KEY (recognition_event_id) REFERENCES recognition_events(id) ON DELETE CASCADE,
 CONSTRAINT fk_webcam_result_expected FOREIGN KEY (expected_identity_id) REFERENCES identities(id) ON DELETE RESTRICT,
 CONSTRAINT fk_webcam_result_predicted FOREIGN KEY (predicted_identity_id) REFERENCES identities(id) ON DELETE SET NULL,
 CONSTRAINT chk_webcam_result_outcome CHECK (outcome IN ('correct','incorrect','not_recognized','technical_failure')),
 UNIQUE KEY uq_webcam_result_event (recognition_event_id), KEY ix_webcam_result_segment_outcome (webcam_evaluation_segment_id, outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO schema_migrations (version) VALUES ('005_webcam_supervised_evaluation') ON DUPLICATE KEY UPDATE version=VALUES(version);
