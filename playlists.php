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
	<title>Playlists - Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<span class="crumb">Playlists</span>
	<span class="spacer"></span>
	<a class="btn" href="actors.php">Actors</a>
	<a class="btn" href="duplicates.php">Find duplicates</a>
	<a class="btn" href="settings.php">Settings</a>
	<a class="btn" href="./">&larr; Library</a>
</header>

<div class="pl-page">
	<aside class="pl-side">
		<form id="pl-new" class="inline-form">
			<input id="pl-new-name" type="text" placeholder="New playlist name" maxlength="100" required>
			<button class="btn primary" type="submit">Create</button>
		</form>
		<div id="pl-list" class="pl-list"></div>
		<p class="muted small">Add files from the library: tick them, then "Add to playlist" in the bar at the top of the grid (or from a file's side panel).</p>
	</aside>

	<main class="pl-main">
		<div id="pl-empty" class="empty"><p>Pick a playlist on the left, or create one.</p></div>
		<section id="pl-view" hidden>
			<div class="pl-head">
				<h2 id="pl-title"></h2>
				<button id="pl-rename" class="btn small" type="button">Rename</button>
				<button id="pl-delete" class="btn small danger" type="button">Delete playlist</button>
			</div>
			<div id="pl-meta" class="muted"></div>
			<div class="pl-toolbar">
				<span id="pl-players"></span>
				<a id="pl-download" class="btn" href="#" title="Save the playlist as a .m3u8 file - double-click it to open it in your player">Download .m3u8</a>
				<button id="pl-save" class="btn" type="button" title="Write the .m3u8 into your playlists folder">Save to playlists folder</button>
				<button id="pl-copy" class="btn" type="button" title="A link that VLC / PotPlayer can open (Open URL / Open network stream)">Copy link</button>
				<select id="pl-sort" title="Sort this playlist">
					<option value="">Sort...</option>
					<option value="name:asc">By name A-Z</option>
					<option value="name:desc">By name Z-A</option>
					<option value="duration:desc">Longest first</option>
					<option value="duration:asc">Shortest first</option>
					<option value="shuffle:asc">Shuffle</option>
				</select>
			</div>
			<div id="pl-path" class="muted small"></div>
			<div id="pl-items" class="pl-items"></div>
		</section>
	</main>
</div>

<div id="toast" class="toast" hidden></div>
<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/playlists.js') ?>"></script>
</body>
</html>
