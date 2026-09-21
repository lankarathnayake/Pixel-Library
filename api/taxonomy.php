<?php
/**
 * GET  api/taxonomy.php    all categories with their terms (+ usage counts)
 * POST api/taxonomy.php    {action: category_add | category_rename | category_delete |
 *                                   term_add | term_rename | term_delete | term_move {ids, category_id, merge_conflicts?} |
 *                                   term_merge {target_id, source_ids} |
 *                                   assign | assign_named | unassign}
 */
require_once __DIR__ . '/../common/bootstrap.php';

api_run(function () {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
		return ['categories' => Taxonomy::tree()];
	}

	$in = json_input();
	$ids = fn($key) => array_map('intval', (array) ($in[$key] ?? []));

	switch ($in['action'] ?? '') {
		case 'category_add':
			return ['id' => Taxonomy::addCategory($in['name'] ?? '')];
		case 'category_rename':
			Taxonomy::renameCategory((int) ($in['id'] ?? 0), $in['name'] ?? '');
			return [];
		case 'category_delete':
			Taxonomy::deleteCategory((int) ($in['id'] ?? 0));
			return [];

		case 'term_add':
			return ['id' => Taxonomy::ensureTerm((int) ($in['category_id'] ?? 0), $in['name'] ?? '')];
		case 'term_rename':
			Taxonomy::renameTerm((int) ($in['id'] ?? 0), $in['name'] ?? '');
			return [];
		case 'term_delete':
			Taxonomy::deleteTerm((int) ($in['id'] ?? 0));
			return [];

		case 'term_move':
			return Taxonomy::moveTerms($ids('ids'), (int) ($in['category_id'] ?? 0), !empty($in['merge_conflicts']));
		case 'term_merge':
			return Taxonomy::mergeTerms((int) ($in['target_id'] ?? 0), $ids('source_ids'));

		case 'assign':
			Taxonomy::assign($ids('media_ids'), $ids('term_ids'));
			return [];
		case 'assign_named':
			$named = [];
			foreach ((array) ($in['terms'] ?? []) as $categoryId => $names) {
				$named[(int) $categoryId] = array_values(array_filter((array) $names, 'is_string'));
			}
			Taxonomy::assignNamed($ids('media_ids'), $named);
			return [];
		case 'unassign':
			Taxonomy::unassign($ids('media_ids'), $ids('term_ids'));
			return [];
	}
	throw new ApiException('Unknown action.');
});
