<?php
namespace HikashopStorelinkr;

/**
 * What the core asks of the CMS it runs in. The Joomla plugin and the WordPress
 * plugin each implement it; the core never names either of them.
 */
interface Platform {

	/** HikaShop's database object (setQuery, loadResult, quote, insertObject...). */
	public function db();

	/** A setting of the connector, as saved in the CMS. */
	public function setting($key, $fallback = null);

	/** The connector's version, for test-connection. */
	public function version();

	/** Fire a HikaShop plugin event (onAfterProductCreate...) the way HikaShop does. */
	public function triggerEvent($event, array $args);

	/**
	 * GET a remote URL.
	 *
	 * @return array{0:int,1:string} The HTTP status (0 when the request failed) and the body.
	 */
	public function httpGet($url, array $headers, $timeout);

	/** Whether a folder or file can be written to. */
	public function isWritable($path);

	/** Write bytes to a file; false when it could not. */
	public function writeFile($path, $bytes);

	/** parse_url, through the CMS's own helper when it has one. */
	public function parseUrl($url);

	/** A random string of letters and digits, for file names. */
	public function randomString($length);
}
