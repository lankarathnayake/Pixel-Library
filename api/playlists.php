<?php
/**
 * GET  api/playlists.php                 all playlists (+ the players found on this PC)
 * GET  api/playlists.php?id=N            one playlist with its items in order
 * POST api/playlists.php {action: create {name, media_ids?} | rename {id, name} | delete {id} | add {id, media_ids} |
 *                                 remove {id, media_ids} | reorder {id, ordered} | sort {id, by, dir} | save_file {id} | play {id, player} |
 *                                 play_files {media_ids, player}  (one-off: temp playlist, overwritten next time)}
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		if (isset($_GET['id'])) {
			return ['playlist' => Playlists::get((int) $_GET['id'])];
		}
		return ['playlists' => Playlists::all(), 'players' => Playlists::players()];
	}
	$in = json_input();
	$ids = fn($key) => array_map('intval', (array) ($in[$key] ?? []));
	$id = (int) ($in['id'] ?? 0);
	switch ($in['action'] ?? '') {
		case 'create':
			return ['id' => Playlists::create($in['name'] ?? '', $ids('media_ids'))];
		case 'rename':
			Playlists::rename($id, $in['name'] ?? '');
			return [];
		case 'delete':
			Playlists::delete($id);
			return [];
		case 'add':
			return ['added' => Playlists::add($id, $ids('media_ids'))];
		case 'remove':
			Playlists::remove($id, $ids('media_ids'));
			return [];
		case 'reorder':
			Playlists::reorder($id, $ids('ordered'));
			return [];
		case 'sort':
			Playlists::sortBy($id, (string) ($in['by'] ?? ''), ($in['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
			return [];
		case 'save_file':
			return ['file' => Playlists::saveFile($id)];
		case 'play':
			return Playlists::play($id, (string) ($in['player'] ?? ''));
		case 'play_files':
			return Playlists::playFiles($ids('media_ids'), (string) ($in['player'] ?? ''));
	}
	throw new ApiException('Unknown action.');
});
