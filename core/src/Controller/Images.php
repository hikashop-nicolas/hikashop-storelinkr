<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * POST images/create: store a standalone image from a CDN url or base64 content
 * and return its id + url. Mirrors the WooCommerce connector's renderCreateImage.
 */

class Images extends Controller {

	public function create() {
		$this->request->requireFields(array('alt'));
		$service = $this->service('ImageService');
		$result = $service->createStandalone(
			$this->request->get('alt'),
			$this->request->get('cdn_url'),
			$this->request->get('imageContent')
		);
		return array(
			'status' => 'success',
			'image_id' => $result['image_id'],
			'media_id' => $result['media_id'],
			'image_url' => $result['image_url'],
			'warnings' => $this->warnings(),
		);
	}
}
