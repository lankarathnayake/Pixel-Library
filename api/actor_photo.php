<?php
/**
 * POST api/actor_photo.php   multipart form: id, photo (the image file)   -> sets the actor's photo
 * POST api/actor_photo.php   form: id, action=remove                      -> removes it
 * (The CSRF token travels in the X-CSRF-Token header, like every other write.)
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new ApiException('POST only.', 405);
	}
	$id = (int) ($_POST['id'] ?? 0);
	if (($_POST['action'] ?? '') === 'remove') {
		Actors::removePhoto($id);
		return ['photo' => null];
	}
	$f = $_FILES['photo'] ?? null;
	if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
		$code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
		throw new ApiException(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The photo is too large (max 5 MB).' : 'No photo was received.');
	}
	if (!is_uploaded_file($f['tmp_name'])) {
		throw new ApiException('Invalid upload.');
	}
	return ['photo' => Actors::setPhoto($id, $f['tmp_name'])];
});