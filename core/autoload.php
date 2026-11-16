<?php
/**
 * The core's class loader: HikashopStorelinkr\X\Y lives in src/X/Y.php.
 * Shared by the Joomla and the WordPress builds; it names neither.
 */
spl_autoload_register(
	function ($class) {
		$prefix = 'HikashopStorelinkr\\';
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
);
