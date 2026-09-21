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
	<title>Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<input id="q" type="search" placeholder="Search names and folders..." autocomplete="off">
	<select id="type" title="Type">
		<option value="">All types</option>
		<option value="image">Images</option>
		<option value="video">Videos</option>
	</select>
	<select id="sort" title="Sort">
		<option value="added_desc">Newest added</option>
		<option value="added_asc">Oldest added</option>
		<option value="name_asc">Name A-Z</option>
		<option value="name_desc">Name Z-A</option>
		<option value="mtime_desc">File date</option>
		<option value="duration_desc">Longest video</option>
		<option value="duration_asc">Shortest video</option>
		<option value="size_desc">Largest</option>
		<option value="size_asc">Smallest</option>
	</select>
	<select id="cols" title="How many cards in one row (the number of files loaded does not change)">
		<option value="0">Auto per row</option>
		<option value="1">1 per row</option>
		<option value="2">2 per row</option>
		<option value="3">3 per row</option>
		<option value="4">4 per row</option>
		<option value="5">5 per row</option>
		<option value="6">6 per row</option>
		<option value="7">7 per row</option>
		<option value="8">8 per row</option>
		<option value="9">9 per row</option>
		<option value="10">10 per row</option>
	</select>
	<span class="spacer"></span>
	<button id="btn-add" class="btn primary" type="button">+ Add files</button>
	<button id="btn-settings" class="btn" type="button" title="How many files are loaded and shown at once">Display</button>
	<a class="btn" href="playlists.php">Playlists</a>
	<a class="btn" href="actors.php">Actors</a>
	<a class="btn" href="manage.php">Manage tags</a>
	<a class="btn" href="settings.php">Settings</a>
</header>

<div class="layout">
	<aside id="sidebar">
		<div class="side-block">
			<label class="check"><input type="checkbox" id="f-untagged"> Untagged only</label>
			<label class="check"><input type="checkbox" id="f-missing"> Missing files only</label>
			<label class="check" title="Videos that have no hover previews yet (pending or failed)"><input type="checkbox" id="f-nothumbs"> Videos without previews</label>
			<label class="f-select"><span>Video length</span>
				<select id="f-dur">
					<option value="">Any length</option>
					<option value="0-300">Under 5 min</option>
					<option value="300-600">5 - 10 min</option>
					<option value="600-1200">10 - 20 min</option>
					<option value="1200-1800">20 - 30 min</option>
					<option value="1800-3600">30 - 60 min</option>
					<option value="3600-">1 hour or more</option>
					<option value="custom">Custom...</option>
				</select></label>
			<div id="f-dur-custom" class="f-custom" hidden>
				<input id="f-dmin" type="number" min="0" step="1" placeholder="from"> <span>to</span> <input id="f-dmax" type="number" min="0" step="1" placeholder="under"> <span>min</span>
			</div>
			<button id="btn-clear" class="btn small" type="button">Clear filters</button>
		</div>
		<div id="facets"></div>
		<div class="side-block" id="tb" hidden>
			<strong class="side-title">Video previews</strong>
			<div id="tb-text" class="small muted"></div>
			<div class="progress" id="tb-progress"><i id="tb-bar"></i></div>
			<button id="tb-run" class="btn small" type="button" hidden></button>
			<button id="tb-retry" class="btn small" type="button" hidden></button>
		</div>
		<div class="side-block">
			<strong class="side-title">Tools</strong>
			<button id="btn-folders" class="btn small" type="button" title="The folders you added: look for new files in them">Folders / rescan...</button>
			<a class="btn small" href="duplicates.php" title="Find identical files and files with the same length">Find duplicates</a>
			<button id="btn-check" class="btn small" type="button" title="Re-check every file against the disk">Check for missing files</button>
		</div>
	</aside>

	<main>
		<div id="status" class="status"></div>

		<div id="bulkbar" class="bulkbar" hidden>
			<strong id="bulk-count" title="Select mode: click tiles to add or remove them (shift-click = range). Double-click opens a file. Esc clears the selection."></strong>
			<button id="bulk-all" class="btn small" type="button">Select all shown</button>
			<button id="bulk-none" class="btn small" type="button">Clear</button>
			<span class="sep"></span>
			<select id="bulk-cat" title="Category"></select>
			<input id="bulk-term" type="text" placeholder="names, comma separated  (e.g. beach, sunset, Actors: Jane, Joe)" title="Several at once: separate with commas. To use another category, write its name and a colon in front - it then applies to the names after it." autocomplete="off">
			<button id="bulk-apply" class="btn small primary" type="button">Apply</button>
			<button id="bulk-unapply" class="btn small" type="button">Remove</button>
			<span class="sep"></span>
			<select id="bulk-pl" title="Playlist"></select>
			<button id="bulk-pl-add" class="btn small" type="button">Add to playlist</button>
			<span id="bulk-play" class="bulk-play"></span>
			<span class="sep"></span>
			<button id="bulk-thumbs" class="btn small" type="button" hidden>Generate previews</button>
			<button id="bulk-delete" class="btn small danger" type="button" title="Takes them out of the library only - the files stay on your computer">Remove from library</button>
			<button id="bulk-purge" class="btn small danger" type="button" title="PERMANENTLY deletes the files from your computer">Delete from disk</button>
		</div>

		<div id="grid" class="grid"></div>
	</main>
</div>

<!-- Viewer -->
<div id="viewer" class="modal viewer" hidden>
	<div class="viewer-stage" id="v-stage"></div>
	<button class="nav prev" id="v-prev" type="button" aria-label="Previous">&#10094;</button>
	<button class="nav next" id="v-next" type="button" aria-label="Next">&#10095;</button>
	<button class="close" id="v-close" type="button" aria-label="Close">&times;</button>
	<div class="viewer-panel" id="v-panel"></div>
</div>

<!-- Add files picker -->
<div id="picker" class="modal picker" hidden>
	<div class="dialog">
		<div class="dialog-head">
			<h2>Add files or folders</h2>
			<button class="close-x" id="p-close" type="button" aria-label="Close">&times;</button>
		</div>
		<p class="hint">Browse this computer and tick the files or folders to add. Nothing is copied or uploaded &mdash; the app just remembers where each file is.</p>
		<form id="p-form" class="pathbar">
			<button type="button" class="btn small" id="p-up" title="Up one folder">&#8593; Up</button>
			<button type="button" class="btn small" id="p-roots" title="Drives / top level">Drives</button>
			<input id="p-path" type="text" placeholder="Type or paste a folder path, then press Enter" spellcheck="false">
			<button class="btn small" type="submit">Go</button>
		</form>
		<div class="picker-list" id="p-list"></div>
		<div class="picker-foot">
			<div class="picker-opts">
				<label class="check"><input type="checkbox" id="p-recursive" checked> Include sub-folders of ticked folders</label>
				<details id="p-terms-wrap">
					<summary>Also tag what I add (optional)</summary>
					<div id="p-terms" class="terms-inputs"></div>
				</details>
			</div>
			<div class="picker-actions">
				<span id="p-summary" class="muted">Nothing selected</span>
				<button class="btn" id="p-cancel" type="button">Cancel</button>
				<button class="btn primary" id="p-add" type="button" disabled>Add to library</button>
			</div>
		</div>
	</div>
</div>

<!-- Folders / rescan -->
<div id="folders" class="modal picker" hidden>
	<div class="dialog folders-dialog" role="dialog" aria-labelledby="fo-title">
		<div class="dialog-head"><h2 id="fo-title">Folders</h2><button class="close-x" id="fo-close" type="button" aria-label="Close">&times;</button></div>
		<p class="hint">Folders you add with "Add files" are remembered here. <strong>Rescan</strong> looks in them for files that are new since last time and adds those. Nothing is ever removed by a rescan.</p>
		<div class="dialog-scroll"><div id="fo-list" class="fo-list"></div>
			<details id="fo-sugg"><summary>Suggestions: folders where your files already are</summary><div id="fo-sugg-list" class="fo-list"></div></details>
		</div>
		<form id="fo-form" class="inline-form">
			<input id="fo-path" type="text" placeholder="Type or paste a folder path to remember..." spellcheck="false">
			<label class="check small"><input type="checkbox" id="fo-rec" checked> sub-folders</label>
			<button class="btn" type="submit">Remember</button>
		</form>
		<div class="picker-actions"><button class="btn primary" id="fo-scan-all" type="button">Rescan all folders</button></div>
	</div>
</div>

<!-- Display settings -->
<div id="settings" class="modal picker" hidden>
	<form id="set-form" class="dialog settings-dialog" role="dialog" aria-labelledby="set-title">
		<div class="dialog-head">
			<h2 id="set-title">Display settings</h2>
			<button class="close-x" id="set-close" type="button" aria-label="Close">&times;</button>
		</div>
		<p class="hint">To stay fast on big libraries only a limited number of files is kept on the page. More load as you scroll; the ones furthest away are dropped, and reload when you scroll back.</p>
		<label class="set-row"><span>Files loaded per scroll</span><input id="set-page" type="number" min="10" max="200" step="10" required></label>
		<label class="set-row"><span>Most files shown at once</span><input id="set-max" type="number" min="20" max="1000" step="10" required></label>
		<label class="check set-check"><input type="checkbox" id="set-whole"><span>Scrollbar covers the whole list</span></label>
		<p class="muted small set-sub">Off (default): the scrollbar covers only the files currently loaded, so it grows and shrinks as you scroll. On: it covers every file, so you can drag it to any spot; the space beyond the loaded files is empty until you get there.</p>
		<p id="set-note" class="muted small"></p>
		<div class="picker-actions">
			<button class="btn" id="set-reset" type="button" style="margin-right:auto">Reset to defaults</button>
			<button class="btn" id="set-cancel" type="button">Cancel</button>
			<button class="btn primary" id="set-save" type="submit">Save</button>
		</div>
	</form>
</div>

<!-- Permanent-deletion confirmation -->
<div id="del" class="modal picker" hidden>
	<div class="dialog danger-dialog" role="alertdialog" aria-labelledby="del-title" aria-describedby="del-body">
		<div class="dialog-head"><h2 id="del-title">Delete permanently?</h2></div>
		<div id="del-body"></div>
		<label id="del-phrase-wrap" class="del-phrase" hidden>
			<span>Type <strong>DELETE</strong> to confirm</span>
			<input id="del-phrase" type="text" autocomplete="off" spellcheck="false">
		</label>
		<div class="picker-actions">
			<button class="btn" id="del-cancel" type="button">Cancel</button>
			<button class="btn danger-solid" id="del-go" type="button" disabled>Delete permanently</button>
		</div>
	</div>
</div>

<div id="grid-loader" class="pill spin" hidden>Loading...</div>
<div id="datalists"></div>
<div id="toast" class="toast" hidden></div>

<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
