<?php
define('APP_PAGE', true);
require_once __DIR__ . '/common/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf" content="<?= h(csrf_token()) ?>">
	<title>Actors - Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<span class="crumb">Actors</span>
	<span class="spacer"></span>
	<a class="btn" href="./">&larr; Library</a>
	<a class="btn" href="manage.php">Manage tags</a>
</header>

<div class="actors-page">
	<div class="a-toolbar">
		<input id="a-q" type="search" placeholder="Search name, full name, notes, custom fields..." autocomplete="off">
		<select id="a-cat" title="Which category to show (Actors by default)"></select>
		<select id="a-sort" title="Sort">
			<option value="name_asc">Name A-Z</option>
			<option value="name_desc">Name Z-A</option>
			<option value="rating_desc">Highest rated</option>
			<option value="rating_asc">Lowest rated</option>
			<option value="files_desc">Most files</option>
			<option value="files_asc">Fewest files</option>
			<option value="age_asc">Youngest first</option>
			<option value="age_desc">Oldest first</option>
			<option value="added_desc">Newest added</option>
			<option value="added_asc">Oldest added</option>
		</select>
		<select id="a-min" title="Minimum star rating">
			<option value="0">Any rating</option>
			<option value="5">5 stars</option>
			<option value="4">4 stars and up</option>
			<option value="3">3 stars and up</option>
			<option value="2">2 stars and up</option>
			<option value="1">1 star and up</option>
			<option value="-1">Not rated</option>
		</select>
		<select id="a-list" title="Only actors on this talent list"></select>
		<span class="spacer"></span>
		<button id="a-fields" class="btn" type="button">Custom fields...</button>
		<button id="a-lists" class="btn" type="button">Talent lists...</button>
	</div>

	<form id="a-create" class="a-create">
		<input id="a-new" type="text" placeholder="New actor name (several at once: separate with commas)" maxlength="2000" autocomplete="off">
		<button class="btn primary" type="submit">Add actor</button>
	</form>

	<div id="a-bulk" class="bulkbar" hidden>
		<strong id="a-bulk-count"></strong>
		<button id="a-bulk-all" class="btn small" type="button">Select all shown</button>
		<button id="a-bulk-none" class="btn small" type="button">Clear</button>
		<span class="sep"></span>
		<select id="a-bulk-list" title="Talent list"></select>
		<button id="a-bulk-add" class="btn small" type="button">Add to list</button>
		<button id="a-bulk-remove" class="btn small" type="button">Remove from list</button>
		<span class="sep"></span>
		<button id="a-bulk-delete" class="btn small danger" type="button">Delete selected</button>
	</div>

	<div id="a-status" class="a-status muted"></div>
	<div class="a-head"><span></span><span>Actor</span><span>Age</span><span>Rating</span><span>Files</span><span>Lists</span><span></span></div>
	<div id="a-rows" class="a-rows"></div>
	<div class="more-wrap a-more"><button id="a-more" class="btn" type="button" hidden>Load more</button></div>
</div>

<!-- Profile -->
<div id="profile" class="modal picker" hidden>
	<form id="p-form" class="dialog profile-dialog" role="dialog" aria-labelledby="p-title">
		<div class="dialog-head">
			<h2 id="p-title">Actor</h2>
			<button class="close-x" id="p-close" type="button" aria-label="Close">&times;</button>
		</div>
		<div class="dialog-scroll">
			<div class="f-row top"><span>Photo</span>
				<div class="photo-box">
					<div id="p-photo" class="photo" title="Drop an image here"></div>
					<div class="photo-actions">
						<div><button id="p-upload" class="btn small" type="button">Upload photo...</button> <button id="p-photo-remove" class="btn small" type="button">Remove</button></div>
						<span class="muted small">JPG, PNG, WebP or GIF, up to 5 MB. Or drop an image on the picture. Saved straight away.</span>
						<input id="p-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
					</div>
				</div>
			</div>
			<label class="f-row"><span>Name <em>(the tag)</em></span><input id="p-name" type="text" maxlength="100" required></label>
			<label class="f-row"><span>Full name</span><input id="p-full" type="text" maxlength="150" autocomplete="off"></label>
			<div class="f-row"><span>Date of birth</span>
				<div class="f-inline"><input id="p-dob" type="date" min="1900-01-01"><span id="p-age" class="muted"></span></div></div>
			<div class="f-row"><span>Rating</span>
				<div class="f-inline"><span id="p-stars" class="stars big"></span><button id="p-clear-rating" class="btn small" type="button">Clear</button></div></div>
			<label class="f-row top"><span>Notes</span><textarea id="p-notes" rows="3" maxlength="5000"></textarea></label>
			<div id="p-fields"></div>
			<div class="f-row top"><span>Talent lists</span>
				<div>
					<div id="p-lists" class="chip-list"></div>
					<div class="f-inline"><input id="p-newlist" type="text" maxlength="100" placeholder="New list..."><button id="p-addlist" class="btn small" type="button">Add list</button></div>
				</div>
			</div>
			<div class="f-row"><span>Files</span><a id="p-files" href="#">Show the files</a></div>
		</div>
		<div class="picker-actions">
			<button class="btn danger" id="p-delete" type="button" style="margin-right:auto">Delete actor</button>
			<button class="btn" id="p-cancel" type="button">Cancel</button>
			<button class="btn primary" type="submit">Save</button>
		</div>
	</form>
</div>

<!-- Custom fields / talent lists managers -->
<div id="mgr" class="modal picker" hidden>
	<div class="dialog mgr-dialog" role="dialog" aria-labelledby="m-title">
		<div class="dialog-head"><h2 id="m-title"></h2><button class="close-x" id="m-close" type="button" aria-label="Close">&times;</button></div>
		<p id="m-hint" class="hint"></p>
		<div id="m-list" class="m-list"></div>
		<form id="m-form" class="inline-form">
			<input id="m-name" type="text" maxlength="100" required>
			<label id="m-long-wrap" class="check small" hidden><input type="checkbox" id="m-long"> multi-line</label>
			<button class="btn primary" type="submit">Add</button>
		</form>
	</div>
</div>

<div id="toast" class="toast" hidden></div>
<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/actors.js') ?>"></script>
</body>
</html>
