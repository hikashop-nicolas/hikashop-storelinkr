<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;
use HikashopStorelinkr\ProductExtras;

/**
 * Maps StoreLinkr variable products onto HikaShop's characteristic/variant
 * model. StoreLinkr sends a parent name, a list of option labels (axes) and a
 * list of variations each carrying an options map (label => value). We create
 * one main product, a characteristic per axis with a value per distinct option,
 * register the axes on the main (auto_variants off so HikaShop does not
 * generate combinations), then create one variant child per variation linked to
 * its value characteristics. Returns ean/uuid -> child id maps.
 */

class VariantService extends Service {

	private $productService = null;
	private $categoryService = null;

	private function productService() {
		if($this->productService === null) {
			$this->productService = new ProductService($this->platform);
		}
		return $this->productService;
	}

	private function categoryService() {
		if($this->categoryService === null) {
			$this->categoryService = new CategoryService($this->platform);
		}
		return $this->categoryService;
	}

	public function createOrUpdate(array $data, array $settings, $publishNew, $mainId = 0) {
		Schema::ensure($this->db());
		$productClass = hikashop_get('class.product');

		$optionLabels = isset($data['options']) ? (array)$data['options'] : array();
		$variations = isset($data['products']) ? (array)$data['products'] : array();
		$variantInfo = isset($data['variant']) ? (array)$data['variant'] : array();
		$name = isset($data['name']) ? (string)$data['name'] : (isset($variantInfo['name']) ? $variantInfo['name'] : '');

		// 1) Axes + their value characteristics.
		$axes = array();          // label => axis characteristic_id
		$valueIds = array();      // label => [value => characteristic_id]
		$ordering = 0;
		foreach($optionLabels as $label) {
			$axisId = $this->upsertCharacteristic($label, 0);
			$axes[$label] = $axisId;
			$valueIds[$label] = array();
			foreach($variations as $variation) {
				$opts = isset($variation['options']) ? (array)$variation['options'] : array();
				if(!isset($opts[$label]) || $opts[$label] === '') {
					continue;
				}
				$value = (string) $opts[$label];
				if(!isset($valueIds[$label][$value])) {
					$valueIds[$label][$value] = $this->upsertCharacteristic($value, $axisId);
				}
			}
		}

		// 2) Main product.
		$main = new \stdClass();
		$existing = null;
		if($mainId > 0) {
			$existing = $this->productService()->findById($mainId);
			if($existing) {
				$main->product_id = (int)$existing->product_id;
			}
		}
		$main->product_type = 'main';
		if($name !== '') {
			$main->product_name = $name;
		}
		if(!empty($variantInfo['description'])) {
			$main->product_description = (string)$variantInfo['description'];
		} elseif(!empty($variantInfo['shortDescription'])) {
			$main->product_description = (string)$variantInfo['shortDescription'];
		}
		$main->{Schema::COL_SOURCE} = $this->importSource();

		$catIds = $this->resolveCategoryIds($data);
		if($catIds !== null) {
			$main->categories = $catIds;
		} elseif(empty($main->product_id)) {
			$main->categories = array();
		}
		if(!empty($variantInfo['brand'])) {
			$bid = $this->categoryService()->upsertManufacturer($variantInfo['brand']);
			if($bid) {
				$main->product_manufacturer_id = $bid;
			}
		} elseif(!empty($data['brand'])) {
			$bid = $this->categoryService()->upsertManufacturer($data['brand']);
			if($bid) {
				$main->product_manufacturer_id = $bid;
			}
		}
		if(empty($main->product_id)) {
			$main->product_published = $publishNew ? 1 : 0;
		} else {
			ProductExtras::restoreIfTakenOffline($main, $existing);
		}
		ProductExtras::apply($this->db(), $main, $variantInfo);

		// Axis objects for the main product.
		$ord = 0;
		$main->characteristics = array();
		foreach($axes as $label => $axisId) {
			$axis = new \stdClass();
			$axis->characteristic_id = (int)$axisId;
			$axis->default_id = 0;
			$axis->ordering = ++$ord;
			$main->characteristics[] = $axis;
		}

		$isNewMain = empty($main->product_id);
		$mainId = (int) $productClass->save($main, false, true);
		if(!$mainId) {
			throw ApiError::because('Could not save the variable product');
		}
		if(isset($main->categories)) {
			$productClass->updateCategories($main, $mainId);
		}
		// auto_variants = 0: register the axes without generating combinations.
		$productClass->updateCharacteristics($main, $mainId, 0);

		$this->platform->triggerEvent($isNewMain ? 'onAfterProductCreate' : 'onAfterProductUpdate', array(&$main));

		// 3) Variant children.
		$eanMap = array();
		$uuidMap = array();
		foreach($variations as $variation) {
			$childId = $this->saveChild($variation, $mainId, $axes, $valueIds, $settings);
			if(!$childId) {
				continue;
			}
			if(!empty($variation['ean'])) {
				$eanMap[(string)$variation['ean']] = $childId;
			}
			if(!empty($variation['uuid'])) {
				$uuidMap[(string)$variation['uuid']] = $childId;
			}
		}

		$mainProduct = $this->productService()->findById($mainId);
		return array(
			'status' => 'success',
			'type' => 'variant',
			'product_id' => $mainId,
			'variant_options' => $eanMap,
			'variant_map' => $uuidMap,
			'url' => $mainProduct ? $this->productService()->productUrl($mainProduct) : '',
			'total_products' => count($variations),
		);
	}

	private function saveChild(array $variation, $mainId, array $axes, array $valueIds, array $settings) {
		$productClass = hikashop_get('class.product');

		$existing = null;
		if(!empty($variation['id'])) {
			$existing = $this->productService()->findById($variation['id']);
		}
		if(!$existing && !empty($variation['sku'])) {
			$existing = $this->productService()->findBySku($variation['sku']);
		}
		if(!$existing && !empty($variation['ean'])) {
			$existing = $this->productService()->findByEan($variation['ean']);
		}

		$child = new \stdClass();
		if($existing) {
			$child->product_id = (int)$existing->product_id;
		}
		$child->product_type = 'variant';
		$child->product_parent_id = (int)$mainId;
		if(!empty($variation['name'])) {
			$child->product_name = (string)$variation['name'];
		}
		if(!empty($variation['sku'])) {
			$child->product_code = trim((string)$variation['sku']);
		}
		if(!empty($variation['ean'])) {
			$child->{Schema::COL_EAN} = trim((string)$variation['ean']);
		}
		if(!empty($variation['uuid'])) {
			$child->{Schema::COL_UUID} = (string)$variation['uuid'];
		}
		$child->{Schema::COL_SOURCE} = $this->importSource();
		ProductExtras::apply($this->db(), $child, $variation);
		if($existing) {
			ProductExtras::restoreIfTakenOffline($child, $existing);
		} else {
			$child->product_published = 1;
		}
		if(isset($variation['images'])) {
			$this->productService()->setImages($child, (array)$variation['images']);
		}

		if(ProductMapper::shouldUpdateStock($variation, $settings)) {
			$child->product_quantity = ProductMapper::computeQuantity($variation);
		}
		if(ProductMapper::shouldUpdatePrice($variation, $settings)) {
			$this->applyChildPrice($child, $variation);
		}

		// Map this variation's option values to their characteristic ids (keyed).
		$child->characteristics = array();
		$opts = isset($variation['options']) ? (array)$variation['options'] : array();
		foreach($opts as $label => $value) {
			if(isset($valueIds[$label][(string)$value])) {
				$vid = (int) $valueIds[$label][(string)$value];
				$child->characteristics[$vid] = $vid;
			}
		}

		$childId = (int) $productClass->save($child, false, true);
		if(!$childId) {
			$this->logWarning('Could not save variant child ' . (isset($variation['sku']) ? $variation['sku'] : ''));
			return 0;
		}
		if(isset($child->prices)) {
			$productClass->updatePrices($child, $childId);
		}
		if(isset($child->images)) {
			$this->productService()->claimImages($child, $childId);
			$productClass->updateFiles($child, $childId, 'images', $child->imagesorder);
		}
		$productClass->updateCharacteristics($child, $childId);

		return $childId;
	}

	private function applyChildPrice($child, array $variation) {
		$sales = isset($variation['salesPrice']) ? (int)$variation['salesPrice'] : 0;
		$promo = isset($variation['promoSalesPrice']) ? (int)$variation['promoSalesPrice'] : 0;
		$effective = ($promo > 0) ? $promo : $sales;
		$price = new \stdClass();
		$price->price_currency_id = $this->currencyId();
		$price->price_value = ($effective > 0) ? ProductMapper::centsToPrice($effective) : '0.00000';
		$price->price_min_quantity = 0;
		$price->price_access = 'all';
		$child->prices = array($price);
	}

	private function upsertCharacteristic($value, $parentId) {
		$value = trim((string)$value);
		$db = $this->db();
		$db->setQuery(
			'SELECT characteristic_id FROM ' . hikashop_table('characteristic') .
			' WHERE characteristic_parent_id = ' . (int)$parentId .
			' AND characteristic_value = ' . $db->quote($value) . ' LIMIT 1'
		);
		$id = (int) $db->loadResult();
		if($id > 0) {
			return $id;
		}
		$characteristicClass = hikashop_get('class.characteristic');
		$element = new \stdClass();
		$element->characteristic_parent_id = (int)$parentId;
		$element->characteristic_value = $value;
		$id = $characteristicClass->save($element);
		return (int)$id;
	}

	private function resolveCategoryIds(array $data) {
		if(!empty($data['categories']) && is_array($data['categories'])) {
			return array_values(array_map('intval', $data['categories']));
		}
		if(!empty($data['categoryId'])) {
			return array((int)$data['categoryId']);
		}
		return null;
	}

	/** Link existing products as variants of a grouped parent (best-effort). */
	public function linkVariants(array $data) {
		$linkProducts = isset($data['linkProducts']) && is_array($data['linkProducts']) ? array_map('intval', $data['linkProducts']) : array();
		$removeProducts = isset($data['removeProducts']) && is_array($data['removeProducts']) ? array_map('intval', $data['removeProducts']) : array();
		$grouped = !empty($data['groupedVariant']);

		$variantId = null;
		if(isset($data['variant']['id'])) {
			$variantId = (int)$data['variant']['id'];
		} elseif(isset($data['id'])) {
			$variantId = (int)$data['id'];
		}

		$productClass = hikashop_get('class.product');
		if($grouped && $variantId) {
			foreach($linkProducts as $pid) {
				$el = new \stdClass();
				$el->product_id = $pid;
				$el->product_parent_id = $variantId;
				$el->product_type = 'variant';
				$productClass->save($el, false, true);
			}
		}
		foreach($removeProducts as $pid) {
			$el = new \stdClass();
			$el->product_id = $pid;
			$el->product_parent_id = 0;
			$el->product_type = 'main';
			$productClass->save($el, false, true);
		}

		$mainProduct = $variantId ? $this->productService()->findById($variantId) : null;
		return array(
			'status' => 'success',
			'variant_id' => $grouped ? $variantId : null,
			'variant_url' => ($grouped && $mainProduct) ? $this->productService()->productUrl($mainProduct) : null,
			'link_to' => $linkProducts,
			'remove_from' => $removeProducts,
		);
	}
}
