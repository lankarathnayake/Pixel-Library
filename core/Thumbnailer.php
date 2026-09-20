<?php

/** Cached JPEG thumbnails for images. Needs PHP's GD extension; without it callers fall back to the original. */
class Thumbnailer {

	private const MAX_PIXELS = 60000000; // don't decode enormous images (GD would exhaust memory)

	private static function dir(): string {
		return rtrim(THUMBS_DIR, '/\\');
	}

	public static function available(): bool {
		return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
	}

	/** Path to a thumbnail for this media row (created on first use), or null to fall back to the original. */
	public static function forMedia(array $row): ?string {
		if (!self::available() || $row['type'] !== 'image') {
			return null;
		}
		$cache = self::dir() . '/' . (int) $row['id'] . '_' . strtotime((string) $row['file_mtime']) . '.jpg';
		if (is_file($cache)) {
			return $cache;
		}
		return self::make($row['path'], $row['ext'], $cache) ? $cache : null;
	}

	private static function make(string $src, string $ext, string $dest): bool {
		$dim = @getimagesize($src);
		if (!$dim || $dim[0] * $dim[1] > self::MAX_PIXELS) {
			return false;
		}
		$loaders = [
			'jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng',
			'gif' => 'imagecreatefromgif', 'webp' => 'imagecreatefromwebp', 'bmp' => 'imagecreatefrombmp',
			'avif' => 'imagecreatefromavif',
		];
		$loader = $loaders[$ext] ?? null;
		if ($loader === null || !function_exists($loader)) {
			return false;
		}
		$img = @$loader($src);
		if (!$img) {
			return false;
		}

		if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
			$exif = @exif_read_data($src);
			$angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
			if ($angle) {
				$rotated = imagerotate($img, $angle, 0);
				if ($rotated) {
					$img = $rotated;
				}
			}
		}

		$w = imagesx($img);
		$h = imagesy($img);
		$scale = min(1, THUMB_SIZE / max($w, $h));
		$tw = max(1, (int) round($w * $scale));
		$th = max(1, (int) round($h * $scale));
		$out = imagecreatetruecolor($tw, $th);
		imagefill($out, 0, 0, imagecolorallocate($out, 24, 24, 27)); // flatten transparency onto the tile colour
		imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

		$tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$ok = imagejpeg($out, $tmp, 82) && @rename($tmp, $dest);
		if (!$ok) {
			@unlink($tmp);
		}
		return $ok;
	}

	/** Deletes cached thumbnails for a media id (called when it leaves the library). */
	public static function forget(int $id): void {
		foreach (glob(self::dir() . '/' . $id . '_*.jpg') ?: [] as $f) {
			@unlink($f);
		}
	}
}
