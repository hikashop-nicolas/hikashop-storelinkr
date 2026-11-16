<?php
namespace HikashopStorelinkr;

/**
 * Pure transforms between StoreLinkr's product payload and HikaShop fields.
 * Prices travel as integer cents; stock quantity is inStock + stockSupplier
 * (min 1 when flagged in stock), mirroring the WooCommerce mapper. Also builds
 * the products-listing item in the exact shape StoreLinkr's connector emits.
 */

class ProductMapper {

	public static function centsToPrice($cents) {
		$cents = (int) $cents;
		if($cents <= 0) {
			return 0.0;
		}
		return round($cents / 100, 5);
	}

	public static function shouldUpdateStock(array $data, array $settings) {
		if(isset($settings['overwrite_product_stock'])) {
			return (bool) $settings['overwrite_product_stock'];
		}
		return !isset($data['updateStock']) || (bool) $data['updateStock'];
	}

	public static function shouldUpdatePrice(array $data, array $settings) {
		if(isset($settings['overwrite_product_prices'])) {
			return (bool) $settings['overwrite_product_prices'];
		}
		return !isset($data['updatePrice']) || (bool) $data['updatePrice'];
	}

	/** Quantity = inStock + stockSupplier, min 1 when flagged in stock, else 0. */
	public static function computeQuantity(array $data) {
		$inStock = isset($data['inStock']) ? (int) $data['inStock'] : 0;
		$supplier = isset($data['stockSupplier']) ? (int) $data['stockSupplier'] : 0;
		$hasStock = isset($data['hasStock']) ? (bool) $data['hasStock'] : false;

		$inStockFlag = $hasStock || $inStock >= 1 || $supplier >= 1;
		if(!$inStockFlag) {
			return 0;
		}
		$qty = $inStock + $supplier;
		return ($qty < 1) ? 1 : $qty;
	}

	/** Build one item for the GET /products listing. */
	public static function buildListItem($product, array $prices, array $categoryChain, array $images, $brand, $ean, $url) {
		$regular = isset($prices['regular']) ? (float) $prices['regular'] : 0.0;
		$promo = isset($prices['promo']) ? (float) $prices['promo'] : 0.0;

		$chain = array_pad(array_slice($categoryChain, 0, 5), 5, null);

		return array(
			'id' => (int) $product->product_id,
			'url' => $url,
			'product_type' => $product->product_type,
			'ean' => (string) $ean,
			'sku' => (string) $product->product_code,
			'brand' => (string) $brand,
			'name' => (string) $product->product_name,
			'title' => (string) $product->product_name,
			'short_description' => '',
			'description' => (string) $product->product_description,
			'price_cents' => (int) round($regular * 100),
			'promo_price_cents' => (int) round($promo * 100),
			'stock' => array(
				'stock_management' => ((int)$product->product_quantity !== -1),
				'has_stock' => ((int)$product->product_quantity === -1 || (int)$product->product_quantity > 0),
				'quantity' => ((int)$product->product_quantity === -1) ? null : (int)$product->product_quantity,
				'allow_backorder' => false,
			),
			'dimensions' => array(
				'length' => self::numeric($product->product_length),
				'width' => self::numeric($product->product_width),
				'height' => self::numeric($product->product_height),
				'unit' => (string) (isset($product->product_dimension_unit) ? $product->product_dimension_unit : null),
			),
			'weight' => array(
				'weight' => self::numeric($product->product_weight),
				'unit' => (string) (isset($product->product_weight_unit) ? $product->product_weight_unit : null),
			),
			'category_main' => $chain[0],
			'category_sub' => $chain[1],
			'category_sub_sub' => $chain[2],
			'category_sub_sub_sub' => $chain[3],
			'category_sub_sub_sub_sub' => $chain[4],
			'images' => $images,
			'facets' => array(),
			'rating' => array(
				'total' => (int) (isset($product->product_total_vote) ? $product->product_total_vote : null),
				'average' => (float) (isset($product->product_average_score) ? $product->product_average_score : null),
			),
		);
	}

	private static function numeric($value) {
		if($value === null || $value === '') {
			return null;
		}
		return (float) $value;
	}
}
