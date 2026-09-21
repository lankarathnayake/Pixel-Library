<?php

/**
 * The file types the app registers, and the MIME type each is served as.
 *
 * The active list is the media_format table, edited on the Settings page. DEFAULTS is the built-in list: it is what
 * the table starts with (db/schema.sqlite.sql, db/migrations/*media-formats*), what "Reset to defaults" restores, and
 * what serving falls back to for files that are already in the library when their format is later removed.
 */
class MediaTypes {

	/** ext => [type, mime]. Must match the rows seeded by the schema (a test compares them). */
	public const DEFAULTS = [
		'jpg'  => ['image', 'image/jpeg'],
		'jpeg' => ['image', 'image/jpeg'],
		'png'  => ['image', 'image/png'],
		'gif'  => ['image', 'image/gif'],
		'webp' => ['image', 'image/webp'],
		'bmp'  => ['image', 'image/bmp'],
		'avif' => ['image', 'image/avif'],
		'mp4'  => ['video', 'video/mp4'],
		'm4v'  => ['video', 'video/mp4'],
		'webm' => ['video', 'video/webm'],
		'ogv'  => ['video', 'video/ogg'],
		'mov'  => ['video', 'video/quicktime'],
		'mkv'  => ['video', 'video/x-matroska'],
		'avi'  => ['video', 'video/x-msvideo'],
		'wmv'  => ['video', 'video/x-ms-wmv'],
		'ts'   => ['video', 'video/mp2t'], // MPEG transport stream - see looksLikeTransportStream(): ".ts" is also TypeScript source
	];

	/** Formats offered as one-click additions on the Settings page (the ones not in DEFAULTS). */
	public const SUGGESTED = [
		'mpg'  => ['video', 'video/mpeg'],
		'mpeg' => ['video', 'video/mpeg'],
		'mpe'  => ['video', 'video/mpeg'],
		'm2v'  => ['video', 'video/mpeg'],
		'vob'  => ['video', 'video/mpeg'],
		'flv'  => ['video', 'video/x-flv'],
		'f4v'  => ['video', 'video/mp4'],
		'3gp'  => ['video', 'video/3gpp'],
		'3g2'  => ['video', 'video/3gpp2'],
		'm2ts' => ['video', 'video/mp2t'],
		'mts'  => ['video', 'video/mp2t'],
		'asf'  => ['video', 'video/x-ms-asf'],
		'divx' => ['video', 'video/x-msvideo'],
		'ogm'  => ['video', 'video/ogg'],
		'qt'   => ['video', 'video/quicktime'],
		'rm'   => ['video', 'video/vnd.rn-realvideo'],
		'rmvb' => ['video', 'video/vnd.rn-realvideo'],
		'jpe'  => ['image', 'image/jpeg'],
		'jfif' => ['image', 'image/jpeg'],
		'tif'  => ['image', 'image/tiff'],
		'tiff' => ['image', 'image/tiff'],
		'heic' => ['image', 'image/heic'],
		'heif' => ['image', 'image/heif'],
		'apng' => ['image', 'image/apng'],
		'jxl'  => ['image', 'image/jxl'],
		'ico'  => ['image', 'image/x-icon'],
	];

	/** Never accepted as a "media format": programs, scripts and web pages. */
	private const BLOCKED = ['php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'ps1', 'vbs', 'js', 'mjs',
		'html', 'htm', 'hta', 'lnk', 'sh', 'jar', 'reg', 'ini', 'sql', 'sqlite'];

	private const MAX_FORMATS = 300;

	private static ?array $active = null;

	// ---------------------------------------------------------------- what is registered

	/** ext => [type, mime] for the formats currently switched on. */
	public static function active(): array {
		if (self::$active === null) {
			try {
				$map = [];
				foreach (Db::pdo()->query('SELECT ext, type, mime FROM media_format ORDER BY type, ext')->fetchAll() as $r) {
					$map[$r['ext']] = [$r['type'], $r['mime']];
				}
				self::$active = $map;
			} catch (Throwable $e) { // an old MySQL database without the table (SQLite upgrades itself): the built-in list
				self::$active = self::DEFAULTS;
			}
		}
		return self::$active;
	}

	/** Forget the cached list (after the Settings page changed it). */
	public static function reset(): void {
		self::$active = null;
	}

	/**
	 * ['ext' => 'jpg', 'type' => 'image', 'mime' => 'image/jpeg'] if this file should be ADDED to the library, else null.
	 * Pass the full path where you have it: ".ts" files are only accepted when their content really is a video.
	 */
	public static function forPath(string $path): ?array {
		return self::kind($path, self::active());
	}

	/**
	 * Like forPath() but for a file that is ALREADY in the library (serving it, deleting it): if its format has since been
	 * removed from the list it keeps working - by the built-in / suggested lists, or else by the type it was registered with
	 * ($storedType = the media row's type).
	 */
	public static function forExisting(string $path, ?string $storedType = null): ?array {
		$hit = self::kind($path, self::active()) ?? self::kind($path, self::DEFAULTS + self::SUGGESTED);
		if ($hit !== null || !in_array($storedType, ['image', 'video'], true)) {
			return $hit;
		}
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!preg_match('/^[a-z0-9]{1,10}$/', $ext) || in_array($ext, self::BLOCKED, true)) {
			return null;
		}
		return ['ext' => $ext, 'type' => $storedType, 'mime' => $storedType === 'video' ? 'video/mp4' : 'image/jpeg'];
	}

	private static function kind(string $path, array $map): ?array {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!isset($map[$ext])) {
			return null;
		}
		if ($ext === 'ts' && is_file($path) && !self::looksLikeTransportStream($path)) {
			return null; // a TypeScript source file, not a video
		}
		return ['ext' => $ext, 'type' => $map[$ext][0], 'mime' => $map[$ext][1]];
	}

	/** A transport stream is 188-byte packets that each start with the sync byte 0x47 (checked at three packets in a row). */
	private static function looksLikeTransportStream(string $path): bool {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		$head = (string) fread($fh, 377);
		fclose($fh);
		return strlen($head) >= 377 && $head[0] === "\x47" && $head[188] === "\x47" && $head[376] === "\x47";
	}

	// ---------------------------------------------------------------- the Settings page

	/** @return array<int, array{ext:string, type:string, mime:string, files:int}> */
	public static function all(): array {
		$counts = [];
		foreach (Db::pdo()->query('SELECT ext, COUNT(*) AS n FROM media GROUP BY ext')->fetchAll() as $r) {
			$counts[$r['ext']] = (int) $r['n'];
		}
		$out = [];
		foreach (self::active() as $ext => [$type, $mime]) {
			$out[] = ['ext' => (string) $ext, 'type' => $type, 'mime' => $mime, 'files' => $counts[$ext] ?? 0];
		}
		return $out;
	}

	/** One-click additions that are not in the list right now (includes built-ins that were removed). */
	public static function suggestions(): array {
		$have = self::active();
		$out = [];
		foreach (self::DEFAULTS + self::SUGGESTED as $ext => [$type, $mime]) {
			if (!isset($have[$ext])) {
				$out[] = ['ext' => (string) $ext, 'type' => $type, 'mime' => $mime];
			}
		}
		return $out;
	}

	public static function normalizeExt(string $ext): string {
		return strtolower(ltrim(trim($ext), '.'));
	}

	/** @return array{ext:string, type:string, mime:string} */
	public static function add(string $ext, string $type, string $mime = ''): array {
		$ext = self::normalizeExt($ext);
		if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
			throw new ApiException('An extension is letters and digits only, up to 10 of them, written without the dot (for example: mpg).');
		}
		if (!in_array($type, ['image', 'video'], true)) {
			throw new ApiException('Choose whether it is a video or an image format.');
		}
		if (in_array($ext, self::BLOCKED, true)) {
			throw new ApiException('.' . $ext . ' is a program, script or web page, not a media format.');
		}
		$mime = strtolower(trim($mime));
		if ($mime === '') {
			$known = self::SUGGESTED[$ext] ?? self::DEFAULTS[$ext] ?? null;
			$mime = $known !== null && $known[0] === $type ? $known[1] : ($type === 'video' ? 'video/mp4' : 'image/jpeg');
		} elseif (!preg_match('#^(video|image)/[a-z0-9][a-z0-9.+-]{0,60}$#', $mime) || strpos($mime, $type . '/') !== 0) {
			throw new ApiException('The MIME type must look like ' . $type . '/something (for example ' . $type . '/' . ($type === 'video' ? 'mp4' : 'jpeg') . ').');
		}
		if (isset(self::active()[$ext])) {
			throw new ApiException('.' . $ext . ' is already in the list.', 409);
		}
		if (count(self::active()) >= self::MAX_FORMATS) {
			throw new ApiException('That is enough formats (' . self::MAX_FORMATS . ').');
		}
		try {
			Db::pdo()->prepare('INSERT INTO media_format (ext, type, mime) VALUES (?, ?, ?)')->execute([$ext, $type, $mime]);
		} catch (PDOException $e) {
			throw self::tableProblem($e);
		}
		self::reset();
		return ['ext' => $ext, 'type' => $type, 'mime' => $mime];
	}

	/**
	 * Takes a format out of the list: new files with that extension are no longer added. Files of that kind that are already
	 * in the library stay (and keep working) unless $removeFiles is set, which takes them out of the LIBRARY (never off the disk).
	 * @return int how many library entries were removed
	 */
	public static function remove(string $ext, bool $removeFiles): int {
		$ext = self::normalizeExt($ext);
		if (!isset(self::active()[$ext])) {
			throw new ApiException('.' . $ext . ' is not in the list.', 404);
		}
		if (count(self::active()) <= 1) {
			throw new ApiException('Keep at least one format.');
		}
		$removed = 0;
		if ($removeFiles) {
			$stmt = Db::pdo()->prepare('SELECT id FROM media WHERE ext = ?');
			$stmt->execute([$ext]);
			$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
			$removed = Library::remove($ids);
		}
		try {
			Db::pdo()->prepare('DELETE FROM media_format WHERE ext = ?')->execute([$ext]);
		} catch (PDOException $e) {
			throw self::tableProblem($e);
		}
		self::reset();
		return $removed;
	}

	/** Back to the built-in list. */
	public static function restoreDefaults(): void {
		$pdo = Db::pdo();
		try {
			$pdo->beginTransaction();
			$pdo->exec('DELETE FROM media_format');
			$ins = $pdo->prepare('INSERT INTO media_format (ext, type, mime) VALUES (?, ?, ?)');
			foreach (self::DEFAULTS as $ext => [$type, $mime]) {
				$ins->execute([$ext, $type, $mime]);
			}
			$pdo->commit();
		} catch (PDOException $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			throw self::tableProblem($e);
		}
		self::reset();
	}

	private static function tableProblem(PDOException $e): ApiException {
		error_log('pixel-library: media_format: ' . $e->getMessage());
		return new ApiException('The file-formats table is missing. On MySQL run db/migrations/006-media-formats.mysql.sql once (SQLite upgrades itself).', 500);
	}
}
