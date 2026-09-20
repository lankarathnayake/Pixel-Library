<?php

/**
 * Folders you added to the library, remembered so they can be rescanned for NEW files later.
 * Rescanning only ever adds files (via Library::add); it never removes or changes anything already registered.
 */
class Folders {

	/** Remembers a folder (or updates its "include sub-folders" flag). Returns its id. */
	public static function register(string $path, bool $recursive = true): int {
		$real = Paths::resolve($path);
		if ($real === null || !is_dir($real)) {
			throw new ApiException('That folder does not exist.', 404);
		}
		if (!Paths::allowed($real)) {
			throw new ApiException('That folder is outside the allowed locations.', 403);
		}
		$pdo = Db::pdo();
		$find = $pdo->prepare('SELECT id FROM library_folder WHERE path_hash = ?');
		$find->execute([Paths::hash($real)]);
		$id = $find->fetchColumn();
		if ($id !== false) {
			$pdo->prepare('UPDATE library_folder SET is_recursive = ? WHERE id = ?')->execute([$recursive ? 1 : 0, $id]);
			return (int) $id;
		}
		$pdo->prepare('INSERT INTO library_folder (path, path_hash, is_recursive) VALUES (?, ?, ?)')->execute([$real, Paths::hash($real), $recursive ? 1 : 0]);
		return (int) $pdo->lastInsertId();
	}

	/** Called after the "Add files" picker: remembers every folder that was added. Problems are ignored (adding already worked). */
	public static function registerMany(array $folders, bool $recursive): void {
		foreach ($folders as $f) {
			try {
				self::register((string) $f, $recursive);
			} catch (ApiException $e) {
				// not a usable folder: nothing to remember
			}
		}
	}

	/** @return array<int, array> the remembered folders, each with how many library files are inside it */
	public static function all(): array {
		$pdo = Db::pdo();
		$rows = $pdo->query('SELECT id, path, is_recursive, added_at, last_scan, last_found FROM library_folder ORDER BY path')->fetchAll();
		$count = $pdo->prepare('SELECT COUNT(*) FROM media WHERE path LIKE ? ESCAPE \'!\'');
		$countFlat = $pdo->prepare('SELECT COUNT(*) FROM media WHERE path LIKE ? ESCAPE \'!\' AND path NOT LIKE ? ESCAPE \'!\'');
		$out = [];
		foreach ($rows as $r) {
			$prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], rtrim($r['path'], '\\/') . DIRECTORY_SEPARATOR);
			if ($r['is_recursive']) {
				$count->execute([$prefix . '%']);
				$files = (int) $count->fetchColumn();
			} else {
				$countFlat->execute([$prefix . '%', $prefix . '%' . DIRECTORY_SEPARATOR . '%']); // direct children only
				$files = (int) $countFlat->fetchColumn();
			}
			$out[] = [
				'id' => (int) $r['id'],
				'path' => $r['path'],
				'recursive' => (bool) $r['is_recursive'],
				'files' => $files,
				'exists' => is_dir($r['path']),
				'last_scan' => $r['last_scan'],
				'last_found' => $r['last_found'] === null ? null : (int) $r['last_found'],
			];
		}
		return $out;
	}

	/** Looks in one folder for files that are not in the library yet and adds them. */
	public static function scan(int $id): array {
		$pdo = Db::pdo();
		$stmt = $pdo->prepare('SELECT id, path, is_recursive FROM library_folder WHERE id = ?');
		$stmt->execute([$id]);
		$row = $stmt->fetch();
		if ($row === false) {
			throw new ApiException('That folder is not remembered any more.', 404);
		}
		$now = date('Y-m-d H:i:s');
		$real = Paths::resolve($row['path']);
		if ($real === null || !is_dir($real) || !Paths::allowed($real)) {
			$pdo->prepare('UPDATE library_folder SET last_scan = ? WHERE id = ?')->execute([$now, $id]);
			return ['id' => $id, 'path' => $row['path'], 'error' => 'The folder is not there any more (unplugged drive or moved?).', 'found' => 0, 'existing' => 0, 'truncated' => false];
		}
		$r = Library::add([], [$real], (bool) $row['is_recursive']);
		$pdo->prepare('UPDATE library_folder SET last_scan = ?, last_found = ? WHERE id = ?')->execute([$now, $r['added'], $id]);
		return ['id' => $id, 'path' => $row['path'], 'error' => null, 'found' => $r['added'], 'existing' => $r['existing'], 'truncated' => $r['truncated']];
	}

	/** @return array{results: array, found: int} */
	public static function scanAll(): array {
		@set_time_limit(0);
		$results = [];
		$found = 0;
		foreach (Db::pdo()->query('SELECT id FROM library_folder ORDER BY path')->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$r = self::scan((int) $id);
			$results[] = $r;
			$found += $r['found'];
		}
		return ['results' => $results, 'found' => $found];
	}

	public static function setRecursive(int $id, bool $recursive): void {
		Db::pdo()->prepare('UPDATE library_folder SET is_recursive = ? WHERE id = ?')->execute([$recursive ? 1 : 0, $id]);
	}

	/** Forgets the folder (its files stay in the library; it just won't be rescanned). */
	public static function forget(int $id): void {
		Db::pdo()->prepare('DELETE FROM library_folder WHERE id = ?')->execute([$id]);
	}

	/**
	 * Folders that hold library files but are not remembered (nor inside a remembered recursive folder): the most useful
	 * places to start rescanning. Files already in the library tell us where they live.
	 * @return array<int, array{path: string, files: int}>
	 */
	public static function suggest(int $limit = 30): array {
		$pdo = Db::pdo();
		$fold = fn($s) => Paths::isWindows() ? mb_strtolower($s, 'UTF-8') : $s;
		$covered = [];
		foreach ($pdo->query('SELECT path, is_recursive FROM library_folder')->fetchAll() as $r) {
			$covered[] = [$fold(rtrim($r['path'], '\\/') . DIRECTORY_SEPARATOR), (bool) $r['is_recursive']];
		}
		$counts = [];
		foreach ($pdo->query('SELECT path FROM media')->fetchAll(PDO::FETCH_COLUMN) as $p) {
			$dir = dirname($p);
			$counts[$dir] = ($counts[$dir] ?? 0) + 1;
		}
		arsort($counts);
		$out = [];
		foreach ($counts as $dir => $n) {
			$prefix = $fold(rtrim($dir, '\\/') . DIRECTORY_SEPARATOR);
			foreach ($covered as [$c, $rec]) {
				if ($prefix === $c || ($rec && strpos($prefix, $c) === 0)) {
					continue 2;
				}
			}
			$out[] = ['path' => (string) $dir, 'files' => $n];
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}
}
