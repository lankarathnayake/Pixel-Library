<?php

/**
 * Everything about turning user-supplied paths into safe, canonical ones,
 * and listing folders for the picker.
 */
class Paths {

	public static function isWindows(): bool {
		return DIRECTORY_SEPARATOR === '\\';
	}

	private static function isAbsolute(string $p): bool {
		if (self::isWindows()) {
			return (bool) preg_match('~^([A-Za-z]:[\\\\/]|\\\\\\\\)~', $p);
		}
		return $p !== '' && $p[0] === '/';
	}

	/** Canonical absolute path (real case, no "..", no symlink tricks) or null if it doesn't exist. */
	public static function resolve(string $path): ?string {
		$path = trim($path);
		if ($path === '' || strpos($path, "\0") !== false) {
			return null;
		}
		// A bare "H:" means "the current directory of drive H" on Windows - treat it as the drive root.
		if (self::isWindows() && preg_match('/^[A-Za-z]:$/', $path)) {
			$path .= '\\';
		}
		if (!self::isAbsolute($path)) {
			return null;
		}
		$real = @realpath($path);
		return $real === false ? null : $real;
	}

	/** Dedupe key for a canonical path. Windows paths are case-insensitive. */
	public static function hash(string $realPath): string {
		return sha1(self::isWindows() ? mb_strtolower($realPath, 'UTF-8') : $realPath);
	}

	/** Top-level places the picker starts from: BROWSE_ROOTS, else all drives / "/". */
	public static function roots(): array {
		if (BROWSE_ROOTS) {
			$out = [];
			foreach (BROWSE_ROOTS as $r) {
				$real = self::resolve((string) $r);
				if ($real !== null && is_dir($real)) {
					$out[] = $real;
				}
			}
			return $out;
		}
		if (!self::isWindows()) {
			return ['/'];
		}
		$out = [];
		foreach (range('A', 'Z') as $letter) {
			$root = $letter . ':\\';
			if (@is_dir($root)) {
				$out[] = $root;
			}
		}
		return $out;
	}

	/** True if $real is inside one of the allowed roots (always true when BROWSE_ROOTS is empty). */
	public static function allowed(string $real): bool {
		if (!BROWSE_ROOTS) {
			return true;
		}
		foreach (self::roots() as $root) {
			if (self::isInside($real, $root)) {
				return true;
			}
		}
		return false;
	}

	private static function isInside(string $path, string $root): bool {
		$fold = fn($s) => self::isWindows() ? mb_strtolower($s, 'UTF-8') : $s;
		$prefix = rtrim($fold($root), '\\/') . DIRECTORY_SEPARATOR;
		return strpos($fold($path) . DIRECTORY_SEPARATOR, $prefix) === 0;
	}

	/**
	 * Sub-folders and supported media files directly inside $dir (folders first).
	 * @return array{entries: array, truncated: bool}
	 */
	public static function listDir(string $dir, int $limit = 5000): array {
		$dirs = [];
		$files = [];
		$truncated = false;
		try {
			$it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
			foreach ($it as $info) {
				$name = $info->getFilename();
				if ($info->isDir()) {
					if (self::isWindows() && ($name === '$RECYCLE.BIN' || $name === 'System Volume Information')) {
						continue;
					}
					$dirs[] = ['name' => $name, 'path' => $info->getPathname(), 'is_dir' => true];
				} else {
					$kind = MediaTypes::forPath($name);
					if ($kind === null) {
						continue;
					}
					$files[] = [
						'name' => $name,
						'path' => $info->getPathname(),
						'is_dir' => false,
						'type' => $kind['type'],
						'size' => (int) @$info->getSize(),
					];
				}
				if (count($dirs) + count($files) >= $limit) {
					$truncated = true;
					break;
				}
			}
		} catch (UnexpectedValueException $e) {
			throw new ApiException('That folder cannot be read.', 403);
		}
		$byName = fn($a, $b) => strnatcasecmp($a['name'], $b['name']);
		usort($dirs, $byName);
		usort($files, $byName);
		return ['entries' => array_merge($dirs, $files), 'truncated' => $truncated];
	}
}
