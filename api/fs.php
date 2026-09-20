<?php
/**
 * GET api/fs.php?path=...   Lists a folder for the "Add files" picker.
 * An empty path lists the top-level places (drives on Windows).
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	$path = trim((string) ($_GET['path'] ?? ''));

	if ($path === '') {
		$entries = array_map(fn($r) => ['name' => $r, 'path' => $r, 'is_dir' => true], Paths::roots());
		return ['path' => '', 'parent' => null, 'entries' => $entries, 'truncated' => false];
	}

	$real = Paths::resolve($path);
	if ($real === null) {
		throw new ApiException('That folder does not exist.', 404);
	}
	if (is_file($real)) {
		$real = dirname($real);
	}
	if (!Paths::allowed($real)) {
		throw new ApiException('That folder is outside the allowed locations.', 403);
	}

	$parent = dirname($real);
	if ($parent === $real || !Paths::allowed($parent)) {
		$parent = ''; // at a root: "up" goes back to the list of drives
	}

	$list = Paths::listDir($real);
	return ['path' => $real, 'parent' => $parent, 'entries' => $list['entries'], 'truncated' => $list['truncated']];
});
