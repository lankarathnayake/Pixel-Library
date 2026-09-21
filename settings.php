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
	<section class="set-card" id="s-player">
		<h2>Video player</h2>
		<p class="hint">
			Master controls for the player in the library viewer. They apply to <strong>every</strong> video you open, and are kept in this browser.
		</p>
		<div class="pl-row2">
			<label for="p-volume">Volume</label>
			<div class="pl-ctl"><input id="p-volume" type="range" min="0" max="100" step="1"> <output id="p-volume-out" class="muted"></output></div>
		</div>
		<div class="pl-row2">
			<label for="p-speed">Playback speed</label>
			<div class="pl-ctl">
				<select id="p-speed">
					<option value="0.5">0.5x</option><option value="0.75">0.75x</option><option value="1">1x (normal)</option>
					<option value="1.25">1.25x</option><option value="1.5">1.5x</option><option value="1.75">1.75x</option><option value="2">2x</option>
				</select>
			</div>
		</div>
		<label class="check"><input type="checkbox" id="p-muted"> Start every video muted</label>
		<label class="check"><input type="checkbox" id="p-autoplay"> Start playing as soon as a video opens</label>
		<label class="check"><input type="checkbox" id="p-autonext"> When a video ends, open and play the next file in the list</label>
		<label class="check" title="Off = the values above are always used and changes made inside the player last only for that video"><input type="checkbox" id="p-remember"> When I change the volume or speed inside the player, use that from now on (updates the values above)</label>
		<p class="hint small" style="margin-top:10px">
			Also: after you close the viewer, the file you were on is <strong>highlighted</strong> in the library ("Last watched") until you change the
			filters or reload the page, and <strong>Go to last watched</strong> in the sidebar jumps back to it.
		</p>
		<div class="set-foot" style="margin-top:12px; padding-top:12px">
			<button id="p-reset" class="btn small" type="button">Reset the player to defaults</button>
		</div>
	</section>

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
