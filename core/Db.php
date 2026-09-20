<?php

/**
 * The one place a database connection is made.
 *
 * Two engines, chosen by DB_DRIVER in the config:
 *   - 'sqlite' (the default): one file (DB_FILE), nothing else to install or run. The schema is created (and later
 *     upgraded) automatically the first time the app connects.
 *   - 'mysql': MySQL / MariaDB, set up by hand from db/schema.mysql.sql.
 *
 * All SQL lives in the core/ classes and is plain SQL that both engines understand (no ON DUPLICATE KEY /
 * INSERT IGNORE / VALUES(), no engine-specific functions, explicit deletes instead of relying on cascades).
 * The few places where the engines differ are handled here:
 *   - text comparison: MySQL's utf8mb4_unicode_ci is case-insensitive. On SQLite the columns that need that are
 *     declared COLLATE UNICODE_CI (a collation registered below) and LIKE is replaced by a Unicode-aware one.
 *   - a PDO statement binds every value as text; SQLite does not convert text to a number when it is compared with
 *     an EXPRESSION (only with a column), so write `ROUND(x) = ROUND(?)` rather than `ROUND(x) = ?`.
 */
class Db {

	/** Version of db/schema.sqlite.sql (its last line sets PRAGMA user_version to the same number). */
	public const SCHEMA_VERSION = 5;

	private static ?PDO $pdo = null;

	public static function driver(): string {
		return DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite';
	}

	public static function pdo(): PDO {
		if (self::$pdo === null) {
			try {
				self::$pdo = self::driver() === 'mysql' ? self::connectMysql() : self::connectSqlite();
			} catch (PDOException $e) {
				error_log('pixel-library: DB connection failed: ' . $e->getMessage());
				throw new ApiException(APP_DEBUG ? 'Database connection failed: ' . $e->getMessage() : 'Database connection failed.', 500);
			}
		}
		return self::$pdo;
	}

	private static function connectMysql(): PDO {
		return new PDO(
			'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
			DB_USER,
			DB_PASS,
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			]
		);
	}

	private static function connectSqlite(): PDO {
		$dir = dirname(DB_FILE);
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new PDOException('The database folder ' . $dir . ' cannot be created.');
		}
		$pdo = new PDO('sqlite:' . DB_FILE, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$pdo->exec('PRAGMA busy_timeout = 15000');   // wait for another process (the web server + a worker) instead of failing
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec('PRAGMA journal_mode = WAL');     // readers never block the writer
		$pdo->exec('PRAGMA synchronous = NORMAL');
		self::registerUnicodeCaseFunctions($pdo);
		self::upgradeSqlite($pdo);
		return $pdo;
	}

	/** Creates the tables on a new database file, and applies db/migrations/sqlite/NNN-*.sql that are newer than the file. */
	private static function upgradeSqlite(PDO $pdo): void {
		$version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
		if ($version >= self::SCHEMA_VERSION) {
			return;
		}
		$lock = fopen(DB_FILE . '.lock', 'c'); // two requests must not both try to create / upgrade
		if ($lock !== false) {
			flock($lock, LOCK_EX);
		}
		try {
			$version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
			if ($version === 0 && $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'media'")->fetchColumn() == 0) {
				$pdo->exec((string) file_get_contents(__DIR__ . '/../db/schema.sqlite.sql'));
				return;
			}
			$files = glob(__DIR__ . '/../db/migrations/sqlite/*.sql') ?: [];
			sort($files);
			foreach ($files as $file) {
				$n = (int) basename($file);
				if ($n <= $version) {
					continue;
				}
				$pdo->beginTransaction();
				try {
					$pdo->exec((string) file_get_contents($file));
					$pdo->exec('PRAGMA user_version = ' . $n);
					$pdo->commit();
				} catch (Throwable $e) {
					if ($pdo->inTransaction()) {
						$pdo->rollBack();
					}
					throw $e;
				}
			}
		} finally {
			if ($lock !== false) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/**
	 * SQLite only knows upper/lower case for A-Z. These make names compare, sort and search like MySQL's
	 * case-insensitive collation does for any letter (e.g. "Émile" = "émile").
	 */
	private static function registerUnicodeCaseFunctions(PDO $pdo): void {
		// IMPORTANT: SQLite indexes are stored in this collation's order. If sortKey() ever changes, every index on a UNICODE_CI
		// column must be rebuilt (REINDEX) or lookups silently go wrong.
		$pdo->sqliteCreateCollation('UNICODE_CI', fn($a, $b) => strcmp(self::sortKey((string) $a), self::sortKey((string) $b)));

		$patterns = []; // LIKE pattern => compiled matcher, so a search over thousands of rows compiles it once
		// "X LIKE Y ESCAPE Z" is like(Y, X, Z). Replacing it changes what every LIKE in the app does (only the case rule).
		$pdo->sqliteCreateFunction('like', function ($pattern, $value, $escape = null) use (&$patterns) {
			if ($pattern === null || $value === null) {
				return null;
			}
			$key = $pattern . "\0" . ($escape ?? '');
			if (!isset($patterns[$key])) {
				if (count($patterns) > 200) {
					$patterns = [];
				}
				$patterns[$key] = self::compileLike((string) $pattern, $escape === null || $escape === '' ? null : (string) $escape);
			}
			return $patterns[$key]((string) $value) ? 1 : 0;
		}, -1);
	}

	/** Punctuation in the order MySQL's utf8mb4_unicode_ci sorts it (all of it before digits, which come before letters). */
	private const PUNCT_ORDER = " _-,;:!?.'\"()[]{}@*/\\&#%`^+<=>|~$";

	private static array $sortKeys = [];

	/**
	 * A string that compares (byte-wise) the way MySQL's utf8mb4_unicode_ci orders names: capitals = small letters,
	 * punctuation and symbols first, then digits, then letters, emoji last. Two names get the same key only if they differ in
	 * capitals alone (unlike MySQL, accents and emoji still tell names apart).
	 */
	public static function sortKey(string $s): string {
		if (isset(self::$sortKeys[$s])) {
			return self::$sortKeys[$s];
		}
		if (count(self::$sortKeys) > 20000) {
			self::$sortKeys = [];
		}
		$chars = preg_split('//u', mb_strtolower($s, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) { // not valid UTF-8: keep the bytes as they are
			return self::$sortKeys[$s] = "\x03" . $s;
		}
		$key = '';
		foreach ($chars as $c) {
			if (isset($c[3])) { // 4 bytes (emoji and other characters beyond U+FFFF): MySQL gives them all one weight above every letter
				$key .= "\x04";
			} elseif (isset($c[1])) { // more than one byte
				if (preg_match('/^[\p{L}\p{M}]$/u', $c)) {
					$key .= "\x03" . $c;
				} elseif (preg_match('/^\p{N}$/u', $c)) {
					$key .= "\x02" . $c;
				} else {
					$key .= "\x01\x7f" . $c; // symbols, emoji, unusual spaces
				}
			} elseif (ctype_alpha($c)) {
				$key .= "\x03" . $c;
			} elseif (ctype_digit($c)) {
				$key .= "\x02" . $c;
			} else {
				$p = strpos(self::PUNCT_ORDER, $c);
				$key .= $p === false ? "\x01\x7f" . $c : "\x01" . chr($p + 1);
			}
		}
		// Tie-break on the real characters: MySQL calls "🔥" and "💖" equal, which would make such names collide as unique names.
		return self::$sortKeys[$s] = $key . "\x00" . implode('', $chars);
	}

	/** @return callable(string): bool */
	private static function compileLike(string $pattern, ?string $escape): callable {
		$chars = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$regex = '';
		$literal = ''; // set while the pattern is plain "%text%" (the common case: a search word) - no regex needed then
		$plain = true;
		$leading = ($chars[0] ?? '') === '%';
		$n = count($chars);
		for ($i = 0; $i < $n; $i++) {
			$c = $chars[$i];
			if ($escape !== null && $c === $escape && $i + 1 < $n) {
				$c = $chars[++$i];
				$regex .= preg_quote($c, '/');
				$literal .= $c;
			} elseif ($c === '%') {
				$regex .= '.*';
				if ($i !== 0 && $i !== $n - 1) {
					$plain = false;
				}
			} elseif ($c === '_') {
				$regex .= '.';
				$plain = false;
			} else {
				$regex .= preg_quote($c, '/');
				$literal .= $c;
			}
		}
		if ($plain && $leading && $n >= 2 && $chars[$n - 1] === '%' && $literal !== '') {
			return fn(string $v): bool => mb_stripos($v, $literal, 0, 'UTF-8') !== false;
		}
		$re = '/^' . $regex . '$/isu';
		return fn(string $v): bool => preg_match($re, $v) === 1;
	}

	/** "?,?,?" for an IN (...) list of $n values. */
	public static function placeholders(int $n): string {
		return implode(',', array_fill(0, $n, '?'));
	}

	/** True if $e is a unique-key / constraint violation (SQLSTATE 23000 on MySQL and SQLite). */
	public static function isDuplicate(PDOException $e): bool {
		return $e->getCode() === '23000';
	}
}
