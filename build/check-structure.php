<?php
/**
 * The rules that keep one source serving two platforms. Run in CI on every push
 * and before a release (php build/check-structure.php). Every finding fails.
 */

$root     = dirname(__DIR__);
$problems = array();
$fail     = function ($where, $what) use (&$problems) {
	$problems[] = "$where: $what";
};

$joomla    = array('/\bJoomla\\\\/', '/\bJFactory\b/', '/\bJPATH_/', '/\bJText\b/', '/\b_JEXEC\b/');
$wordpress = array('/\badd_action\s*\(/', '/\badd_filter\s*\(/', '/\bwp_[a-z_]+\s*\(/', '/\bABSPATH\b/', '/\bget_option\s*\(/', '/\bWP_Hika/', '/\besc_[a-z_]+\s*\(/', '/\b__\s*\(/');

function php_files($dir) {
	$out = array();
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
		if (substr($f, -4) === '.php') {
			$out[] = (string) $f;
		}
	}
	sort($out);
	return $out;
}

foreach (php_files("$root/core") as $file) {
	$rel  = substr($file, strlen($root) + 1);
	$body = php_strip_whitespace($file);
	foreach (array_merge($joomla, $wordpress) as $rx) {
		if (preg_match($rx, $body)) {
			$fail($rel, "the core names a platform symbol ($rx): go through Platform");
		}
	}
	if (preg_match('/\$_(GET|POST|REQUEST|SERVER|COOKIE|FILES)\b/', $body)) {
		$fail($rel, 'the core reads a superglobal: the platform builds the Request');
	}
	if (preg_match('/\b(exit|die)\b/', $body)) {
		$fail($rel, 'the core exits: return or throw ApiError, the platform answers');
	}
	if (preg_match('/throw\s+(new\b|ApiError::because)/', $body)) {
		$fail($rel, 'call ApiError::raise(): Plugin Check reads the arguments of a throw as output');
	}
}
foreach (php_files("$root/wordpress") as $file) {
	$body = php_strip_whitespace($file);
	foreach ($joomla as $rx) {
		if (preg_match($rx, $body)) {
			$fail(substr($file, strlen($root) + 1), "WordPress code names a Joomla symbol ($rx)");
		}
	}
}
foreach (php_files("$root/joomla") as $file) {
	$body = php_strip_whitespace($file);
	foreach ($wordpress as $rx) {
		if (preg_match($rx, $body)) {
			$fail(substr($file, strlen($root) + 1), "Joomla code names a WordPress symbol ($rx)");
		}
	}
}
foreach (array_merge(php_files("$root/core"), php_files("$root/joomla"), php_files("$root/wordpress"), php_files("$root/build")) as $file) {
	exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
	if ($code !== 0) {
		$fail(substr($file, strlen($root) + 1), 'does not parse');
	}
}
foreach (array('joomla/storelinkr.xml' => '#<version>\{__VERSION__\}</version>#', 'wordpress/hikashop-storelinkr.php' => '#Version:\s+\{__VERSION__\}#', 'wordpress/readme.txt' => '#Stable tag: \{__VERSION__\}#') as $file => $rx) {
	if (!preg_match($rx, file_get_contents("$root/$file"))) {
		$fail($file, 'carries a version of its own: write {__VERSION__}, VERSION holds it');
	}
}
if (!preg_match('/^\d+\.\d+\.\d+$/', trim(file_get_contents("$root/VERSION")))) {
	$fail('VERSION', 'is not x.y.z');
}

if ($problems) {
	fwrite(STDERR, implode("\n", $problems) . "\n" . count($problems) . " problem(s)\n");
	exit(1);
}
echo "structure ok\n";
