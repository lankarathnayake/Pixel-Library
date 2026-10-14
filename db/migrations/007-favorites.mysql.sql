-- Favourites: a heart on a file (1 = favourite), with a "Favourites only" filter.
-- Only for databases created from an OLDER schema.mysql.sql. Fresh installs already have this.
-- Run once:   mysql -u root pixel_library < db/migrations/007-favorites.mysql.sql
ALTER TABLE media
	ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER is_missing,
	ADD KEY idx_media_favorite (is_favorite);
