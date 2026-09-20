-- Rescan folders, playlists and the duplicate finder. Only ADDS things (one column, four tables).
-- Only for databases created from an OLDER schema.mysql.sql. Fresh installs already have this.
-- Run once:   mysql -u root pixel_library < db/migrations/005-folders-playlists-duplicates.mysql.sql

-- Duplicate finder: a hash of the file's content (size + sampled bytes), filled in when you scan. NULL = not hashed yet.
ALTER TABLE media
	ADD COLUMN content_hash CHAR(40) NULL AFTER thumb_claimed_at,
	ADD KEY idx_media_hash (content_hash),
	ADD KEY idx_media_size (size);

-- Folders you added, so they can be rescanned for new files.
CREATE TABLE IF NOT EXISTS library_folder (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	path        VARCHAR(2048) NOT NULL,
	path_hash   CHAR(40) NOT NULL,
	is_recursive TINYINT(1) NOT NULL DEFAULT 1,
	added_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_scan   DATETIME NULL,
	last_found  INT UNSIGNED NULL,              -- how many NEW files the last scan found
	PRIMARY KEY (id),
	UNIQUE KEY uq_folder_hash (path_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Playlists: ordered lists of files, exportable as .m3u8 for VLC / PotPlayer.
CREATE TABLE IF NOT EXISTS playlist (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name        VARCHAR(100) NOT NULL,
	created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_playlist_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_item (
	playlist_id  INT UNSIGNED NOT NULL,
	media_id     INT UNSIGNED NOT NULL,
	position     INT UNSIGNED NOT NULL,
	PRIMARY KEY (playlist_id, media_id),
	KEY idx_pi_position (playlist_id, position),
	KEY idx_pi_media (media_id),
	CONSTRAINT fk_pi_playlist FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE,
	CONSTRAINT fk_pi_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
