<?php
/**
 * GET  api/library.php               search/list media (q, type, terms[], untagged, missing, sort, page | offset+limit)
 * GET  api/library.php?id=N          one media item's details
 * GET  api/library.php?ids=1,2,3     several items, in the list shape (to refresh tiles)
 * GET  api/library.php?thumbs=status video preview queue counts + whether ffmpeg is available
 * POST api/library.php  {action: add | remove | delete_files | check | thumbs_run | thumbs_queue | thumbs_retry}
 *   remove {ids}                       takes files out of the library only (files stay on disk)
 *   delete_files {ids, confirm:"DELETE"}  PERMANENTLY deletes the files from disk + their entries/tags/previews
 *   thumbs_queue {ids, force}  queue specific videos; thumbs_run {ids?} works only on those (else the whole queue)
 */
require_once __DIR__ . '/../common/bootstrap.php';

function thumbs_status(): array {
	return ['ffmpeg' => Ffmpeg::available()] + VideoThumbs::stats();
}

/** Media ids from a request, validated and capped. */
function thumbs_ids($raw): array {
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw), fn($i) => $i > 0)));
	if (count($ids) > 20000) {
		throw new ApiException('Select at most 20,000 videos at a time.');
	}
	return $ids;
}

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		if (isset($_GET['thumbs'])) {
			return thumbs_status();
		}
		if (isset($_GET['ids'])) {
			$ids = array_slice(array_map('intval', explode(',', (string) $_GET['ids'])), 0, 200);
			return ['items' => Library::byIds($ids)];
		}
		if (isset($_GET['id'])) {
			$detail = Library::detail((int) $_GET['id']);
			if ($detail === null) {
				throw new ApiException('That file is not in the library.', 404);
			}
			return ['item' => $detail];
		}
		// paging: ?page=N (60 per page) or, for the sliding window, ?offset=ROW&limit=COUNT (limit capped at 200)
		return Library::search($_GET, (int) ($_GET['page'] ?? 1), (int) ($_GET['limit'] ?? 60), isset($_GET['offset']) ? (int) $_GET['offset'] : null);
	}

	$in = json_input();
	switch ($in['action'] ?? '') {
		case 'add':
			$files = array_values(array_filter((array) ($in['files'] ?? []), 'is_string'));
			$folders = array_values(array_filter((array) ($in['folders'] ?? []), 'is_string'));
			if (!$files && !$folders) {
				throw new ApiException('Select at least one file or folder.');
			}
			$named = [];
			foreach ((array) ($in['terms'] ?? []) as $categoryId => $names) {
				$named[(int) $categoryId] = array_values(array_filter((array) $names, 'is_string'));
			}
			$result = Library::add($files, $folders, !empty($in['recursive']), $named);
			Folders::registerMany($folders, !empty($in['recursive'])); // remembered, so "Rescan folders" can look for new files later
			return $result;

		case 'remove':
			return ['removed' => Library::remove((array) ($in['ids'] ?? []))];

		case 'check':
			return Library::checkMissing();

		case 'delete_files':
			// PERMANENT. The explicit confirm word stops a stray or replayed request from deleting anything.
			if (($in['confirm'] ?? '') !== 'DELETE') {
				throw new ApiException('Deleting files needs explicit confirmation.');
			}
			$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])), fn($i) => $i > 0)));
			if (!$ids) {
				throw new ApiException('Select at least one file.');
			}
			if (count($ids) > 5000) {
				throw new ApiException('Delete at most 5,000 files at a time.');
			}
			return Library::deleteFiles($ids);

		case 'thumbs_run':
			// One short slice of the queue per request, so the page can show progress and be stopped.
			if (!Ffmpeg::available()) {
				throw new ApiException('ffmpeg was not found. Run tools\\get-ffmpeg.ps1 (or start.bat and answer Yes), or set FFMPEG_PATH and FFPROBE_PATH in config.local.php.');
			}
			$seconds = max(1, min(25, (int) ($in['seconds'] ?? 10)));
			if (isset($in['ids'])) { // only these videos (the user's selection)
				$ids = thumbs_ids($in['ids']);
				return ['run' => VideoThumbs::run($seconds, null, $ids), 'remaining' => VideoThumbs::remaining($ids)] + thumbs_status();
			}
			return ['run' => VideoThumbs::run($seconds)] + thumbs_status();

		case 'thumbs_queue':
			// Put the given videos in the queue: failed ones, plus finished ones when force = regenerate.
			return ['queued' => VideoThumbs::queue(thumbs_ids($in['ids'] ?? []), !empty($in['force']))] + thumbs_status();

		case 'thumbs_retry':
			return ['requeued' => VideoThumbs::retryFailed()] + thumbs_status();
	}
	throw new ApiException('Unknown action.');
});
