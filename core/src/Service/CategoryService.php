<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;

/**
 * Maps StoreLinkr categories onto HikaShop product categories. StoreLinkr uses
 * parentId 0 for top level; HikaShop nests product categories under a "product"
 * container below the root, so parentId 0 resolves to that container. Also
 * resolves a 5-level name path to a leaf id and upserts manufacturer (brand)
 * categories. Listing mirrors the WooCommerce connector's category shape
 * (source/id/categoryMain..categorySubSubSubSub/url/parentId).
 */

class CategoryService extends Service {

	private $mainProductCategory = null;

	/** Top "product" container id; StoreLinkr parentId 0 maps here. */
	public function getMainProductCategory() {
		if($this->mainProductCategory === null) {
			$categoryClass = hikashop_get('class.category');
			$main = 'product';
			$categoryClass->getMainElement($main);
			$this->mainProductCategory = (int) $main;
			if($this->mainProductCategory <= 0) {
				$this->mainProductCategory = (int) $categoryClass->getRoot();
			}
		}
		return $this->mainProductCategory;
	}

	private function resolveParentId($parentId) {
		$parentId = (int) $parentId;
		return ($parentId > 0) ? $parentId : $this->getMainProductCategory();
	}

	public function findByNameAndParent($name, $parentId, $type = 'product') {
		$db = $this->db();
		$db->setQuery(
			'SELECT category_id FROM ' . hikashop_table('category') .
			' WHERE category_name = ' . $db->quote($name) .
			' AND category_parent_id = ' . (int)$parentId .
			' AND category_type = ' . $db->quote($type) . ' LIMIT 1'
		);
		return (int) $db->loadResult();
	}

	/** Create (or return existing) a product category under the given parent. */
	public function create($name, $parentId, $source = null) {
		$name = trim((string)$name);
		if($name === '') {
			ApiError::raise('Category name is required');
		}
		$parent = $this->resolveParentId($parentId);

		$existingId = $this->findByNameAndParent($name, $parent, 'product');
		if($existingId > 0) {
			return $this->toResponse($existingId);
		}

		$categoryClass = hikashop_get('class.category');
		$category = new \stdClass();
		$category->category_name = $name;
		$category->category_type = 'product';
		$category->category_parent_id = $parent;
		$category->category_published = 1;
		$id = $categoryClass->save($category);
		if(!$id) {
			ApiError::raise('Could not create category: ' . $name);
		}
		return $this->toResponse((int)$id);
	}

	public function update($id, $name, $parentId) {
		$categoryClass = hikashop_get('class.category');
		$category = $categoryClass->get((int)$id);
		if(empty($category) || empty($category->category_id)) {
			ApiError::raise('Invalid category!');
		}
		$update = new \stdClass();
		$update->category_id = (int)$id;
		if($name !== null && trim((string)$name) !== '') {
			$update->category_name = trim((string)$name);
		}
		if($parentId !== null) {
			$update->category_parent_id = $this->resolveParentId($parentId);
		}
		$status = $categoryClass->save($update);
		if(!$status) {
			ApiError::raise('Could not update category ' . (int)$id);
		}
		return true;
	}

	/**
	 * Resolve a 5-level name path (main, sub, ...) to a leaf category id,
	 * creating each missing level. Mirrors getCorrespondingCategoryIdsByPath().
	 */
	public function resolvePath(array $path) {
		$keys = array('main', 'sub', 'subSub', 'subSubSub', 'subSubSubSub');
		$parent = 0;
		$leaf = 0;
		foreach($keys as $key) {
			if(empty($path[$key])) {
				continue;
			}
			$category = $this->create($path[$key], $parent);
			$leaf = (int) $category['id'];
			$parent = $leaf;
		}
		return $leaf;
	}

	/** Manufacturer (brand) is a category with type manufacturer; returns its id. */
	public function upsertManufacturer($name) {
		$name = trim((string)$name);
		if($name === '') {
			return 0;
		}
		$categoryClass = hikashop_get('class.category');
		$main = 'manufacturer';
		$categoryClass->getMainElement($main);
		$parent = (int)$main > 0 ? (int)$main : (int)$categoryClass->getRoot();

		$existingId = $this->findByNameAndParent($name, $parent, 'manufacturer');
		if($existingId > 0) {
			return $existingId;
		}
		$category = new \stdClass();
		$category->category_name = $name;
		$category->category_type = 'manufacturer';
		$category->category_parent_id = $parent;
		$category->category_published = 1;
		$id = $categoryClass->save($category);
		return $id ? (int)$id : 0;
	}

	/** List all product categories in the StoreLinkr shape. */
	public function listAll() {
		$db = $this->db();
		$db->setQuery(
			'SELECT category_id, category_name, category_parent_id, category_alias FROM ' . hikashop_table('category') .
			" WHERE category_type = 'product' ORDER BY category_name ASC"
		);
		$rows = (array) $db->loadObjectList('category_id');

		$container = $this->getMainProductCategory();
		$root = (int) hikashop_get('class.category')->getRoot();

		$out = array();
		foreach($rows as $id => $row) {
			if((int)$id === $container) {
				continue;
			}
			$out[] = $this->buildCategoryObject($row, $rows, $container, $root);
		}
		return $out;
	}

	private function buildCategoryObject($row, $rows, $container, $root) {
		// Build the ancestor chain (self first), stopping at the container/root.
		$chain = array();
		$current = $row;
		$guard = 0;
		while(!empty($current) && $guard < 10) {
			$chain[] = $current->category_name;
			$parentId = (int) $current->category_parent_id;
			if($parentId === $container || $parentId === $root || $parentId === 0 || !isset($rows[$parentId])) {
				break;
			}
			$current = $rows[$parentId];
			$guard++;
		}
		$chain = array_reverse($chain);
		$chain = array_slice($chain, -5);
		$chain = array_pad($chain, 5, '');

		return array(
			'source' => 'HIKASHOP',
			'id' => (int) $row->category_id,
			'categoryMain' => $chain[0],
			'categorySub' => $chain[1],
			'categorySubSub' => $chain[2],
			'categorySubSubSub' => $chain[3],
			'categorySubSubSubSub' => $chain[4],
			'url' => $this->categoryUrl($row->category_id, $row->category_alias),
			'parentId' => (int) $row->category_parent_id,
		);
	}

	private function toResponse($id) {
		$categoryClass = hikashop_get('class.category');
		$category = $categoryClass->get((int)$id);
		return array(
			'id' => (int) $category->category_id,
			'name' => $category->category_name,
			'url' => $this->categoryUrl($category->category_id, (isset($category->category_alias) ? $category->category_alias : null)),
			'parent' => (int) $category->category_parent_id,
		);
	}

	private function categoryUrl($id, $alias = '') {
		$base = defined('HIKASHOP_LIVE') ? HIKASHOP_LIVE : '';
		$url = rtrim($base, '/') . '/index.php?option=com_hikashop&ctrl=category&task=listing&cid=' . (int)$id;
		if(!empty($alias)) {
			$url .= '&name=' . rawurlencode($alias);
		}
		return $url;
	}
}
