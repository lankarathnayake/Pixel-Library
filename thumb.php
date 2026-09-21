<?php
/**
 * GET thumb.php?id=N        Thumbnail for an image (cached JPEG). Falls back to the
 *                           original file when GD is not available or it can't be decoded.
 * GET thumb.php?id=N&n=I    Preview frame number I (0-based) of a video, as recorded in
 *                           the media_thumb table.
 */
require_once __DIR__ . '/common/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);

if (isset($_GET['n'])) {
	$file = VideoThumbs::fileFor($id, (int) $_GET['n']);
	if ($file === null) {
		http_response_code(404);
		exit('Not found.');
	}
	FileStreamer::send($file, 'image/jpeg', true); // frames can be regenerated under the same URL
}

$row = Library::find($id);
$kind = $row ? MediaTypes::forExisting($row['path'], $row['type']) : null;
if ($row === null || $kind === null || $kind['type'] !== 'image') {
	http_response_code(404);
	exit('Not found.');
}
if (!is_file($row['path'])) {
	Library::markMissing((int) $row['id']);
	http_response_code(404);
	exit('The file is no longer at its registered location.');
}

$thumb = Thumbnailer::forMedia($row);
if ($thumb !== null) {
	FileStreamer::send($thumb, 'image/jpeg');
}
FileStreamer::send($row['path'], $kind['mime']);
