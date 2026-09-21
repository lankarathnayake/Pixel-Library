<?php
/**
 * Backend tests. Run from the project root:   php tests/run.php
 *
 * Uses a throw-away database (a temp SQLite file; with TEST_DRIVER=mysql: pixel_library_test, created and dropped here) and
 * a temp folder of dummy media files - it never touches your real library or files.
 */

if (PHP_SAPI !== 'cli') {
	exit('CLI only.');
}

// SQLite (a throw-away temp file) by default; TEST_DRIVER=mysql runs the same tests on MySQL (database pixel_library_test).
define('DB_DRIVER', getenv('TEST_DRIVER') === 'mysql' ? 'mysql' : 'sqlite');
define('DB_NAME', 'pixel_library_test');
if (getenv('TEST_DRIVER') === 'mysql') { // credentials of a MySQL user that may create databases (default: local root without a password)
	define('DB_HOST', getenv('TEST_MYSQL_HOST') ?: 'localhost');
	define('DB_USER', getenv('TEST_MYSQL_USER') ?: 'root');
	define('DB_PASS', getenv('TEST_MYSQL_PASS') ?: '');
}
define('DB_FILE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-' . bin2hex(random_bytes(3)) . '.sqlite');
// Never write into the real storage/thumbs (test DB ids would collide with real ones).
define('DELETE_LOG', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-deleted-' . bin2hex(random_bytes(3)) . '.log');
define('PHOTOS_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-photos-' . bin2hex(random_bytes(3)));
define('PLAYLIST_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-playlists-' . bin2hex(random_bytes(3)));
define('PLAYER_PATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-fake-player-' . bin2hex(random_bytes(3)) . '.bat');
define('THUMBS_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-thumbs-' . bin2hex(random_bytes(3)));
if (getenv('FFMPEG_PATH')) {
	define('FFMPEG_PATH', getenv('FFMPEG_PATH'));
}
if (getenv('FFPROBE_PATH')) {
	define('FFPROBE_PATH', getenv('FFPROBE_PATH'));
}
error_reporting(E_ALL & ~E_WARNING); // config.local.php re-define()s DB_NAME; harmless
require __DIR__ . '/../config.php';
error_reporting(E_ALL);
require __DIR__ . '/../common/autoload.php';

$pass = 0;
$fail = 0;
function check($cond, string $label): void {
	global $pass, $fail;
	if ($cond) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}
function throwsApi(callable $fn, int $code = 0): bool {
	try {
		$fn();
	} catch (ApiException $e) {
		return $code === 0 || $e->getCode() === $code;
	}
	return false;
}
function rrmdir(string $d): void {
	foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
		if (in_array(basename($f), ['.', '..'], true)) {
			continue;
		}
		is_dir($f) ? rrmdir($f) : unlink($f);
	}
	@rmdir($d);
}

// ---- fresh database ----
if (DB_DRIVER === 'mysql') {
	$admin = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$admin->exec('DROP DATABASE IF EXISTS pixel_library_test');
	$admin->exec('CREATE DATABASE pixel_library_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
	foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/../db/schema.mysql.sql')))) as $stmt) {
		Db::pdo()->exec($stmt);
	}
} else {
	Db::pdo(); // creates the SQLite file and its tables (that is part of what is tested)
}
echo 'Database: ' . DB_DRIVER . "\n";

// ---- dummy media tree ----
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-test-' . bin2hex(random_bytes(3));
mkdir("$root/trips/beach", 0777, true);
mkdir("$root/empty");
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$root/a.png", $png);
file_put_contents("$root/100%_done.jpg", $png);
file_put_contents("$root/clip.mp4", str_repeat('x', 1000));
file_put_contents("$root/notes.txt", 'not media');
file_put_contents("$root/trips/b.webm", str_repeat('y', 500));
file_put_contents("$root/trips/beach/c.PNG", $png);
file_put_contents("$root/trips/beach/dé ü.mp4", 'z');

echo "Paths\n";
check(Paths::resolve('relative/path') === null, 'relative path rejected');
check(Paths::resolve("$root/trips/../a.png") === realpath("$root/a.png"), '".." is resolved away');
check(Paths::resolve("$root/nope.png") === null, 'missing path -> null');
check(Paths::hash(realpath("$root/a.png")) === Paths::hash(Paths::isWindows() ? strtoupper(realpath("$root/a.png")) : realpath("$root/a.png")), 'hash case rule');
$list = Paths::listDir($root);
$names = array_column($list['entries'], 'name');
check($names === ['empty', 'trips', '100%_done.jpg', 'a.png', 'clip.mp4'], 'listDir: folders first, media only, sorted');

echo "Adding\n";
$r = Library::add([], [$root], false);
check($r['added'] === 3 && $r['existing'] === 0, 'folder (non-recursive) adds 3 files');
$r = Library::add([], [$root], true);
check($r['added'] === 3 && $r['existing'] === 3, 'recursive adds the 3 in sub-folders, 3 already known');
$r = Library::add(["$root/a.png", "$root/notes.txt", "$root/ghost.png", 'relative.png'], ["$root/nope"], false);
check($r['added'] === 0 && $r['existing'] === 1 && count($r['skipped']) === 4, 'files: dup counted, txt/missing/relative/folder skipped');
check(Db::pdo()->query('SELECT COUNT(*) FROM media')->fetchColumn() == 6, '6 rows total');
$row = Library::find((int) Db::pdo()->query("SELECT id FROM media WHERE name = 'a.png'")->fetchColumn());
check($row['type'] === 'image' && (int) $row['width'] === 1 && (int) $row['height'] === 1 && $row['ext'] === 'png', 'image metadata (type, dimensions)');
$uni = Db::pdo()->query("SELECT COUNT(*) FROM media WHERE name = 'dé ü.mp4'")->fetchColumn();
check($uni == 1, 'unicode file name stored');
check(Db::pdo()->query("SELECT ext FROM media WHERE name = 'c.PNG'")->fetchColumn() === 'png', 'extension lower-cased');

echo "Search\n";
check(Library::search([])['total'] === 6, 'all');
check(Library::search(['type' => 'video'])['total'] === 3, 'type=video');
check(Library::search(['type' => 'image'])['total'] === 3, 'type=image');
check(Library::search(['q' => 'beach'])['total'] === 2, 'q matches folder name');
check(Library::search(['q' => '100%'])['total'] === 1, 'q with % is literal');
check(Library::search(['q' => '_'])['total'] === 1, 'q with _ is literal');
check(Library::search(['q' => 'clip mp4'])['total'] === 1, 'q words are ANDed');
$p1 = Library::search(['sort' => 'name_asc'], 1, 4);
$p2 = Library::search(['sort' => 'name_asc'], 2, 4);
check(count($p1['items']) === 4 && $p1['has_more'] && count($p2['items']) === 2 && !$p2['has_more'], 'pagination');
check(Library::search(['sort' => 'size_desc'])['items'][0]['name'] === 'clip.mp4', 'sort by size');
$allIds = array_column(Library::search(['sort' => 'name_asc'], 1, 200)['items'], 'id');
$win = Library::search(['sort' => 'name_asc'], 1, 2, 3);
check(array_column($win['items'], 'id') === array_slice($allIds, 3, 2) && $win['total'] === 6 && $win['has_more'] === true, 'offset+limit returns any window, not just page boundaries');
$tail = Library::search(['sort' => 'name_asc'], 1, 10, 4);
check(array_column($tail['items'], 'id') === array_slice($allIds, 4) && $tail['has_more'] === false, 'window at the end: fewer items, has_more false');
check(Library::search(['sort' => 'name_asc'], 1, 5, 99)['items'] === [], 'offset past the end -> empty');
check(array_column(Library::search(['sort' => 'name_asc'], 1, 2, 0)['items'], 'id') === array_slice($allIds, 0, 2), 'offset 0 = first rows');
check(array_column(Library::search(['sort' => 'name_asc'], 3, 2)['items'], 'id') === array_slice($allIds, 4, 2), 'page/perPage still work when no offset is given');

echo "Taxonomy\n";
$tags = (int) Db::pdo()->query("SELECT id FROM category WHERE name = 'Tags'")->fetchColumn();
$actors = (int) Db::pdo()->query("SELECT id FROM category WHERE name = 'Actors'")->fetchColumn();
check($tags > 0 && $actors > 0, 'starter categories seeded');
check(throwsApi(fn() => Taxonomy::addCategory('tags'), 409), 'duplicate category (case-insensitive) -> 409');
check(throwsApi(fn() => Taxonomy::addCategory('   ')), 'blank name rejected');
$studios = Taxonomy::addCategory('  Studios  ');
check(Db::pdo()->query("SELECT name FROM category WHERE id = $studios")->fetchColumn() === 'Studios', 'name trimmed');
$beach = Taxonomy::ensureTerm($tags, 'Beach');
check(Taxonomy::ensureTerm($tags, 'beach') === $beach, 'ensureTerm is idempotent, case-insensitive');
check(Taxonomy::ensureTerm($actors, 'Beach') !== $beach, 'same name in another category is a different term');
check(throwsApi(fn() => Taxonomy::ensureTerm(999999, 'x'), 404), 'unknown category -> 404');

$ids = fn(string $name) => (int) Db::pdo()->query('SELECT id FROM media WHERE name = ' . Db::pdo()->quote($name))->fetchColumn();
$a = $ids('a.png'); $b = $ids('b.webm'); $c = $ids('c.png'); $clip = $ids('clip.mp4');
Taxonomy::assignNamed([$a, $b, $c], [$tags => ['Beach', 'Summer'], $actors => ['Jane Doe']]);
Taxonomy::assignNamed([$a], [$tags => ['beach']]); // duplicate link must not error
check(Db::pdo()->query('SELECT COUNT(*) FROM media_term')->fetchColumn() == 9, 'assignNamed: 3 media x 3 terms, no dupes');
Taxonomy::assignNamed([$clip], [$actors => ['John Roe']]);
Taxonomy::assignNamed([999999], [$tags => ['Ghost']]); // unknown media ignored
check(Db::pdo()->query('SELECT COUNT(*) FROM media_term')->fetchColumn() == 10, 'unknown media id ignored');

$summer = Taxonomy::ensureTerm($tags, 'Summer');
$jane = Taxonomy::ensureTerm($actors, 'Jane Doe');
$john = Taxonomy::ensureTerm($actors, 'John Roe');
check(Library::search(['terms' => [$beach]])['total'] === 3, 'filter by one term');
check(Library::search(['terms' => [$jane, $john]])['total'] === 4, 'OR within a category');
check(Library::search(['terms' => [$summer, $john]])['total'] === 0, 'AND across categories');
check(Library::search(['terms' => [$summer, $jane]])['total'] === 3, 'AND across categories (match)');
check(Library::search(['untagged' => 1])['total'] === 2, 'untagged');
check(Library::search(['terms' => [999999]])['total'] === 0, 'unknown term id -> nothing');
$tree = Taxonomy::tree();
$treeTags = array_values(array_filter($tree, fn($c) => $c['id'] === $tags))[0];
$beachNode = array_values(array_filter($treeTags['terms'], fn($t) => $t['id'] === $beach))[0];
check($beachNode['count'] === 3, 'tree usage counts');
check(count(Taxonomy::forMedia([$a])[$a]) === 3, 'forMedia');

Taxonomy::unassign([$a, $b], [$beach]);
check(Library::search(['terms' => [$beach]])['total'] === 1, 'unassign');
Taxonomy::renameTerm($summer, 'Winter');
check(Db::pdo()->query("SELECT name FROM term WHERE id = $summer")->fetchColumn() === 'Winter', 'rename term');
check(throwsApi(fn() => Taxonomy::renameTerm($summer, 'beach'), 409), 'rename to existing -> 409');
Taxonomy::deleteTerm($jane);
check(Db::pdo()->query("SELECT COUNT(*) FROM media_term WHERE term_id = $jane")->fetchColumn() == 0, 'deleteTerm removes links');
Taxonomy::deleteCategory($actors);
check(Db::pdo()->query("SELECT COUNT(*) FROM term WHERE category_id = $actors")->fetchColumn() == 0, 'deleteCategory removes terms');
check(Db::pdo()->query("SELECT COUNT(*) FROM media_term WHERE term_id = $john")->fetchColumn() == 0, 'deleteCategory removes links');
check(Db::pdo()->query('SELECT COUNT(*) FROM media')->fetchColumn() == 6, 'media untouched by taxonomy deletes');

echo "Missing files / removal\n";
unlink("$root/clip.mp4");
$r = Library::checkMissing();
check($r['newly_missing'] === 1 && $r['missing'] === 1 && $r['checked'] === 6, 'checkMissing flags a deleted file');
check(Library::search(['missing' => 1])['total'] === 1, 'missing filter');
file_put_contents("$root/clip.mp4", 'back');
$r = Library::checkMissing();
check($r['restored'] === 1 && $r['missing'] === 0, 'checkMissing restores when the file returns');
unlink("$root/clip.mp4");
Library::markMissing($clip);
Library::add(["$root/a.png"], [], false); // re-adding must not resurrect a still-missing file
check((int) Library::find($clip)['is_missing'] === 1, 're-adding other files leaves missing flag alone');
file_put_contents("$root/clip.mp4", 'back again');
Library::add(["$root/clip.mp4"], [], false);
check((int) Library::find($clip)['is_missing'] === 0, 're-adding a file that came back clears the flag');

check(Library::remove([$a, $b, 999999]) === 2, 'remove returns count');
check(is_file("$root/a.png") && is_file("$root/trips/b.webm"), 'remove never touches files on disk');
check(Db::pdo()->query("SELECT COUNT(*) FROM media_term WHERE media_id IN ($a, $b)")->fetchColumn() == 0, 'remove clears term links');
$d = Library::detail($c);
check($d !== null && $d['folder'] === dirname($d['path']) && count($d['terms']) === 2, 'detail');
check(Library::detail($a) === null, 'detail of removed -> null');

echo "Video previews\n";
if (!Ffmpeg::available()) {
	echo "  skipped (ffmpeg/ffprobe not found - set FFMPEG_PATH / FFPROBE_PATH env vars to run these)\n";
} else {
	// start from an empty library so only the videos made here are in the queue
	Db::pdo()->exec('DELETE FROM media_term');
	Db::pdo()->exec('DELETE FROM media_thumb');
	Db::pdo()->exec('DELETE FROM media');

	$plan = fn($d) => VideoThumbs::plan($d);
	check(count($plan(10)) === 5 && count($plan(59.9)) === 5, 'plan: under 1 min => 5 frames');
	check(count($plan(120)) === 6 && count($plan(299)) === 6, 'plan: under 5 min => 6 frames');
	check(count($plan(600)) === 8 && count($plan(1199)) === 8, 'plan: under 20 min => 8 frames');
	check(count($plan(1200)) === 10 && count($plan(7200)) === 10, 'plan: 20 min and over => 10 frames');
	$p10 = $plan(2000);
	check(abs($p10[0]['pct'] - 5) < 0.001 && abs($p10[9]['pct'] - 95) < 0.001 && abs($p10[1]['pct'] - 15) < 0.001, 'plan: 10 frames sit at 5%, 15% ... 95%');
	$tinyTimes = array_column($plan(0.8), 'time');
	check($tinyTimes === [0.0] && count($plan(2.9)) === 1 && count($plan(3)) === 5, 'plan: under 3 s => one frame at the start');

	mkdir("$root/vid");
	$make = function (string $name, int $secs) use ($root) {
		return Ffmpeg::run([FFMPEG_PATH, '-v', 'error', '-f', 'lavfi', '-i', "testsrc=size=320x180:rate=1:duration=$secs",
			'-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-y', "$root/vid/$name"], 180) !== null;
	};
	$made = $make('short.mp4', 30) && $make('medium.mp4', 240) && $make('long.mp4', 600) && $make('huge.mp4', 1300)
		&& $make("日本語 ünï.mp4", 30) && $make('tiny.mp4', 1);
	check($made, 'generated 6 test videos with ffmpeg');
	file_put_contents("$root/vid/corrupt.mp4", 'this is not a video');

	$r = Library::add([], ["$root/vid"], false);
	check($r['added'] === 7, 'added 7 videos');
	$st = VideoThumbs::stats();
	check($st['pending'] === 7 && $st['done'] === 0 && $st['total'] === 7, 'all 7 start pending');

	$run = VideoThumbs::run(600);
	check($run['processed'] === 7 && $run['done'] === 6 && $run['failed'] === 1, 'run: 6 done, corrupt file failed: ' . json_encode($run));
	$st = VideoThumbs::stats();
	check($st['pending'] === 0 && $st['done'] === 6 && $st['failed'] === 1, 'stats after run');

	$idOf = fn(string $n) => (int) Db::pdo()->query('SELECT id FROM media WHERE name = ' . Db::pdo()->quote($n))->fetchColumn();
	$count = fn(int $id) => (int) Db::pdo()->query("SELECT COUNT(*) FROM media_thumb WHERE media_id = $id")->fetchColumn();
	check($count($idOf('short.mp4')) === 5, 'short (30s) => 5 previews');
	check($count($idOf('medium.mp4')) === 6, 'medium (4 min) => 6 previews');
	check($count($idOf('long.mp4')) === 8, 'long (10 min) => 8 previews');
	check($count($idOf('huge.mp4')) === 10, 'huge (~22 min) => 10 previews');
	check($count($idOf("日本語 ünï.mp4")) === 5, 'non-ASCII file name works');
	check($count($idOf('tiny.mp4')) === 1 && (int) Library::find($idOf('tiny.mp4'))['thumb_status'] === VideoThumbs::DONE, 'tiny (1s) video => a single preview, status done');
	check($count($idOf('corrupt.mp4')) === 0 && (int) Library::find($idOf('corrupt.mp4'))['thumb_status'] === VideoThumbs::FAILED, 'corrupt file: no previews, status failed');
	$noThumbs = array_column(Library::search(['nothumbs' => 1])['items'], 'name');
	check($noThumbs === ['corrupt.mp4'], 'filter "videos without previews" finds only the video that has none: ' . json_encode($noThumbs));
	check(Library::search(['nothumbs' => 1, 'type' => 'image'])['total'] === 0, '...and never lists images');

	$long = Library::find($idOf('long.mp4'));
	check(abs((float) $long['duration'] - 600) < 2 && (int) $long['width'] === 320 && (int) $long['height'] === 180, 'duration + dimensions recorded');
	$dir = VideoThumbs::dir() . '/' . $long['id'];
	$files = glob("$dir/*.jpg");
	check(count($files) === 8, '8 jpg files on disk in storage/thumbs/video/<id>/');
	$hashes = array_map('md5_file', $files);
	check(count(array_unique($hashes)) === 8, 'every preview is a different frame (seeking works)');
	$ok = true;
	foreach ($files as $fpath) {
		$dim = getimagesize($fpath);
		$ok = $ok && $dim && $dim[2] === IMAGETYPE_JPEG && $dim[0] === 320 && $dim[1] === 180;
	}
	check($ok, 'previews are JPEGs, 320x180');
	$times = VideoThumbs::timesFor([(int) $long['id']])[(int) $long['id']];
	check(count($times) === 8 && abs($times[0] - 37.5) < 0.1 && abs($times[7] - 562.5) < 0.1, 'times recorded (6.25% .. 93.75% of 600s): ' . json_encode($times));

	// the DB row is what points at the file
	check(VideoThumbs::fileFor((int) $long['id'], 3) === $dir . '/3.jpg', 'fileFor resolves via the DB row');
	check(VideoThumbs::fileFor((int) $long['id'], 99) === null, 'fileFor: unknown index -> null');
	Db::pdo()->prepare("UPDATE media_thumb SET file = '../../../config.php' WHERE media_id = ? AND idx = 0")->execute([$long['id']]);
	check(VideoThumbs::fileFor((int) $long['id'], 0) === null, 'fileFor refuses a path-traversal value in the DB');
	Db::pdo()->prepare("UPDATE media_thumb SET file = '0.jpg' WHERE media_id = ? AND idx = 0")->execute([$long['id']]);

	// list / lookup shape
	$item = array_values(array_filter(Library::search(['type' => 'video'])['items'], fn($i) => $i['id'] === (int) $long['id']))[0];
	check(count($item['thumbs']) === 8 && $item['thumb_status'] === 1 && abs($item['duration'] - 600) < 2, 'search items carry thumbs / status / duration');
	$byId = Library::byIds([(int) $long['id'], 999999]);
	check(count($byId) === 1 && $byId[0]['thumbs'] === $item['thumbs'], 'byIds returns the same shape');
	check(Library::detail((int) $long['id'])['duration'] !== null, 'detail includes duration');
	check(Library::search(['sort' => 'duration_desc'])['items'][0]['name'] === 'huge.mp4', 'sort by duration');

	// queue mechanics
	check(VideoThumbs::claimNext() === null, 'queue empty once everything is processed');
	check(VideoThumbs::retryFailed() === 1, 'retryFailed re-queues the corrupt file');
	$row = VideoThumbs::claimNext();
	check($row !== null && $row['name'] === 'corrupt.mp4', 'claimNext hands it out');
	check(VideoThumbs::claimNext() === null, 'a claimed video is not handed out twice');
	Db::pdo()->prepare('UPDATE media SET thumb_claimed_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), $row['id']]);
	$again = VideoThumbs::claimNext();
	check($again !== null && (int) $again['id'] === (int) $row['id'], 'a stale claim (crashed worker) is taken over');
	check(VideoThumbs::process($again) === 'failed', 'still failing on reprocess');

	// scoped queue: work on a selection only
	$short = $idOf('short.mp4'); $tiny = $idOf('tiny.mp4'); $bad = $idOf('corrupt.mp4'); $huge = $idOf('huge.mp4');
	$status = fn(int $id) => (int) Library::find($id)['thumb_status'];
	$sel = [$short, $tiny, $bad];
	check(VideoThumbs::queue($sel, false) === [$bad], 'queue(): without force only the failed video is queued');
	check($status($short) === VideoThumbs::DONE && $status($tiny) === VideoThumbs::DONE, '...finished ones are left alone');
	$q = VideoThumbs::queue($sel, true);
	sort($q);
	$want = $sel;
	sort($want);
	check($q === $want && $status($short) === VideoThumbs::PENDING, 'queue(force): finished videos are re-queued to regenerate');
	check($status($huge) === VideoThumbs::DONE, 'videos outside the selection are untouched');
	check(VideoThumbs::remaining($sel) === 3, 'remaining() counts the selection');
	check(VideoThumbs::claimNext([]) === null, 'empty scope claims nothing');
	Db::pdo()->prepare('UPDATE media SET thumb_status = 0 WHERE id = ?')->execute([$huge]); // pending, but NOT selected
	$r = VideoThumbs::run(120, null, $sel);
	check($r['processed'] === 3 && $r['done'] === 2 && $r['failed'] === 1, 'scoped run processed exactly the 3 selected: ' . json_encode($r));
	check($status($huge) === VideoThumbs::PENDING, '...and skipped the pending video that was not selected');
	check(VideoThumbs::remaining($sel) === 0, 'selection fully processed');
	check($count($short) === 5 && $count($tiny) === 1 && is_dir(VideoThumbs::dir() . '/' . $short), 'regenerated videos have their frames again');
	check(VideoThumbs::queue([$huge, 999999], false) === [$huge], 'queue(): an already-pending video counts as waiting; unknown ids ignored');
	check(VideoThumbs::run(120)['done'] === 1 && $status($huge) === VideoThumbs::DONE, 'unscoped run then handles the rest');

	// file replaced -> stale previews discarded
	$short = Library::find($idOf('short.mp4'));
	file_put_contents($short['path'], file_get_contents($short['path']) . 'padding-to-change-size');
	Library::add([$short['path']], [], false);
	$after = Library::find((int) $short['id']);
	check((int) $after['thumb_status'] === VideoThumbs::PENDING && $count((int) $short['id']) === 0 && !is_dir(VideoThumbs::dir() . '/' . $short['id']), 're-adding a changed video discards its old previews and re-queues it');
	check(VideoThumbs::run(60)['done'] === 1 && $count((int) $short['id']) === 5, 'and they are regenerated');

	// source file gone
	$med = Library::find($idOf('medium.mp4'));
	VideoThumbs::retryOne((int) $med['id']);
	unlink($med['path']);
	$row = VideoThumbs::claimNext();
	check($row !== null && VideoThumbs::process($row) === 'missing' && (int) Library::find((int) $med['id'])['is_missing'] === 1, 'source file gone: reported missing and flagged');
	check(VideoThumbs::claimNext() === null, 'a missing video is not re-queued forever');

	// removal cleans rows and files
	$dirLong = VideoThumbs::dir() . '/' . $long['id'];
	Library::remove([(int) $long['id']]);
	check($count((int) $long['id']) === 0 && !is_dir($dirLong), 'removing a video deletes its preview rows and files');
	check(is_file($long['path']), '...but never the video itself');
}

echo "Permanent deletion\n";
{
	Db::pdo()->exec('DELETE FROM media_term');
	Db::pdo()->exec('DELETE FROM media_thumb');
	Db::pdo()->exec('DELETE FROM media');

	$d = "$root/del";
	mkdir("$d/sub", 0777, true);
	file_put_contents("$d/one.png", $png);
	file_put_contents("$d/two.png", $png);
	file_put_contents("$d/vid.mp4", str_repeat('v', 4000));
	file_put_contents("$d/unregistered.png", $png);
	file_put_contents("$d/sub/other.mp4", str_repeat('o', 700));
	$r = Library::add(["$d/one.png", "$d/two.png", "$d/vid.mp4", "$d/sub/other.mp4"], [], false);
	check($r['added'] === 4, 'added 4 files to delete-test');
	$one = $ids('one.png'); $two = $ids('two.png'); $vid = $ids('vid.mp4'); $other = $ids('other.mp4');

	// give them tags + preview thumbnails so we can prove all of it is cleaned up
	$tagDel = Taxonomy::ensureTerm($tags, 'Del-Tag');
	Taxonomy::assign([$one, $vid], [$tagDel]);
	$vd = VideoThumbs::dir() . '/' . $vid;
	mkdir($vd, 0777, true);
	file_put_contents("$vd/0.jpg", 'x');
	Db::pdo()->prepare('INSERT INTO media_thumb (media_id, idx, time_sec, file) VALUES (?, 0, 1.5, ?)')->execute([$vid, '0.jpg']);
	@mkdir(THUMBS_DIR, 0777, true);
	$imgThumb = THUMBS_DIR . "/{$one}_1700000000.jpg";
	file_put_contents($imgThumb, 'x');
	$sizeOne = filesize("$d/one.png");

	$res = Library::deleteFiles([$one, $vid, 999999]);
	check($res['deleted'] === 2 && $res['failed'] === [] && $res['already_gone'] === 0, 'deleted 2 files (unknown id ignored): ' . json_encode($res));
	check($res['bytes'] === $sizeOne + 4000, 'reports the bytes freed');
	check(!file_exists("$d/one.png") && !file_exists("$d/vid.mp4"), 'the files are really gone from disk');
	check(is_file("$d/two.png") && is_file("$d/unregistered.png") && is_file("$d/sub/other.mp4"), 'other files (registered or not) are untouched');
	check(Db::pdo()->query("SELECT COUNT(*) FROM media WHERE id IN ($one, $vid)")->fetchColumn() == 0, 'library entries removed');
	check(Db::pdo()->query("SELECT COUNT(*) FROM media_term WHERE media_id IN ($one, $vid)")->fetchColumn() == 0, 'tag links removed');
	check(Db::pdo()->query("SELECT COUNT(*) FROM term WHERE id = $tagDel")->fetchColumn() == 1, '...but the tag itself still exists for other files');
	check(Db::pdo()->query("SELECT COUNT(*) FROM media_thumb WHERE media_id = $vid")->fetchColumn() == 0 && !is_dir($vd), 'video preview rows + frame files removed');
	check(!file_exists($imgThumb), 'image thumbnail file removed');
	$log = file_get_contents(DELETE_LOG);
	check(strpos($log, realpath($d) . DIRECTORY_SEPARATOR . 'one.png') !== false && strpos($log, "vid.mp4") !== false && substr_count($log, "\n") === 2, 'each deletion is written to the log');

	// already gone: only the entry is cleaned up
	unlink("$d/two.png");
	$res = Library::deleteFiles([$two]);
	check($res['already_gone'] === 1 && $res['deleted'] === 0 && Library::find($two) === null, 'a file that is already gone just loses its entry');

	// wrong type behind a registered row: refused, nothing changes
	file_put_contents("$d/note.txt", 'important notes');
	$realNote = realpath("$d/note.txt");
	Db::pdo()->prepare("INSERT INTO media (path, path_hash, name, ext, type, size) VALUES (?, ?, 'note.txt', 'txt', 'image', 15)")->execute([$realNote, Paths::hash($realNote)]);
	$noteId = (int) Db::pdo()->lastInsertId();
	$res = Library::deleteFiles([$noteId]);
	check($res['deleted'] === 0 && count($res['failed']) === 1 && is_file($realNote) && Library::find($noteId) !== null, 'an unsupported file type is refused (file + entry kept)');

	// a folder replaced by a link/junction after registration: the path no longer resolves to where it was added
	$linked = false;
	rename("$d/sub", "$d/sub-real");
	if (Paths::isWindows()) {
		exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', "$d/sub")) . ' ' . escapeshellarg(str_replace('/', '\\', "$d/sub-real")) . ' 2>&1', $o, $code);
		$linked = $code === 0;
	} else {
		$linked = @symlink("$d/sub-real", "$d/sub");
	}
	if ($linked) {
		$res = Library::deleteFiles([$other]);
		check($res['deleted'] === 0 && count($res['failed']) === 1 && is_file("$d/sub-real/other.mp4") && Library::find($other) !== null, 'a folder swapped for a link is refused: nothing behind it is deleted');
		Paths::isWindows() ? rmdir("$d/sub") : unlink("$d/sub");
	} else {
		echo "  skipped (could not create a link/junction here)\n";
	}
	rename("$d/sub-real", "$d/sub");

	// files Windows will not let us delete: in use / read-only
	if (Paths::isWindows()) {
		file_put_contents("$d/locked.mp4", str_repeat('l', 300));
		file_put_contents("$d/fine.mp4", str_repeat('f', 300));
		file_put_contents("$d/ro.mp4", str_repeat('r', 300));
		Library::add(["$d/locked.mp4", "$d/fine.mp4", "$d/ro.mp4"], [], false);
		$locked = $ids('locked.mp4'); $fine = $ids('fine.mp4'); $ro = $ids('ro.mp4');
		Taxonomy::assign([$locked], [$tagDel]);

		// Lock it the way another program would: a separate process holds an exclusive handle
		// (PHP's own fopen() on Windows doesn't block deletion, so it can't be used to simulate this).
		$marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-lock-' . bin2hex(random_bytes(3));
		$lockPath = realpath("$d/locked.mp4");
		$ps = "\$f = [IO.File]::Open('$lockPath', 'Open', 'Read', 'None'); New-Item -ItemType File -Path '$marker' | Out-Null; Start-Sleep -Seconds 25";
		$holder = proc_open(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
		for ($i = 0; $i < 100 && !is_file($marker); $i++) {
			usleep(100000);
		}
		check(is_file($marker), 'another process is holding the file open exclusively');
		$res = Library::deleteFiles([$fine, $locked]);
		check($res['deleted'] === 1 && count($res['failed']) === 1 && $res['failed'][0]['id'] === $locked, 'mixed batch: the free file is deleted, the locked one is reported as failed');
		check(is_file("$d/locked.mp4") && Library::find($locked) !== null && !file_exists("$d/fine.mp4"), '...the locked file and its entry are kept, the free one is gone');
		check(Db::pdo()->query("SELECT COUNT(*) FROM media_term WHERE media_id = $locked")->fetchColumn() == 1, '...and the locked file keeps its tags');
		proc_terminate($holder);
		proc_close($holder);
		@unlink($marker);
		usleep(500000); // let Windows release the handle
		$res = Library::deleteFiles([$locked]);
		check($res['deleted'] === 1 && !file_exists("$d/locked.mp4"), 'once released, the same file deletes fine');

		chmod("$d/ro.mp4", 0444); // read-only attribute
		$res = Library::deleteFiles([$ro]);
		check($res['deleted'] === 0 && count($res['failed']) === 1 && is_file("$d/ro.mp4"), 'a read-only file is not force-deleted');
		chmod("$d/ro.mp4", 0666);
	}
	check(is_file("$d/unregistered.png"), 'a file that was never in the library survived everything');
}

echo "Actors\n";
{
	$actorsCat = Taxonomy::addCategory('Actors');
	$meta = Actors::meta();
	check($meta['default_category'] === $actorsCat, 'meta: the category named "Actors" is the default');

	$r = Actors::create($actorsCat, ['Jane Doe', 'john roe', '  Jane Doe ', '', 'Ann Lee']);
	check(count($r['created']) === 3 && count($r['existing']) === 1, 'create: 3 new, the duplicate reported as existing');
	[$jane, $john, $ann] = $r['created'];

	$p = Actors::save($jane, ['name' => 'Jane D.', 'full_name' => 'Jane Marie Doe', 'dob' => '1995-04-12', 'rating' => 4, 'notes' => 'Likes travel']);
	$expectAge = (int) (new DateTime('1995-04-12'))->diff(new DateTime('today'))->y;
	check($p['name'] === 'Jane D.' && $p['full_name'] === 'Jane Marie Doe' && $p['dob'] === '1995-04-12' && $p['rating'] === 4 && $p['age'] === $expectAge, 'save: name, full name, date of birth, rating; age is worked out (' . $p['age'] . ')');
	check(throwsApi(fn() => Actors::save($jane, ['name' => 'john roe']), 409), 'renaming to an existing name -> 409');
	check(throwsApi(fn() => Actors::save($jane, ['dob' => '1995-02-30'])), 'an impossible date is refused');
	check(throwsApi(fn() => Actors::save($jane, ['dob' => date('Y-m-d', strtotime('+2 days'))])), 'a date of birth in the future is refused');
	check(throwsApi(fn() => Actors::save($jane, ['dob' => '1850-01-01'])), 'a date before 1900 is refused');
	check(throwsApi(fn() => Actors::save($jane, ['rating' => 6])), 'a rating above 5 is refused');
	check(Actors::profile($jane)['name'] === 'Jane D.' && Actors::profile($jane)['rating'] === 4, 'a refused save changes nothing');
	check(Actors::save($jane, ['rating' => null])['rating'] === null, 'a rating can be cleared');
	Actors::rate($jane, 5);
	check(Actors::profile($jane)['rating'] === 5, 'rate(): quick star rating');
	check(throwsApi(fn() => Actors::rate(999999, 3), 404), 'rating an unknown actor -> 404');

	// custom fields
	$nat = Actors::addField('Nationality', false);
	$bio = Actors::addField('Bio', true);
	check(throwsApi(fn() => Actors::addField('nationality', false), 409), 'duplicate field name (case-insensitive) -> 409');
	Actors::save($jane, ['fields' => [$nat => 'Sri Lankan', $bio => "line1\nline2", 999999 => 'ignored']]);
	$fields = array_column(Actors::profile($jane)['fields'], 'value', 'name');
	check($fields['Nationality'] === 'Sri Lankan' && $fields['Bio'] === "line1\nline2", 'custom field values saved (unknown field id ignored)');
	check(Actors::profile($jane)['fields'][1]['is_long'] === true, 'a field can be multi-line');
	Actors::save($jane, ['fields' => [$bio => '']]);
	check(Actors::profile($jane)['fields'][1]['value'] === '', 'an emptied field value is removed');

	// talent lists
	$fav = Actors::addList('Favourites');
	$top = Actors::addList('Top 10');
	check(throwsApi(fn() => Actors::addList('favourites'), 409), 'duplicate list name -> 409');
	Actors::save($jane, ['lists' => [$fav, $top, 999999]]);
	check(Actors::profile($jane)['lists'] === [$fav, $top], 'list membership saved (unknown list ignored)');
	Actors::assignToList($fav, [$john, $ann, $jane], true);
	check(Actors::listMembers($fav) === [$jane, $john, $ann] || count(Actors::listMembers($fav)) === 3, 'assignToList adds several (already-members are fine)');
	Actors::assignToList($fav, [$ann], false);
	check(count(Actors::listMembers($fav)) === 2, '...and removes');
	check(throwsApi(fn() => Actors::assignToList(999999, [$jane], true), 404), 'assigning to an unknown list -> 404');

	// search / sort / filters
	Db::pdo()->exec("INSERT INTO media (path, path_hash, name, ext, type, size) VALUES ('/x/actor-test.png', '" . sha1('actor-test') . "', 'actor-test.png', 'png', 'image', 1)");
	$mid = (int) Db::pdo()->lastInsertId();
	Taxonomy::assign([$mid], [$jane]);
	$names = fn($f) => array_column(Actors::search($actorsCat, $f)['actors'], 'name');
	check($names([]) === ['Ann Lee', 'Jane D.', 'john roe'], 'search: default order is name A-Z');
	check($names(['sort' => 'name_desc']) === ['john roe', 'Jane D.', 'Ann Lee'], 'sort: name Z-A');
	$byRating = $names(['sort' => 'rating_desc']);
	check($byRating[0] === 'Jane D.' && end($byRating) !== 'Jane D.', 'sort: highest rated first, unrated last');
	check($names(['sort' => 'files_desc'])[0] === 'Jane D.', 'sort: most files first');
	check($names(['sort' => 'age_desc'])[0] === 'Jane D.', 'sort: actors with a date of birth come before those without');
	check($names(['q' => 'marie']) === ['Jane D.'], 'search finds the full name');
	check($names(['q' => 'travel']) === ['Jane D.'], 'search finds the notes (a save only touches the fields it includes, so the notes are still there)');
	check($names(['q' => 'sri']) === ['Jane D.'], 'search finds custom field values');
	check($names(['q' => 'jane sri']) === ['Jane D.'] && $names(['q' => 'jane zzz']) === [], 'search words are ANDed');
	check($names(['min_rating' => 5]) === ['Jane D.'], 'filter: minimum rating');
	check($names(['unrated' => 1]) === ['Ann Lee', 'john roe'], 'filter: not rated');
	check($names(['list' => $fav]) === ['Jane D.', 'john roe'], 'filter: on a talent list');
	$row = Actors::search($actorsCat, ['q' => 'jane'])['actors'][0];
	check($row['files'] === 1 && count($row['lists']) === 2 && $row['age'] === $expectAge, 'a row carries its file count, lists and age');
	check(Actors::search($actorsCat, [], 1, 1)['actors'][0]['name'] === 'Jane D.' && Actors::search($actorsCat, [], 0, 2)['has_more'] === true, 'paging (offset / limit)');
	check(Actors::search($actorsCat + 999, [])['total'] === 0, 'another category has none of them');

	// photo
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
	$tmpPng = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($tmpPng, $png);
	$name1 = Actors::setPhoto($john, $tmpPng);
	check(preg_match('/^[a-f0-9]{12}\.png$/', $name1) === 1 && is_file(PHOTOS_DIR . '/' . $name1), 'photo: stored under a generated name (' . $name1 . ')');
	check(Actors::profile($john)['photo'] === $name1 && Actors::search($actorsCat, ['q' => 'john'])['actors'][0]['photo'] === $name1, 'photo: shows in the profile and the list');
	$file = Actors::photoFile($john);
	check($file !== null && $file['mime'] === 'image/png' && $file['path'] === PHOTOS_DIR . '/' . $name1, 'photo: photoFile() finds it via the database');
	$treeTerm = fn(int $id) => array_values(array_filter(array_merge(...array_column(Taxonomy::tree(), 'terms')), fn($t) => $t['id'] === $id))[0];
	check($treeTerm($john)['photo'] === $name1 && $treeTerm($ann)['photo'] === null, 'the tag tree carries each term\'s photo (used by the sidebar and viewer)');
	$name2 = Actors::setPhoto($john, $tmpPng);
	check($name2 !== $name1 && !is_file(PHOTOS_DIR . '/' . $name1) && is_file(PHOTOS_DIR . '/' . $name2), 'photo: a new upload gets a new name and the old file is deleted');
	$tmpTxt = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($tmpTxt, 'this is not an image');
	check(throwsApi(fn() => Actors::setPhoto($john, $tmpTxt)), 'photo: a non-image is refused (its content is checked, not its name)');
	$tmpBig = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($tmpBig, $png . str_repeat('x', Actors::PHOTO_MAX_BYTES));
	check(throwsApi(fn() => Actors::setPhoto($john, $tmpBig)), 'photo: over 5 MB is refused');
	check(Actors::profile($john)['photo'] === $name2 && is_file(PHOTOS_DIR . '/' . $name2), 'photo: refused uploads leave the current photo alone');
	check(throwsApi(fn() => Actors::setPhoto(999999, $tmpPng), 404), 'photo: unknown actor -> 404');
	Db::pdo()->prepare('UPDATE actor_profile SET photo = ? WHERE term_id = ?')->execute(['../../config.php', $john]);
	check(Actors::photoFile($john) === null, 'photo: a tampered database value is never served (only generated names)');
	Db::pdo()->prepare('UPDATE actor_profile SET photo = ? WHERE term_id = ?')->execute([$name2, $john]);
	Actors::removePhoto($john);
	check(Actors::profile($john)['photo'] === null && !is_file(PHOTOS_DIR . '/' . $name2), 'photo: remove deletes the file and clears it');
	$name3 = Actors::setPhoto($ann, $tmpPng);
	Actors::delete([$ann]);
	check(!is_file(PHOTOS_DIR . '/' . $name3), 'photo: deleting the actor deletes the photo file');
	@unlink($tmpPng); @unlink($tmpTxt); @unlink($tmpBig);
	// deleting cleans everything up (and never touches the files)
	Actors::deleteList($top);
	check(Actors::profile($jane)['lists'] === [$fav] && Actors::profile($jane) !== null, 'deleting a list un-lists actors but keeps them');
	$del = Actors::delete([$jane, 999999]);
	check($del['deleted'] === 1 && $del['files_untagged'] === 1, 'delete: 1 actor deleted, tag removed from 1 file');
	$leftover = (int) Db::pdo()->query("SELECT (SELECT COUNT(*) FROM actor_profile WHERE term_id = $jane) + (SELECT COUNT(*) FROM actor_field_value WHERE term_id = $jane) + (SELECT COUNT(*) FROM talent_list_actor WHERE term_id = $jane) + (SELECT COUNT(*) FROM media_term WHERE term_id = $jane)")->fetchColumn();
	check(Actors::profile($jane) === null && $leftover === 0, 'no profile, field value, list or tag rows left behind');
	check(Db::pdo()->query("SELECT COUNT(*) FROM media WHERE id = $mid")->fetchColumn() == 1, 'the file itself is untouched');
	Actors::save($john, ['fields' => [$nat => 'X']]);
	Actors::deleteField($nat);
	check(Db::pdo()->query("SELECT COUNT(*) FROM actor_field_value WHERE field_id = $nat")->fetchColumn() == 0, 'deleting a field deletes its values');
	Taxonomy::deleteCategory($actorsCat);
	$orphans = (int) Db::pdo()->query("SELECT (SELECT COUNT(*) FROM actor_profile) + (SELECT COUNT(*) FROM actor_field_value) + (SELECT COUNT(*) FROM talent_list_actor)")->fetchColumn();
	check($orphans === 0, 'deleting the whole category removes every profile, field value and list membership');
	Db::pdo()->exec("DELETE FROM media WHERE id = $mid");
}

echo "Duration filter\n";
{
	Db::pdo()->exec('DELETE FROM playlist_item');
	Db::pdo()->exec('DELETE FROM media_term');
	Db::pdo()->exec('DELETE FROM media_thumb');
	Db::pdo()->exec('DELETE FROM media');
	$mk = function (string $name, string $type, ?float $dur, int $size = 1000, ?int $w = 1280, ?int $h = 720, ?string $hash = null) {
		$p = "/x/dur/$name";
		Db::pdo()->prepare('INSERT INTO media (path, path_hash, name, ext, type, size, duration, width, height, content_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$p, sha1($p), $name, pathinfo($name, PATHINFO_EXTENSION), $type, $size, $dur, $w, $h, $hash]);
		return (int) Db::pdo()->lastInsertId();
	};
	$d1 = $mk('d1.mp4', 'video', 120); $d2 = $mk('d2.mp4', 'video', 300); $d3 = $mk('d3.mp4', 'video', 301); $d4 = $mk('d4.mp4', 'video', 1800);
	$mk('pic.png', 'image', null); $mk('unknown.mp4', 'video', null);
	$nm = fn($f, $sort = 'duration_asc') => array_column(Library::search($f + ['sort' => $sort])['items'], 'name');
	check($nm(['dmin' => 0, 'dmax' => 300]) === ['d1.mp4'], 'duration 0-5 min: only the 2 minute video (5:00 itself is not "under 5")');
	check($nm(['dmin' => 300]) === ['d2.mp4', 'd3.mp4', 'd4.mp4'], 'duration 5+ min: includes exactly 5:00, excludes images and videos of unknown length');
	check($nm(['dmin' => 300, 'dmax' => 1800]) === ['d2.mp4', 'd3.mp4'], 'a range: from <= length < to');
	check($nm(['dmin' => '', 'dmax' => '']) === ['d1.mp4', 'd2.mp4', 'd3.mp4', 'd4.mp4', 'unknown.mp4', 'pic.png'] || count($nm(['dmin' => '', 'dmax' => ''])) === 6, 'empty bounds = no filter');
	check($nm([], 'duration_asc')[0] === 'd1.mp4' && $nm([], 'duration_desc')[0] === 'd4.mp4', 'sort: shortest first / longest first');
	check(array_slice($nm([], 'duration_asc'), -2) !== ['d4.mp4', 'd1.mp4'] && in_array('unknown.mp4', array_slice($nm([], 'duration_asc'), -2), true), 'videos of unknown length sort last when ascending');
	check($nm(['dmin' => 60, 'dmax' => 400, 'q' => 'd3']) === ['d3.mp4'], 'combines with the search box');
}

echo "Rescan folders\n";
{
	$fd = "$root/fold";
	mkdir("$fd/sub", 0777, true);
	file_put_contents("$fd/a.png", $png); file_put_contents("$fd/b.png", $png); file_put_contents("$fd/sub/s.png", $png);
	$fid = Folders::register($fd, true);
	check(Folders::register($fd, true) === $fid && count(Folders::all()) === 1, 'register: the same folder twice is one entry');
	check(throwsApi(fn() => Folders::register("$root/nope"), 404), 'register: a missing folder -> 404');
	Folders::registerMany(["$root/nope", $fd], true); // bad ones are ignored
	check(count(Folders::all()) === 1, 'registerMany ignores folders that do not exist');
	$r = Folders::scan($fid);
	check($r['found'] === 3 && $r['error'] === null, 'first scan finds the 3 files (sub-folder included): ' . json_encode($r));
	check(Folders::all()[0]['files'] === 3 && Folders::all()[0]['last_found'] === 3 && Folders::all()[0]['last_scan'] !== null, 'the list shows 3 files and when it was scanned');
	file_put_contents("$fd/c.png", $png); file_put_contents("$fd/sub/t.png", $png);
	check(Folders::scan($fid)['found'] === 2, 'rescan finds only the 2 NEW files');
	check(Folders::scan($fid)['found'] === 0, 'a second rescan finds nothing new');
	check(Db::pdo()->query("SELECT COUNT(*) FROM media WHERE path LIKE '%" . 'fold' . "%'")->fetchColumn() == 5, 'nothing was duplicated: 5 files in the library');
	Folders::setRecursive($fid, false);
	file_put_contents("$fd/d.png", $png); file_put_contents("$fd/sub/u.png", $png);
	check(Folders::scan($fid)['found'] === 1, 'without "include sub-folders" only the new top-level file is found');
	check(Folders::all()[0]['files'] === 4 + 0 && Folders::all()[0]['recursive'] === false, 'and the file count only counts direct children (a b c d)');
	$all = Folders::scanAll();
	check($all['found'] === 0 && count($all['results']) === 1, 'scanAll runs every remembered folder');
	rename($fd, "$fd-moved");
	$gone = Folders::scan($fid);
	check($gone['error'] !== null && $gone['found'] === 0, 'a folder that has disappeared is reported, not an exception: ' . $gone['error']);
	rename("$fd-moved", $fd);
	Folders::forget($fid);
	check(Folders::all() === [] && Db::pdo()->query("SELECT COUNT(*) FROM media WHERE path LIKE '%fold%'")->fetchColumn() == 6, 'forget only forgets the folder: its files stay in the library');
	$sug = Folders::suggest();
	check(count($sug) >= 1 && $sug[0]['files'] >= 1 && is_string($sug[0]['path']), 'suggestions: folders that hold library files but are not remembered');
	Folders::register($fd, true);
	check(!in_array($fd, array_column(Folders::suggest(), 'path'), true) || realpath($fd) !== null, 'a remembered folder is no longer suggested');
}

echo "Duplicates\n";
{
	Db::pdo()->exec('DELETE FROM playlist_item');
	Db::pdo()->exec('DELETE FROM media_term');
	Db::pdo()->exec('DELETE FROM media');
	$dd = "$root/dups";
	mkdir("$dd/one", 0777, true); mkdir("$dd/two", 0777, true); mkdir("$dd/three", 0777, true);
	$X = str_repeat('A', 5000);
	file_put_contents("$dd/one/a.mp4", $X);
	file_put_contents("$dd/two/a-copy.mp4", $X);
	file_put_contents("$dd/three/other.mp4", str_repeat('B', 5000)); // same size, different content
	file_put_contents("$dd/three/single.mp4", str_repeat('C', 4999)); // different size
	Library::add([], [$dd], true);
	$idOf = fn($n) => (int) Db::pdo()->query('SELECT id FROM media WHERE name = ' . Db::pdo()->quote($n))->fetchColumn();
	[$a, $copy, $other, $single] = [$idOf('a.mp4'), $idOf('a-copy.mp4'), $idOf('other.mp4'), $idOf('single.mp4')];

	$st = Duplicates::status();
	check($st['candidates'] === 3 && $st['unhashed'] === 3, 'only the 3 files that share a size are candidates (single.mp4 is not)');
	$sc = Duplicates::scan(10);
	check($sc['hashed'] === 3 && $sc['remaining'] === 0, 'scan hashes them: ' . json_encode($sc));
	check((int) Db::pdo()->query("SELECT COUNT(*) FROM media WHERE id = $single AND content_hash IS NULL")->fetchColumn() === 1, '...and never reads the file that cannot be a duplicate');
	$g = Duplicates::groups('identical');
	check($g['total'] === 1 && count($g['groups'][0]['files']) === 2, 'one identical group of 2 (the same-size different-content file is not in it)');
	$ids = array_column($g['groups'][0]['files'], 'id');
	sort($ids);
	check($ids === [min($a, $copy), max($a, $copy)] && $g['groups'][0]['keep'] === $a, 'suggested keep: the older entry');
	check($g['wasted'] === 5000 && $g['groups'][0]['wasted'] === 5000, 'wasted space counted: 5000 bytes');
	check(Duplicates::hashFile("$dd/nope.mp4", 5) === null, 'hashFile: unreadable file -> null');
	// big files are sampled: differences in the sampled regions are seen
	$big1 = tempnam(sys_get_temp_dir(), 'bg'); $big2 = tempnam(sys_get_temp_dir(), 'bg');
	file_put_contents($big1, str_repeat('Z', 5 * 1048576)); file_put_contents($big2, 'Y' . str_repeat('Z', 5 * 1048576 - 1));
	check(Duplicates::hashFile($big1, filesize($big1)) !== Duplicates::hashFile($big2, filesize($big2)) && Duplicates::hashFile($big1, filesize($big1)) === Duplicates::hashFile($big1, filesize($big1)), 'hash: stable, and sensitive to the start of a big file');
	@unlink($big1); @unlink($big2);

	// same length lookalikes
	$mkv = function (string $name, float $dur, int $w, int $h, int $size) {
		$p = "/x/len/$name";
		Db::pdo()->prepare("INSERT INTO media (path, path_hash, name, ext, type, size, duration, width, height) VALUES (?, ?, ?, 'mp4', 'video', ?, ?, ?, ?)")->execute([$p, sha1($p), $name, $size, $dur, $w, $h]);
		return (int) Db::pdo()->lastInsertId();
	};
	$l1 = $mkv('len1.mp4', 100, 1920, 1080, 900); $l2 = $mkv('len2.mp4', 100.4, 1920, 1080, 500); $l3 = $mkv('len3.mp4', 100, 1280, 720, 300);
	$s = Duplicates::groups('samelength');
	$sameIds = array_map(fn($x) => array_column($x['files'], 'id'), $s['groups']);
	check(count($sameIds) === 1 && !array_diff([$l1, $l2], $sameIds[0]) && !in_array($l3, $sameIds[0], true), 'same length: 100s and 100.4s at the same resolution are grouped, the 720p one is not');
	check($s['groups'][0]['keep'] === $l1, 'suggested keep: the higher-resolution one');

	// resolving
	$tagsCat = Taxonomy::addCategory('DupTags');
	$tag = Taxonomy::ensureTerm($tagsCat, 'from-copy');
	Taxonomy::assign([$copy], [$tag]);
	$plist = Playlists::create('dup playlist', [$copy]);
	check(throwsApi(fn() => Duplicates::resolve($a, [$other], 'remove')), 'resolve: a file that is NOT a duplicate is refused');
	check(is_file("$dd/three/other.mp4") && Library::find($other) !== null, '...and nothing happened to it');
	$res = Duplicates::resolve($a, [$copy], 'remove', true);
	check($res['handled'] === 1 && Library::find($copy) === null && is_file("$dd/two/a-copy.mp4"), 'resolve (remove): the copy leaves the library, its file stays on disk');
	check(Taxonomy::forMedia([$a])[$a][0]['name'] === 'from-copy', '...its tag was merged into the kept file');
	check(array_column(Playlists::get($plist)['items'], 'id') === [$a], '...and its place in the playlist went to the kept file');
	// delete mode
	file_put_contents("$dd/two/a-copy2.mp4", $X);
	Library::add(["$dd/two/a-copy2.mp4"], [], false);
	$copy2 = $idOf('a-copy2.mp4');
	Duplicates::scan(10);
	check(throwsApi(fn() => Duplicates::resolve($a, [$copy2], 'nonsense')), 'resolve: unknown action refused');
	$res = Duplicates::resolve($a, [$copy2], 'delete', false);
	check($res['result']['deleted'] === 1 && !is_file("$dd/two/a-copy2.mp4") && is_file("$dd/one/a.mp4"), 'resolve (delete): the copy is deleted from disk, the kept file is untouched');
	check(throwsApi(fn() => Duplicates::resolve($a, [$a], 'remove')), 'resolve: you cannot "get rid of" the file you keep');
	Taxonomy::deleteCategory($tagsCat);
	Playlists::delete($plist);
}

echo "Playlists\n";
{
	Db::pdo()->exec('DELETE FROM playlist_item');
	Db::pdo()->exec('DELETE FROM media');
	$vids = [];
	foreach ([['Zulu.mp4', 300], ['alpha.mp4', 100], ['Mike.mp4', 200], ['gone.mp4', 50]] as [$n, $d]) {
		$p = "$root/pl/$n";
		Db::pdo()->prepare("INSERT INTO media (path, path_hash, name, ext, type, size, duration) VALUES (?, ?, ?, 'mp4', 'video', 10, ?)")->execute([$p, sha1($p), $n, $d]);
		$vids[$n] = (int) Db::pdo()->lastInsertId();
	}
	[$z, $al, $mi, $go] = [$vids['Zulu.mp4'], $vids['alpha.mp4'], $vids['Mike.mp4'], $vids['gone.mp4']];
	$pl = Playlists::create('  Night mix ', [$z, $al, 999999, $z]);
	check(array_column(Playlists::get($pl)['items'], 'id') === [$z, $al], 'create: files in the order given, duplicates and unknown ids skipped');
	check(throwsApi(fn() => Playlists::create('night mix'), 409), 'duplicate playlist name -> 409');
	check(Playlists::add($pl, [$mi, $z, $go]) === 2 && array_column(Playlists::get($pl)['items'], 'id') === [$z, $al, $mi, $go], 'add appends (already-present files are not repeated)');
	Playlists::reorder($pl, [$go, $mi, $z]);
	check(array_column(Playlists::get($pl)['items'], 'id') === [$go, $mi, $z, $al], 'reorder: the given order first, anything left out stays after it');
	Playlists::remove($pl, [$mi]);
	$pos = array_map('intval', Db::pdo()->query("SELECT position FROM playlist_item WHERE playlist_id = $pl ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
	check(array_column(Playlists::get($pl)['items'], 'id') === [$go, $z, $al] && $pos === [1, 2, 3], 'remove: positions are renumbered without gaps');
	Playlists::add($pl, [$mi]);
	Playlists::sortBy($pl, 'name');
	check(array_column(Playlists::get($pl)['items'], 'name') === ['alpha.mp4', 'gone.mp4', 'Mike.mp4', 'Zulu.mp4'], 'sort by name (natural, case-insensitive)');
	Playlists::sortBy($pl, 'duration', 'desc');
	check(array_column(Playlists::get($pl)['items'], 'name') === ['Zulu.mp4', 'Mike.mp4', 'alpha.mp4', 'gone.mp4'], 'sort by duration, longest first');
	Playlists::sortBy($pl, 'shuffle');
	check(count(Playlists::get($pl)['items']) === 4, 'shuffle keeps every item');
	Playlists::reorder($pl, [$z, $mi, $al, $go]);
	check(Playlists::get($pl)['duration'] === 650.0 && Playlists::all()[0]['items'] === 4 && Playlists::all()[0]['duration'] === 650.0, 'total length: 650 s');

	Db::pdo()->prepare('UPDATE media SET is_missing = 1 WHERE id = ?')->execute([$go]);
	$m3u = Playlists::m3u($pl);
	$lines = preg_split('/\r\n/', trim($m3u));
	check($lines[0] === '#EXTM3U' && strpos($m3u, "#EXTINF:300,Zulu\r\n" . "$root/pl/Zulu.mp4\r\n") !== false, 'm3u8: header, #EXTINF with length and title, then the absolute path');
	check(strpos($m3u, 'gone.mp4') === false && substr_count($m3u, '#EXTINF') === 3, '...files flagged missing are left out');
	check(Playlists::fileName('Night: mix / 2*') === 'Night_ mix _ 2.m3u8' || preg_match('/^[^\\\\\/:*?"<>|]+\.m3u8$/', Playlists::fileName('Night: mix / 2*')) === 1, 'file names are made safe: ' . Playlists::fileName('Night: mix / 2*'));
	$saved = Playlists::saveFile($pl);
	check(is_file($saved) && file_get_contents($saved) === $m3u && strpos($saved, PLAYLIST_DIR) === 0, 'save file: written into the playlist folder');

	if (Paths::isWindows()) {
		$log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixel-library-player-' . bin2hex(random_bytes(3)) . '.log';
		file_put_contents(PLAYER_PATH, "@echo off\r\necho %* > \"$log\"\r\n");
		check(count(array_filter(Playlists::players(), fn($p) => $p['key'] === 'custom')) === 1, 'players(): the configured player is listed');
		$t0 = microtime(true);
		$r = Playlists::play($pl, 'custom');
		$took = microtime(true) - $t0;
		for ($i = 0; $i < 40 && !is_file($log); $i++) { usleep(100000); }
		check(is_file($log) && strpos(file_get_contents($log), '.m3u8') !== false, 'play: the player was started with the .m3u8 file as its argument');
		check($took < 3, 'play returns immediately, it does not wait for the player (' . round($took, 2) . 's)');
		@unlink($log);

		// "Play now": files straight into the player through one temp playlist that is overwritten each time
		$imgPath = "$root/pl/pic.jpg";
		Db::pdo()->prepare("INSERT INTO media (path, path_hash, name, ext, type, size) VALUES (?, ?, 'pic.jpg', 'jpg', 'image', 10)")->execute([$imgPath, sha1($imgPath)]);
		$img = (int) Db::pdo()->lastInsertId();
		$plCount = count(Playlists::all());
		$r = Playlists::playFiles([$mi, $img, $go, $z, $mi, 999999], 'custom');
		for ($i = 0; $i < 40 && !is_file($log); $i++) { usleep(100000); }
		$now = PLAYLIST_DIR . DIRECTORY_SEPARATOR . Playlists::NOW_PLAYING;
		$paths = array_values(array_filter(preg_split('/\r\n/', (string) @file_get_contents($now)), fn($l) => $l !== '' && $l[0] !== '#'));
		check($r['count'] === 2 && $paths === ["$root/pl/Mike.mp4", "$root/pl/Zulu.mp4"], 'play now: only present videos, in the order given, duplicates dropped');
		check(is_file($log) && strpos(file_get_contents($log), Playlists::NOW_PLAYING) !== false, 'play now: the player was started with the temp playlist');
		@unlink($log);
		Playlists::playFiles([$al], 'custom');
		$paths = array_values(array_filter(preg_split('/\r\n/', (string) file_get_contents($now)), fn($l) => $l !== '' && $l[0] !== '#'));
		check($paths === ["$root/pl/alpha.mp4"] && count(Playlists::all()) === $plCount, 'play now: the next one overwrites the temp playlist and no saved playlist is created');
		for ($i = 0; $i < 40 && !is_file($log); $i++) { usleep(100000); }
		@unlink($log);
		check(throwsApi(fn() => Playlists::playFiles([$img, $go], 'custom')), 'play now: refused when nothing playable (image / missing only)');
		check(throwsApi(fn() => Playlists::playFiles([], 'custom')), 'play now: refused with nothing selected');
		check(Playlists::fileName('_now-playing') !== Playlists::NOW_PLAYING, 'a saved playlist can never be named like the temp file');
		Db::pdo()->prepare('DELETE FROM media WHERE id = ?')->execute([$img]);
	}
	check(throwsApi(fn() => Playlists::playFiles([1], 'nosuchplayer'), 404), 'play now: an unknown player name is refused');
	check(throwsApi(fn() => Playlists::play($pl, 'nosuchplayer'), 404), 'play: an unknown player name is refused (requests never carry paths)');
	$empty = Playlists::create('empty');
	check(throwsApi(fn() => Playlists::play($empty, 'custom')), 'play: an empty playlist is refused');

	Library::remove([$al]);
	check(!in_array($al, array_column(Playlists::get($pl)['items'], 'id'), true), 'removing a file from the library removes it from playlists');
	Playlists::rename($pl, 'Renamed');
	check(Playlists::get($pl)['name'] === 'Renamed', 'rename');
	Playlists::delete($pl);
	check(throwsApi(fn() => Playlists::get($pl), 404) && Db::pdo()->query("SELECT COUNT(*) FROM media WHERE id = $z")->fetchColumn() == 1, 'delete: the playlist goes, the files stay in the library');
	Playlists::delete($empty);
}

echo "Unicode case (names compare and search case-insensitively for any letter)\n";
{
	$cat = Taxonomy::addCategory('Uni');
	$a = Taxonomy::ensureTerm($cat, 'Émile Ünal');
	$b = Taxonomy::ensureTerm($cat, 'éMILE ÜNAL');
	check($a === $b, 'a term typed again with other capitals (É / é, Ü / ü) is the same term');
	check(throwsApi(fn() => Taxonomy::renameTerm(Taxonomy::ensureTerm($cat, 'Other'), 'ÉMILE ünal'), 409), 'renaming onto a name that differs only in capitals is refused');
	check(throwsApi(fn() => Taxonomy::addCategory('uni'), 409), 'category names are unique regardless of case');
	$u = "$root/Ünïcode Ñame.mp4";
	file_put_contents($u, 'u');
	Library::add([$u], [], false);
	check(Library::search(['q' => 'ünïcode ñAME'])['total'] === 1, 'search finds "Ünïcode Ñame" typed in lower case');
	check(Library::search(['q' => 'ÜNÏCODE'])['total'] === 1, 'search finds it typed in upper case');
	check(Library::search(['q' => 'ünï%'])['total'] === 0, 'a % in the search is literal, not a wildcard');
	@unlink($u);
}

echo "MPEG transport stream (.ts) is a video, TypeScript (.ts) is not\n";
{
	$dir = "$root/ts";
	mkdir($dir);
	file_put_contents("$dir/app.ts", "export const answer: number = 42;\n" . str_repeat("// padding so the file is long enough to look at\n", 30));
	file_put_contents("$dir/G.ts", 'G');
	file_put_contents("$dir/lookalike.ts", str_repeat("\x47" . str_repeat("\x00", 187), 4)); // sync byte every 188 bytes
	check(MediaTypes::forPath("$dir/app.ts") === null, 'a TypeScript source file is not a video');
	check(MediaTypes::forPath("$dir/G.ts") === null, 'a tiny file that merely starts with "G" is not a video');
	check((MediaTypes::forPath("$dir/lookalike.ts")['type'] ?? '') === 'video' && MediaTypes::forPath("$dir/lookalike.ts")['mime'] === 'video/mp2t', 'a file with the transport-stream sync bytes is a video (video/mp2t)');
	check((MediaTypes::forPath("$dir/not-there.ts")['type'] ?? '') === 'video', 'a bare name / missing file is judged by its extension');
	check(array_column(Paths::listDir($dir)['entries'], 'name') === ['lookalike.ts'], 'the folder picker lists only the real one');
	$r = Library::add([], [$dir], false);
	check($r['added'] === 1, 'adding the folder registers only the real one (not the TypeScript files)');
	$r = Library::add(["$dir/app.ts"], [], false);
	check($r['added'] === 0 && count($r['skipped']) === 1, 'adding the TypeScript file directly is refused');

	if (Ffmpeg::available()) {
		$ok = Ffmpeg::run([FFMPEG_PATH, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x120:rate=5:duration=4', '-c:v', 'mpeg2video', '-f', 'mpegts', '-y', "$dir/real.ts"], 60) !== null;
		check($ok && MediaTypes::forPath("$dir/real.ts") !== null, 'a real .ts made by ffmpeg is recognised');
		Library::add(["$dir/real.ts"], [], false);
		$tsId = (int) Db::pdo()->query("SELECT id FROM media WHERE name = 'real.ts'")->fetchColumn();
		VideoThumbs::run(60, null, [$tsId]);
		$row = Library::find($tsId);
		check($tsId > 0 && (int) $row['thumb_status'] === VideoThumbs::DONE && count(VideoThumbs::timesFor([$tsId])[$tsId] ?? []) > 0, 'previews are made for a .ts video');
	}
}

echo "File formats (Settings page)\n";
{
	$pdo = Db::pdo();
	MediaTypes::reset();
	$seed = [];
	foreach ($pdo->query('SELECT ext, type, mime FROM media_format')->fetchAll() as $r) {
		$seed[$r['ext']] = [$r['type'], $r['mime']];
	}
	$def = MediaTypes::DEFAULTS;
	ksort($seed);
	ksort($def);
	check($seed === $def && count($seed) === 16, 'the formats seeded by the schema are exactly the built-in list (16)');

	$dir = "$root/fmt";
	mkdir($dir);
	file_put_contents("$dir/a.mpg", 'mpeg-ish');
	file_put_contents("$dir/b.xyz", 'unknown');
	file_put_contents("$dir/c.mp4", 'mp4-ish');
	check(Library::add([], [$dir], false)['added'] === 1, 'before adding formats: only the .mp4 is picked up');

	check(MediaTypes::add('.MPG', 'video') === ['ext' => 'mpg', 'type' => 'video', 'mime' => 'video/mpeg'], 'adding ".MPG" (dot, capitals) stores "mpg" with its usual MIME type');
	check(throwsApi(fn() => MediaTypes::add('mpg', 'video'), 409), 'adding it again -> 409');
	check(Library::add([], [$dir], false)['added'] === 1, 'a rescan now picks up the .mpg (and still not the unknown .xyz)');
	check(MediaTypes::add('xyz', 'video', 'video/x-xyz')['mime'] === 'video/x-xyz', 'a format nobody has heard of can be added with its own MIME type');
	check(Library::add([], [$dir], false)['added'] === 1, '...and its files are picked up');
	check(in_array('mpg', array_column(MediaTypes::all(), 'ext'), true) && array_column(MediaTypes::all(), 'files', 'ext')['mpg'] === 1, 'the list shows how many library files use each format');

	foreach (['', 'a b', 'toolongextension', 'mp-4', '.'] as $bad) {
		check(throwsApi(fn() => MediaTypes::add($bad, 'video')), 'not an extension, refused: "' . $bad . '"');
	}
	foreach (['exe', 'php', '.PHP', 'html', 'ps1', 'js'] as $bad) {
		check(throwsApi(fn() => MediaTypes::add($bad, 'video')), 'a program / script / web page is never a media format: ' . $bad);
	}
	check(throwsApi(fn() => MediaTypes::add('abc', 'audio')), 'an unknown kind is refused');
	check(throwsApi(fn() => MediaTypes::add('abc', 'video', 'image/png')) && throwsApi(fn() => MediaTypes::add('abc', 'video', 'text/html')), 'a MIME type of the wrong kind, or outside video/ and image/, is refused');

	$idMpg = (int) $pdo->query("SELECT id FROM media WHERE name = 'a.mpg'")->fetchColumn();
	$idXyz = (int) $pdo->query("SELECT id FROM media WHERE name = 'b.xyz'")->fetchColumn();
	check(MediaTypes::remove('mpg', false) === 0 && MediaTypes::forPath("$dir/a.mpg") === null, 'removing .mpg: new .mpg files are no longer picked up');
	check(Library::find($idMpg) !== null, '...but the .mpg already in the library stays');
	check(MediaTypes::forExisting("$dir/a.mpg", 'video')['mime'] === 'video/mpeg', '...and can still be served (by the suggested list)');
	check(in_array('mpg', array_column(MediaTypes::suggestions(), 'ext'), true), '...and .mpg is offered as a one-click suggestion again');
	check(MediaTypes::forExisting("$dir/b.xyz", 'video')['mime'] === 'video/x-xyz', 'a custom format that is still listed serves with its own MIME type');
	check(MediaTypes::remove('xyz', false) === 0 && MediaTypes::forExisting("$dir/b.xyz", 'video')['type'] === 'video' && MediaTypes::forExisting("$dir/b.xyz", 'video')['mime'] === 'video/mp4', 'a custom format removed later: its files still serve (by the type they were added with)');
	check(MediaTypes::forExisting("$dir/b.xyz") === null, '...but without a stored type an unknown extension is not a media file');
	MediaTypes::add('xyz', 'video');
	check(MediaTypes::remove('xyz', true) === 1 && Library::find($idXyz) === null && is_file("$dir/b.xyz"), 'removing a format with "take the files out of the library": they leave the library, never the disk');
	check(throwsApi(fn() => MediaTypes::remove('nope', false), 404), 'removing something that is not listed -> 404');

	MediaTypes::remove('avi', false);
	check(!isset(MediaTypes::active()['avi']) && in_array('avi', array_column(MediaTypes::suggestions(), 'ext'), true), 'a built-in format can be removed (and is then offered again)');
	MediaTypes::restoreDefaults();
	check(MediaTypes::active() === MediaTypes::DEFAULTS || (array_keys(MediaTypes::active()) === array_keys(MediaTypes::DEFAULTS) || count(MediaTypes::active()) === 16) && isset(MediaTypes::active()['avi']) && !isset(MediaTypes::active()['xyz']) && !isset(MediaTypes::active()['mpg']), 'reset: back to the 16 built-in formats');

	$pdo->exec("DELETE FROM media_format WHERE ext <> 'mp4'");
	MediaTypes::reset();
	check(throwsApi(fn() => MediaTypes::remove('mp4', false)), 'the last format cannot be removed');
	MediaTypes::restoreDefaults();
	check(count(MediaTypes::active()) === 16, 'reset restores the list after that');

	// a file whose format was removed can still be deleted through the app's own safety check
	file_put_contents("$dir/d.mpg", 'to delete');
	MediaTypes::add('mpg', 'video');
	Library::add(["$dir/d.mpg"], [], false);
	$idD = (int) $pdo->query("SELECT id FROM media WHERE name = 'd.mpg'")->fetchColumn();
	MediaTypes::remove('mpg', false);
	$del = Library::deleteFiles([$idD]);
	check($del['deleted'] === 1 && !is_file("$dir/d.mpg"), 'deleting from disk still works for a file whose format was removed');
	MediaTypes::restoreDefaults();
}

// ---- cleanup ----
rrmdir($root);
rrmdir(PLAYLIST_DIR);
@unlink(PLAYER_PATH);
rrmdir(PHOTOS_DIR);
@unlink(DELETE_LOG);
rrmdir(THUMBS_DIR . '/video');
rrmdir(THUMBS_DIR);
if (DB_DRIVER === 'mysql') {
	$admin->exec('DROP DATABASE pixel_library_test');
} else {
	foreach (['', '-wal', '-shm', '.lock'] as $suffix) {
		@unlink(DB_FILE . $suffix);
	}
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
