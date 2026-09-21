-- Pixel Library schema. Apply to an empty database, e.g.:
--   mysql -u root -e "CREATE DATABASE pixel_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root pixel_library < db/schema.mysql.sql

-- A registered file. Only the location + metadata is stored - never the file itself.
CREATE TABLE IF NOT EXISTS media (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	path        VARCHAR(2048) NOT NULL,           -- absolute path as it exists on disk
	path_hash   CHAR(40) NOT NULL,                -- sha1 of the path (lower-cased on Windows); dedupe key
	name        VARCHAR(255) NOT NULL,            -- file name incl. extension
	ext         VARCHAR(10) NOT NULL,
	type        ENUM('image','video') NOT NULL,
	size        BIGINT UNSIGNED NOT NULL DEFAULT 0,
	file_mtime  DATETIME NULL,                    -- file's last-modified time when registered
	width       INT UNSIGNED NULL,                -- images only
	height      INT UNSIGNED NULL,
	duration    DECIMAL(10,2) NULL,               -- videos only: length in seconds (filled when previews are made)
	is_missing  TINYINT(1) NOT NULL DEFAULT 0,    -- file no longer found at `path`
	thumb_status      TINYINT NOT NULL DEFAULT 0, -- videos: 0 pending, 1 done, 2 failed, 3 in progress
	thumb_claimed_at  DATETIME NULL,              -- when a worker took it (stale claims are retried)
	content_hash      CHAR(40) NULL,              -- duplicate finder: hash of size + sampled bytes (NULL = not hashed yet)
	added_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_media_path_hash (path_hash),
	KEY idx_media_type (type),
	KEY idx_media_name (name),
	KEY idx_media_added (added_at),
	KEY idx_media_missing (is_missing),
	KEY idx_media_thumb_status (thumb_status),
	KEY idx_media_hash (content_hash),
	KEY idx_media_size (size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preview frames of a video, taken at evenly spaced points. The files live in
-- storage/thumbs/video/<media_id>/ ; this table is the pointer to them.
CREATE TABLE IF NOT EXISTS media_thumb (
	media_id  INT UNSIGNED NOT NULL,
	idx       SMALLINT UNSIGNED NOT NULL,        -- 0-based order along the video
	time_sec  DECIMAL(10,2) NOT NULL,            -- position in the video this frame was taken from
	file      VARCHAR(100) NOT NULL,             -- file name inside that folder
	PRIMARY KEY (media_id, idx),
	CONSTRAINT fk_thumb_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A kind of label: "Tags", "Actors", "Studios"... user-defined.
CREATE TABLE IF NOT EXISTS category (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name        VARCHAR(100) NOT NULL,
	created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A label inside a category: the tag "beach", the actor "Jane Doe".
CREATE TABLE IF NOT EXISTS term (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	category_id  INT UNSIGNED NOT NULL,
	name         VARCHAR(100) NOT NULL,
	created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_term_category_name (category_id, name),
	CONSTRAINT fk_term_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which labels a file carries.
CREATE TABLE IF NOT EXISTS media_term (
	media_id  INT UNSIGNED NOT NULL,
	term_id   INT UNSIGNED NOT NULL,
	PRIMARY KEY (media_id, term_id),
	KEY idx_media_term_term (term_id),
	CONSTRAINT fk_mt_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE,
	CONSTRAINT fk_mt_term FOREIGN KEY (term_id) REFERENCES term (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actor profiles: details, star rating, custom text fields and talent lists (see db/migrations/003).
CREATE TABLE IF NOT EXISTS actor_profile (
	term_id    INT UNSIGNED NOT NULL,
	full_name  VARCHAR(150) NULL,
	dob        DATE NULL,                          -- date of birth (age is worked out from it)
	rating     TINYINT UNSIGNED NULL,              -- 1-5 stars, NULL = not rated
	notes      TEXT NULL,
	photo      VARCHAR(60) NULL,                   -- stored file name under storage/actors/ (NULL = no photo)
	PRIMARY KEY (term_id),
	CONSTRAINT fk_ap_term FOREIGN KEY (term_id) REFERENCES term (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A custom text field you define once ("Nationality", "Height", ...); every profile then has it.
CREATE TABLE IF NOT EXISTS field_def (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name        VARCHAR(100) NOT NULL,
	is_long     TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = multi-line text box
	created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_field_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actor_field_value (
	term_id   INT UNSIGNED NOT NULL,
	field_id  INT UNSIGNED NOT NULL,
	value     TEXT NOT NULL,
	PRIMARY KEY (term_id, field_id),
	KEY idx_afv_field (field_id),
	CONSTRAINT fk_afv_term FOREIGN KEY (term_id) REFERENCES term (id) ON DELETE CASCADE,
	CONSTRAINT fk_afv_field FOREIGN KEY (field_id) REFERENCES field_def (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Talent lists: named collections such as "Favourites" or "To watch". An actor can be on any number of them.
CREATE TABLE IF NOT EXISTS talent_list (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name        VARCHAR(100) NOT NULL,
	created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_list_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talent_list_actor (
	list_id  INT UNSIGNED NOT NULL,
	term_id  INT UNSIGNED NOT NULL,
	PRIMARY KEY (list_id, term_id),
	KEY idx_tla_term (term_id),
	CONSTRAINT fk_tla_list FOREIGN KEY (list_id) REFERENCES talent_list (id) ON DELETE CASCADE,
	CONSTRAINT fk_tla_term FOREIGN KEY (term_id) REFERENCES term (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Which file extensions the app registers (edited on the Settings page). Must stay identical to MediaTypes::DEFAULTS.
CREATE TABLE IF NOT EXISTS media_format (
	ext   VARCHAR(10) NOT NULL,                                -- lower case, no dot
	type  ENUM('image','video') NOT NULL,
	mime  VARCHAR(100) NOT NULL,                               -- what the file is served as
	PRIMARY KEY (ext)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO media_format (ext, type, mime) VALUES
	('jpg', 'image', 'image/jpeg'), ('jpeg', 'image', 'image/jpeg'), ('png', 'image', 'image/png'), ('gif', 'image', 'image/gif'),
	('webp', 'image', 'image/webp'), ('bmp', 'image', 'image/bmp'), ('avif', 'image', 'image/avif'),
	('mp4', 'video', 'video/mp4'), ('m4v', 'video', 'video/mp4'), ('webm', 'video', 'video/webm'), ('ogv', 'video', 'video/ogg'),
	('mov', 'video', 'video/quicktime'), ('mkv', 'video', 'video/x-matroska'), ('avi', 'video', 'video/x-msvideo'),
	('wmv', 'video', 'video/x-ms-wmv'), ('ts', 'video', 'video/mp2t');

-- Starter categories (add, rename or delete freely in the Manage page). Plain INSERT so it stays portable.
INSERT INTO category (name) VALUES ('Tags'), ('Actors');
