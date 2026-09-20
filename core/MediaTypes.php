<?php

/** The file types the app registers, and the MIME type each is served as. */
class MediaTypes {

	private const MAP = [
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
	];

	/** ['ext' => 'jpg', 'type' => 'image', 'mime' => 'image/jpeg'] or null if unsupported. */
	public static function forPath(string $path): ?array {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!isset(self::MAP[$ext])) {
			return null;
		}
		return ['ext' => $ext, 'type' => self::MAP[$ext][0], 'mime' => self::MAP[$ext][1]];
	}
}
