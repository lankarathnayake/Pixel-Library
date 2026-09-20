<?php

/** The registered media: adding files by location, searching, removing, checking they still exist. */
class Library {

	private const CHUNK = 500;

	private const SORTS = [
		'added_desc' => 'm.added_at DESC, m.id DESC',
		'added_asc'  => 'm.added_at ASC, m.id ASC',
		'name_asc'   => 'm.name ASC, m.id ASC',
		'name_desc'  => 'm.name DESC, m.id DESC',
		'size_desc'  => 'm.size DESC, m.id DESC',
		'size_asc'   => 'm.size ASC, m.id ASC',
		'duration_desc' => 'm.duration DESC, m.id DESC',
		'duration_asc' => '(m.duration IS NULL) ASC, m.duration ASC, m.id ASC',
		'mtime_desc' => 'm.file_mtime DESC, m.id DESC',
	];

	/**
	 * Registers files (by path) and everything supported inside folders.
	 * Nothing is copied: only the location + metadata is stored. Files that are
	 * already registered are left as they are (and un-flagged if they had gone missing).
	 *
	 * @param string[] $files
	 * @param string[] $folders
	 * @param array $named category id => term names to apply to every file touched
	 * @return array{added:int, existing:int, skipped:array, truncated:bool}
	 */
	public static function add(array $files, array $folders, bool $recursive, array $named = []): array {
		@set_time_limit(0);
		$skipped = [];
		$truncated = false;
		$paths = []; // canonical path => true

		foreach ($files as $p) {
			$real = Paths::resolve((string) $p);
			if ($real === null || !is_file($real)) {
				$skipped[] = ['path' => (string) $p, 'reason' => 'File not found'];
			} elseif (!Paths::allowed($real)) {
				$skipped[] = ['path' => (string) $p, 'reason' => 'Outside the allowed folders'];
			} elseif (MediaTypes::forPath($real) === null) {
				$skipped[] = ['path' => (string) $p, 'reason' => 'Unsupported file type'];
			} else {
				$paths[$real] = true;
			}
		}

		foreach ($folders as $f) {
			$real = Paths::resolve((string) $f);
			if ($real === null || !is_dir($real)) {
				$skipped[] = ['path' => (string) $f, 'reason' => 'Folder not found'];
				continue;
			}
			if (!Paths::allowed($real)) {
				$skipped[] = ['path' => (string) $f, 'reason' => 'Outside the allowed folders'];
				continue;
			}
			$room = MAX_SCAN_FILES - count($paths);
			if ($room <= 0) {
				$truncated = true;
				break;
			}
			$cut = false;
			foreach (Scanner::files($real, $recursive, $room, $cut) as $p) {
				$paths[$p] = true;
			}
			$truncated = $truncated || $cut;
		}

		$pdo = Db::pdo();
		$find = $pdo->prepare('SELECT id, size, type FROM media WHERE path_hash = ?');
		$insert = $pdo->prepare(
			'INSERT INTO media (path, path_hash, name, ext, type, size, file_mtime, width, height)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$refresh = $pdo->prepare('UPDATE media SET is_missing = 0, size = ?, file_mtime = ? WHERE id = ?');

		$added = 0;
		$existing = 0;
		$touched = [];
		$n = 0;
		$pdo->beginTransaction();
		try {
			foreach (array_keys($paths) as $real) {
				$kind = MediaTypes::forPath($real);
				$size = (int) @filesize($real);
				$mtime = @filemtime($real);
				$mtime = $mtime ? date('Y-m-d H:i:s', $mtime) : null;

				$find->execute([Paths::hash($real)]);
				$known = $find->fetch();
				if ($known !== false) {
					$id = (int) $known['id'];
					$existing++;
					$refresh->execute([$size, $mtime, $id]);
					if ((int) $known['size'] !== $size) { // the file changed: what was worked out from its old content is stale
						$pdo->prepare('UPDATE media SET content_hash = NULL WHERE id = ?')->execute([$id]);
						if ($known['type'] === 'video') {
							VideoThumbs::retryOne($id);
						}
					}
					$touched[] = $id;
				} else {
					$w = $h = null;
					if ($kind['type'] === 'image' && ($dim = @getimagesize($real))) {
						$w = (int) $dim[0];
						$h = (int) $dim[1];
					}
					$insert->execute([$real, Paths::hash($real), basename($real), $kind['ext'], $kind['type'], $size, $mtime, $w, $h]);
					$added++;
					$touched[] = (int) $pdo->lastInsertId();
				}

				if (++$n % self::CHUNK === 0) { // keep transactions small on big folders
					$pdo->commit();
					$pdo->beginTransaction();
				}
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ($named && $touched) {
			Taxonomy::assignNamed($touched, $named);
		}
		return ['added' => $added, 'existing' => $existing, 'skipped' => $skipped, 'truncated' => $truncated];
	}

	/**
	 * @param array $f filters: q, type, terms (term ids), untagged, missing, sort
	 * @return array{items: array, total: int, has_more: bool}
	 */
	public static function search(array $f, int $page = 1, int $perPage = 60, ?int $offset = null): array {
		$pdo = Db::pdo();
		$where = [];
		$args = [];

		foreach (preg_split('/\s+/', trim((string) ($f['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$where[] = "m.path LIKE ? ESCAPE '!'";
			$args[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
		}

		if (in_array($f['type'] ?? '', ['image', 'video'], true)) {
			$where[] = 'm.type = ?';
			$args[] = $f['type'];
		}
		if (!empty($f['missing'])) {
			$where[] = 'm.is_missing = 1';
		}
		// video length in seconds: dmin <= length < dmax (videos whose length is not known yet never match)
		if (isset($f['dmin']) && $f['dmin'] !== '' && is_numeric($f['dmin'])) {
			$where[] = 'm.duration >= ?';
			$args[] = (float) $f['dmin'];
		}
		if (isset($f['dmax']) && $f['dmax'] !== '' && is_numeric($f['dmax'])) {
			$where[] = 'm.duration < ?';
			$args[] = (float) $f['dmax'];
		}
		if (!empty($f['nothumbs'])) { // videos that have no preview frames (pending, failed or never generated)
			$where[] = "m.type = 'video'";
			$where[] = 'NOT EXISTS (SELECT 1 FROM media_thumb p WHERE p.media_id = m.id)';
		}
		if (!empty($f['untagged'])) {
			$where[] = 'NOT EXISTS (SELECT 1 FROM media_term x WHERE x.media_id = m.id)';
		}

		// Faceted filter: OR within a category, AND across categories.
		$termIds = array_values(array_unique(array_filter(array_map('intval', (array) ($f['terms'] ?? [])), fn($i) => $i > 0)));
		if ($termIds) {
			$stmt = $pdo->prepare('SELECT id, category_id FROM term WHERE id IN (' . Db::placeholders(count($termIds)) . ')');
			$stmt->execute($termIds);
			$byCat = [];
			foreach ($stmt->fetchAll() as $t) {
				$byCat[(int) $t['category_id']][] = (int) $t['id'];
			}
			if (!$byCat) {
				$where[] = '1 = 0'; // filter references terms that no longer exist
			}
			foreach ($byCat as $ids) {
				$where[] = 'EXISTS (SELECT 1 FROM media_term x WHERE x.media_id = m.id AND x.term_id IN (' . Db::placeholders(count($ids)) . '))';
				array_push($args, ...$ids);
			}
		}

		$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$order = self::SORTS[$f['sort'] ?? ''] ?? self::SORTS['added_desc'];
		$page = max(1, $page);
		$perPage = max(1, min(200, $perPage));
		// An explicit $offset (any row, not just a page boundary) lets the UI load a sliding window of the list.
		$offset = $offset !== null ? max(0, $offset) : ($page - 1) * $perPage;

		$count = $pdo->prepare("SELECT COUNT(*) FROM media m $whereSql");
		$count->execute($args);
		$total = (int) $count->fetchColumn();

		$stmt = $pdo->prepare(
			'SELECT ' . self::LIST_COLUMNS . " FROM media m $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset"
		);
		$stmt->execute($args);
		$items = self::listItems($stmt->fetchAll());

		return ['items' => $items, 'total' => $total, 'has_more' => $offset + count($items) < $total];
	}

	/** The same item shape as search(), for specific ids (used to refresh tiles in place). */
	public static function byIds(array $ids): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		if (!$ids) {
			return [];
		}
		$stmt = Db::pdo()->prepare('SELECT ' . self::LIST_COLUMNS . ' FROM media m WHERE m.id IN (' . Db::placeholders(count($ids)) . ')');
		$stmt->execute($ids);
		return self::listItems($stmt->fetchAll());
	}

	private const LIST_COLUMNS = 'm.id, m.name, m.type, m.ext, m.size, m.width, m.height, m.duration, m.is_missing, m.thumb_status';

	/** Rows -> API items. Videos also carry `thumbs`: the time (seconds) of each preview frame, in order. */
	private static function listItems(array $rows): array {
		$videoIds = [];
		foreach ($rows as $r) {
			if ($r['type'] === 'video') {
				$videoIds[] = (int) $r['id'];
			}
		}
		$times = $videoIds ? VideoThumbs::timesFor($videoIds) : [];
		return array_map(function ($r) use ($times) {
			$item = [
				'id' => (int) $r['id'],
				'name' => $r['name'],
				'type' => $r['type'],
				'ext' => $r['ext'],
				'size' => (int) $r['size'],
				'width' => $r['width'] === null ? null : (int) $r['width'],
				'height' => $r['height'] === null ? null : (int) $r['height'],
				'is_missing' => (bool) $r['is_missing'],
			];
			if ($r['type'] === 'video') {
				$item['duration'] = $r['duration'] === null ? null : (float) $r['duration'];
				$item['thumb_status'] = (int) $r['thumb_status'];
				$item['thumbs'] = $times[(int) $r['id']] ?? [];
			}
			return $item;
		}, $rows);
	}

	/** One media row (all columns, incl. path) or null. */
	public static function find(int $id): ?array {
		$stmt = Db::pdo()->prepare('SELECT * FROM media WHERE id = ?');
		$stmt->execute([$id]);
		$row = $stmt->fetch();
		return $row === false ? null : $row;
	}

	/** Detail for the viewer: metadata + folder + terms. */
	public static function detail(int $id): ?array {
		$r = self::find($id);
		if ($r === null) {
			return null;
		}
		return [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'path' => $r['path'],
			'folder' => dirname($r['path']),
			'type' => $r['type'],
			'ext' => $r['ext'],
			'size' => (int) $r['size'],
			'width' => $r['width'] === null ? null : (int) $r['width'],
			'height' => $r['height'] === null ? null : (int) $r['height'],
			'duration' => $r['duration'] === null ? null : (float) $r['duration'],
			'is_missing' => (bool) $r['is_missing'],
			'added_at' => $r['added_at'],
			'file_mtime' => $r['file_mtime'],
			'terms' => Taxonomy::forMedia([$id])[$id] ?? [],
		];
	}

	public static function markMissing(int $id): void {
		Db::pdo()->prepare('UPDATE media SET is_missing = 1 WHERE id = ?')->execute([$id]);
	}

	/**
	 * PERMANENTLY deletes files from disk (no Recycle Bin), then removes their library entries, tags and
	 * preview thumbnails. Only files registered in the library can be deleted, and each one is re-verified first:
	 * it must still be exactly where it was registered (a folder swapped for a link/junction is refused), be a
	 * regular file of a supported type, and be inside the allowed folders. If the file cannot be deleted
	 * (in use, read-only, no permission) nothing about it is changed. A file that is already gone just has its
	 * entry cleaned up.
	 *
	 * @param int[] $ids
	 * @return array{deleted:int, already_gone:int, failed:array<int, array{id:int, name:string, reason:string}>, bytes:int}
	 */
	public static function deleteFiles(array $ids): array {
		@set_time_limit(0);
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		$res = ['deleted' => 0, 'already_gone' => 0, 'failed' => [], 'bytes' => 0];
		$cleanup = []; // ids whose entries should now go

		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$stmt = Db::pdo()->prepare('SELECT id, path, path_hash, name FROM media WHERE id IN (' . Db::placeholders(count($chunk)) . ')');
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll() as $row) {
				$id = (int) $row['id'];
				$path = $row['path'];
				$fail = function (string $why) use (&$res, $id, $row) {
					$res['failed'][] = ['id' => $id, 'name' => $row['name'], 'reason' => $why];
				};

				if (!file_exists($path) && !is_link($path)) { // gone already: only the entry is left to clean up
					$res['already_gone']++;
					$cleanup[] = $id;
					continue;
				}
				$real = Paths::resolve($path);
				if ($real === null || Paths::hash($real) !== $row['path_hash']) {
					$fail('It is no longer at the location it was added from (moved, or a folder was replaced by a link).');
					continue;
				}
				if (!is_file($real) || MediaTypes::forPath($real) === null) {
					$fail('Not a regular media file.');
					continue;
				}
				if (!Paths::allowed($real)) {
					$fail('Outside the allowed folders.');
					continue;
				}

				$size = (int) @filesize($real);
				$deleted = false;
				for ($try = 0; $try < 3 && !$deleted; $try++) { // a just-closed video stream may still hold the file for a moment
					if ($try > 0) {
						usleep(250000);
					}
					$deleted = @unlink($real);
				}
				if (!$deleted) {
					$fail('Could not be deleted - it is in use by another program, read-only, or you lack permission.');
					continue;
				}
				$res['deleted']++;
				$res['bytes'] += $size;
				$cleanup[] = $id;
				self::logDeletion($id, $size, $real);
			}
		}

		if ($cleanup) {
			self::remove($cleanup); // entries, tags, and both kinds of preview thumbnails
		}
		return $res;
	}

	private static function logDeletion(int $id, int $size, string $path): void {
		@file_put_contents(DELETE_LOG, date('Y-m-d H:i:s') . "\t#" . $id . "\t" . $size . "\t" . $path . "\n", FILE_APPEND | LOCK_EX);
	}

	/** Removes files from the library. The files on disk are never touched. */
	public static function remove(array $ids): int {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		$pdo = Db::pdo();
		$removed = 0;
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$in = Db::placeholders(count($chunk));
			$pdo->beginTransaction();
			try {
				$pdo->prepare("DELETE FROM media_term WHERE media_id IN ($in)")->execute($chunk);
				$pdo->prepare("DELETE FROM media_thumb WHERE media_id IN ($in)")->execute($chunk);
				$pdo->prepare("DELETE FROM playlist_item WHERE media_id IN ($in)")->execute($chunk);
				$del = $pdo->prepare("DELETE FROM media WHERE id IN ($in)");
				$del->execute($chunk);
				$removed += $del->rowCount();
				$pdo->commit();
			} catch (Throwable $e) {
				$pdo->rollBack();
				throw $e;
			}
		}
		foreach ($ids as $id) {
			Thumbnailer::forget($id);
			VideoThumbs::forgetFiles($id);
		}
		return $removed;
	}

	/** Re-checks every registered file against the disk and updates the "missing" flag. */
	public static function checkMissing(): array {
		@set_time_limit(0);
		$pdo = Db::pdo();
		$page = $pdo->prepare('SELECT id, path, is_missing FROM media WHERE id > ? ORDER BY id LIMIT ' . self::CHUNK);
		$last = 0;
		$checked = 0;
		$nowMissing = [];
		$restored = [];
		while (true) {
			$page->execute([$last]);
			$rows = $page->fetchAll();
			if (!$rows) {
				break;
			}
			foreach ($rows as $r) {
				$last = (int) $r['id'];
				$checked++;
				$exists = is_file($r['path']);
				if (!$exists && !$r['is_missing']) {
					$nowMissing[] = $last;
				} elseif ($exists && $r['is_missing']) {
					$restored[] = $last;
				}
			}
		}
		foreach ([[1, $nowMissing], [0, $restored]] as [$flag, $ids]) {
			foreach (array_chunk($ids, self::CHUNK) as $chunk) {
				$pdo->prepare('UPDATE media SET is_missing = ' . $flag . ' WHERE id IN (' . Db::placeholders(count($chunk)) . ')')->execute($chunk);
			}
		}
		$total = (int) $pdo->query('SELECT COUNT(*) FROM media WHERE is_missing = 1')->fetchColumn();
		return ['checked' => $checked, 'newly_missing' => count($nowMissing), 'restored' => count($restored), 'missing' => $total];
	}
}
