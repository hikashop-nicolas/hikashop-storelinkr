<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * Category endpoints: list, create, update. Response shapes mirror the
 * WooCommerce connector (categories list + {id,name,url,parent} on create).
 */

class Categories extends Controller {

	public function index() {
		$service = $this->service('CategoryService');
		return array(
			'status' => 'success',
			'categories' => $service->listAll(),
		);
	}

	public function create() {
		$this->request->requireFields(array('import_source', 'name', 'parentId'));
		$service = $this->service('CategoryService');
		$category = $service->create(
			$this->request->get('name'),
			$this->request->get('parentId'),
			$this->importSource()
		);
		return array(
			'status' => 'success',
			'category' => $category,
		);
	}

	public function update() {
		$this->request->requireFields(array('name', 'parentId'));
		$service = $this->service('CategoryService');
		$service->update(
			(int) $this->request->get('id'),
			$this->request->get('name'),
			$this->request->get('parentId')
		);
		return array('status' => 'success');
	}
}
