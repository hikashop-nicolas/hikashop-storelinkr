<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;

/**
 * Maps StoreLinkr stock locations onto HikaShop warehouses. Address fields the
 * warehouse table has no column for are kept in the description so nothing is
 * lost. Returns the warehouse id as the location id.
 */

class StockLocationService extends Service {

	public function create(array $data) {
		$name = isset($data['name']) ? trim((string)$data['name']) : '';
		if($name === '') {
			throw ApiError::because('Stock location name is required');
		}
		$row = new \stdClass();
		$row->warehouse_name = $name;
		$row->warehouse_published = 1;
		$row->warehouse_description = $this->describe($data);
		$row->warehouse_created = time();
		$row->warehouse_modified = time();
		$db = $this->db();
		$db->insertObject(hikashop_table('warehouse'), $row, 'warehouse_id');
		$id = (int) $db->insertid();
		if(!$id) {
			throw ApiError::because('Stock location could not be saved!');
		}
		return $id;
	}

	public function update($id, array $data) {
		$id = (int) $id;
		$row = new \stdClass();
		$row->warehouse_id = $id;
		if(!empty($data['name'])) {
			$row->warehouse_name = trim((string)$data['name']);
		}
		$row->warehouse_description = $this->describe($data);
		$row->warehouse_modified = time();
		$db = $this->db();
		$db->updateObject(hikashop_table('warehouse'), $row, 'warehouse_id');
		return $id;
	}

	private function describe(array $data) {
		$parts = array();
		foreach(array('address', 'city', 'postal_code', 'country_code', 'phone_number') as $key) {
			if(!empty($data[$key])) {
				$parts[] = $key . ': ' . $data[$key];
			}
		}
		return implode("\n", $parts);
	}
}
