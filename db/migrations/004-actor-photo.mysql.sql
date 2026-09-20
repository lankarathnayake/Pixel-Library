-- Actor photo: the stored file name (under storage/actors/), NULL = no photo.
-- Only for databases that already ran 003. Fresh installs already have the column.
-- Run once:   mysql -u root pixel_library < db/migrations/004-actor-photo.mysql.sql

ALTER TABLE actor_profile ADD COLUMN photo VARCHAR(60) NULL AFTER notes;
