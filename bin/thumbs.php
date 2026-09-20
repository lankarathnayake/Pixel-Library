<?php
/**
 * Command-line worker for video preview frames. Best for a big library: no browser needed,
 * no request time limits. Safe to run while the web page's "Generate previews" is also used
 * (each video is claimed by exactly one worker), and safe to stop and restart at any time.
 *
 *   php bin/thumbs.php                  make previews for every video that has none
 *   php bin/thumbs.php --limit=50       stop after 50 videos
 *   php bin/thumbs.php --retry-failed   also re-queue videos that failed before
 *
 * Uses the same config.php / config.local.php as the web app (so the same database and ffmpeg paths).
 */

if (PHP_SAPI !== 'cli') {
	exit('CLI only.');
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../common/autoload.php';

$limit = null;
$retry = false;
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
		$limit = (int) $m[1];
	} elseif ($arg === '--retry-failed') {
		$retry = true;
	} else {
		fwrite(STDERR, "Unknown option: $arg\nUsage: php bin/thumbs.php [--limit=N] [--retry-failed]\n");
		exit(2);
	}
}

if (!Ffmpeg::available()) {
	fwrite(STDERR, "ffmpeg/ffprobe not found. Run tools\\get-ffmpeg.ps1, or put them on the PATH, or set FFMPEG_PATH / FFPROBE_PATH in config.local.php.\n");
	exit(1);
}
if ($retry) {
	echo 'Re-queued ' . VideoThumbs::retryFailed() . " failed video(s).\n";
}

$stats = VideoThumbs::stats();
$todo = $stats['pending'] + $stats['running'];
if ($limit !== null) {
	$todo = min($todo, $limit);
}
echo "$todo video(s) to do (parallel frames per video: " . VIDEO_THUMB_PARALLEL . "). Ctrl+C to stop; run again to continue.\n";

$t0 = microtime(true);
$n = 0;
$done = $failed = $missing = 0;
while ($limit === null || $n < $limit) {
	$row = VideoThumbs::claimNext();
	if ($row === null) {
		break;
	}
	$n++;
	$t = microtime(true);
	try {
		$outcome = VideoThumbs::process($row);
	} catch (Throwable $e) {
		fwrite(STDERR, '  error: ' . $e->getMessage() . "\n");
		$outcome = 'failed';
		Db::pdo()->prepare('UPDATE media SET thumb_status = 2, thumb_claimed_at = NULL WHERE id = ?')->execute([$row['id']]);
	}
	if ($outcome === 'done') {
		$done++;
	} elseif ($outcome === 'missing') {
		$missing++;
	} else {
		$failed++;
	}
	printf("[%d/%d] %-7s %5.1fs  %s\n", $n, $todo, $outcome, microtime(true) - $t, mb_strimwidth($row['name'], 0, 80, '...'));
}

printf("\nFinished: %d done, %d failed, %d missing file(s) in %.0fs.\n", $done, $failed, $missing, microtime(true) - $t0);
exit(0);
