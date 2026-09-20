<?php

/**
 * Preview frames for videos: 5-10 JPEGs per video at evenly spaced points,
 * stored under storage/thumbs/video/<media_id>/ and pointed to by media_thumb rows.
 *
 * Generation is a queue, not part of "add": media.thumb_status is 0 (pending) for every new
 * video, and workers (the "Generate previews" button, or bin/thumbs.php) drain it. A video
 * is claimed by setting thumb_status = 3 so two workers never take the same one.
 */
class VideoThumbs {

	public const PENDING = 0;
	public const DONE = 1;
	public const FAILED = 2;
	public const RUNNING = 3;

	private const STALE_CLAIM_MINUTES = 15;
	private const MIN_FRAMES = 3; // fewer than this (or than planned, if less) => treat the video as failed

	public static function dir(): string {
		return rtrim(THUMBS_DIR, '/\\') . '/video';
	}

	/**
	 * Where to grab frames: N points at the middle of N equal slices of the video
	 * (10 frames = 5%, 15%, ... 95%). Middles avoid the black first/last frames, and
	 * N grows with length so short clips aren't over-sampled: <1 min 5, <5 min 6, <20 min 8, else 10
	 * (under 3 seconds: a single frame from the start).
	 * @return array<int, array{pct: float, time: float}>
	 */
	public static function plan(float $duration): array {
		if ($duration < 3) { // too short for several distinct frames (and a 1-frame clip has nothing after t=0)
			return [['pct' => 0.0, 'time' => 0.0]];
		}
		$n = $duration < 60 ? 5 : ($duration < 300 ? 6 : ($duration < 1200 ? 8 : 10));
		$last = max(0.0, $duration - 0.5); // stay clear of the very end, where a seek may find no frame
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$pct = ($i + 0.5) / $n;
			$out[] = ['pct' => $pct * 100, 'time' => round(min($duration * $pct, $last), 2)];
		}
		return $out;
	}

	/** Counts for non-missing videos: total, pending, done, failed, running. */
	public static function stats(): array {
		$rows = Db::pdo()->query(
			"SELECT thumb_status, COUNT(*) AS n FROM media WHERE type = 'video' AND is_missing = 0 GROUP BY thumb_status"
		)->fetchAll();
		$s = ['pending' => 0, 'done' => 0, 'failed' => 0, 'running' => 0];
		$names = [self::PENDING => 'pending', self::DONE => 'done', self::FAILED => 'failed', self::RUNNING => 'running'];
		foreach ($rows as $r) {
			$s[$names[(int) $r['thumb_status']] ?? 'pending'] += (int) $r['n'];
		}
		$s['total'] = array_sum($s);
		return $s;
	}

	/** Puts failed videos back in the queue. Returns how many. */
	public static function retryFailed(): int {
		$stmt = Db::pdo()->prepare("UPDATE media SET thumb_status = 0, thumb_claimed_at = NULL WHERE type = 'video' AND thumb_status = ?");
		$stmt->execute([self::FAILED]);
		return $stmt->rowCount();
	}

	/**
	 * Queues specific videos (e.g. the user's selection): failed ones always, and finished ones too when $force
	 * (to regenerate). Videos already pending/running are left as they are; missing files and images are ignored.
	 * @param int[] $ids
	 * @return int[] the ids of those videos that are now waiting in the queue
	 */
	public static function queue(array $ids, bool $force = false): array {
		$ids = self::cleanIds($ids);
		$pdo = Db::pdo();
		$statuses = $force ? self::DONE . ', ' . self::FAILED : (string) self::FAILED;
		$waiting = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$in = Db::placeholders(count($chunk));
			$pdo->prepare(
				"UPDATE media SET thumb_status = 0, thumb_claimed_at = NULL
				 WHERE type = 'video' AND is_missing = 0 AND thumb_status IN ($statuses) AND id IN ($in)"
			)->execute($chunk);
			$sel = $pdo->prepare("SELECT id FROM media WHERE type = 'video' AND is_missing = 0 AND thumb_status = 0 AND id IN ($in) ORDER BY id");
			$sel->execute($chunk);
			foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $id) {
				$waiting[] = (int) $id;
			}
		}
		return $waiting;
	}

	/** How many of these videos are still waiting or being worked on. */
	public static function remaining(array $ids): int {
		$n = 0;
		foreach (array_chunk(self::cleanIds($ids), 500) as $chunk) {
			$stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM media WHERE thumb_status IN (0, 3) AND is_missing = 0 AND id IN (' . Db::placeholders(count($chunk)) . ')');
			$stmt->execute($chunk);
			$n += (int) $stmt->fetchColumn();
		}
		return $n;
	}

	private static function cleanIds(array $ids): array {
		return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
	}

	/**
	 * Takes the next pending video (also reclaims ones a crashed worker abandoned). Null when the queue is empty.
	 * @param int[]|null $ids only consider these videos (null = the whole library; an empty array = nothing)
	 */
	public static function claimNext(?array $ids = null): ?array {
		$pdo = Db::pdo();
		$scope = '';
		$scopeArgs = [];
		if ($ids !== null) {
			$scopeArgs = self::cleanIds($ids);
			if (!$scopeArgs) {
				return null;
			}
			$scope = ' AND id IN (' . Db::placeholders(count($scopeArgs)) . ')';
		}
		$stale = date('Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * 60);
		$find = $pdo->prepare(
			"SELECT id FROM media WHERE type = 'video' AND is_missing = 0
			 AND (thumb_status = 0 OR (thumb_status = 3 AND thumb_claimed_at < ?))$scope
			 ORDER BY id LIMIT 1"
		);
		$claim = $pdo->prepare(
			'UPDATE media SET thumb_status = 3, thumb_claimed_at = ?
			 WHERE id = ? AND (thumb_status = 0 OR (thumb_status = 3 AND thumb_claimed_at < ?))'
		);
		for ($attempt = 0; $attempt < 20; $attempt++) {
			$find->execute(array_merge([$stale], $scopeArgs));
			$id = $find->fetchColumn();
			if ($id === false) {
				return null;
			}
			$claim->execute([date('Y-m-d H:i:s'), $id, $stale]);
			if ($claim->rowCount() === 1) { // another worker may have won the race; then try the next one
				return Library::find((int) $id);
			}
		}
		return null;
	}

	/** Makes the previews for one claimed video. Returns 'done', 'failed' or 'missing'. */
	public static function process(array $row): string {
		$id = (int) $row['id'];
		if (!is_file($row['path'])) {
			Library::markMissing($id);
			self::setStatus($id, self::PENDING);
			return 'missing';
		}
		$info = Ffmpeg::probe($row['path']);
		if ($info === null) {
			return self::fail($id);
		}

		$dir = self::dir() . '/' . $id;
		self::removeDir($dir);
		if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
			return self::fail($id);
		}

		$plan = self::plan($info['duration']);
		$cmds = [];
		foreach ($plan as $i => $p) {
			$cmds[$i] = Ffmpeg::frameCommand($row['path'], $p['time'], $dir . '/' . $i . '.jpg');
		}
		$ran = Ffmpeg::runMany($cmds, (int) VIDEO_THUMB_PARALLEL, 90);

		$frames = [];
		foreach ($plan as $i => $p) {
			$file = $dir . '/' . $i . '.jpg';
			if (!empty($ran[$i]) && is_file($file) && filesize($file) > 0) {
				$frames[] = ['time' => $p['time'], 'file' => $i . '.jpg'];
			} else {
				@unlink($file);
			}
		}
		if (count($frames) < min(self::MIN_FRAMES, count($plan))) {
			self::removeDir($dir);
			return self::fail($id);
		}

		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$pdo->prepare('DELETE FROM media_thumb WHERE media_id = ?')->execute([$id]);
			$ins = $pdo->prepare('INSERT INTO media_thumb (media_id, idx, time_sec, file) VALUES (?, ?, ?, ?)');
			foreach ($frames as $idx => $f) {
				$ins->execute([$id, $idx, $f['time'], $f['file']]);
			}
			$pdo->prepare(
				'UPDATE media SET thumb_status = 1, thumb_claimed_at = NULL, duration = ?, width = ?, height = ? WHERE id = ?'
			)->execute([round($info['duration'], 2), $info['width'] ?: null, $info['height'] ?: null, $id]);
			$pdo->commit();
		} catch (Throwable $e) {
			$pdo->rollBack();
			self::removeDir($dir);
			throw $e;
		}
		return 'done';
	}

	/**
	 * Works through the queue until it is empty, $maxVideos is reached, or $budgetSeconds has passed
	 * (checked between videos, so one video may run a little over). With $ids, only those videos are worked on.
	 * @return array{processed:int, done:int, failed:int, missing:int}
	 */
	public static function run(int $budgetSeconds, ?int $maxVideos = null, ?array $ids = null): array {
		@set_time_limit(0);
		$start = time();
		$r = ['processed' => 0, 'done' => 0, 'failed' => 0, 'missing' => 0];
		while (time() - $start < $budgetSeconds && ($maxVideos === null || $r['processed'] < $maxVideos)) {
			$row = self::claimNext($ids);
			if ($row === null) {
				break;
			}
			try {
				$outcome = self::process($row);
			} catch (Throwable $e) {
				error_log('pixel-library: thumbnails for media ' . $row['id'] . ' failed: ' . $e->getMessage());
				$outcome = self::fail((int) $row['id']);
			}
			$r['processed']++;
			$r[$outcome]++;
		}
		return $r;
	}

	/**
	 * Preview times per media id, in order.
	 * @param int[] $mediaIds
	 * @return array<int, float[]>
	 */
	public static function timesFor(array $mediaIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_map('intval', $mediaIds)), 500) as $chunk) {
			$stmt = Db::pdo()->prepare('SELECT media_id, time_sec FROM media_thumb WHERE media_id IN (' . Db::placeholders(count($chunk)) . ') ORDER BY media_id, idx');
			$stmt->execute($chunk);
			foreach ($stmt->fetchAll() as $r) {
				$out[(int) $r['media_id']][] = (float) $r['time_sec'];
			}
		}
		return $out;
	}

	/** Absolute path of one preview frame (as recorded in the DB), or null. */
	public static function fileFor(int $mediaId, int $idx): ?string {
		$stmt = Db::pdo()->prepare('SELECT file FROM media_thumb WHERE media_id = ? AND idx = ?');
		$stmt->execute([$mediaId, $idx]);
		$file = $stmt->fetchColumn();
		if ($file === false || $file !== basename($file)) { // stored value must be a plain file name
			return null;
		}
		$path = self::dir() . '/' . $mediaId . '/' . $file;
		return is_file($path) ? $path : null;
	}

	/** Deletes a video's preview files (its rows are deleted by the caller, e.g. Library::remove). */
	public static function forgetFiles(int $mediaId): void {
		self::removeDir(self::dir() . '/' . $mediaId);
	}

	/** Discards a video's previews and queues it to be made again (e.g. the file was replaced). */
	public static function retryOne(int $mediaId): void {
		Db::pdo()->prepare('DELETE FROM media_thumb WHERE media_id = ?')->execute([$mediaId]);
		self::removeDir(self::dir() . '/' . $mediaId);
		self::setStatus($mediaId, self::PENDING);
	}

	private static function fail(int $id): string {
		self::setStatus($id, self::FAILED);
		return 'failed';
	}

	private static function setStatus(int $id, int $status): void {
		Db::pdo()->prepare('UPDATE media SET thumb_status = ?, thumb_claimed_at = NULL WHERE id = ?')->execute([$status, $id]);
	}

	private static function removeDir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}
}
