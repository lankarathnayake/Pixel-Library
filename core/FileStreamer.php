<?php

/** Streams a file to the browser with caching headers and HTTP Range support (needed for video seeking). */
class FileStreamer {

	private const MAX_RANGE = 8 * 1024 * 1024; // longest slice sent for one Range request

	/**
	 * @param bool $revalidate for files that get regenerated under the same URL (video preview frames): the browser
	 *                         must re-check with its ETag each time (a cheap 304) instead of trusting a cached copy.
	 */
	public static function send(string $path, string $mime, bool $revalidate = false): void {
		$size = filesize($path);
		$mtime = filemtime($path);
		if ($size === false || $mtime === false) {
			http_response_code(404);
			exit;
		}
		$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

		while (ob_get_level()) {
			ob_end_clean();
		}
		@set_time_limit(0);

		header('ETag: ' . $etag);
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
		header('Cache-Control: ' . ($revalidate ? 'private, no-cache' : 'private, max-age=3600'));
		header('X-Content-Type-Options: nosniff');
		header('Content-Security-Policy: sandbox');
		header('Accept-Ranges: bytes');

		if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
			http_response_code(304);
			exit;
		}

		$start = 0;
		$end = $size - 1;
		$status = 200;
		$range = trim($_SERVER['HTTP_RANGE'] ?? '');
		// Single ranges only; anything fancier is answered with the whole file, which is always valid.
		if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
			if ($m[1] === '') { // "last N bytes"
				$start = max(0, $size - (int) $m[2]);
			} else {
				$start = (int) $m[1];
				if ($m[2] !== '') {
					$end = min($end, (int) $m[2]);
				}
			}
			if ($size === 0 || $start > $end || $start >= $size) {
				http_response_code(416);
				header('Content-Range: bytes */' . $size);
				exit;
			}
			// A video player asks for "bytes=N-" (to the end). Answer with a slice: browsers simply request the next
			// one as playback goes on. PHP's built-in web server (start.bat) handles one request at a time on Windows, so a
			// response that lasts as long as the video is playing would freeze the whole app.
			if ($m[1] !== '' && $end - $start + 1 > self::MAX_RANGE) {
				$end = $start + self::MAX_RANGE - 1;
			}
			$status = 206;
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
		}

		$length = $size === 0 ? 0 : $end - $start + 1;
		http_response_code($status);
		header('Content-Type: ' . $mime);
		header('Content-Length: ' . $length);

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) {
			exit;
		}
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			exit;
		}
		fseek($fh, $start);
		$left = $length;
		while ($left > 0 && !feof($fh) && !connection_aborted()) {
			$chunk = fread($fh, (int) min(1 << 20, $left));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			flush();
			$left -= strlen($chunk);
		}
		fclose($fh);
		exit;
	}
}
