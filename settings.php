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
	<title>Settings - Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<span class="crumb">Settings</span>
	<span class="spacer"></span>
	<a class="btn" href="playlists.php">Playlists</a>
	<a class="btn" href="actors.php">Actors</a>
	<a class="btn" href="manage.php">Manage tags</a>
	<a class="btn" href="./">&larr; Library</a>
</header>

<div class="set-page">
	<section class="set-card">
		<h2>File formats</h2>
		<p class="hint">
			Files with these extensions are added when you add files or folders, and when you rescan folders. Add a format you
			are missing, or remove one you never want. Files already in your library stay and keep working either way.
		</p>
		<div class="set-note">
			A newly added format only affects files added from now on. To pick up files of that kind in folders you added earlier:
			<button id="s-rescan" class="btn small" type="button">Rescan all my folders now</button>
		</div>

		<div class="set-cols">
			<div>
				<h3>Videos <span id="s-vcount" class="muted small"></span></h3>
				<div id="s-videos" class="fmt-list"></div>
			</div>
			<div>
				<h3>Images <span id="s-icount" class="muted small"></span></h3>
				<div id="s-images" class="fmt-list"></div>
			</div>
		</div>

		<h3 class="set-sub">Add a format</h3>
		<form id="s-form" class="set-form">
			<label>Extension <input id="s-ext" type="text" placeholder="mpg" maxlength="11" autocomplete="off" spellcheck="false" required></label>
			<label>Kind
				<select id="s-type"><option value="video">Video</option><option value="image">Image</option></select>
			</label>
			<label class="grow">MIME type <span class="muted small">(optional)</span> <input id="s-mime" type="text" placeholder="video/mp4" autocomplete="off" spellcheck="false"></label>
			<button class="btn primary" type="submit">Add</button>
		</form>
		<p class="hint small">
			Write the extension without the dot. The MIME type is what the file is served as to your browser; leave it empty to use the usual
			one. Your browser may not be able to <em>play</em> every video format (that is fine: the file is still catalogued and gets previews;
			use "Play in PotPlayer / VLC" to watch it).
		</p>

		<div id="s-suggest-wrap" hidden>
			<h3 class="set-sub">Common formats you can add with one click</h3>
			<div id="s-suggest" class="set-chips"></div>
		</div>

		<div class="set-foot">
			<button id="s-reset" class="btn small" type="button">Reset to the built-in list</button>
		</div>
	</section>
</div>

<div id="toast" class="toast" hidden></div>
<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/settings.js') ?>"></script>
</body>
</html>
