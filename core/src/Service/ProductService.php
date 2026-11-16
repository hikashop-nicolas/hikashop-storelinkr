<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;
use HikashopStorelinkr\ProductExtras;

/**
 * Maps StoreLinkr products onto HikaShop products. Matching follows id -> sku
 * (product_code) -> ean (storelinkr_ean column), so an upsert updates the
 * existing product instead of duplicating it. Writes replicate HikaShop's own
 * save orchestration: save the row (after-trigger suppressed), then
 * updateCategories / updatePrices / updateFiles, then fire the after-trigger.
 */

class ProductService extends Service {

	private $categoryService = null;
	private $imageService = null;

	private function categoryService() {
		if($this->categoryService === null) {
			$this->categoryService = new CategoryService($this->platform);
		}
		return $this->categoryService;
	}

	private function imageService() {
		if($this->imageService === null) {
			$this->imageService = new ImageService($this->platform);
		}
		return $this->imageService;
	}

	// ── Matching ─────────────────────────────────────────────────────────

	public function findById($id) {
		$id = (int) $id;
		if($id <= 0) {
			return null;
		}
		$productClass = hikashop_get('class.product');
		$product = $productClass->get($id);
		return (!empty($product) && !empty($product->product_id)) ? $product : null;
	}

	public function findBySku($sku) {
		$sku = trim((string)$sku);
		if($sku === '') {
			return null;
		}
		$db = $this->db();
		$db->setQuery('SELECT product_id FROM ' . hikashop_table('product') . ' WHERE product_code = ' . $db->quote($sku) . ' LIMIT 1');
		$id = (int) $db->loadResult();
		return $id ? $this->findById($id) : null;
	}

	public function findByEan($ean) {
		$ean = trim((string)$ean);
		if($ean === '') {
			return null;
		}
		Schema::ensure($this->db());
		$db = $this->db();
		$db->setQuery('SELECT product_id FROM ' . hikashop_table('product') . ' WHERE ' . $db->quoteName(Schema::COL_EAN) . ' = ' . $db->quote($ean) . ' LIMIT 1');
		$id = (int) $db->loadResult();
		return $id ? $this->findById($id) : null;
	}

	public function match(array $data) {
		if(!empty($data['id'])) {
			$p = $this->findById($data['id']);
			if($p && $this->isTheProductSent($p, $data)) return $p;
			if($p) {
				$this->logWarning('Product id ' . (int)$data['id'] . ' is another product in this shop: matched by SKU or EAN instead');
			}
		}
		if(!empty($data['sku'])) {
			$p = $this->findBySku($data['sku']);
			if($p) return $p;
		}
		if(!empty($data['ean'])) {
			$p = $this->findByEan($data['ean']);
			if($p) return $p;
		}
		return null;
	}

	/**
	 * Whether the product an id names is the one StoreLinkr sends: an id from another
	 * shop (a site moved, a database restored) can name any product here. Its StoreLinkr
	 * uuid decides; a product StoreLinkr never touched must have the same SKU or EAN.
	 */
	private function isTheProductSent($product, array $data) {
		$uuid = isset($product->{Schema::COL_UUID}) ? (string)$product->{Schema::COL_UUID} : '';
		if($uuid !== '' && !empty($data['uuid'])) {
			return $uuid === (string)$data['uuid'];
		}
		if(!empty($data['sku']) && (string)$product->product_code === trim((string)$data['sku'])) {
			return true;
		}
		$ean = isset($product->{Schema::COL_EAN}) ? (string)$product->{Schema::COL_EAN} : '';
		if($ean !== '' && !empty($data['ean']) && $ean === trim((string)$data['ean'])) {
			return true;
		}
		// No uuid sent and nothing to compare: as StoreLinkr's own connectors, trust the id.
		return empty($data['uuid']) && empty($data['sku']) && empty($data['ean']);
	}

	// ── Create / update ──────────────────────────────────────────────────

	public function createOrUpdate(array $data, array $settings, $publishNew) {
		Schema::ensure($this->db());

		$existing = $this->match($data);
		$isNew = empty($existing);

		$element = $this->mapElement($data, $existing, $settings);

		$catIds = $this->resolveCategories($data);
		if($catIds !== null) {
			$element->categories = $catIds;
		} elseif($isNew) {
			$element->categories = array();
		}

		if(!empty($data['brand'])) {
			$mid = $this->categoryService()->upsertManufacturer($data['brand']);
			if($mid) {
				$element->product_manufacturer_id = $mid;
			}
		}

		if($isNew) {
			$element->product_published = $publishNew ? 1 : 0;
		} else {
			ProductExtras::restoreIfTakenOffline($element, $existing);
		}

		$overwriteImages = !isset($settings['overwrite_images']) || (bool)$settings['overwrite_images'];
		if($overwriteImages && isset($data['images'])) {
			$this->setImages($element, (array)$data['images']);
		}

		return $this->saveElement($element, $isNew);
	}

	private function mapElement(array $data, $existing, array $settings) {
		$element = new \stdClass();
		if(!empty($existing)) {
			$element->product_id = (int) $existing->product_id;
			$element->product_type = $existing->product_type;
		} else {
			$element->product_type = 'main';
		}

		if(isset($data['name'])) {
			$element->product_name = (string) $data['name'];
		}
		if(isset($data['sku'])) {
			$sku = trim((string)$data['sku']);
			if($sku !== '') {
				$element->product_code = $sku;
			}
		}
		if(isset($data['ean'])) {
			$element->{Schema::COL_EAN} = trim((string)$data['ean']);
		}
		if(isset($data['uuid'])) {
			$element->{Schema::COL_UUID} = (string) $data['uuid'];
		}
		$element->{Schema::COL_SOURCE} = isset($data['importSource']) && $data['importSource'] !== ''
			? (string)$data['importSource'] : $this->importSource();

		$updateLong = !isset($settings['overwrite_long_description']) || (bool)$settings['overwrite_long_description'];
		$updateShort = !isset($settings['overwrite_short_description']) || (bool)$settings['overwrite_short_description'];
		$long = isset($data['longDescription']) ? (string)$data['longDescription'] : null;
		$short = isset($data['shortDescription']) ? (string)$data['shortDescription'] : null;
		if($updateLong && $long !== null && $long !== '') {
			$element->product_description = $long;
		} elseif($updateShort && $short !== null && $short !== '') {
			$element->product_description = $short;
		}

		ProductExtras::apply($this->db(), $element, $data);

		if(ProductMapper::shouldUpdatePrice($data, $settings)) {
			$this->applyPrices($element, $data);
		}
		if(ProductMapper::shouldUpdateStock($data, $settings)) {
			$element->product_quantity = ProductMapper::computeQuantity($data);
		}

		return $element;
	}

	private function applyPrices($element, array $data) {
		$sales = isset($data['salesPrice']) ? (int)$data['salesPrice'] : 0;
		$promo = isset($data['promoSalesPrice']) ? (int)$data['promoSalesPrice'] : 0;
		$advised = isset($data['advisedPrice']) ? (int)$data['advisedPrice'] : 0;

		$effective = ($promo > 0) ? $promo : $sales;

		$price = new \stdClass();
		$price->price_currency_id = $this->currencyId();
		$price->price_value = ($effective > 0) ? ProductMapper::centsToPrice($effective) : '0.00000';
		$price->price_min_quantity = 0;
		$price->price_access = 'all';
		$element->prices = array($price);

		if($advised > 0) {
			$element->product_msrp = ProductMapper::centsToPrice($advised);
		} elseif($promo > 0 && $sales > 0) {
			$element->product_msrp = ProductMapper::centsToPrice($sales);
		}
	}

	private function resolveCategories(array $data) {
		if(!empty($data['category_path']) && is_array($data['category_path'])) {
			$leaf = $this->categoryService()->resolvePath($data['category_path']);
			return $leaf ? array($leaf) : array();
		}
		if(!empty($data['categoryId'])) {
			return array((int)$data['categoryId']);
		}
		return null;
	}

	/**
	 * The images of a product, in StoreLinkr's order, the first one its main image.
	 * When that first image is gone, the product keeps its current images rather
	 * than promote the next one (often a dimension drawing): StoreLinkr sees it
	 * in invalid_media and uploads it again.
	 */
	public function setImages($element, array $sent) {
		$sent = array_values(array_filter(array_map('intval', $sent), function($v){ return $v > 0; }));
		$ids = $this->validImageIds($sent);
		if(!empty($sent) && (empty($ids) || $ids[0] !== $sent[0])) {
			$this->logWarning('The main image ' . $sent[0] . ' no longer exists: the current images are kept until it is sent again');
			return;
		}
		$element->images = $ids;
		$order = array();
		foreach($ids as $i => $fid) {
			$order[$fid] = $i;
		}
		$element->imagesorder = $order;
	}

	/**
	 * A HikaShop image belongs to one product: one StoreLinkr also sends for
	 * another product (a variant showing its parent's photo) gets a record of its
	 * own for this one, pointing at the same file.
	 */
	public function claimImages($element, $productId) {
		if(empty($element->images)) {
			return;
		}
		$db = $this->db();
		$db->setQuery('SELECT * FROM ' . hikashop_table('file') . ' WHERE file_id IN (' . implode(',', array_map('intval', $element->images)) . ')');
		$rows = (array)$db->loadObjectList('file_id');
		$ids = array();
		$order = array();
		foreach($element->images as $i => $id) {
			$row = isset($rows[$id]) ? $rows[$id] : null;
			if($row && (int)$row->file_ref_id !== 0 && (int)$row->file_ref_id !== (int)$productId) {
				$copy = clone $row;
				unset($copy->file_id);
				$copy->file_ref_id = (int)$productId;
				$db->insertObject(hikashop_table('file'), $copy, 'file_id');
				$id = (int)$db->insertid();
			}
			$ids[] = (int)$id;
			$order[(int)$id] = $i;
		}
		$element->images = $ids;
		$element->imagesorder = $order;
	}

	/** Image ids that exist with a real image file, in the order given. */
	public function validImageIds(array $ids) {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$ids = array_filter($ids, function($v){ return $v > 0; });
		if(empty($ids)) {
			return array();
		}
		$db = $this->db();
		$db->setQuery('SELECT file_id, file_path FROM ' . hikashop_table('file') . ' WHERE file_id IN (' . implode(',', $ids) . ')');
		$folder = hikashop_get('class.file')->getPath('product');
		$valid = array();
		foreach((array)$db->loadObjectList() as $row) {
			$path = $folder . $row->file_path;
			// A missing or broken file counts as gone, so StoreLinkr sends it again.
			if(is_file($path) && getimagesize($path) !== false) {
				$valid[] = (int)$row->file_id;
			}
		}
		// Preserve the order StoreLinkr sent.
		return array_values(array_intersect($ids, $valid));
	}

	/** Save the element with HikaShop's related-data orchestration. */
	public function saveElement($element, $isNew) {
		$productClass = hikashop_get('class.product');
		$status = $productClass->save($element, false, true);
		if(!$status) {
			$error = method_exists($productClass, 'getError') ? $productClass->getError() : '';
			ApiError::raise('Could not save product' . ($error ? ': ' . $error : ''));
		}
		$pid = (int) $status;

		if(isset($element->categories)) {
			$productClass->updateCategories($element, $pid);
		}
		if(isset($element->prices)) {
			$productClass->updatePrices($element, $pid);
		}
		if(isset($element->images)) {
			$this->claimImages($element, $pid);
			$productClass->updateFiles($element, $pid, 'images', isset($element->imagesorder) ? $element->imagesorder : null);
		}
		if(isset($element->characteristics)) {
			$productClass->updateCharacteristics($element, $pid);
		}

		$this->platform->triggerEvent($isNew ? 'onAfterProductCreate' : 'onAfterProductUpdate', array(&$element));

		return $pid;
	}

	/** Publish or take offline; offline is flagged so a later sync of it brings it back. */
	public function setPublished($productId, $published) {
		Schema::ensure($this->db());
		$element = new \stdClass();
		$element->product_id = (int) $productId;
		$element->product_published = $published ? 1 : 0;
		$element->{Schema::COL_OFFLINE} = $published ? 0 : 1;
		$productClass = hikashop_get('class.product');
		return (bool) $productClass->save($element);
	}

	/** Set a product's image gallery (file ids, in order); returns the kept ids. */
	public function setGallery($productId, array $fileIds) {
		$ids = $this->validImageIds($fileIds);
		$order = array();
		foreach(array_values($ids) as $i => $fid) {
			$order[$fid] = $i;
		}
		$element = new \stdClass();
		$element->product_id = (int) $productId;
		$element->images = $ids;
		$element->imagesorder = $order;
		$productClass = hikashop_get('class.product');
		$productClass->updateFiles($element, (int)$productId, 'images', $order);
		return $ids;
	}

	public function fileUrlById($fileId) {
		$db = $this->db();
		$db->setQuery('SELECT file_path FROM ' . hikashop_table('file') . ' WHERE file_id = ' . (int)$fileId);
		$path = $db->loadResult();
		if(empty($path)) {
			return '';
		}
		$config = hikashop_config();
		$folder = trim((string)$config->get('uploadfolder', 'media/com_hikashop/upload/'), '/');
		$base = defined('HIKASHOP_LIVE') ? rtrim(HIKASHOP_LIVE, '/') : '';
		return $base . '/' . $folder . '/' . ltrim($path, '/');
	}

	public function productUrl($product) {
		$base = defined('HIKASHOP_LIVE') ? rtrim(HIKASHOP_LIVE, '/') : '';
		$url = $base . '/index.php?option=com_hikashop&ctrl=product&task=show&cid=' . (int)$product->product_id;
		if(!empty($product->product_alias)) {
			$url .= '&name=' . rawurlencode($product->product_alias);
		}
		return $url;
	}

	// ── Listing ──────────────────────────────────────────────────────────

	public function listProducts($page, $perPage = 250) {
		Schema::ensure($this->db());
		$page = max(1, (int)$page);
		$offset = ($page - 1) * $perPage;
		$db = $this->db();

		$db->setQuery('SELECT COUNT(*) FROM ' . hikashop_table('product') . " WHERE product_type = 'main' AND product_published = 1");
		$total = (int) $db->loadResult();

		$db->setQuery('SELECT * FROM ' . hikashop_table('product') . " WHERE product_type = 'main' AND product_published = 1 ORDER BY product_id ASC", $offset, $perPage);
		$products = (array) $db->loadObjectList();

		$categoryMap = $this->loadCategoryMap();
		$container = $this->categoryService()->getMainProductCategory();
		$root = (int) hikashop_get('class.category')->getRoot();

		$items = array();
		foreach($products as $product) {
			$prices = $this->loadProductPrice($product->product_id);
			$chain = $this->categoryChainForProduct($product->product_id, $categoryMap, $container, $root);
			$images = $this->loadProductImages($product->product_id);
			$brand = $this->manufacturerName($product->product_manufacturer_id, $categoryMap);
			$ean = isset($product->{Schema::COL_EAN}) ? $product->{Schema::COL_EAN} : '';
			$items[] = ProductMapper::buildListItem($product, $prices, $chain, $images, $brand, $ean, $this->productUrl($product));
		}

		return array(
			'status' => 'success',
			'total_records' => $total,
			'total_pages' => (int) ceil($total / $perPage),
			'current_page' => $page,
			'products' => $items,
		);
	}

	private function loadProductPrice($productId) {
		$db = $this->db();
		$db->setQuery('SELECT price_value FROM ' . hikashop_table('price') . ' WHERE price_product_id = ' . (int)$productId . ' ORDER BY price_min_quantity ASC, price_value ASC', 0, 1);
		$value = $db->loadResult();
		return array('regular' => $value !== null ? (float)$value : 0.0, 'promo' => 0.0);
	}

	private function loadProductImages($productId) {
		$db = $this->db();
		$db->setQuery('SELECT file_id, file_path, file_name FROM ' . hikashop_table('file') . ' WHERE file_ref_id = ' . (int)$productId . " AND file_type = 'product' ORDER BY file_ordering ASC");
		$rows = (array) $db->loadObjectList();
		$config = hikashop_config();
		$folder = trim((string)$config->get('uploadfolder', 'media/com_hikashop/upload/'), '/');
		$base = defined('HIKASHOP_LIVE') ? rtrim(HIKASHOP_LIVE, '/') : '';
		$images = array();
		foreach($rows as $row) {
			$images[] = array(
				'id' => (int) $row->file_id,
				'url' => $base . '/' . $folder . '/' . ltrim($row->file_path, '/'),
				'alt' => (string) $row->file_name,
			);
		}
		return $images;
	}

	private function loadCategoryMap() {
		$db = $this->db();
		$db->setQuery('SELECT category_id, category_name, category_parent_id FROM ' . hikashop_table('category'));
		return (array) $db->loadObjectList('category_id');
	}

	private function categoryChainForProduct($productId, $categoryMap, $container, $root) {
		$db = $this->db();
		$db->setQuery('SELECT category_id FROM ' . hikashop_table('product_category') . ' WHERE product_id = ' . (int)$productId . ' ORDER BY ordering ASC', 0, 1);
		$catId = (int) $db->loadResult();
		if($catId <= 0 || !isset($categoryMap[$catId])) {
			return array();
		}
		$chain = array();
		$current = $categoryMap[$catId];
		$guard = 0;
		while(!empty($current) && $guard < 10) {
			$chain[] = $current->category_name;
			$parentId = (int) $current->category_parent_id;
			if($parentId === $container || $parentId === $root || $parentId === 0 || !isset($categoryMap[$parentId])) {
				break;
			}
			$current = $categoryMap[$parentId];
			$guard++;
		}
		return array_reverse($chain);
	}

	private function manufacturerName($manufacturerId, $categoryMap) {
		$manufacturerId = (int) $manufacturerId;
		if($manufacturerId > 0 && isset($categoryMap[$manufacturerId])) {
			return (string) $categoryMap[$manufacturerId]->category_name;
		}
		return '';
	}

	// ── Lifecycle ────────────────────────────────────────────────────────

	public function makeOffline(array $gtins, array $productIds) {
		$results = array('success' => array(), 'failed' => array(), 'not_found' => array());

		foreach($gtins as $gtin) {
			$product = $this->findByEan($gtin);
			if(!$product) {
				$results['not_found'][] = $gtin;
				continue;
			}
			$results[$this->setPublished($product->product_id, false) ? 'success' : 'failed'][] = $gtin;
		}
		foreach($productIds as $id) {
			$product = $this->findById($id);
			if(!$product) {
				$results['not_found'][] = $id;
				continue;
			}
			$results[$this->setPublished($product->product_id, false) ? 'success' : 'failed'][] = $id;
		}
		return $results;
	}

	public function archiveValidate(array $products) {
		$archived = array();
		$notFound = array();
		foreach($products as $entry) {
			$product = null;
			if(!empty($entry['ean'])) {
				$product = $this->findByEan($entry['ean']);
			}
			if(!$product && !empty($entry['sku'])) {
				$product = $this->findBySku($entry['sku']);
			}
			if(!$product) {
				$notFound[] = $entry;
				continue;
			}
			$this->setPublished($product->product_id, false);
			$archived[] = array(
				'ean' => isset($product->{Schema::COL_EAN}) ? $product->{Schema::COL_EAN} : '',
				'sku' => (string) $product->product_code,
				'post_id' => (int) $product->product_id,
				'post_type' => (string) $product->product_type,
			);
		}
		return array('archived' => $archived, 'not_found' => $notFound);
	}
}
