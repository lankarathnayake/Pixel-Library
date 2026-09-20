-- Actor profiles: extra details, star rating, custom text fields and talent lists.
-- Only for databases created from an OLDER schema.mysql.sql. Fresh installs already have this.
-- Run once:   mysql -u root pixel_library < db/migrations/003-actor-profiles.mysql.sql
-- Only ADDS tables. Nothing existing is changed. (A "profile" belongs to a term, i.e. an entry of a category such as Actors.)

CREATE TABLE IF NOT EXISTS actor_profile (
	term_id    INT UNSIGNED NOT NULL,
	full_name  VARCHAR(150) NULL,
	dob        DATE NULL,                          -- date of birth (age is worked out from it)
	rating     TINYINT UNSIGNED NULL,              -- 1-5 stars, NULL = not rated
	notes      TEXT NULL,
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
