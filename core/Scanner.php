<?php

/** Finds the supported media files inside a folder. */
class Scanner {

	private const MAX_DEPTH = 25;

	/**
	 * @param bool $truncated set to true if the $limit cut the scan short
	 * @return string[] canonical paths of supported files
	 */
	public static function files(string $dir, bool $recursive, int $limit, bool &$truncated): array {
		$out = [];
		$flags = FilesystemIterator::SKIP_DOTS;
		try {
			if ($recursive) {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($dir, $flags),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD // skip folders we can't read
				);
				$it->setMaxDepth(self::MAX_DEPTH);
			} else {
				$it = new FilesystemIterator($dir, $flags);
			}
			foreach ($it as $info) {
				if (!$info->isFile() || MediaTypes::forPath($info->getFilename()) === null) {
					continue;
				}
				if (count($out) >= $limit) {
					$truncated = true;
					break;
				}
				$real = realpath($info->getPathname());
				if ($real !== false) {
					$out[] = $real;
				}
			}
		} catch (UnexpectedValueException $e) {
			// unreadable folder: return what we have
		}
		return $out;
	}
}
