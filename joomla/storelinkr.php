<?php
/**
 * HikaShop StoreLinkr Connector, the Joomla plugin.
 *
 * StoreLinkr calls https://<site>/storelinkr/v1/... with the paths of its
 * WooCommerce connector. This system plugin catches those calls in
 * onAfterInitialise, before Joomla's router applies SEF or language
 * redirects, and hands them to the shared core.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;

class PlgSystemStorelinkr extends CMSPlugin {
	/** Load the plugin's language for its settings page. */
	protected $autoloadLanguage = true;

	/** The path StoreLinkr calls, as under WordPress (/wp-json/storelinkr/v1 matches too). */
	const PREFIX = '/storelinkr/v1';

	public function onAfterInitialise() {
		$app  = Factory::getApplication();
		$path = (string) parse_url((string) $app->getInput()->server->getString('REQUEST_URI', ''), PHP_URL_PATH);
		$pos  = strpos($path, self::PREFIX);
		if (false === $pos) {
			return;
		}
		$rest = substr($path, $pos + strlen(self::PREFIX));
		if ('' !== $rest && '/' !== $rest[0]) {
			return;
		}
		$helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
		if (! is_file($helper)) {
			return;
		}
		require_once $helper;
		require_once __DIR__ . '/core/autoload.php';
		require_once __DIR__ . '/src/JoomlaPlatform.php';

		$platform = new \HikashopStorelinkr\Joomla\JoomlaPlatform($this->params);
		$request  = $platform->request(rawurldecode(trim($rest, '/')));
		list($status, $body) = \HikashopStorelinkr\Router::handle($platform, $request);
		$platform->send($status, $body);
	}
}
