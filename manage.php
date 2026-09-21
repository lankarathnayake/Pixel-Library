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
	<title>Manage tags - Pixel Library</title>
	<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="./">Pixel Library</a>
	<span class="crumb">Manage categories &amp; tags</span>
	<span class="spacer"></span>
	<a class="btn" href="actors.php">Actors</a>
	<a class="btn" href="settings.php">Settings</a>
	<a class="btn" href="./">&larr; Back to library</a>
</header>

<div class="manage">
	<p class="hint">
		A <strong>category</strong> is a kind of label (Tags, Actors, Studios&hellip;). Each category holds any number of
		<strong>terms</strong> (the tag &ldquo;beach&rdquo;, the actor &ldquo;Jane Doe&rdquo;). Deleting a term or category only removes the
		label &mdash; your files are never touched.
		<br>Put a term in the wrong category, or made two for the same thing? <strong>Tick</strong> terms to <strong>move</strong> them to another category
		(files, actor profile and photo go with them) or <strong>merge</strong> several into one.
	</p>
	<form id="new-cat" class="inline-form">
		<input type="text" id="new-cat-name" placeholder="New category, e.g. Studios" maxlength="100" required>
		<button class="btn primary" type="submit">Add category</button>
	</form>
	<div id="cats"></div>
</div>

<div id="toast" class="toast" hidden></div>
<script src="<?= asset('assets/common.js') ?>"></script>
<script src="<?= asset('assets/manage.js') ?>"></script>
</body>
</html>
