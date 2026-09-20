<?php
/**
 * Central configuration for Pixel Library.
 *
 * The values below are PRODUCTION defaults. For local development, drop a
 * config.local.php next to this file (git-ignored) that define()s whatever
 * differs locally - it is loaded first and its definitions win.
 */

// Local overrides win - load them before the defaults below.
if (is_file(__DIR__ . '/config.local.php')) {
	require __DIR__ . '/config.local.php';
}

// ---- Database ----
// 'sqlite' (default): one file, created and upgraded automatically - nothing to install.
// 'mysql': MySQL / MariaDB (create it from db/schema.mysql.sql, then set the DB_HOST/USER/PASS/NAME below).
defined('DB_DRIVER') || define('DB_DRIVER', 'sqlite');
defined('DB_FILE') || define('DB_FILE', __DIR__ . '/storage/pixel-library.sqlite');
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'pixel_library');
defined('DB_PASS') || define('DB_PASS', 'CHANGE_ME_IN_PROD');
defined('DB_NAME') || define('DB_NAME', 'pixel_library');

// ---- Public URL (no trailing slash) - only its path scopes the session cookie ----
defined('APP_URL') || define('APP_URL', 'http://localhost');

// ---- Session ----
defined('SESSION_NAME') || define('SESSION_NAME', 'pixel_library_session');

// ---- Timezone ----
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'UTC');
date_default_timezone_set(APP_TIMEZONE);

// ---- Access ----
// This app can list every folder on the machine it runs on, and has no login.
// By default it only answers requests from this machine (localhost). Only set
// ALLOW_REMOTE to true if you put your own authentication in front of it.
defined('ALLOW_REMOTE') || define('ALLOW_REMOTE', false);

// ---- Where the folder picker may look ----
// Empty array = the whole machine (all drives on Windows, "/" elsewhere).
// Otherwise only these folders (and everything under them) can be browsed
// and added, e.g. ['D:\\Videos', 'E:\\Photos'].
defined('BROWSE_ROOTS') || define('BROWSE_ROOTS', []);

// ---- Adding folders ----
// Upper bound on how many files one "add folder" request will register.
defined('MAX_SCAN_FILES') || define('MAX_SCAN_FILES', 50000);

// ---- Thumbnails (images only; needs PHP's GD extension, else originals are used) ----
defined('THUMB_SIZE') || define('THUMB_SIZE', 360);

// ---- Show internal error messages in API responses (development only) ----
defined('APP_DEBUG') || define('APP_DEBUG', false);

// ---- Video preview thumbnails (needs ffmpeg + ffprobe) ----
// Either on the PATH, or give the full path, e.g. 'C:\ffmpeg\bin\ffmpeg.exe'.
defined('FFMPEG_PATH')  || define('FFMPEG_PATH', 'ffmpeg');
defined('FFPROBE_PATH') || define('FFPROBE_PATH', 'ffprobe');
// Width in pixels of each preview frame (height follows the video's aspect ratio).
defined('VIDEO_THUMB_WIDTH') || define('VIDEO_THUMB_WIDTH', 320);
// How many frames of one video ffmpeg extracts at the same time.
defined('VIDEO_THUMB_PARALLEL') || define('VIDEO_THUMB_PARALLEL', 4);

// ---- Where generated thumbnails are stored (must be writable by the web server) ----
defined('THUMBS_DIR') || define('THUMBS_DIR', __DIR__ . '/storage/thumbs');

// ---- Permanent deletion ----
// Every file deleted from disk through the app is appended here (time, id, size, path): a record of
// what was removed, since it does not go to the Recycle Bin. Must be writable by the web server.
defined('DELETE_LOG') || define('DELETE_LOG', __DIR__ . '/storage/deleted.log');

// ---- Actor photos (uploaded from the Actors page; must be writable by the web server) ----
defined('PHOTOS_DIR') || define('PHOTOS_DIR', __DIR__ . '/storage/actors');

// ---- Playlists ----
// Where exported .m3u8 files are written ("Save file" / "Play in ..."). Point it at a folder you like, e.g. 'D:\Playlists'.
defined('PLAYLIST_DIR') || define('PLAYLIST_DIR', __DIR__ . '/storage/playlists');
// VLC and PotPlayer are found automatically in their usual install folders. Any other player: full path to its .exe.
defined('PLAYER_PATH') || define('PLAYER_PATH', '');
