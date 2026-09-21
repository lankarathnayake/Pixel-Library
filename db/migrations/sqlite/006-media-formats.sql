-- Which file extensions the app registers (edited on the Settings page). Seeded with the built-in list, which must stay
-- identical to MediaTypes::DEFAULTS (a test compares them).
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
