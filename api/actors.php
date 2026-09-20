<?php
/**
 * GET  api/actors.php?meta=1                     categories, custom fields, talent lists
 * GET  api/actors.php?cat=ID&q=&sort=&min_rating=&list=&offset=&limit=    the actors of a category
 * GET  api/actors.php?id=N                       one full profile
 * GET  api/actors.php?list_members=ID            term ids on a talent list
 * POST api/actors.php  {action: create | save | rate | delete | assign_list |
 *                               field_add | field_rename | field_delete | list_add | list_rename | list_delete}
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		if (isset($_GET['meta'])) {
			return Actors::meta();
		}
		if (isset($_GET['id'])) {
			$p = Actors::profile((int) $_GET['id']);
			if ($p === null) {
				throw new ApiException('That actor does not exist.', 404);
			}
			return ['actor' => $p];
		}
		if (isset($_GET['list_members'])) {
			return ['term_ids' => Actors::listMembers((int) $_GET['list_members'])];
		}
		$cat = (int) ($_GET['cat'] ?? 0);
		if ($cat <= 0) {
			$cat = (int) (Actors::meta()['default_category'] ?? 0);
		}
		return Actors::search($cat, $_GET, (int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 60)) + ['category_id' => $cat];
	}

	$in = json_input();
	$ids = fn($key) => array_map('intval', (array) ($in[$key] ?? []));

	switch ($in['action'] ?? '') {
		case 'create':
			$names = is_array($in['names'] ?? null) ? $in['names'] : [$in['names'] ?? ''];
			return Actors::create((int) ($in['category_id'] ?? 0), array_values(array_filter($names, 'is_string')));
		case 'save':
			return ['actor' => Actors::save((int) ($in['id'] ?? 0), $in)];
		case 'rate':
			Actors::rate((int) ($in['id'] ?? 0), $in['rating'] ?? null);
			return [];
		case 'delete':
			if (count($ids('ids')) > 500) {
				throw new ApiException('Delete at most 500 actors at a time.');
			}
			return Actors::delete($ids('ids'));
		case 'assign_list':
			Actors::assignToList((int) ($in['list_id'] ?? 0), $ids('ids'), !empty($in['add']));
			return [];

		case 'field_add':
			return ['id' => Actors::addField($in['name'] ?? '', !empty($in['is_long']))];
		case 'field_rename':
			Actors::renameField((int) ($in['id'] ?? 0), $in['name'] ?? '');
			return [];
		case 'field_delete':
			Actors::deleteField((int) ($in['id'] ?? 0));
			return [];

		case 'list_add':
			return ['id' => Actors::addList($in['name'] ?? '')];
		case 'list_rename':
			Actors::renameList((int) ($in['id'] ?? 0), $in['name'] ?? '');
			return [];
		case 'list_delete':
			Actors::deleteList((int) ($in['id'] ?? 0));
			return [];
	}
	throw new ApiException('Unknown action.');
});
