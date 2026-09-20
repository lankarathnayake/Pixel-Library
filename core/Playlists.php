<?php

/**
 * Playlists: named, ordered lists of library files. They are kept in the database and can be exported as .m3u8 (absolute
 * file paths), which VLC, PotPlayer and most other players open. A player can also be launched with one (Windows).
 */
class Playlists {

	private const CHUNK = 500;

	// ---------------------------------------------------------------- playlists

	/** @return array<int, array{id:int, name:string, items:int, duration:float}> */
	public static function all(): array {
		$rows = Db::pdo()->query(
			'SELECT p.id, p.name, COUNT(i.media_id) AS items, COALESCE(SUM(m.duration), 0) AS duration
			 FROM playlist p LEFT JOIN playlist_item i ON i.playlist_id = p.id LEFT JOIN media m ON m.id = i.media_id
			 GROUP BY p.id, p.name ORDER BY p.name'
		)->fetchAll();
		return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'items' => (int) $r['items'], 'duration' => (float) $r['duration']], $rows);
	}

	public static function create($name, array $mediaIds = []): int {
		$name = Taxonomy::cleanName($name);
		$pdo = Db::pdo();
		try {
			$pdo->prepare('INSERT INTO playlist (name) VALUES (?)')->execute([$name]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A playlist with that name already exists.', 409) : $e;
		}
		$id = (int) $pdo->lastInsertId();
		if ($mediaIds) {
			self::add($id, $mediaIds);
		}
		return $id;
	}

	public static function rename(int $id, $name): void {
		$name = Taxonomy::cleanName($name);
		try {
			Db::pdo()->prepare('UPDATE playlist SET name = ? WHERE id = ?')->execute([$name, $id]);
		} catch (PDOException $e) {
			throw Db::isDuplicate($e) ? new ApiException('A playlist with that name already exists.', 409) : $e;
		}
	}

	public static function delete(int $id): void {
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$pdo->prepare('DELETE FROM playlist_item WHERE playlist_id = ?')->execute([$id]);
			$pdo->prepare('DELETE FROM playlist WHERE id = ?')->execute([$id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	private static function exists(int $id): array {
		$stmt = Db::pdo()->prepare('SELECT id, name FROM playlist WHERE id = ?');
		$stmt->execute([$id]);
		$r = $stmt->fetch();
		if ($r === false) {
			throw new ApiException('That playlist does not exist.', 404);
		}
		return $r;
	}

	// ---------------------------------------------------------------- items

	/** A playlist with its items in order. */
	public static function get(int $id): array {
		$p = self::exists($id);
		$stmt = Db::pdo()->prepare(
			'SELECT i.position, m.id, m.name, m.path, m.type, m.ext, m.size, m.duration, m.width, m.height, m.is_missing
			 FROM playlist_item i JOIN media m ON m.id = i.media_id WHERE i.playlist_id = ? ORDER BY i.position, m.id'
		);
		$stmt->execute([$id]);
		$rows = $stmt->fetchAll();
		$frames = VideoThumbs::timesFor(array_map(fn($r) => (int) $r['id'], $rows));
		$items = array_map(function ($r) use ($frames) {
			$n = count($frames[(int) $r['id']] ?? []);
			return [
				'id' => (int) $r['id'], 'name' => $r['name'], 'path' => $r['path'], 'type' => $r['type'], 'size' => (int) $r['size'],
				'duration' => $r['duration'] === null ? null : (float) $r['duration'], 'is_missing' => (bool) $r['is_missing'],
				'cover' => $r['type'] === 'video' && $n ? (int) floor($n * 0.3) : null,
			];
		}, $rows);
		return [
			'id' => (int) $p['id'], 'name' => $p['name'], 'items' => $items,
			'duration' => array_sum(array_map(fn($i) => $i['duration'] ?? 0, $items)),
			'missing' => count(array_filter($items, fn($i) => $i['is_missing'])),
		];
	}

	/** Appends files (in the order given) to the end of the playlist; files already in it are skipped. Returns how many were added. */
	public static function add(int $id, array $mediaIds): int {
		self::exists($id);
		$pdo = Db::pdo();
		$ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds), fn($i) => $i > 0)));
		$have = array_flip(array_map('intval', $pdo->query('SELECT media_id FROM playlist_item WHERE playlist_id = ' . (int) $id)->fetchAll(PDO::FETCH_COLUMN)));
		$pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM playlist_item WHERE playlist_id = ' . (int) $id)->fetchColumn();
		$valid = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$stmt = $pdo->prepare('SELECT id FROM media WHERE id IN (' . Db::placeholders(count($chunk)) . ')');
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
				$valid[(int) $m] = true;
			}
		}
		$ins = $pdo->prepare('INSERT INTO playlist_item (playlist_id, media_id, position) VALUES (?, ?, ?)');
		$added = 0;
		$pdo->beginTransaction();
		try {
			foreach ($ids as $m) { // keep the caller's order
				if (isset($valid[$m]) && !isset($have[$m])) {
					$ins->execute([$id, $m, ++$pos]);
					$added++;
				}
			}
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return $added;
	}

	public static function remove(int $id, array $mediaIds): void {
		self::exists($id);
		$ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds), fn($i) => $i > 0)));
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			Db::pdo()->prepare('DELETE FROM playlist_item WHERE playlist_id = ? AND media_id IN (' . Db::placeholders(count($chunk)) . ')')->execute(array_merge([$id], $chunk));
		}
		self::renumber($id);
	}

	/** Sets the order to exactly this list of media ids (ids not in the playlist are ignored, missing ones keep their relative order at the end). */
	public static function reorder(int $id, array $ordered): void {
		self::exists($id);
		$current = array_map('intval', Db::pdo()->query('SELECT media_id FROM playlist_item WHERE playlist_id = ' . (int) $id . ' ORDER BY position, media_id')->fetchAll(PDO::FETCH_COLUMN));
		$want = array_values(array_intersect(array_unique(array_map('intval', $ordered)), $current));
		self::writeOrder($id, array_merge($want, array_values(array_diff($current, $want))));
	}

	/** Sorts by 'name' | 'duration' | 'shuffle' (direction 'asc' | 'desc' for the first two). */
	public static function sortBy(int $id, string $by, string $dir = 'asc'): void {
		$p = self::get($id);
		$items = $p['items'];
		if ($by === 'shuffle') {
			shuffle($items);
		} elseif ($by === 'name') {
			usort($items, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		} elseif ($by === 'duration') {
			usort($items, fn($a, $b) => ($a['duration'] ?? 0) <=> ($b['duration'] ?? 0));
		} else {
			throw new ApiException('Unknown sort.');
		}
		if ($dir === 'desc' && $by !== 'shuffle') {
			$items = array_reverse($items);
		}
		self::writeOrder($id, array_map(fn($i) => $i['id'], $items));
	}

	private static function writeOrder(int $id, array $mediaIds): void {
		$pdo = Db::pdo();
		$upd = $pdo->prepare('UPDATE playlist_item SET position = ? WHERE playlist_id = ? AND media_id = ?');
		$pdo->beginTransaction();
		try {
			foreach ($mediaIds as $i => $m) {
				$upd->execute([$i + 1, $id, $m]);
			}
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	private static function renumber(int $id): void {
		self::writeOrder($id, array_map('intval', Db::pdo()->query('SELECT media_id FROM playlist_item WHERE playlist_id = ' . (int) $id . ' ORDER BY position, media_id')->fetchAll(PDO::FETCH_COLUMN)));
	}

	/** Moves these files' places in playlists to $toId (unless that playlist already has $toId, then they are simply dropped). Used by the duplicate finder. */
	public static function replaceMedia(array $fromIds, int $toId): void {
		$pdo = Db::pdo();
		$fromIds = array_values(array_unique(array_map('intval', $fromIds)));
		if (!$fromIds) {
			return;
		}
		$stmt = $pdo->prepare('SELECT playlist_id, media_id FROM playlist_item WHERE media_id IN (' . Db::placeholders(count($fromIds)) . ') ORDER BY playlist_id, position');
		$stmt->execute($fromIds);
		$has = $pdo->prepare('SELECT 1 FROM playlist_item WHERE playlist_id = ? AND media_id = ?');
		foreach ($stmt->fetchAll() as $r) {
			$has->execute([$r['playlist_id'], $toId]);
			if ($has->fetchColumn() === false) {
				$pdo->prepare('UPDATE playlist_item SET media_id = ? WHERE playlist_id = ? AND media_id = ?')->execute([$toId, $r['playlist_id'], $r['media_id']]);
			} else {
				$pdo->prepare('DELETE FROM playlist_item WHERE playlist_id = ? AND media_id = ?')->execute([$r['playlist_id'], $r['media_id']]);
			}
		}
	}

	// ---------------------------------------------------------------- export

	/** The playlist as .m3u8 text: one absolute path per file, with the title and length. Files flagged missing are left out. */
	public static function m3u(int $id): string {
		$p = self::get($id);
		return self::m3uText($p['name'], $p['items']);
	}

	private static function m3uText(string $name, array $items): string {
		$out = "#EXTM3U\r\n#PLAYLIST:" . str_replace(["\r", "\n"], ' ', $name) . "\r\n";
		foreach ($items as $i) {
			if ($i['is_missing']) {
				continue;
			}
			$title = str_replace(["\r", "\n", ','], [' ', ' ', ' '], pathinfo($i['name'], PATHINFO_FILENAME));
			$out .= '#EXTINF:' . ($i['duration'] !== null ? (int) round($i['duration']) : -1) . ',' . $title . "\r\n" . $i['path'] . "\r\n";
		}
		return $out;
	}

	public static function fileName(string $name): string {
		$safe = trim((string) preg_replace('/[^\p{L}\p{N} ._()\[\]-]+/u', '_', $name), " ._"); // letters, digits, space and . _ - ( ) [ ]
		return ($safe === '' ? 'playlist' : mb_substr($safe, 0, 80)) . '.m3u8';
	}

	/** Writes the .m3u8 into PLAYLIST_DIR and returns its full path (so you can double-click it or drag it into a player). */
	public static function saveFile(int $id): string {
		$p = self::exists($id);
		$dir = rtrim(PLAYLIST_DIR, '/\\');
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new ApiException('The playlist folder cannot be created.', 500);
		}
		$path = $dir . DIRECTORY_SEPARATOR . self::fileName($p['name']);
		if (@file_put_contents($path, self::m3u($id)) === false) {
			throw new ApiException('The playlist file could not be written (is the folder writable?).', 500);
		}
		return $path;
	}

	// ---------------------------------------------------------------- players

	/** Players found on this PC (plus PLAYER_PATH from the config). key => [name, exe]. Requests only ever name a key, never a path. */
	private static function playerTable(): array {
		$known = [
			'vlc' => ['VLC', ['C:\Program Files\VideoLAN\VLC\vlc.exe', 'C:\Program Files (x86)\VideoLAN\VLC\vlc.exe']],
			'potplayer' => ['PotPlayer', [
				'C:\Program Files\DAUM\PotPlayer\PotPlayerMini64.exe', 'C:\Program Files (x86)\DAUM\PotPlayer\PotPlayerMini.exe',
				'C:\Program Files\PotPlayer\PotPlayerMini64.exe', 'C:\Program Files (x86)\PotPlayer\PotPlayerMini.exe', 'C:\Program Files\DAUM\PotPlayer\PotPlayerMini.exe',
			]],
		];
		$found = [];
		foreach ($known as $key => [$name, $paths]) {
			foreach ($paths as $p) {
				if (@is_file($p)) {
					$found[$key] = [$name, $p];
					break;
				}
			}
		}
		if (PLAYER_PATH !== '' && @is_file(PLAYER_PATH)) {
			$found['custom'] = [pathinfo(PLAYER_PATH, PATHINFO_FILENAME), PLAYER_PATH];
		}
		return $found;
	}

	/** @return array<int, array{key:string, name:string}> */
	public static function players(): array {
		$out = [];
		foreach (self::playerTable() as $key => [$name]) {
			$out[] = ['key' => $key, 'name' => $name];
		}
		return $out;
	}

	/** Writes the .m3u8 and starts the chosen player with it. Returns immediately (the player keeps running on its own). */
	public static function play(int $id, string $playerKey): array {
		$players = self::playerTable();
		if (!isset($players[$playerKey])) {
			throw new ApiException('That player was not found on this computer.', 404);
		}
		if (self::get($id)['items'] === []) {
			throw new ApiException('The playlist is empty.');
		}
		return self::launch(self::saveFile($id), $playerKey, $players);
	}

	/** Name of the throw-away playlist "Play now" writes. fileName() never yields a leading underscore, so no saved playlist can clash with it. */
	public const NOW_PLAYING = '_now-playing.m3u8';
	private const NOW_PLAYING_MAX = 2000;

	/**
	 * "Play now": plays the given files (in the order given) without touching any saved playlist. They go into one
	 * throw-away playlist file that is simply overwritten by the next "Play now". Videos only; files flagged missing are skipped.
	 */
	public static function playFiles(array $mediaIds, string $playerKey): array {
		$players = self::playerTable();
		if (!isset($players[$playerKey])) {
			throw new ApiException('That player was not found on this computer.', 404);
		}
		$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $mediaIds)))), 0, self::NOW_PLAYING_MAX);
		if ($ids === []) {
			throw new ApiException('Select some files first.');
		}
		$stmt = Db::pdo()->prepare('SELECT id, name, path, type, duration, is_missing FROM media WHERE id IN (' . Db::placeholders(count($ids)) . ')');
		$stmt->execute($ids);
		$byId = [];
		foreach ($stmt->fetchAll() as $r) {
			if ($r['type'] === 'video' && !$r['is_missing']) {
				$byId[(int) $r['id']] = ['name' => $r['name'], 'path' => $r['path'], 'is_missing' => false, 'duration' => $r['duration'] === null ? null : (float) $r['duration']];
			}
		}
		$items = [];
		foreach ($ids as $id) { // keep the order the caller chose
			if (isset($byId[$id])) {
				$items[] = $byId[$id];
			}
		}
		if ($items === []) {
			throw new ApiException('None of the selected files is a video that is present on disk.');
		}
		$dir = rtrim(PLAYLIST_DIR, '/\\');
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new ApiException('The playlist folder cannot be created.', 500);
		}
		$file = $dir . DIRECTORY_SEPARATOR . self::NOW_PLAYING;
		if (@file_put_contents($file, self::m3uText('Now playing', $items)) === false) {
			throw new ApiException('The playlist file could not be written (is the folder writable?).', 500);
		}
		return self::launch($file, $playerKey, $players) + ['count' => count($items)];
	}

	private static function launch(string $file, string $playerKey, array $players): array {
		[$name, $exe] = $players[$playerKey];
		$nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
		if (DIRECTORY_SEPARATOR === '\\') {
			// PowerShell's Start-Process hands the player to Windows and returns at once, so this request never waits for the
			// player window to close (the player must outlive the web request). Both values are single-quoted.
			$q = fn($v) => "'" . str_replace("'", "''", $v) . "'";
			$script = 'Start-Process -FilePath ' . $q($exe) . ' -ArgumentList ' . $q('"' . $file . '"');
			$cmd = ['powershell', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-Command', $script];
		} else {
			$cmd = ['sh', '-c', 'nohup ' . escapeshellarg($exe) . ' ' . escapeshellarg($file) . ' >/dev/null 2>&1 &'];
		}
		$proc = @proc_open($cmd, [0 => ['file', $nul, 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes);
		if (!is_resource($proc)) {
			throw new ApiException('Could not start ' . $name . '.', 500);
		}
		proc_close($proc);
		return ['player' => $name, 'file' => $file];
	}
}
