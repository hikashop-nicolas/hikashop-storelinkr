<?php
/**
 * JEDChecker's rules on the Joomla package, from the command line. Needs a local
 * Joomla site with JEDChecker installed (https://github.com/joomla-extensions/jedchecker).
 *
 *   php build/jed-check.php /path/to/joomla-site [dist/plg_system_storelinkr.zip]
 *
 * Prints each rule's findings and exits 1 when any is an error or a warning.
 */

$site = isset($argv[1]) ? rtrim($argv[1], '/') : '';
$zip  = isset($argv[2]) ? $argv[2] : dirname(__DIR__) . '/dist/plg_system_storelinkr.zip';
if (!is_file($site . '/administrator/components/com_jedchecker/jedchecker.xml')) {
	fwrite(STDERR, "usage: php build/jed-check.php <joomla site with JEDChecker> [zip]\n");
	exit(2);
}
$zip = realpath($zip);

$_SERVER['HTTP_HOST'] = 'localhost';
const _JEXEC = 1;
define('JPATH_BASE', $site . '/administrator');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
\Joomla\CMS\Factory::$application = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
$app = \Joomla\CMS\Factory::$application;
$app->loadLanguage(\Joomla\CMS\Language\Language::getInstance('en-GB'));
$app->getLanguage()->load('com_jedchecker', JPATH_ADMINISTRATOR . '/components/com_jedchecker');

$base = JPATH_ADMINISTRATOR . '/components/com_jedchecker/src';
spl_autoload_register(function ($class) use ($base) {
	$prefix = 'Joomla\\Component\\Jedchecker\\Administrator\\';
	if (strpos($class, $prefix) === 0) {
		$file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

$result = \Joomla\Component\Jedchecker\Administrator\Api\CheckerApi::check($zip);
if (empty($result['success'])) {
	fwrite(STDERR, 'JEDChecker failed: ' . $result['error'] . "\n");
	exit(1);
}
$bad = 0;
foreach ($result['rules'] as $rule) {
	$c = isset($rule['data']['count']) ? $rule['data']['count'] : array();
	printf("%-16s error=%d warning=%d compatibility=%d notice=%d info=%d\n", $rule['id'], $c['error'] ?? 0, $c['warning'] ?? 0, $c['compatibility'] ?? 0, $c['notice'] ?? 0, $c['info'] ?? 0);
	foreach ((array) ($rule['data']['issues'] ?? array()) as $issue) {
		if (in_array($issue['type'], array('error', 'warning', 'compatibility', 'notice'), true)) {
			$bad++;
			$where = preg_replace('#^.*/unzipped/[^/]+/#', '', (string) $issue['location']);
			echo '   ' . $issue['type'] . ' ' . $where . ($issue['line'] ? ':' . $issue['line'] : '') . ': ' . trim(strip_tags(str_replace('<br />', ' | ', (string) $issue['text']))) . "\n";
		}
	}
}
echo $bad ? "$bad finding(s)\n" : "JEDChecker: clean\n";
exit($bad ? 1 : 0);
