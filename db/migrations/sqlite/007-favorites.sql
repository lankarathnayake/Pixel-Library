-- Favourites: a heart on a file (1 = favourite), with a "Favourites only" filter.
ALTER TABLE media ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0;
CREATE INDEX idx_media_favorite ON media (is_favorite);
