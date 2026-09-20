<?php
/**
 * Class autoloader for core/*.php (one class per file, file = class name).
 */
spl_autoload_register(function ($class) {
	$file = __DIR__ . '/../core/' . $class . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
