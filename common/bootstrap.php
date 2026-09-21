<?php
/**
 * Included at the top of every page and endpoint.
 *  - refuses non-local requests (unless ALLOW_REMOTE)
 *  - starts a session only where needed (pages, and any POST) so that
 *    thumbnail / video requests never queue behind a session lock
 *  - enforces the CSRF token on every POST
 *  - provides the small JSON/HTML helpers the endpoints share
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/autoload.php';

function guard_local_only() {
	if (ALLOW_REMOTE) {
		return;
	}
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	// Host check as well as IP: stops DNS-rebinding pages from reaching a localhost-only app.
	$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
	if (!in_array($ip, ['127.0.0.1', '::1'], true) || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
		http_response_code(403);
		exit('Pixel Library only accepts requests from this machine (open it via http://localhost/). See ALLOW_REMOTE in config.php.');
	}
}

function start_app_session() {
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}
	$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	// Scope the cookie to this app's URL path so it isn't sent to sibling apps on the same host.
	$path = parse_url(APP_URL, PHP_URL_PATH) ?: '/';

	session_name(SESSION_NAME);
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => $path,
		'secure' => $secure,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
	session_start();
}

function csrf_token() {
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function csrf_valid() {
	$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
	$expected = $_SESSION['csrf_token'] ?? '';
	return $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
}

/**
 * URL of a file under assets/ stamped with its modification time (assets/app.js?v=1758362183). A browser can
 * then never keep using an old copy after the file changes: the URL itself changes.
 */
function asset(string $path): string {
	$mtime = @filemtime(__DIR__ . '/../' . $path);
	return h($path . ($mtime ? '?v=' . $mtime : ''));
}

function h($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $status = 200) {
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

/** Decoded JSON request body (POST endpoints) as an array. */
function json_input(): array {
	$raw = file_get_contents('php://input');
	$data = json_decode($raw === false ? '' : $raw, true);
	return is_array($data) ? $data : [];
}

/**
 * Runs an endpoint handler. The handler returns an array that is sent as
 * {"success": true, ...}; ApiException becomes {"success": false, "message"}.
 */
function api_run(callable $handler) {
	try {
		$data = $handler();
		json_out(['success' => true] + (is_array($data) ? $data : []));
	} catch (ApiException $e) {
		json_out(['success' => false, 'message' => $e->getMessage()] + $e->data, $e->getCode() ?: 400);
	} catch (Throwable $e) {
		error_log('pixel-library: ' . $e);
		json_out(['success' => false, 'message' => APP_DEBUG ? $e->getMessage() : 'Server error.'], 500);
	}
}

guard_local_only();

if ($_SERVER['REQUEST_METHOD'] === 'POST' || defined('APP_PAGE')) {
	start_app_session();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
	json_out(['success' => false, 'message' => 'Your session token is missing or expired. Reload the page and try again.'], 403);
}
