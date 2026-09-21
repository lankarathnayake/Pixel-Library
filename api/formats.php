<?php
/**
 * The file formats the app registers (Settings page).
 * GET  api/formats.php    the active formats (with how many library files use each) + one-click suggestions
 * POST api/formats.php    {action: add {ext, type, mime?} | remove {ext, remove_files?} | reset}
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		return ['formats' => MediaTypes::all(), 'suggestions' => MediaTypes::suggestions()];
	}
	$in = json_input();
	switch ($in['action'] ?? '') {
		case 'add':
			return ['format' => MediaTypes::add((string) ($in['ext'] ?? ''), (string) ($in['type'] ?? ''), (string) ($in['mime'] ?? ''))];
		case 'remove':
			return ['removed_files' => MediaTypes::remove((string) ($in['ext'] ?? ''), !empty($in['remove_files']))];
		case 'reset':
			MediaTypes::restoreDefaults();
			return [];
	}
	throw new ApiException('Unknown action.');
});
