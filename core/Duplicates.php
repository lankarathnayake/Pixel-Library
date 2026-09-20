<?php

/**
 * Duplicate finder.
 *  - "Identical": files with the same size AND the same content hash. The hash covers the size plus 1 MB from the start,
 *    middle and end (whole file if it is small), so it is fast even for huge videos and practically never wrong.
 *    Only files that share a size with another file are ever hashed (nothing else can be a duplicate).
 *  - "Same length": videos with the same duration (to the second) and resolution but not identical content: likely the same
 *    video in another quality/format. Only a hint: nothing is deleted without you choosing.
 */
class Duplicates {

	private const SAMPLE = 1048576; // 1 MB

	/** Hash of size + sampled bytes, or null if the file can't be read. */
	public static function hashFile(string $path, int $size): ?string {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return null;
		}
		$ctx = hash_init('sha1');
		hash_update($ctx, (string) $size);
		if ($size <= 3 * self::SAMPLE) {
			while (!feof($fh)) {
				$b = fread($fh, self::SAMPLE);
				if ($b === false) {
					fclose($fh);
					return null;
				}
				hash_update($ctx, $b);
			}
		} else {
			foreach ([0, intdiv($size, 2) - intdiv(self::SAMPLE, 2), $size - self::SAMPLE] as $offset) {
				if (fseek($fh, $offset) !== 0 || ($b = fread($fh, self::SAMPLE)) === false || $b === '') {
					fclose($fh);
					return null;
				}
				hash_update($ctx, $b);
			}
		}
		fclose($fh);
		return hash_final($ctx);
	}

	private const SHARES_SIZE = 'm.is_missing = 0 AND m.size > 0 AND EXISTS (SELECT 1 FROM media o WHERE o.size = m.size AND o.id <> m.id AND o.is_missing = 0)';

	/** @return array{candidates:int, unhashed:int, identical_groups:int, wasted:int} */
	public static function status(): array {
		$pdo = Db::pdo();
		$cand = (int) $pdo->query('SELECT COUNT(*) FROM media m WHERE ' . self::SHARES_SIZE)->fetchColumn();
		$un = (int) $pdo->query('SELECT COUNT(*) FROM media m WHERE m.content_hash IS NULL AND ' . self::SHARES_SIZE)->fetchColumn();
		$g = $pdo->query(
			'SELECT COUNT(*) AS n, COALESCE(SUM((c - 1) * s), 0) AS wasted FROM
			 (SELECT COUNT(*) AS c, MAX(size) AS s FROM media WHERE content_hash IS NOT NULL AND is_missing = 0 GROUP BY content_hash HAVING COUNT(*) > 1) g'
		)->fetch();
		return ['candidates' => $cand, 'unhashed' => $un, 'identical_groups' => (int) $g['n'], 'wasted' => (int) $g['wasted']];
	}

	/**
	 * Hashes files that share a size with another file, for up to $seconds (checked between files).
	 * @return array{hashed:int, remaining:int}
	 */
	public static function scan(int $seconds): array {
		@set_time_limit(0);
		$pdo = Db::pdo();
		$start = time();
		$hashed = 0;
		$pick = $pdo->prepare('SELECT m.id, m.path, m.size FROM media m WHERE m.content_hash IS NULL AND ' . self::SHARES_SIZE . ' ORDER BY m.size DESC, m.id LIMIT 25');
		$set = $pdo->prepare('UPDATE media SET content_hash = ? WHERE id = ?');
		while (time() - $start < $seconds) {
			$pick->execute();
			$rows = $pick->fetchAll();
			if (!$rows) {
				break;
			}
			foreach ($rows as $r) {
				if (!is_file($r['path'])) {
					Library::markMissing((int) $r['id']);
					continue;
				}
				// unreadable file: a unique marker, so it is never grouped and never retried forever
				$hash = self::hashFile($r['path'], (int) $r['size']) ?? sha1('unreadable-' . $r['id']);
				$set->execute([$hash, $r['id']]);
				$hashed++;
				if (time() - $start >= $seconds) {
					break 2;
				}
			}
		}
		return ['hashed' => $hashed, 'remaining' => self::status()['unhashed']];
	}

	/**
	 * @param string $mode 'identical' | 'samelength'
	 * @return array{groups: array, total: int, wasted: int}
	 */
	public static function groups(string $mode, int $offset = 0, int $limit = 30): array {
		$pdo = Db::pdo();
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);

		if ($mode === 'samelength') {
			$keys = $pdo->query(
				"SELECT ROUND(duration) AS d, width, height FROM media
				 WHERE type = 'video' AND duration IS NOT NULL AND is_missing = 0 AND width IS NOT NULL
				 GROUP BY ROUND(duration), width, height HAVING COUNT(*) > 1 ORDER BY ROUND(duration) DESC"
			)->fetchAll();
			$members = $pdo->prepare("SELECT id FROM media WHERE type = 'video' AND is_missing = 0 AND ROUND(duration) = ROUND(?) AND width = ? AND height = ? ORDER BY id"); // ROUND(?): a bound value is text, and SQLite compares text to an expression as text
			$groups = [];
			foreach ($keys as $k) {
				$members->execute([$k['d'], $k['width'], $k['height']]);
				$ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
				$hashes = array_column(self::rows($ids), 'content_hash');
				if (count($ids) > 1 && !(count(array_unique($hashes)) === 1 && !in_array(null, $hashes, true))) { // all-identical groups belong to the other tab
					$groups[] = ['key' => 'len:' . $k['d'] . ':' . $k['width'] . 'x' . $k['height'], 'ids' => $ids];
				}
			}
			$total = count($groups);
			$slice = array_slice($groups, $offset, $limit);
			$wasted = 0;
		} else {
			$total = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM media WHERE content_hash IS NOT NULL AND is_missing = 0 GROUP BY content_hash HAVING COUNT(*) > 1) g')->fetchColumn();
			$wasted = self::status()['wasted'];
			$hashes = $pdo->query(
				"SELECT content_hash FROM media WHERE content_hash IS NOT NULL AND is_missing = 0
				 GROUP BY content_hash HAVING COUNT(*) > 1 ORDER BY MAX(size) * COUNT(*) DESC, content_hash LIMIT $limit OFFSET $offset"
			)->fetchAll(PDO::FETCH_COLUMN);
			$slice = [];
			$byHash = $pdo->prepare('SELECT id FROM media WHERE content_hash = ? AND is_missing = 0 ORDER BY id');
			foreach ($hashes as $h) {
				$byHash->execute([$h]);
				$slice[] = ['key' => 'h:' . $h, 'ids' => array_map('intval', $byHash->fetchAll(PDO::FETCH_COLUMN))];
			}
		}

		$out = [];
		foreach ($slice as $g) {
			$files = self::rows($g['ids']);
			usort($files, fn($a, $b) => [$b['width'] * $b['height'], $b['size'], $a['id']] <=> [$a['width'] * $a['height'], $a['size'], $b['id']]);
			$keep = $files[0]['id']; // best resolution, then biggest, then the oldest entry
			$out[] = [
				'key' => $g['key'],
				'keep' => $keep,
				'files' => $files,
				'wasted' => array_sum(array_column($files, 'size')) - $files[0]['size'],
			];
		}
		return ['groups' => $out, 'total' => $total, 'wasted' => $wasted];
	}

	/** Full info for a set of media ids (for the cards). */
	private static function rows(array $ids): array {
		if (!$ids) {
			return [];
		}
		$stmt = Db::pdo()->prepare(
			'SELECT m.id, m.name, m.path, m.type, m.size, m.duration, m.width, m.height, m.added_at, m.file_mtime, m.content_hash,
			        (SELECT COUNT(*) FROM media_term x WHERE x.media_id = m.id) AS tags,
			        (SELECT COUNT(*) FROM playlist_item p WHERE p.media_id = m.id) AS playlists
			 FROM media m WHERE m.id IN (' . Db::placeholders(count($ids)) . ')'
		);
		$stmt->execute($ids);
		$frames = VideoThumbs::timesFor($ids);
		return array_map(function ($r) use ($frames) {
			$n = count($frames[(int) $r['id']] ?? []);
			return [
				'id' => (int) $r['id'], 'name' => $r['name'], 'path' => $r['path'], 'folder' => dirname($r['path']), 'type' => $r['type'],
				'size' => (int) $r['size'], 'duration' => $r['duration'] === null ? null : (float) $r['duration'],
				'width' => (int) $r['width'], 'height' => (int) $r['height'], 'added_at' => $r['added_at'], 'file_mtime' => $r['file_mtime'],
				'content_hash' => $r['content_hash'], 'tags' => (int) $r['tags'], 'playlists' => (int) $r['playlists'],
				'cover' => $r['type'] === 'video' ? ($n ? (int) floor($n * 0.3) : null) : null,
			];
		}, $stmt->fetchAll());
	}

	/** Is $other really a duplicate of $keep (identical content, or the same video length + resolution)? */
	private static function isDuplicate(array $keep, array $other): bool {
		if ($keep['content_hash'] !== null && $keep['content_hash'] === $other['content_hash']) {
			return true;
		}
		return $keep['type'] === 'video' && $other['type'] === 'video' && $keep['duration'] !== null && $other['duration'] !== null
			&& round($keep['duration']) === round($other['duration']) && $keep['width'] === $other['width'] && $keep['height'] === $other['height'];
	}

	/**
	 * Keeps one file of a group and deals with the others: 'delete' = PERMANENTLY delete them from disk, 'remove' = only take
	 * them out of the library. Tags and playlist places of the others move to the kept file first (unless disabled).
	 * Only genuine duplicates of the kept file are touched.
	 * @return array{action:string, handled:int, skipped:int[], result:array}
	 */
	public static function resolve(int $keepId, array $otherIds, string $action, bool $mergeTags = true): array {
		if (!in_array($action, ['delete', 'remove'], true)) {
			throw new ApiException('Unknown action.');
		}
		$rows = self::rows(array_values(array_unique(array_merge([$keepId], array_map('intval', $otherIds)))));
		$byId = array_column($rows, null, 'id');
		if (!isset($byId[$keepId])) {
			throw new ApiException('The file to keep is not in the library.', 404);
		}
		$ok = [];
		$skipped = [];
		foreach (array_unique(array_map('intval', $otherIds)) as $id) {
			if ($id !== $keepId && isset($byId[$id]) && self::isDuplicate($byId[$keepId], $byId[$id])) {
				$ok[] = $id;
			} else {
				$skipped[] = $id;
			}
		}
		if (!$ok) {
			throw new ApiException('None of those files is a duplicate of the file you keep.');
		}

		$pdo = Db::pdo();
		if ($mergeTags) {
			$stmt = $pdo->prepare('SELECT DISTINCT term_id FROM media_term WHERE media_id IN (' . Db::placeholders(count($ok)) . ')');
			$stmt->execute($ok);
			Taxonomy::assign([$keepId], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
		}
		Playlists::replaceMedia($ok, $keepId); // keep their places in playlists

		$result = $action === 'delete' ? Library::deleteFiles($ok) : ['removed' => Library::remove($ok)];
		return ['action' => $action, 'handled' => count($ok), 'skipped' => $skipped, 'result' => $result];
	}
}
