<?php
/**
 * GET  api/folders.php                      the remembered folders (+ suggestions from where your files already are)
 * POST api/folders.php {action: register {path, recursive} | scan {id} | scan_all | recursive {id, recursive} | forget {id}}
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		return ['folders' => Folders::all(), 'suggestions' => Folders::suggest()];
	}
	$in = json_input();
	switch ($in['action'] ?? '') {
		case 'register':
			return ['id' => Folders::register((string) ($in['path'] ?? ''), !isset($in['recursive']) || !empty($in['recursive']))];
		case 'scan':
			return Folders::scan((int) ($in['id'] ?? 0));
		case 'scan_all':
			return Folders::scanAll();
		case 'recursive':
			Folders::setRecursive((int) ($in['id'] ?? 0), !empty($in['recursive']));
			return [];
		case 'forget':
			Folders::forget((int) ($in['id'] ?? 0));
			return [];
	}
	throw new ApiException('Unknown action.');
});
