<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;

/**
 * Reads HikaShop orders into StoreLinkr's order shape, and creates an order
 * from StoreLinkr's (thin) payload: billing + shipping addresses, an order with
 * a configurable imported status, and line items matched to products by sku/ean.
 * Totals travel as integer cents. Order creation is a bare save (no checkout
 * pipeline), so it does not trigger customer/invoice emails.
 */

class OrderService extends Service {

	private $productService = null;

	private function productService() {
		if($this->productService === null) {
			$this->productService = new ProductService($this->platform);
		}
		return $this->productService;
	}

	public function listOrders($limit = 20) {
		$db = $this->db();
		$db->setQuery(
			'SELECT * FROM ' . hikashop_table('order') .
			" WHERE order_type = 'sale' ORDER BY order_created DESC", 0, (int)$limit
		);
		$orders = (array) $db->loadObjectList();

		$out = array();
		foreach($orders as $order) {
			$out[] = $this->formatOrder($order);
		}
		return $out;
	}

	private function formatOrder($order) {
		$billing = $this->loadAddress($order->order_billing_address_id);
		$shipping = $this->loadAddress($order->order_shipping_address_id);
		$currency = $this->currencyCode($order->order_currency_id);

		$lines = array();
		$db = $this->db();
		$db->setQuery('SELECT * FROM ' . hikashop_table('order_product') . ' WHERE order_id = ' . (int)$order->order_id);
		foreach((array)$db->loadObjectList() as $item) {
			$lines[] = array(
				'item_id' => (int) $item->order_product_id,
				'product_id' => (int) $item->product_id,
				'ean' => $this->productEan($item->product_id),
				'sku' => (string) $item->order_product_code,
				'name' => (string) $item->order_product_name,
				'quantity' => (int) $item->order_product_quantity,
				'price' => (int) round((float)$item->order_product_price * 100),
				'price_incl_vat' => (int) round(((float)$item->order_product_price + (float)$item->order_product_tax) * 100),
			);
		}

		return array(
			'order_id' => (int) $order->order_id,
			'order_number' => (string) $order->order_number,
			'order_status' => (string) $order->order_status,
			'created' => $order->order_created ? gmdate('Y-m-d H:i:s', (int)$order->order_created) : null,
			'customer' => array(
				'first_name' => $billing ? (string)$billing->address_firstname : '',
				'last_name' => $billing ? (string)$billing->address_lastname : '',
				'email' => $this->orderEmail($order->order_user_id),
				'company_name' => $billing ? (string)$billing->address_company : '',
				'billingAddress' => $this->addressShape($billing),
				'shippingAddress' => $this->addressShape($shipping),
			),
			'order_lines' => $lines,
			'currency' => $currency,
			'grand_total_cents' => (int) round((float)$order->order_full_price * 100),
			'shipping_costs_cents' => (int) round((float)$order->order_shipping_price * 100),
			'discount_cents' => (int) round((float)$order->order_discount_price * 100),
			'payment_status' => (string) $order->order_status,
			'deliver_method' => $this->shippingName($order),
		);
	}

	private function addressShape($address) {
		if(empty($address)) {
			return array('address' => '', 'addition' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => '', 'phone' => '');
		}
		// address_country / address_state hold a zone namekey (e.g. country_France_73),
		// not a raw value: resolve them through the zone table.
		return array(
			'address' => (string) $address->address_street,
			'addition' => (string) $address->address_street2,
			'city' => (string) $address->address_city,
			'state' => $this->zoneName($address->address_state),
			'postcode' => (string) $address->address_post_code,
			'country' => $this->zoneCountryCode($address->address_country),
			'phone' => (string) $address->address_telephone,
		);
	}

	public function createOrder(array $data) {
		$billingId = $this->saveAddress($data, 'billing');
		$shippingId = $this->saveAddress($data, 'shipping');

		$order = new \stdClass();
		$order->order_type = 'sale';
		$order->order_status = (string) $this->params->get('imported_order_status', 'created');
		$order->order_user_id = 0;
		$order->order_billing_address_id = $billingId;
		$order->order_shipping_address_id = $shippingId;
		$order->order_currency_id = $this->resolveCurrency(isset($data['currency']) ? $data['currency'] : '');
		if(isset($data['total_amount_cents'])) {
			$order->order_full_price = round(((int)$data['total_amount_cents']) / 100, 5);
		}
		if(!empty($data['order_number'])) {
			$order->order_number = (string) $data['order_number'];
		}
		if(!empty($data['notes'])) {
			$order->order_partner_id = 0;
		}

		$orderClass = hikashop_get('class.order');
		$orderId = (int) $orderClass->save($order);
		if(!$orderId) {
			ApiError::raise('Could not create the order');
		}

		if(!empty($data['items']) && is_array($data['items'])) {
			$this->saveLines($orderId, $data['items']);
		}

		return $orderId;
	}

	private function saveLines($orderId, array $items) {
		$db = $this->db();
		foreach($items as $item) {
			$product = null;
			if(!empty($item['sku'])) {
				$product = $this->productService()->findBySku($item['sku']);
			}
			if(!$product && !empty($item['ean'])) {
				$product = $this->productService()->findByEan($item['ean']);
			}
			$line = new \stdClass();
			$line->order_id = (int) $orderId;
			$line->product_id = $product ? (int)$product->product_id : 0;
			$line->order_product_quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
			$line->order_product_name = isset($item['name']) ? (string)$item['name'] : ($product ? $product->product_name : '');
			$line->order_product_code = isset($item['sku']) ? (string)$item['sku'] : ($product ? $product->product_code : '');
			$line->order_product_price = isset($item['price']) ? round(((int)$item['price']) / 100, 5) : 0;
			$line->order_product_tax = 0;
			$db->insertObject(hikashop_table('order_product'), $line);
		}
	}

	/**
	 * Insert the address directly. The address class save() applies frontend
	 * ownership/VAT validation that rejects guest import addresses, so for an
	 * imported order we write the row ourselves.
	 */
	private function saveAddress(array $data, $type) {
		$prefix = ($type === 'billing') ? 'billing_' : 'shipping_';
		$address = new \stdClass();
		$address->address_type = $type;
		$address->address_user_id = 0;
		$address->address_firstname = isset($data['first_name']) ? (string)$data['first_name'] : '';
		$address->address_lastname = isset($data['last_name']) ? (string)$data['last_name'] : '';
		$address->address_company = isset($data['company_name']) ? (string)$data['company_name'] : '';
		$address->address_street = isset($data[$type . '_address']) ? (string)$data[$type . '_address'] : (isset($data[$prefix . 'address']) ? (string)$data[$prefix . 'address'] : '');
		$address->address_street2 = isset($data[$prefix . 'address_two']) ? (string)$data[$prefix . 'address_two'] : '';
		$address->address_post_code = isset($data[$prefix . 'postal_code']) ? (string)$data[$prefix . 'postal_code'] : '';
		$address->address_city = isset($data[$prefix . 'city']) ? (string)$data[$prefix . 'city'] : '';
		$address->address_telephone = isset($data['phone_number']) ? (string)$data['phone_number'] : '';
		// HikaShop stores the country zone namekey (not an id) in address_country.
		$countryNamekey = $this->resolveCountryNamekey(isset($data[$prefix . 'country_code']) ? $data[$prefix . 'country_code'] : '');
		if($countryNamekey !== '') {
			$address->address_country = $countryNamekey;
		}
		$address->address_published = 1;

		$db = $this->db();
		$db->insertObject(hikashop_table('address'), $address, 'address_id');
		return (int) $db->insertid();
	}

	private function loadAddress($id) {
		$id = (int) $id;
		if($id <= 0) {
			return null;
		}
		$db = $this->db();
		$db->setQuery('SELECT * FROM ' . hikashop_table('address') . ' WHERE address_id = ' . $id);
		return $db->loadObject();
	}

	private function resolveCurrency($code) {
		$id = $this->currencyIdByCode($code);
		return $id ? $id : $this->currencyId();
	}

	private function currencyIdByCode($code) {
		$code = trim((string)$code);
		if($code === '') {
			return 0;
		}
		$db = $this->db();
		$db->setQuery('SELECT currency_id FROM ' . hikashop_table('currency') . ' WHERE currency_code = ' . $db->quote($code) . ' LIMIT 1');
		return (int) $db->loadResult();
	}

	private function currencyCode($currencyId) {
		$db = $this->db();
		$db->setQuery('SELECT currency_code FROM ' . hikashop_table('currency') . ' WHERE currency_id = ' . (int)$currencyId);
		return (string) $db->loadResult();
	}

	/** Look up a zone by its namekey (the stored form) or numeric id. */
	private function resolveZone($ref) {
		$ref = trim((string)$ref);
		if($ref === '') {
			return null;
		}
		$db = $this->db();
		$column = ctype_digit($ref) ? 'zone_id' : 'zone_namekey';
		$db->setQuery('SELECT zone_name, zone_code_2, zone_code_3 FROM ' . hikashop_table('zone') . ' WHERE ' . $column . ' = ' . $db->quote($ref) . ' LIMIT 1');
		return $db->loadObject();
	}

	/** Country as an ISO code (matching the WooCommerce connector) from a zone ref. */
	private function zoneCountryCode($ref) {
		$zone = $this->resolveZone($ref);
		if(empty($zone)) {
			return '';
		}
		if(!empty($zone->zone_code_2)) {
			return (string) $zone->zone_code_2;
		}
		return (string) (!empty($zone->zone_code_3) ? $zone->zone_code_3 : $zone->zone_name);
	}

	/** Human-readable name for a zone ref (used for the state). */
	private function zoneName($ref) {
		$zone = $this->resolveZone($ref);
		return empty($zone) ? '' : (string) $zone->zone_name;
	}

	/** Resolve an ISO country code to the zone namekey HikaShop stores in addresses. */
	private function resolveCountryNamekey($code) {
		$code = trim((string)$code);
		if($code === '') {
			return '';
		}
		$db = $this->db();
		$db->setQuery(
			'SELECT zone_namekey FROM ' . hikashop_table('zone') .
			" WHERE zone_type = 'country' AND (zone_code_2 = " . $db->quote($code) .
			' OR zone_code_3 = ' . $db->quote($code) . ') LIMIT 1'
		);
		return (string) $db->loadResult();
	}

	/** Shipping method name via HikaShop's resolver (handles order_shipping_id). */
	private function shippingName($order) {
		$shippingClass = hikashop_get('class.shipping');
		if(!$shippingClass || !method_exists($shippingClass, 'getAllShippingNames')) {
			return (string) (isset($order->order_shipping_method) ? $order->order_shipping_method : null);
		}
		if(!isset($order->products)) {
			$order->products = array();
		}
		if(!isset($order->shippings)) {
			$order->shippings = array();
		}
		if(isset($order->order_shipping_params) && is_string($order->order_shipping_params) && function_exists('hikashop_unserialize')) {
			$decoded = hikashop_unserialize($order->order_shipping_params);
			if($decoded !== false) {
				$order->order_shipping_params = $decoded;
			}
		}
		$names = array_filter(array_map('trim', (array) $shippingClass->getAllShippingNames($order)));
		return implode(', ', $names);
	}

	private function orderEmail($userId) {
		$userId = (int) $userId;
		if($userId <= 0) {
			return '';
		}
		$db = $this->db();
		$db->setQuery('SELECT user_email FROM ' . hikashop_table('user') . ' WHERE user_id = ' . $userId);
		return (string) $db->loadResult();
	}

	private function productEan($productId) {
		Schema::ensure($this->db());
		$db = $this->db();
		$db->setQuery('SELECT ' . $db->quoteName(Schema::COL_EAN) . ' FROM ' . hikashop_table('product') . ' WHERE product_id = ' . (int)$productId);
		return (string) $db->loadResult();
	}
}
