-- Video preview thumbnails (added after the first release).
-- Only for databases created from the ORIGINAL schema.mysql.sql; fresh installs already have this.
-- Run once:   mysql -u root pixel_library < db/migrations/002-video-thumbs.mysql.sql

ALTER TABLE media
	ADD COLUMN duration          DECIMAL(10,2) NULL AFTER height,               -- video length in seconds
	ADD COLUMN thumb_status      TINYINT NOT NULL DEFAULT 0 AFTER is_missing,    -- 0 pending, 1 done, 2 failed, 3 in progress
	ADD COLUMN thumb_claimed_at  DATETIME NULL AFTER thumb_status,               -- when a worker took it (stale claims are retried)
	ADD KEY idx_media_thumb_status (thumb_status);

CREATE TABLE IF NOT EXISTS media_thumb (
	media_id  INT UNSIGNED NOT NULL,
	idx       SMALLINT UNSIGNED NOT NULL,        -- 0-based order along the video
	time_sec  DECIMAL(10,2) NOT NULL,            -- position in the video this frame was taken from
	file      VARCHAR(100) NOT NULL,             -- path relative to storage/thumbs/video/<media_id>/
	PRIMARY KEY (media_id, idx),
	CONSTRAINT fk_thumb_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
