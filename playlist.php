<?php
/**
 * GET playlist.php?id=N            The playlist as .m3u8 text (absolute file paths). Open this link in VLC / PotPlayer
 *                                  ("Open URL" / "Open network stream"), or add &download=1 to save it as a file.
 */
require_once __DIR__ . '/common/bootstrap.php';

try {
	$p = Playlists::get((int) ($_GET['id'] ?? 0));
} catch (ApiException $e) {
	http_response_code(404);
	exit('No such playlist.');
}
header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . Playlists::fileName($p['name']) . '"');
header('Cache-Control: no-store');
echo Playlists::m3u((int) $p['id']);
