<?php

/**
 * Thin wrapper around the ffmpeg / ffprobe executables.
 * Commands are passed as argument arrays (no shell), so file names can't inject anything.
 * Output goes to temp files / NUL rather than pipes: pipes can't be polled reliably on Windows.
 */
class Ffmpeg {

	private static ?bool $available = null;

	private static function nul(): string {
		return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
	}

	/** True if both ffmpeg and ffprobe can be run. */
	public static function available(): bool {
		if (self::$available === null) {
			self::$available = self::run([FFMPEG_PATH, '-version'], 10) !== null
				&& self::run([FFPROBE_PATH, '-version'], 10) !== null;
		}
		return self::$available;
	}

	/**
	 * Runs a command to completion and returns its stdout, or null if it failed,
	 * could not start, or ran longer than $timeout seconds (then it is killed).
	 */
	public static function run(array $cmd, int $timeout = 30): ?string {
		$outFile = tempnam(sys_get_temp_dir(), 'ba');
		if ($outFile === false) {
			return null;
		}
		try {
			$proc = @proc_open($cmd, [0 => ['file', self::nul(), 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', self::nul(), 'w']], $pipes);
			if (!is_resource($proc)) {
				return null;
			}
			$deadline = microtime(true) + $timeout;
			while (true) {
				$st = proc_get_status($proc);
				if (!$st['running']) {
					$code = $st['exitcode'];
					proc_close($proc);
					return $code === 0 ? (string) file_get_contents($outFile) : null;
				}
				if (microtime(true) > $deadline) {
					proc_terminate($proc);
					proc_close($proc);
					return null;
				}
				usleep(10000);
			}
		} finally {
			@unlink($outFile);
		}
	}

	/**
	 * Runs many commands, at most $parallel at a time, each with its own $timeout.
	 * @param array[] $cmds
	 * @return bool[] same keys as $cmds: true if the process exited with status 0
	 */
	public static function runMany(array $cmds, int $parallel, int $timeout = 60): array {
		$queue = $cmds;
		$running = []; // key => [proc, deadline]
		$result = [];
		while ($queue || $running) {
			while ($queue && count($running) < max(1, $parallel)) {
				$key = array_key_first($queue);
				$cmd = $queue[$key];
				unset($queue[$key]);
				$proc = @proc_open($cmd, [0 => ['file', self::nul(), 'r'], 1 => ['file', self::nul(), 'w'], 2 => ['file', self::nul(), 'w']], $pipes);
				if (is_resource($proc)) {
					$running[$key] = [$proc, microtime(true) + $timeout];
				} else {
					$result[$key] = false;
				}
			}
			foreach ($running as $key => [$proc, $deadline]) {
				$st = proc_get_status($proc);
				if (!$st['running']) {
					proc_close($proc);
					$result[$key] = $st['exitcode'] === 0;
					unset($running[$key]);
				} elseif (microtime(true) > $deadline) {
					proc_terminate($proc);
					proc_close($proc);
					$result[$key] = false;
					unset($running[$key]);
				}
			}
			if ($running) {
				usleep(15000);
			}
		}
		return $result;
	}

	/** ['duration' => seconds, 'width' => int, 'height' => int] for a video file, or null if unreadable. */
	public static function probe(string $path): ?array {
		$json = self::run([
			FFPROBE_PATH, '-v', 'error', '-select_streams', 'v:0',
			'-show_entries', 'stream=width,height,duration:format=duration',
			'-of', 'json', $path,
		], 30);
		$data = $json === null ? null : json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}
		$stream = $data['streams'][0] ?? [];
		$duration = (float) ($data['format']['duration'] ?? $stream['duration'] ?? 0);
		if ($duration <= 0) {
			return null;
		}
		return ['duration' => $duration, 'width' => (int) ($stream['width'] ?? 0), 'height' => (int) ($stream['height'] ?? 0)];
	}

	/** ffmpeg command that writes one JPEG frame from $path at $seconds. */
	public static function frameCommand(string $path, float $seconds, string $out): array {
		return [
			FFMPEG_PATH, '-nostdin', '-hide_banner', '-loglevel', 'error',
			'-ss', number_format($seconds, 3, '.', ''), // before -i: fast keyframe seek, no decoding from the start
			'-i', $path,
			'-frames:v', '1', '-an', '-sn',
			'-vf', "scale=w='min(" . (int) VIDEO_THUMB_WIDTH . ",iw)':h=-2",
			'-q:v', '4', '-y', $out,
		];
	}
}
