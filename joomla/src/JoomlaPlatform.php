<?php
namespace HikashopStorelinkr\Joomla;

use HikashopStorelinkr\Platform;
use HikashopStorelinkr\Request;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;

/** The core's view of Joomla. */
class JoomlaPlatform implements Platform {
	/** @var Registry */
	private $params;

	public function __construct(Registry $params) {
		$this->params = $params;
	}

	/** The request StoreLinkr sent, from Joomla's input. */
	public function request($path) {
		$input   = Factory::getApplication()->getInput();
		$headers = array();
		foreach ($input->server->getArray() as $name => $value) {
			if (0 === strpos($name, 'HTTP_')) {
				$headers[ substr($name, 5) ] = (string) $value;
			}
		}
		$params = $input->get->getArray();
		$body   = json_decode((string) file_get_contents('php://input'), true);
		$params = array_merge($params, is_array($body) ? $body : $input->post->getArray());
		return new Request($input->server->getString('REQUEST_METHOD', 'GET'), $path, $headers, $params);
	}

	/** Answer StoreLinkr with JSON, and nothing of the page around it. */
	public function send($status, array $body) {
		$app = Factory::getApplication();
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$app->setHeader('Status', (string) $status, true);
		$app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$app->sendHeaders();
		echo json_encode($body);
		$app->close();
	}

	public function db() {
		return Factory::getDbo();
	}

	public function setting($key, $fallback = null) {
		return $this->params->get($key, $fallback);
	}

	public function version() {
		return '{__VERSION__}';
	}

	public function triggerEvent($event, array $args) {
		PluginHelper::importPlugin('hikashop');
		Factory::getApplication()->triggerEvent($event, $args);
	}

	public function httpGet($url, array $headers, $timeout) {
		try {
			$response = HttpFactory::getHttp()->get($url, $headers, $timeout);
			return array( (int) $response->code, (string) $response->body );
		} catch (\Exception $e) {
			return array( 0, '' );
		}
	}

	public function isWritable($path) {
		return is_writable($path);
	}

	public function writeFile($path, $bytes) {
		return false !== File::write($path, $bytes);
	}

	public function parseUrl($url) {
		return parse_url($url);
	}

	public function randomString($length) {
		return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
	}

	/** The address StoreLinkr calls, for the settings page. */
	public static function endpoint() {
		return rtrim(Uri::root(), '/') . '/storelinkr/v1';
	}
}
