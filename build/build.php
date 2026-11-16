<?php
/**
 * Builds the two packages from one source:
 *   dist/plg_system_storelinkr.zip   the Joomla plugin (joomla/ + core/)
 *   dist/hikashop-storelinkr.zip     the WordPress plugin (wordpress/ + core/)
 * and updates/plg_system_storelinkr.xml, the Joomla update file.
 *
 * Each PHP file gets the direct-access guard of its platform after its
 * namespace and use lines, where JEDChecker and Plugin Check look for it.
 *
 *   php build/build.php
 */

$root    = dirname(__DIR__);
$version = trim(file_get_contents($root . '/VERSION'));
$date    = gmdate('Y-m-d');
$repo    = 'hikashop-nicolas/hikashop-storelinkr';

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
	fwrite(STDERR, "VERSION is not x.y.z: $version\n");
	exit(1);
}

$builds = array(
	'joomla' => array(
		'zip'    => 'plg_system_storelinkr.zip',
		'folder' => '',
		'guard'  => "\\defined('_JEXEC') or die;",
		'parts'  => array('joomla' => '', 'core' => 'core', 'LICENSE.txt' => 'LICENSE.txt'),
	),
	'wordpress' => array(
		'zip'    => 'hikashop-storelinkr.zip',
		'folder' => 'hikashop-storelinkr/',
		'guard'  => "defined( 'ABSPATH' ) || exit;",
		'parts'  => array('wordpress' => '', 'core' => 'core', 'LICENSE.txt' => 'LICENSE.txt'),
	),
);

/** Insert the guard after the opening tag, the file's docblock, namespace and use lines. */
function guarded($php, $guard, $name) {
	if (strpos($php, 'WP_UNINSTALL_PLUGIN') !== false) {
		return $php; // uninstall.php has its own guard
	}
	$tokens = token_get_all($php);
	$offset = 0;
	$after  = 0;
	$depth  = 0;
	$code   = false;
	foreach ($tokens as $t) {
		$text = is_array($t) ? $t[1] : $t;
		$id   = is_array($t) ? $t[0] : null;
		$offset += strlen($text);
		if (in_array($id, array(T_OPEN_TAG, T_WHITESPACE, T_DOC_COMMENT, T_COMMENT), true)) {
			// a comment after namespace/use belongs to the code below it
			if ($id !== T_WHITESPACE && !$code) {
				$after = $offset;
			}
			continue;
		}
		if ($id === T_NAMESPACE || $id === T_USE || $depth > 0) {
			$code  = true;
			$depth = ($text === ';') ? 0 : 1;
			if ($text === ';') {
				$after = $offset;
			}
			continue;
		}
		break;
	}
	if ($after === 0) {
		fwrite(STDERR, "no place for the guard in $name\n");
		exit(1);
	}
	return substr($php, 0, $after) . "\n\n" . $guard . "\n" . substr($php, $after);
}

/** Every PHP file names its licence (JEDChecker's GPL rule). */
function licensed($php, $version) {
	$notice = "/**\n * @package   HikaShop StoreLinkr Connector\n * @version   $version\n * @copyright (C) 2026 Hikari Software\n * @license   GNU General Public License version 2 or later; see LICENSE.txt\n */\n";
	return preg_replace('/^<\?php\s*\n/', "<?php\n" . $notice, $php, 1);
}

function files($dir) {
	$out = array();
	$it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$out[] = $f->getPathname();
	}
	sort($out);
	return $out;
}

@mkdir($root . '/dist');
foreach ($builds as $name => $b) {
	$zipPath = $root . '/dist/' . $b['zip'];
	@unlink($zipPath);
	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
		fwrite(STDERR, "cannot write $zipPath\n");
		exit(1);
	}
	$count = 0;
	foreach ($b['parts'] as $src => $dest) {
		$srcPath = $root . '/' . $src;
		$list    = is_dir($srcPath) ? files($srcPath) : array($srcPath);
		foreach ($list as $file) {
			$rel  = is_dir($srcPath) ? substr($file, strlen($srcPath) + 1) : '';
			$path = $b['folder'] . ltrim($dest . ($rel !== '' ? ($dest !== '' ? '/' : '') . $rel : ''), '/');
			$body = file_get_contents($file);
			$body = str_replace(array('{__VERSION__}', '{__DATE__}'), array($version, $date), $body);
			if (substr($file, -4) === '.php') {
				$body = licensed($body, $version);
				$body = guarded($body, $b['guard'], $file);
			}
			$zip->addFromString($path, $body);
			$count++;
		}
	}
	$zip->close();
	printf("%s: %d files, %s\n", $b['zip'], $count, hash_file('sha256', $zipPath));
}

$xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
	<update>
		<name>System - HikaShop StoreLinkr Connector</name>
		<description>HikaShop StoreLinkr Connector</description>
		<element>storelinkr</element>
		<type>plugin</type>
		<folder>system</folder>
		<client>site</client>
		<version>$version</version>
		<infourl title="HikaShop StoreLinkr Connector">https://github.com/$repo/releases/tag/v$version</infourl>
		<downloads>
			<downloadurl type="full" format="zip">https://github.com/$repo/releases/download/v$version/plg_system_storelinkr.zip</downloadurl>
		</downloads>
		<sha256>%SHA%</sha256>
		<tags>
			<tag>stable</tag>
		</tags>
		<targetplatform name="joomla" version="(4|5|6)\..*" />
		<php_minimum>7.4</php_minimum>
	</update>
</updates>

XML;
file_put_contents($root . '/updates/plg_system_storelinkr.xml', str_replace('%SHA%', hash_file('sha256', $root . '/dist/plg_system_storelinkr.zip'), $xml));
echo "updates/plg_system_storelinkr.xml: $version\n";
