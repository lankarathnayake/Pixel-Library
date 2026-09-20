<?php

/**
 * Categories ("Tags", "Actors"...), the terms inside them, and which media
 * carry which terms.
 */
class Taxonomy {

	private const NAME_MAX = 100;
	private const CHUNK = 500;

	public static function cleanName($name): string {
		$name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
		if ($name === '') {
			throw new ApiException('Name cannot be empty.');
		}
		if (mb_strlen($name) > self::NAME_MAX) {
			throw new ApiException('Name is too long (max ' . self::NAME_MAX . ' characters).');
		}
		return $name;
	}

	/** All categories, each with its terms and how many media use each term. */
	public static function tree(): array {
		$pdo = Db::pdo();
		$cats = $pdo->query('SELECT id, name FROM category ORDER BY id')->fetchAll(); // creation order
		$terms = $pdo->query(
			'SELECT t.id, t.category_id, t.name, p.photo, p.full_name, COUNT(mt.media_id) AS cnt
			 FROM term t LEFT JOIN media_term mt ON mt.term_id = t.id LEFT JOIN actor_profile p ON p.term_id = t.id
			 GROUP BY t.id, t.category_id, t.name, p.photo, p.full_name
			 ORDER BY t.name'
		)->fetchAll();

		$byCat = [];
		foreach ($terms as $t) {
			$byCat[(int) $t['category_id']][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'count' => (int) $t['cnt'], 'photo' => $t['photo'], 'full_name' => $t['full_name']];
		}
		$out = [];
		foreach ($cats as $c) {
			$out[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'terms' => $byCat[(int) $c['id']] ?? []];
		}
		return $out;
	}

	// ---- categories ----

	public static function addCategory($name): int {
		$name = self::cleanName($name);
		$pdo = Db::pdo();
		try {
			$pdo->prepare('INSERT INTO category (name) VALUES (?)')->execute([$name]);
		} catch (PDOException $e) {
			if (Db::isDuplicate($e)) {
				throw new ApiException('A category with that name already exists.', 409);
			}
			throw $e;
		}
		return (int) $pdo->lastInsertId();
	}

	public static function renameCategory(int $id, $name): void {
		$name = self::cleanName($name);
		try {
			Db::pdo()->prepare('UPDATE category SET name = ? WHERE id = ?')->execute([$name, $id]);
		} catch (PDOException $e) {
			if (Db::isDuplicate($e)) {
				throw new ApiException('A category with that name already exists.', 409);
			}
			throw $e;
		}
	}

	/** Deletes the category, its terms, and those terms' links to media (media files themselves are untouched). */
	public static function deleteCategory(int $id): void {
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$ids = $pdo->prepare('SELECT id FROM term WHERE category_id = ?');
			$ids->execute([$id]);
			Actors::forgetTerms($ids->fetchAll(PDO::FETCH_COLUMN));
			$pdo->prepare('DELETE FROM media_term WHERE term_id IN (SELECT id FROM term WHERE category_id = ?)')->execute([$id]);
			$pdo->prepare('DELETE FROM term WHERE category_id = ?')->execute([$id]);
			$pdo->prepare('DELETE FROM category WHERE id = ?')->execute([$id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	// ---- terms ----

	/** Id of the term with this name in the category, creating it if needed. */
	public static function ensureTerm(int $categoryId, $name): int {
		$name = self::cleanName($name);
		$pdo = Db::pdo();
		$find = $pdo->prepare('SELECT id FROM term WHERE category_id = ? AND name = ?');
		$find->execute([$categoryId, $name]);
		$id = $find->fetchColumn();
		if ($id !== false) {
			return (int) $id;
		}
		$exists = $pdo->prepare('SELECT 1 FROM category WHERE id = ?');
		$exists->execute([$categoryId]);
		if ($exists->fetchColumn() === false) {
			throw new ApiException('That category no longer exists.', 404);
		}
		try {
			$pdo->prepare('INSERT INTO term (category_id, name) VALUES (?, ?)')->execute([$categoryId, $name]);
			return (int) $pdo->lastInsertId();
		} catch (PDOException $e) {
			if (!Db::isDuplicate($e)) {
				throw $e;
			}
			$find->execute([$categoryId, $name]); // created by a concurrent request
			return (int) $find->fetchColumn();
		}
	}

	public static function renameTerm(int $id, $name): void {
		$name = self::cleanName($name);
		try {
			Db::pdo()->prepare('UPDATE term SET name = ? WHERE id = ?')->execute([$name, $id]);
		} catch (PDOException $e) {
			if (Db::isDuplicate($e)) {
				throw new ApiException('That category already has a term with that name.', 409);
			}
			throw $e;
		}
	}

	public static function deleteTerm(int $id): void {
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$pdo->prepare('DELETE FROM media_term WHERE term_id = ?')->execute([$id]);
			Actors::forgetTerms([$id]); // profile, custom field values, list membership
			$pdo->prepare('DELETE FROM term WHERE id = ?')->execute([$id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	// ---- media <-> term links ----

	/** Apply these terms to these media (already-linked pairs are left alone). */
	public static function assign(array $mediaIds, array $termIds): void {
		$mediaIds = self::existing('media', $mediaIds);
		$termIds = self::existing('term', $termIds);
		if (!$mediaIds || !$termIds) {
			return;
		}
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$ins = $pdo->prepare('INSERT INTO media_term (media_id, term_id) VALUES (?, ?)');
			foreach ($termIds as $termId) {
				foreach (array_chunk($mediaIds, self::CHUNK) as $chunk) {
					$have = $pdo->prepare('SELECT media_id FROM media_term WHERE term_id = ? AND media_id IN (' . Db::placeholders(count($chunk)) . ')');
					$have->execute(array_merge([$termId], $chunk));
					$already = array_flip(array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN)));
					foreach ($chunk as $mediaId) {
						if (!isset($already[$mediaId])) {
							$ins->execute([$mediaId, $termId]);
						}
					}
				}
			}
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Same as assign(), but terms are given by name per category and created on the fly.
	 * @param array $named category id => list of term names
	 */
	public static function assignNamed(array $mediaIds, array $named): void {
		$termIds = [];
		foreach ($named as $categoryId => $names) {
			foreach ((array) $names as $name) {
				if (trim((string) $name) === '') {
					continue;
				}
				$termIds[] = self::ensureTerm((int) $categoryId, $name);
			}
		}
		self::assign($mediaIds, array_values(array_unique($termIds)));
	}

	public static function unassign(array $mediaIds, array $termIds): void {
		$mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));
		$termIds = array_values(array_unique(array_map('intval', $termIds)));
		if (!$mediaIds || !$termIds) {
			return;
		}
		$pdo = Db::pdo();
		foreach (array_chunk($mediaIds, self::CHUNK) as $chunk) {
			$pdo->prepare(
				'DELETE FROM media_term WHERE term_id IN (' . Db::placeholders(count($termIds)) . ')
				 AND media_id IN (' . Db::placeholders(count($chunk)) . ')'
			)->execute(array_merge($termIds, $chunk));
		}
	}

	/**
	 * @param int[] $mediaIds
	 * @return array media id => list of ['id', 'name', 'category_id']
	 */
	public static function forMedia(array $mediaIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_map('intval', $mediaIds)), self::CHUNK) as $chunk) {
			$stmt = Db::pdo()->prepare(
				'SELECT mt.media_id, t.id, t.name, t.category_id
				 FROM media_term mt JOIN term t ON t.id = mt.term_id
				 WHERE mt.media_id IN (' . Db::placeholders(count($chunk)) . ')
				 ORDER BY t.name'
			);
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll() as $r) {
				$out[(int) $r['media_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'category_id' => (int) $r['category_id']];
			}
		}
		return $out;
	}

	/** Keeps only ids that exist in $table ('media' or 'term'). */
	private static function existing(string $table, array $ids): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		$found = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$stmt = Db::pdo()->prepare("SELECT id FROM $table WHERE id IN (" . Db::placeholders(count($chunk)) . ')');
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
				$found[] = (int) $id;
			}
		}
		return $found;
	}
}
