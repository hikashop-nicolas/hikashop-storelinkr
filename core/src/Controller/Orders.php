<?php
namespace HikashopStorelinkr\Controller;

use HikashopStorelinkr\Controller;
use HikashopStorelinkr\ApiError;

/**
 * Order endpoints: list (GET) and create (POST). Create maps StoreLinkr's thin
 * payload to a HikaShop order with billing/shipping addresses and line items.
 */

class Orders extends Controller {

	public function index() {
		$service = $this->service('OrderService');
		return array(
			'status' => 'success',
			'orders' => $service->listOrders(),
		);
	}

	public function create() {
		$this->request->requireFields(array('uuid', 'first_name', 'last_name', 'currency', 'mail_address', 'billing_address', 'shipping_address'));
		$service = $this->service('OrderService');
		$orderId = $service->createOrder($this->request->all());
		return array(
			'status' => 'success',
			'id' => $orderId,
		);
	}
}
