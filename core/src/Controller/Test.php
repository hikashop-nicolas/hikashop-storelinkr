<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * GET test-connection: confirms reachability + auth and reports the plugin
 * version, the shop URL and whether the product image folder is writable.
 * Response keys mirror the WooCommerce connector (status/version/domain/writable).
 */

class Test extends Controller {

	public function connection() {
		$writable = false;
		$fileClass = hikashop_get('class.file');
		if($fileClass) {
			$path = $fileClass->getPath('image');
			$writable = !empty($path) && $this->platform->isWritable($path);
		}

		return array(
			'status' => 'success',
			'version' => $this->platform->version(),
			'domain' => defined('HIKASHOP_LIVE') ? HIKASHOP_LIVE : '',
			'writable' => $writable,
		);
	}
}
