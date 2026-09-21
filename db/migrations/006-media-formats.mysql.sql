-- Which file extensions the app registers (edited on the Settings page). Run once:
--   mysql -u root pixel_library < db/migrations/006-media-formats.mysql.sql
-- Seeded with the built-in list, which must stay identical to MediaTypes::DEFAULTS.
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
