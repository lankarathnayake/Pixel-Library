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
	<title>Find duplicates - Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<span class="crumb">Find duplicates</span>
	<span class="spacer"></span>
	<a class="btn" href="playlists.php">Playlists</a>
	<a class="btn" href="actors.php">Actors</a>
	<a class="btn" href="./">&larr; Library</a>
</header>

<div class="dup-page">
	<p class="hint">
		<strong>Identical files</strong> have exactly the same content (same size, and the same fingerprint of the start, middle and end).
		<strong>Same length</strong> are videos with the same running time and resolution but different content - probably the same video in another quality. That list is only a hint.
		Nothing is deleted until you choose what to keep and press a button.
	</p>

	<div class="dup-toolbar">
		<select id="d-mode" title="What to look for">
			<option value="identical">Identical files</option>
			<option value="samelength">Same length + resolution</option>
		</select>
		<button id="d-scan" class="btn primary" type="button">Scan for identical files</button>
		<button id="d-stop" class="btn" type="button" hidden>Stop</button>
		<label class="check small" title="Tags and playlist places of the removed copies move to the file you keep"><input type="checkbox" id="d-merge" checked> Move tags and playlist places to the kept file</label>
		<span class="spacer"></span>
		<button id="d-auto" class="btn danger" type="button" title="For every group shown: keep the suggested file and delete the others from disk">Keep suggested &amp; delete the rest (all shown)...</button>
	</div>
	<div class="progress" id="d-progress" hidden><i id="d-bar"></i></div>
	<div id="d-summary" class="dup-summary"></div>

	<div id="d-groups"></div>
	<div class="more-wrap"><button id="d-more" class="btn" type="button" hidden>Show more groups</button></div>
</div>

<div id="toast" class="toast" hidden></div>
<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/duplicates.js') ?>"></script>
</body>
</html>
