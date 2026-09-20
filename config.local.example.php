<?php
/**
 * Copy this file to config.local.php and change what you need.
 * config.local.php is git-ignored and loaded first by config.php, so
 * anything defined here overrides the defaults in config.php.
 * NEVER commit config.local.php.
 *
 * For the standalone setup (start.bat + SQLite) you need none of this except, perhaps, the time zone and ffmpeg.
 */

// ---- Timezone (PHP timezone identifier) ----
define('APP_TIMEZONE', 'UTC');

// ---- Video previews: full paths if ffmpeg/ffprobe are not on the PATH ----
// define('FFMPEG_PATH', 'C:\ffmpeg\bin\ffmpeg.exe');
// define('FFPROBE_PATH', 'C:\ffmpeg\bin\ffprobe.exe');

// ---- Optional: limit the folder picker to these folders ----
// define('BROWSE_ROOTS', ['D:\\Videos', 'E:\\Photos']);

// ---- Optional: keep the database somewhere else (default: storage/pixel-library.sqlite) ----
// define('DB_FILE', 'D:\\Library\\pixel-library.sqlite');

// ---- Optional: use MySQL / MariaDB instead of SQLite (load db/schema.mysql.sql first) ----
// define('DB_DRIVER', 'mysql');
// define('DB_HOST', 'localhost');
// define('DB_USER', 'pixel_library');
// define('DB_PASS', 'a-strong-password');
// define('DB_NAME', 'pixel_library');

// ---- Only when served from a sub-folder by Apache: its URL path scopes the session cookie ----
// define('APP_URL', 'http://localhost/Pixel-Library');

// ---- Development only: show internal error details in API responses ----
// define('APP_DEBUG', true);
