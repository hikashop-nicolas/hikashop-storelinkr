<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * Variant endpoints: create-variant, {id}/update-variant, link-variants.
 * Maps StoreLinkr variable products to HikaShop characteristic-based variants.
 */

class Variants extends Controller {

	public function create() {
		$this->request->requireFields(array('name', 'products', 'options', 'categories', 'importSource'));
		return $this->save(0);
	}

	public function update() {
		$this->request->requireFields(array('name', 'products', 'options', 'categories', 'importSource'));
		return $this->save((int) $this->request->get('id'));
	}

	private function save($mainId) {
		$service = $this->service('VariantService');
		$data = $this->request->all();
		$settings = is_array($this->request->get('settings')) ? $this->request->get('settings') : array();
		$publishNew = isset($settings['publish_new_products'])
			? (bool) $settings['publish_new_products']
			: (bool) $this->params->get('publish_new_products', 1);

		$result = $service->createOrUpdate($data, $settings, $publishNew, $mainId);
		$result['warnings'] = $this->warnings();
		return $result;
	}

	public function link() {
		$this->request->requireFields(array('variant', 'linkProducts'));
		$service = $this->service('VariantService');
		$result = $service->linkVariants($this->request->all());
		$result['warnings'] = $this->warnings();
		return $result;
	}
}
