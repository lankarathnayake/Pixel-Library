<?php
/**
 * GET file.php?id=N   Streams a registered file. Only files that are in the
 * library (looked up by id) can be served - never an arbitrary path.
 */
require_once __DIR__ . '/common/bootstrap.php';

$row = Library::find((int) ($_GET['id'] ?? 0));
$kind = $row ? MediaTypes::forExisting($row['path'], $row['type']) : null;
if ($row === null || $kind === null) {
	http_response_code(404);
	exit('Not found.');
}
if (!is_file($row['path'])) {
	Library::markMissing((int) $row['id']);
	http_response_code(404);
	exit('The file is no longer at its registered location.');
}

FileStreamer::send($row['path'], $kind['mime']);
