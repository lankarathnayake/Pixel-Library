<?php
/** GET photo.php?id=N   An actor's photo. Only the file recorded in the database can be served, never a path. */
require_once __DIR__ . '/common/bootstrap.php';

$photo = Actors::photoFile((int) ($_GET['id'] ?? 0));
if ($photo === null) {
	http_response_code(404);
	exit('No photo.');
}
FileStreamer::send($photo['path'], $photo['mime']);