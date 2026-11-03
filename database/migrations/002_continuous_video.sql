ALTER TABLE video_sessions
    ADD COLUMN session_group_id BINARY(16) NULL AFTER id,
    ADD COLUMN interval_ms INT UNSIGNED NOT NULL DEFAULT 1000 AFTER status,
    ADD COLUMN paused_at DATETIME(3) NULL AFTER started_at,
    ADD COLUMN total_paused_ms BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER ended_at,
    ADD COLUMN missed_intervals INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_paused_ms,
    ADD COLUMN last_frame_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER missed_intervals,
    ADD UNIQUE KEY uq_video_sessions_group_architecture (session_group_id, architecture_id),
    ADD KEY ix_video_sessions_group_status (session_group_id, status);

ALTER TABLE execution_metrics
    ADD UNIQUE KEY uq_execution_metrics_event_name (recognition_event_id, metric_name);

INSERT INTO schema_migrations (version) VALUES ('002_continuous_video')
ON DUPLICATE KEY UPDATE version = VALUES(version);
