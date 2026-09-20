<?php
/**
 * Router for PHP's built-in web server (what start.bat runs):   php -S 127.0.0.1:8686 router.php
 *
 * It does what .htaccess does under Apache: only the pages, the API and the assets can be requested from a
 * browser. Everything else in this folder (core/, common/, db/, storage/, tests/, bin/, config files, the
 * database, notes...) answers 404. It is an allow-list, judged on the file's real path, so odd spellings
 * (upper case, "..", short 8.3 names, alternate data streams) cannot get around it.
 */

if (PHP_SAPI !== 'cli-server') {
	http_response_code(404);
	exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($uriPath === '' || $uriPath === '/') {
	return false; // the server serves index.php
}
if (strpbrk($uriPath, "\0:") !== false) { // NUL bytes, drive letters, NTFS streams (file.php::$DATA)
	http_response_code(404);
	exit;
}

$root = realpath(__DIR__);
$real = realpath($root . $uriPath);
$ok = false;
if ($real !== false && is_file($real) && strncasecmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0) {
	$rel = str_replace('\\', '/', substr($real, strlen($root) + 1));
	$ok = preg_match('#^(assets/[^/].*|api/[^/]+\.php|[^/]+\.php)$#i', $rel) === 1
		&& !preg_match('#^(config[^/]*|router)\.php$#i', $rel);
	// Only ever run the file that was asked for: "/x.php/extra" style PATH_INFO requests are not used by this app.
	if ($ok && strcasecmp(str_replace('\\', '/', $root) . '/' . $rel, str_replace('\\', '/', $root) . $uriPath) !== 0) {
		$ok = false;
	}
}
if (!$ok) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Not found.';
	exit;
}
return false; // an allowed page, API endpoint or asset: let the server handle it
