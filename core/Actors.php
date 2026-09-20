<?php

/**
 * Actor profiles. An "actor" is a term (an entry of a category such as Actors); its profile adds a full name,
 * date of birth, star rating and notes, values for the custom text fields you define, and membership of talent lists.
 * Tags themselves (name, files tagged) stay in Taxonomy; this class only holds the extra information.
 * All SQL is plain (no ON DUPLICATE KEY etc.) and deletes are explicit, so it stays portable.
 */
class Actors {

	private const CHUNK = 500;
	private const MAX_FIELDS = 50;

	private const SORTS = [
		'name_asc'    => 't.name ASC',
		'name_desc'   => 't.name DESC',
		'rating_desc' => '(p.rating IS NULL) ASC, p.rating DESC, t.name ASC',
		'rating_asc'  => '(p.rating IS NULL) ASC, p.rating ASC, t.name ASC',
		'age_asc'     => '(p.dob IS NULL) ASC, p.dob DESC, t.name ASC', // youngest first
		'age_desc'    => '(p.dob IS NULL) ASC, p.dob ASC, t.name ASC',  // oldest first
		'files_desc'  => 'files DESC, t.name ASC',
		'files_asc'   => 'files ASC, t.name ASC',
		'added_desc'  => 't.id DESC',
		'added_asc'   => 't.id ASC',
	];

	// ---------------------------------------------------------------- reading

	/** Everything the page needs to start: categories (+ which one is "the" actors category), fields and lists. */
	public static function meta(): array {
		$pdo = Db::pdo();
		$cats = $pdo->query('SELECT id, name FROM category ORDER BY id')->fetchAll();
		$default = null;
		foreach ($cats as $c) {
			if (mb_strtolower($c['name'], 'UTF-8') === 'actors') {
				$default = (int) $c['id'];
			}
		}
		if ($default === null && $cats) {
			$default = (int) $cats[0]['id'];
		}
		return [
			'categories' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $cats),
			'default_category' => $default,
			'fields' => self::fields(),
			'lists' => self::lists(),
		];
	}

	/**
	 * @param array $f q (search words), sort, min_rating (1-5), list (list id)
	 * @return array{actors: array, total: int, has_more: bool}
	 */
	public static function search(int $categoryId, array $f, int $offset = 0, int $limit = 60): array {
		$pdo = Db::pdo();
		$where = ['t.category_id = ?'];
		$args = [$categoryId];

		foreach (preg_split('/\s+/', trim((string) ($f['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
			$where[] = "(t.name LIKE ? ESCAPE '!' OR p.full_name LIKE ? ESCAPE '!' OR p.notes LIKE ? ESCAPE '!'
				OR EXISTS (SELECT 1 FROM actor_field_value v WHERE v.term_id = t.id AND v.value LIKE ? ESCAPE '!'))";
			array_push($args, $like, $like, $like, $like);
		}
		$min = (int) ($f['min_rating'] ?? 0);
		if ($min >= 1 && $min <= 5) {
			$where[] = 'p.rating >= ?';
			$args[] = $min;
		}
		if (!empty($f['unrated'])) {
			$where[] = 'p.rating IS NULL';
		}
		$list = (int) ($f['list'] ?? 0);
		if ($list > 0) {
			$where[] = 'EXISTS (SELECT 1 FROM talent_list_actor la WHERE la.term_id = t.id AND la.list_id = ?)';
			$args[] = $list;
		}

		$whereSql = 'WHERE ' . implode(' AND ', $where);
		$order = self::SORTS[$f['sort'] ?? ''] ?? self::SORTS['name_asc'];
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$from = 'FROM term t LEFT JOIN actor_profile p ON p.term_id = t.id';

		$count = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
		$count->execute($args);
		$total = (int) $count->fetchColumn();

		$stmt = $pdo->prepare(
			"SELECT t.id, t.name, p.full_name, p.dob, p.rating, p.photo,
			        (SELECT COUNT(*) FROM media_term x WHERE x.term_id = t.id) AS files
			 $from $whereSql ORDER BY $order LIMIT $limit OFFSET $offset"
		);
		$stmt->execute($args);
		$rows = $stmt->fetchAll();

		$ids = array_map(fn($r) => (int) $r['id'], $rows);
		$lists = self::listsFor($ids);
		$actors = array_map(fn($r) => [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'full_name' => $r['full_name'],
			'dob' => $r['dob'],
			'age' => self::age($r['dob']),
			'rating' => $r['rating'] === null ? null : (int) $r['rating'],
			'photo' => $r['photo'],
			'files' => (int) $r['files'],
			'lists' => $lists[(int) $r['id']] ?? [],
		], $rows);

		return ['actors' => $actors, 'total' => $total, 'has_more' => $offset + count($actors) < $total];
	}

	/** One actor's full profile, or null if there is no such term. */
	public static function profile(int $termId): ?array {
		$pdo = Db::pdo();
		$stmt = $pdo->prepare(
			'SELECT t.id, t.name, t.category_id, c.name AS category, p.full_name, p.dob, p.rating, p.notes, p.photo,
			        (SELECT COUNT(*) FROM media_term x WHERE x.term_id = t.id) AS files
			 FROM term t JOIN category c ON c.id = t.category_id LEFT JOIN actor_profile p ON p.term_id = t.id
			 WHERE t.id = ?'
		);
		$stmt->execute([$termId]);
		$r = $stmt->fetch();
		if ($r === false) {
			return null;
		}
		$vals = $pdo->prepare('SELECT field_id, value FROM actor_field_value WHERE term_id = ?');
		$vals->execute([$termId]);
		$byField = [];
		foreach ($vals->fetchAll() as $v) {
			$byField[(int) $v['field_id']] = $v['value'];
		}
		$fields = array_map(fn($d) => $d + ['value' => $byField[$d['id']] ?? ''], self::fields());
		$mine = self::listsFor([$termId])[$termId] ?? [];

		return [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'category_id' => (int) $r['category_id'],
			'category' => $r['category'],
			'full_name' => $r['full_name'],
			'dob' => $r['dob'],
			'age' => self::age($r['dob']),
			'rating' => $r['rating'] === null ? null : (int) $r['rating'],
			'notes' => $r['notes'],
			'photo' => $r['photo'],
			'files' => (int) $r['files'],
			'fields' => $fields,
			'lists' => array_map(fn($l) => $l['id'], $mine),
		];
	}

	public static function age(?string $dob): ?int {
		if ($dob === null || $dob === '') {
			return null;
		}
		try {
			return (int) (new DateTime($dob))->diff(new DateTime('today'))->y;
		} catch (Exception $e) {
			return null;
		}
	}

	// ---------------------------------------------------------------- writing profiles

	/**
	 * Creates actors (terms) in a category from a list of names. Names that already exist are reported, not duplicated.
	 * @param string[] $names
	 * @return array{created: int[], existing: int[]}
	 */
	public static function create(int $categoryId, array $names): array {
		$created = [];
		$existing = [];
		$pdo = Db::pdo();
		$find = $pdo->prepare('SELECT id FROM term WHERE category_id = ? AND name = ?');
		foreach (array_slice($names, 0, 200) as $raw) {
			if (trim((string) $raw) === '') {
				continue;
			}
			$name = Taxonomy::cleanName($raw);
			$find->execute([$categoryId, $name]);
			$was = $find->fetchColumn();
			$id = Taxonomy::ensureTerm($categoryId, $name);
			if ($was === false) {
				$created[] = $id;
			} elseif (!in_array($id, $existing, true)) {
				$existing[] = $id;
			}
		}
		return ['created' => $created, 'existing' => $existing];
	}

	/**
	 * Saves a profile: name (renames the tag), full name, date of birth, rating, notes, custom field values and list
	 * membership. Only the keys present in $in are touched.
	 */
	public static function save(int $termId, array $in): array {
		if (self::profile($termId) === null) {
			throw new ApiException('That actor does not exist.', 404);
		}
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			if (array_key_exists('name', $in)) {
				Taxonomy::renameTerm($termId, $in['name']);
			}

			$cols = [];
			if (array_key_exists('full_name', $in)) {
				$v = trim((string) $in['full_name']);
				if (mb_strlen($v) > 150) {
					throw new ApiException('Full name is too long (max 150 characters).');
				}
				$cols['full_name'] = $v === '' ? null : $v;
			}
			if (array_key_exists('dob', $in)) {
				$cols['dob'] = self::cleanDob($in['dob']);
			}
			if (array_key_exists('rating', $in)) {
				$cols['rating'] = self::cleanRating($in['rating']);
			}
			if (array_key_exists('notes', $in)) {
				$v = trim((string) $in['notes']);
				if (mb_strlen($v) > 5000) {
					throw new ApiException('Notes are too long (max 5,000 characters).');
				}
				$cols['notes'] = $v === '' ? null : $v;
			}
			if ($cols) {
				self::writeProfile($termId, $cols);
			}

			if (isset($in['fields']) && is_array($in['fields'])) {
				$defined = array_column(self::fields(), 'id');
				foreach ($in['fields'] as $fieldId => $value) {
					if (!in_array((int) $fieldId, $defined, true)) {
						continue; // unknown / deleted field
					}
					$value = trim((string) $value);
					if (mb_strlen($value) > 2000) {
						throw new ApiException('A custom field value is too long (max 2,000 characters).');
					}
					self::writeFieldValue($termId, (int) $fieldId, $value);
				}
			}

			if (isset($in['lists']) && is_array($in['lists'])) {
				self::setLists($termId, array_map('intval', $in['lists']));
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $e;
		}
		return self::profile($termId);
	}

	/** Sets (1-5) or clears (null) the star rating. */
	public static function rate(int $termId, $rating): void {
		if (self::profile($termId) === null) {
			throw new ApiException('That actor does not exist.', 404);
		}
		self::writeProfile($termId, ['rating' => self::cleanRating($rating)]);
	}

	private static function cleanRating($v): ?int {
		if ($v === null || $v === '' || (int) $v === 0) {
			return null;
		}
		$n = (int) $v;
		if ($n < 1 || $n > 5) {
			throw new ApiException('A rating is 1 to 5 stars.');
		}
		return $n;
	}

	private static function cleanDob($v): ?string {
		$v = trim((string) $v);
		if ($v === '') {
			return null;
		}
		$d = DateTime::createFromFormat('!Y-m-d', $v);
		$errs = DateTime::getLastErrors();
		if ($d === false || $d->format('Y-m-d') !== $v || ($errs && ($errs['warning_count'] || $errs['error_count']))) {
			throw new ApiException('Date of birth must be a real date (YYYY-MM-DD).');
		}
		if ($d > new DateTime('today') || (int) $d->format('Y') < 1900) {
			throw new ApiException('Date of birth must be between 1900 and today.');
		}
		return $v;
	}

	/** Insert-or-update of the profile row (portable: look first). */
	private static function writeProfile(int $termId, array $cols): void {
		$pdo = Db::pdo();
		$has = $pdo->prepare('SELECT 1 FROM actor_profile WHERE term_id = ?');
		$has->execute([$termId]);
		if ($has->fetchColumn() === false) {
			$pdo->prepare('INSERT INTO actor_profile (term_id) VALUES (?)')->execute([$termId]);
		}
		foreach ($cols as $col => $value) { // $col comes from the fixed list above, never from the request
			$pdo->prepare("UPDATE actor_profile SET $col = ? WHERE term_id = ?")->execute([$value, $termId]);
		}
	}

	private static function writeFieldValue(int $termId, int $fieldId, string $value): void {
		$pdo = Db::pdo();
		$pdo->prepare('DELETE FROM actor_field_value WHERE term_id = ? AND field_id = ?')->execute([$termId, $fieldId]);
		if ($value !== '') {
			$pdo->prepare('INSERT INTO actor_field_value (term_id, field_id, value) VALUES (?, ?, ?)')->execute([$termId, $fieldId, $value]);
		}
	}

	private static function setLists(int $termId, array $listIds): void {
		$pdo = Db::pdo();
		$valid = array_column(self::lists(), 'id');
		$pdo->prepare('DELETE FROM talent_list_actor WHERE term_id = ?')->execute([$termId]);
		$ins = $pdo->prepare('INSERT INTO talent_list_actor (list_id, term_id) VALUES (?, ?)');
		foreach (array_unique($listIds) as $id) {
			if (in_array($id, $valid, true)) {
				$ins->execute([$id, $termId]);
			}
		}
	}

	/**
	 * Deletes actors: the tag is removed from every file (the files themselves are untouched) together with the profile.
	 * @param int[] $termIds
	 * @return array{deleted: int, files_untagged: int}
	 */
	public static function delete(array $termIds): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $termIds), fn($i) => $i > 0)));
		$pdo = Db::pdo();
		$deleted = 0;
		$untagged = 0;
		foreach ($ids as $id) {
			$c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM media_term WHERE term_id = t.id) FROM term t WHERE t.id = ?');
			$c->execute([$id]);
			$n = $c->fetchColumn();
			if ($n === false) {
				continue;
			}
			Taxonomy::deleteTerm($id); // removes the tag from files + calls forgetTerms() for the profile data
			$untagged += (int) $n;
			$deleted++;
		}
		return ['deleted' => $deleted, 'files_untagged' => $untagged];
	}

	/** Removes all profile data of these terms. Called whenever a term or category is deleted, so nothing is left behind. */
	public static function forgetTerms(array $termIds): void {
		$pdo = Db::pdo();
		foreach (array_chunk(array_map('intval', $termIds), self::CHUNK) as $chunk) {
			$in = Db::placeholders(count($chunk));
			$photos = $pdo->prepare("SELECT photo FROM actor_profile WHERE photo IS NOT NULL AND term_id IN ($in)");
			$photos->execute($chunk);
			foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $name) {
				self::deletePhotoFile((string) $name);
			}
			foreach (['actor_profile', 'actor_field_value', 'talent_list_actor'] as $table) {
				$pdo->prepare("DELETE FROM $table WHERE term_id IN ($in)")->execute($chunk);
			}
		}
	}

	// ---------------------------------------------------------------- photo

	private const PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
	private const PHOTO_MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
	public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * Stores an image file as the actor's photo (the old photo, if any, is deleted). The file's real content is checked
	 * (not its name): it must be a JPEG, PNG, GIF or WebP image of at most 5 MB. The stored name is generated, never taken
	 * from the client, and changes on every upload (so browsers never show a stale picture).
	 * @return string the stored file name
	 */
	public static function setPhoto(int $termId, string $path): string {
		if (self::profile($termId) === null) {
			throw new ApiException('That actor does not exist.', 404);
		}
		$size = @filesize($path);
		if ($size === false || $size <= 0) {
			throw new ApiException('That file is empty or unreadable.');
		}
		if ($size > self::PHOTO_MAX_BYTES) {
			throw new ApiException('The photo is too large (max 5 MB).');
		}
		$info = @getimagesize($path);
		$ext = $info ? (self::PHOTO_TYPES[$info[2]] ?? null) : null;
		if ($ext === null) {
			throw new ApiException('Photos must be JPG, PNG, GIF or WebP images.');
		}
		if (!is_dir(PHOTOS_DIR) && !@mkdir(PHOTOS_DIR, 0777, true) && !is_dir(PHOTOS_DIR)) {
			throw new ApiException('The photos folder cannot be created.', 500);
		}
		$name = bin2hex(random_bytes(6)) . '.' . $ext;
		if (!@copy($path, rtrim(PHOTOS_DIR, '/\\') . '/' . $name)) {
			throw new ApiException('The photo could not be saved (is the photos folder writable?).', 500);
		}
		$old = self::profile($termId)['photo'];
		self::writeProfile($termId, ['photo' => $name]);
		if ($old) {
			self::deletePhotoFile($old);
		}
		return $name;
	}

	public static function removePhoto(int $termId): void {
		$p = self::profile($termId);
		if ($p === null) {
			throw new ApiException('That actor does not exist.', 404);
		}
		if ($p['photo']) {
			self::writeProfile($termId, ['photo' => null]);
			self::deletePhotoFile($p['photo']);
		}
	}

	/** Absolute path + MIME type of an actor's photo, or null. */
	public static function photoFile(int $termId): ?array {
		$stmt = Db::pdo()->prepare('SELECT photo FROM actor_profile WHERE term_id = ?');
		$stmt->execute([$termId]);
		$name = $stmt->fetchColumn();
		if (!is_string($name) || !preg_match('/^[a-f0-9]{12}\.(jpg|png|gif|webp)$/', $name, $m)) { // only names we generated
			return null;
		}
		$path = rtrim(PHOTOS_DIR, '/\\') . '/' . $name;
		return is_file($path) ? ['path' => $path, 'mime' => self::PHOTO_MIME[$m[1]]] : null;
	}

	private static function deletePhotoFile(string $name): void {
		if (preg_match('/^[a-f0-9]{12}\.(jpg|png|gif|webp)$/', $name)) { // never delete anything else
			@unlink(rtrim(PHOTOS_DIR, '/\\') . '/' . $name);
		}
	}

	// ---------------------------------------------------------------- custom fields

	/** @return array<int, array{id:int, name:string, is_long:bool}> */
	public static function fields(): array {
		$rows = Db::pdo()->query('SELECT id, name, is_long FROM field_def ORDER BY id')->fetchAll();
		return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'is_long' => (bool) $r['is_long']], $rows);
	}

	public static function addField($name, bool $isLong): int {
		$name = Taxonomy::cleanName($name);
		$pdo = Db::pdo();
		if (count(self::fields()) >= self::MAX_FIELDS) {
			throw new ApiException('At most ' . self::MAX_FIELDS . ' custom fields.');
		}
		try {
			$pdo->prepare('INSERT INTO field_def (name, is_long) VALUES (?, ?)')->execute([$name, $isLong ? 1 : 0]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A field with that name already exists.', 409) : $e;
		}
		return (int) $pdo->lastInsertId();
	}

	public static function renameField(int $id, $name): void {
		$name = Taxonomy::cleanName($name);
		try {
			Db::pdo()->prepare('UPDATE field_def SET name = ? WHERE id = ?')->execute([$name, $id]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A field with that name already exists.', 409) : $e;
		}
	}

	/** Deletes the field and every actor's value for it. */
	public static function deleteField(int $id): void {
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$pdo->prepare('DELETE FROM actor_field_value WHERE field_id = ?')->execute([$id]);
			$pdo->prepare('DELETE FROM field_def WHERE id = ?')->execute([$id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	// ---------------------------------------------------------------- talent lists

	/** @return array<int, array{id:int, name:string, members:int}> */
	public static function lists(): array {
		$rows = Db::pdo()->query(
			'SELECT l.id, l.name, (SELECT COUNT(*) FROM talent_list_actor a WHERE a.list_id = l.id) AS members
			 FROM talent_list l ORDER BY l.name'
		)->fetchAll();
		return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'members' => (int) $r['members']], $rows);
	}

	/** @return array<int, array<int, array{id:int, name:string}>> term id => lists it is on */
	private static function listsFor(array $termIds): array {
		$out = [];
		foreach (array_chunk($termIds, self::CHUNK) as $chunk) {
			$stmt = Db::pdo()->prepare(
				'SELECT a.term_id, l.id, l.name FROM talent_list_actor a JOIN talent_list l ON l.id = a.list_id
				 WHERE a.term_id IN (' . Db::placeholders(count($chunk)) . ') ORDER BY l.name'
			);
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll() as $r) {
				$out[(int) $r['term_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
			}
		}
		return $out;
	}

	public static function addList($name): int {
		$name = Taxonomy::cleanName($name);
		$pdo = Db::pdo();
		try {
			$pdo->prepare('INSERT INTO talent_list (name) VALUES (?)')->execute([$name]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A list with that name already exists.', 409) : $e;
		}
		return (int) $pdo->lastInsertId();
	}

	public static function renameList(int $id, $name): void {
		$name = Taxonomy::cleanName($name);
		try {
			Db::pdo()->prepare('UPDATE talent_list SET name = ? WHERE id = ?')->execute([$name, $id]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A list with that name already exists.', 409) : $e;
		}
	}

	/** Deletes the list (its members are just un-listed, the actors stay). */
	public static function deleteList(int $id): void {
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$pdo->prepare('DELETE FROM talent_list_actor WHERE list_id = ?')->execute([$id]);
			$pdo->prepare('DELETE FROM talent_list WHERE id = ?')->execute([$id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/** Adds (or removes) several actors to/from a list. */
	public static function assignToList(int $listId, array $termIds, bool $add): void {
		$pdo = Db::pdo();
		$exists = $pdo->prepare('SELECT 1 FROM talent_list WHERE id = ?');
		$exists->execute([$listId]);
		if ($exists->fetchColumn() === false) {
			throw new ApiException('That list no longer exists.', 404);
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $termIds), fn($i) => $i > 0)));
		$has = $pdo->prepare('SELECT 1 FROM talent_list_actor WHERE list_id = ? AND term_id = ?');
		$term = $pdo->prepare('SELECT 1 FROM term WHERE id = ?');
		foreach ($ids as $id) {
			$has->execute([$listId, $id]);
			$member = $has->fetchColumn() !== false;
			if ($add && !$member) {
				$term->execute([$id]);
				if ($term->fetchColumn() !== false) {
					$pdo->prepare('INSERT INTO talent_list_actor (list_id, term_id) VALUES (?, ?)')->execute([$listId, $id]);
				}
			} elseif (!$add && $member) {
				$pdo->prepare('DELETE FROM talent_list_actor WHERE list_id = ? AND term_id = ?')->execute([$listId, $id]);
			}
		}
	}

	/** Term ids on a list (used to filter the library by a list). */
	public static function listMembers(int $listId): array {
		$stmt = Db::pdo()->prepare('SELECT term_id FROM talent_list_actor WHERE list_id = ? ORDER BY term_id');
		$stmt->execute([$listId]);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}
}
