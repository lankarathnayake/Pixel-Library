<?php
/**
 * GET  api/duplicates.php?status=1                       counts (candidates, not yet hashed, identical groups, wasted bytes)
 * GET  api/duplicates.php?mode=identical|samelength&offset=&limit=    the groups
 * POST api/duplicates.php {action: scan {seconds} | resolve {keep, others, mode: delete|remove, merge_tags, confirm}}
 *   resolve with mode=delete PERMANENTLY deletes files, so it needs confirm:"DELETE".
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		if (isset($_GET['status'])) {
			return Duplicates::status();
		}
		$mode = ($_GET['mode'] ?? 'identical') === 'samelength' ? 'samelength' : 'identical';
		return Duplicates::groups($mode, (int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 30)) + ['mode' => $mode];
	}
	$in = json_input();
	switch ($in['action'] ?? '') {
		case 'scan':
			return ['scan' => Duplicates::scan(max(1, min(25, (int) ($in['seconds'] ?? 10))))] + Duplicates::status();
		case 'resolve':
			$mode = ($in['mode'] ?? '') === 'delete' ? 'delete' : 'remove';
			if ($mode === 'delete' && ($in['confirm'] ?? '') !== 'DELETE') {
				throw new ApiException('Deleting files needs explicit confirmation.');
			}
			$others = array_map('intval', (array) ($in['others'] ?? []));
			if (!$others || count($others) > 200) {
				throw new ApiException('Choose the files to get rid of (up to 200 at a time).');
			}
			return Duplicates::resolve((int) ($in['keep'] ?? 0), $others, $mode, !isset($in['merge_tags']) || !empty($in['merge_tags']));
	}
	throw new ApiException('Unknown action.');
});
