<?php
/**
 * Copies a library from MySQL / MariaDB into a SQLite file. The MySQL database is only READ, never changed.
 * Every id is kept, so tags, playlists, actor photos and preview folders (named after the file's id) stay valid.
 *
 *   php bin/migrate-mysql-to-sqlite.php [--out=FILE] [--force]
 *        [--mysql-host=localhost] [--mysql-user=root] [--mysql-pass=] [--mysql-db=pixel_library]
 *
 *   --out    the SQLite file to write (default: DB_FILE from the config, storage/pixel-library.sqlite)
 *   --force  replace the output file if it already exists (otherwise the script refuses to touch it)
 *
 * Run it again whenever you like: with --force it rebuilds the SQLite file from the current MySQL data.
 * Then point the app at it: DB_DRIVER = 'sqlite' in config.local.php (or remove the DB_DRIVER / DB_* lines).
 */

if (PHP_SAPI !== 'cli') {
	exit('CLI only.');
}

$opt = ['out' => null, 'force' => false, 'mysql-host' => 'localhost', 'mysql-user' => 'root', 'mysql-pass' => '', 'mysql-db' => 'pixel_library'];
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--force') {
		$opt['force'] = true;
	} elseif (preg_match('/^--(out|mysql-host|mysql-user|mysql-pass|mysql-db)=(.*)$/s', $arg, $m)) {
		$opt[$m[1]] = $m[2];
	} else {
		fwrite(STDERR, "Unknown option: $arg\nSee the top of bin/migrate-mysql-to-sqlite.php for usage.\n");
		exit(2);
	}
}

// The target is always SQLite, whatever config.local.php says (its own definitions are ignored: they come second).
define('DB_DRIVER', 'sqlite');
if ($opt['out'] !== null) {
	define('DB_FILE', $opt['out']);
}
error_reporting(E_ALL & ~E_WARNING);
require __DIR__ . '/../config.php';
error_reporting(E_ALL);
require __DIR__ . '/../common/autoload.php';

// Parents before children (foreign keys).
const TABLES = ['media', 'media_thumb', 'category', 'term', 'media_term', 'actor_profile', 'field_def', 'actor_field_value',
	'talent_list', 'talent_list_actor', 'library_folder', 'playlist', 'playlist_item'];

if (!in_array('pdo_mysql', get_loaded_extensions(), true)) {
	fwrite(STDERR, "The pdo_mysql extension is not loaded in this PHP (start.bat's php.standalone.ini loads it; or run this with XAMPP's php.exe).\n");
	exit(1);
}
try {
	$src = new PDO('mysql:host=' . $opt['mysql-host'] . ';dbname=' . $opt['mysql-db'] . ';charset=utf8mb4', $opt['mysql-user'], $opt['mysql-pass'], [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
} catch (PDOException $e) {
	fwrite(STDERR, 'Cannot open the MySQL database: ' . $e->getMessage() . "\n");
	exit(1);
}
$src->exec('SET SESSION TRANSACTION READ ONLY');

$file = DB_FILE;
if (is_file($file) && filesize($file) > 0) {
	if (!$opt['force']) {
		fwrite(STDERR, "$file already exists. Use --force to replace it (a copy is kept as $file.bak).\n");
		exit(1);
	}
	@unlink("$file.bak");
	foreach (['-wal', '-shm', '.lock'] as $s) {
		@unlink($file . $s);
	}
	rename($file, "$file.bak");
}

$dst = Db::pdo(); // creates the file with the current schema
$dst->exec('DELETE FROM category'); // the starter categories: the real ones come from MySQL
echo "MySQL {$opt['mysql-db']} -> $file\n";

$counts = [];
$dst->beginTransaction();
try {
	foreach (TABLES as $table) {
		$cols = array_column($dst->query("PRAGMA table_info($table)")->fetchAll(), 'name');
		$have = $src->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
		$use = array_values(array_intersect($cols, $have));
		$missing = array_diff($cols, $use);
		if ($missing) {
			echo "  note: $table has no source column(s) " . implode(', ', $missing) . " - they get their defaults\n";
		}
		$insert = $dst->prepare("INSERT INTO $table (" . implode(', ', $use) . ') VALUES (' . Db::placeholders(count($use)) . ')');
		$n = 0;
		$rows = $src->query('SELECT ' . implode(', ', array_map(fn($c) => "`$c`", $use)) . " FROM `$table`");
		foreach ($rows as $row) {
			$i = 0;
			foreach ($use as $c) {
				$v = $row[$c];
				$insert->bindValue(++$i, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
			}
			$insert->execute();
			$n++;
		}
		$counts[$table] = $n;
		echo sprintf("  %-20s %7d rows\n", $table, $n);
	}

	// Keep the id counters where MySQL had them, so an id that was deleted is not handed out again.
	foreach (TABLES as $table) {
		if (!$dst->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn()) {
			break;
		}
		$next = $src->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
		$next->execute([$opt['mysql-db'], $table]);
		$auto = (int) $next->fetchColumn();
		if ($auto > 1) {
			$seq = $dst->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
			$seq->execute([$table]);
			$cur = $seq->fetchColumn();
			$seq->closeCursor(); // an unfinished SELECT would block the checkpoint below
			if ($cur === false) {
				$dst->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$table, $auto - 1]);
			} elseif ((int) $cur < $auto - 1) {
				$dst->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?')->execute([$auto - 1, $table]);
			}
		}
	}
	$dst->commit();
} catch (Throwable $e) {
	if ($dst->inTransaction()) {
		$dst->rollBack();
	}
	fwrite(STDERR, 'FAILED, nothing was kept: ' . $e->getMessage() . "\n");
	exit(1);
}

// ---- verify ----
$bad = 0;
foreach (TABLES as $table) {
	$in = (int) $dst->query("SELECT COUNT(*) FROM $table")->fetchColumn();
	$out = (int) $src->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
	if ($in !== $out) {
		echo "  MISMATCH in $table: MySQL $out, SQLite $in\n";
		$bad++;
	}
}
$check = $dst->query('PRAGMA integrity_check')->fetchColumn();
$fk = $dst->query('PRAGMA foreign_key_check')->fetchAll();
if ($check !== 'ok' || $fk) {
	echo '  integrity: ' . $check . ', foreign-key problems: ' . count($fk) . "\n";
	$bad++;
}
try {
	$dst->exec('PRAGMA wal_checkpoint(TRUNCATE)'); // fold the write-ahead log into the main file
} catch (PDOException $e) {
	// harmless: SQLite folds it in later
}
echo $bad ? "DONE WITH PROBLEMS - do not switch to this file.\n" : "Done - every table matches and the integrity check passed.\n";
exit($bad ? 1 : 0);
