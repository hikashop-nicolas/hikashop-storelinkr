<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * Stock location endpoints: create (POST) and update (PUT). Maps to HikaShop
 * warehouses; returns the warehouse id as the location id.
 */

class StockLocations extends Controller {

	public function create() {
		$this->request->requireFields(array('name'));
		$service = $this->service('StockLocationService');
		$id = $service->create($this->request->all());
		return array(
			'status' => 'success',
			'location' => array('id' => $id),
		);
	}

	public function update() {
		$this->request->requireFields(array('id', 'name'));
		$service = $this->service('StockLocationService');
		$id = $service->update((int) $this->request->get('id'), $this->request->all());
		return array(
			'status' => 'success',
			'location' => array('id' => $id),
		);
	}
}
