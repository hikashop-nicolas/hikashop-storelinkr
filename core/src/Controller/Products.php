<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * Product endpoints: list, create, update, image, archive, archive/validate,
 * offline, upsert-bulk. Response shapes mirror the WooCommerce connector
 * (type/product_id/url/warnings, etc.).
 */

class Products extends Controller {

	public function index() {
		$service = $this->service('ProductService');
		$page = (int) $this->request->get('page', 1);
		return $service->listProducts($page);
	}

	public function create() {
		$this->request->requireFields(array('name', 'ean', 'site', 'importSource', 'salesPrice', 'inStock', 'stockSupplier', 'hasStock'));
		return $this->saveAndRespond(false);
	}

	public function update() {
		$this->request->requireFields(array('name', 'ean', 'site', 'importSource', 'salesPrice', 'id', 'inStock', 'stockSupplier', 'hasStock'));
		return $this->saveAndRespond(true);
	}

	private function saveAndRespond($isUpdate) {
		$service = $this->service('ProductService');
		$data = $this->request->all();
		$settings = $this->settings();

		$productId = $service->createOrUpdate($data, $settings, $this->publishNew($settings));
		$product = $service->findById($productId);

		$response = array(
			'status' => 'success',
			'type' => $product ? $product->product_type : 'main',
			'product_id' => $productId,
			'url' => $product ? $service->productUrl($product) : '',
			'warnings' => $this->warnings(),
		);

		if($isUpdate) {
			$gallery = array_map('intval', (array) $this->request->get('images', array()));
			$response['images'] = $gallery;
			$response['invalid_media'] = $this->invalidMedia($gallery);
		}
		return $response;
	}

	public function image() {
		$this->request->requireFields(array('id', 'alt'));
		$service = $this->service('ProductService');
		$productId = (int) $this->request->get('id');
		$product = $service->findById($productId);
		if(!$product) {
			ApiError::raise('Product not found with id ' . $productId);
		}

		$imageService = $this->service('ImageService');
		$mediaId = $imageService->ingestForProduct(
			$productId,
			$this->request->get('alt'),
			$this->request->get('cdn_url'),
			$this->request->get('imageContent')
		);

		$gallery = array_map('intval', (array) $this->request->get('imageGallery', array()));
		if(!empty($mediaId)) {
			$gallery[] = (int) $mediaId;
		}
		$kept = $service->setGallery($productId, $gallery);

		return array(
			'status' => 'success',
			'product' => array(
				'main' => !empty($kept) ? $service->fileUrlById($kept[0]) : '',
				'gallery' => array_values($kept),
				'thumb' => !empty($kept) ? $kept[0] : null,
				'gallery_debug' => $gallery,
			),
			'image_id' => $mediaId,
			'image_url' => $service->fileUrlById($mediaId),
			'warnings' => $this->warnings(),
		);
	}

	public function archive() {
		$this->request->requireFields(array('id'));
		$service = $this->service('ProductService');
		$service->setPublished((int) $this->request->get('id'), false);
		return array('status' => 'success');
	}

	public function archiveValidate() {
		$this->request->requireFields(array('products'));
		$service = $this->service('ProductService');
		return $service->archiveValidate((array) $this->request->get('products'));
	}

	public function offline() {
		if(!$this->request->has('gtins') && !$this->request->has('product_ids')) {
			ApiError::raise('At least one of gtins or product_ids field is required');
		}
		$gtins = (array) $this->request->get('gtins', array());
		$productIds = (array) $this->request->get('product_ids', array());
		$service = $this->service('ProductService');
		return array(
			'status' => 'success',
			'results' => $service->makeOffline($gtins, $productIds),
		);
	}

	public function upsertBulk() {
		$this->request->requireFields(array('products'));
		$products = $this->request->get('products');
		if(!is_array($products)) {
			ApiError::raise('Products is not an array!');
		}
		$settings = $this->settings();
		$publishNew = $this->publishNew($settings);
		$service = $this->service('ProductService');

		$result = array();
		foreach($products as $uuid => $raw) {
			$raw = (array) $raw;
			$raw['settings'] = $settings;
			if(empty($raw['uuid']) && is_string($uuid)) {
				$raw['uuid'] = $uuid;
			}
			try {
				$productId = $service->createOrUpdate($raw, $settings, $publishNew);
				$product = $service->findById($productId);
				$result[$uuid] = array(
					'product_id' => $productId,
					'url' => $product ? $service->productUrl($product) : '',
				);
			} catch (\Exception $e) {
				$result[$uuid] = array(
					'error' => $e->getMessage(),
					'product_uuid' => $uuid,
				);
			}
		}

		return array(
			'status' => 'success',
			'products' => $result,
			'warnings' => $this->warnings(),
		);
	}

	private function settings() {
		$settings = $this->request->get('settings', array());
		return is_array($settings) ? $settings : array();
	}

	private function publishNew(array $settings) {
		if(isset($settings['publish_new_products'])) {
			return (bool) $settings['publish_new_products'];
		}
		return (bool) $this->params->get('publish_new_products', 1);
	}

	/** The images StoreLinkr named that are gone or broken: it uploads them again. */
	private function invalidMedia(array $ids) {
		$valid = $this->service('ProductService')->validImageIds($ids);
		return array_values(array_diff(array_map('intval', $ids), $valid));
	}
}
