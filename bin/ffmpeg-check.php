<?php
/**
 * Exit code 0 if ffmpeg + ffprobe work with the current config (including the "ffmpeg" folder next to the app), else 1.
 * start.bat uses it to decide whether to offer the ffmpeg download.
 */
if (PHP_SAPI !== 'cli') {
	exit('CLI only.');
}
require __DIR__ . '/../config.php';
require __DIR__ . '/../common/autoload.php';
exit(Ffmpeg::available() ? 0 : 1);
