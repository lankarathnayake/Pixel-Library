-- Pixel Library schema for SQLite. You never have to apply this by hand: core/Db.php runs it when it finds an empty
-- database file (and runs db/migrations/sqlite/NNN-*.sql when the file is older than Db::SCHEMA_VERSION).
--
-- UNICODE_CI is a collation core/Db.php registers on every connection (case-insensitive for any letter, like MySQL's
-- utf8mb4_unicode_ci). Timestamps are local time, as MySQL's were. Other tools that open this file (sqlite3.exe, DB Browser) do not know it and will refuse to
-- read or sort those columns - use them through the app, or register a collation of that name first.

-- A registered file. Only the location + metadata is stored - never the file itself.
CREATE TABLE media (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,   -- never reused: video preview folders are named after it
	path        TEXT NOT NULL,                       -- absolute path as it exists on disk
	path_hash   TEXT NOT NULL,                       -- sha1 of the path (lower-cased on Windows); dedupe key
	name        TEXT NOT NULL COLLATE UNICODE_CI,    -- file name incl. extension
	ext         TEXT NOT NULL,
	type        TEXT NOT NULL CHECK (type IN ('image', 'video')),
	size        INTEGER NOT NULL DEFAULT 0,
	file_mtime  TEXT NULL,                           -- file's last-modified time when registered
	width       INTEGER NULL,                        -- images only
	height      INTEGER NULL,
	duration    REAL NULL,                           -- videos only: length in seconds (filled when previews are made)
	is_missing  INTEGER NOT NULL DEFAULT 0,          -- file no longer found at `path`
	thumb_status      INTEGER NOT NULL DEFAULT 0,    -- videos: 0 pending, 1 done, 2 failed, 3 in progress
	thumb_claimed_at  TEXT NULL,                     -- when a worker took it (stale claims are retried)
	content_hash      TEXT NULL,                     -- duplicate finder: hash of size + sampled bytes (NULL = not hashed yet)
	added_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE UNIQUE INDEX uq_media_path_hash ON media (path_hash);
CREATE INDEX idx_media_type ON media (type);
CREATE INDEX idx_media_name ON media (name);
CREATE INDEX idx_media_added ON media (added_at);
CREATE INDEX idx_media_missing ON media (is_missing);
CREATE INDEX idx_media_thumb_status ON media (thumb_status);
CREATE INDEX idx_media_hash ON media (content_hash);
CREATE INDEX idx_media_size ON media (size);

-- Preview frames of a video, taken at evenly spaced points. The files live in
-- storage/thumbs/video/<media_id>/ ; this table is the pointer to them.
CREATE TABLE media_thumb (
	media_id  INTEGER NOT NULL REFERENCES media (id) ON DELETE CASCADE,
	idx       INTEGER NOT NULL,                      -- 0-based order along the video
	time_sec  REAL NOT NULL,                         -- position in the video this frame was taken from
	file      TEXT NOT NULL,                         -- file name inside that folder
	PRIMARY KEY (media_id, idx)
);

-- A kind of label: "Tags", "Actors", "Studios"... user-defined.
CREATE TABLE category (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	name        TEXT NOT NULL COLLATE UNICODE_CI UNIQUE,
	created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- A label inside a category: the tag "beach", the actor "Jane Doe".
CREATE TABLE term (
	id           INTEGER PRIMARY KEY AUTOINCREMENT,
	category_id  INTEGER NOT NULL REFERENCES category (id) ON DELETE CASCADE,
	name         TEXT NOT NULL COLLATE UNICODE_CI,
	created_at   TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
	UNIQUE (category_id, name)
);

-- Which labels a file carries.
CREATE TABLE media_term (
	media_id  INTEGER NOT NULL REFERENCES media (id) ON DELETE CASCADE,
	term_id   INTEGER NOT NULL REFERENCES term (id) ON DELETE CASCADE,
	PRIMARY KEY (media_id, term_id)
);
CREATE INDEX idx_media_term_term ON media_term (term_id);

-- Actor profiles: details, star rating, custom text fields and talent lists.
CREATE TABLE actor_profile (
	term_id    INTEGER PRIMARY KEY REFERENCES term (id) ON DELETE CASCADE,
	full_name  TEXT NULL,
	dob        TEXT NULL,                            -- date of birth, YYYY-MM-DD (age is worked out from it)
	rating     INTEGER NULL,                         -- 1-5 stars, NULL = not rated
	notes      TEXT NULL,
	photo      TEXT NULL                             -- stored file name under storage/actors/ (NULL = no photo)
);

-- A custom text field you define once ("Nationality", "Height", ...); every profile then has it.
CREATE TABLE field_def (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	name        TEXT NOT NULL COLLATE UNICODE_CI UNIQUE,
	is_long     INTEGER NOT NULL DEFAULT 0,          -- 1 = multi-line text box
	created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE actor_field_value (
	term_id   INTEGER NOT NULL REFERENCES term (id) ON DELETE CASCADE,
	field_id  INTEGER NOT NULL REFERENCES field_def (id) ON DELETE CASCADE,
	value     TEXT NOT NULL,
	PRIMARY KEY (term_id, field_id)
);
CREATE INDEX idx_afv_field ON actor_field_value (field_id);

-- Talent lists: named collections such as "Favourites" or "To watch". An actor can be on any number of them.
CREATE TABLE talent_list (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	name        TEXT NOT NULL COLLATE UNICODE_CI UNIQUE,
	created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE talent_list_actor (
	list_id  INTEGER NOT NULL REFERENCES talent_list (id) ON DELETE CASCADE,
	term_id  INTEGER NOT NULL REFERENCES term (id) ON DELETE CASCADE,
	PRIMARY KEY (list_id, term_id)
);
CREATE INDEX idx_tla_term ON talent_list_actor (term_id);

-- Folders you added, so they can be rescanned for new files.
CREATE TABLE library_folder (
	id           INTEGER PRIMARY KEY AUTOINCREMENT,
	path         TEXT NOT NULL COLLATE UNICODE_CI,
	path_hash    TEXT NOT NULL,
	is_recursive INTEGER NOT NULL DEFAULT 1,
	added_at     TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
	last_scan    TEXT NULL,
	last_found   INTEGER NULL                         -- how many NEW files the last scan found
);
CREATE UNIQUE INDEX uq_folder_hash ON library_folder (path_hash);

-- Playlists: ordered lists of files, exportable as .m3u8 for VLC / PotPlayer.
CREATE TABLE playlist (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	name        TEXT NOT NULL COLLATE UNICODE_CI UNIQUE,
	created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE playlist_item (
	playlist_id  INTEGER NOT NULL REFERENCES playlist (id) ON DELETE CASCADE,
	media_id     INTEGER NOT NULL REFERENCES media (id) ON DELETE CASCADE,
	position     INTEGER NOT NULL,
	PRIMARY KEY (playlist_id, media_id)
);
CREATE INDEX idx_pi_position ON playlist_item (playlist_id, position);
CREATE INDEX idx_pi_media ON playlist_item (media_id);

-- Which file extensions the app registers (edited on the Settings page). Must stay identical to MediaTypes::DEFAULTS.
CREATE TABLE media_format (
	ext   TEXT PRIMARY KEY,                                    -- lower case, no dot
	type  TEXT NOT NULL CHECK (type IN ('image', 'video')),
	mime  TEXT NOT NULL                                        -- what the file is served as
);
INSERT INTO media_format (ext, type, mime) VALUES
	('jpg', 'image', 'image/jpeg'), ('jpeg', 'image', 'image/jpeg'), ('png', 'image', 'image/png'), ('gif', 'image', 'image/gif'),
	('webp', 'image', 'image/webp'), ('bmp', 'image', 'image/bmp'), ('avif', 'image', 'image/avif'),
	('mp4', 'video', 'video/mp4'), ('m4v', 'video', 'video/mp4'), ('webm', 'video', 'video/webm'), ('ogv', 'video', 'video/ogg'),
	('mov', 'video', 'video/quicktime'), ('mkv', 'video', 'video/x-matroska'), ('avi', 'video', 'video/x-msvideo'),
	('wmv', 'video', 'video/x-ms-wmv'), ('ts', 'video', 'video/mp2t');

-- Starter categories (add, rename or delete freely in the Manage page).
INSERT INTO category (name) VALUES ('Tags'), ('Actors');

PRAGMA user_version = 6;
