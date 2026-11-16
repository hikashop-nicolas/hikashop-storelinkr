<?php
/**
 * Every route of the connector, over HTTP, against a shop with the plugin
 * installed: what StoreLinkr does in a sync, in order. It writes products,
 * categories, an order and a warehouse: run it on a test shop only.
 *
 *   php tests/smoke.php <base> <key> <secret>
 *   e.g. php tests/smoke.php http://localhost/j6/index.php/storelinkr/v1 k s
 */

if ($argc < 4) {
	fwrite(STDERR, "usage: php tests/smoke.php <base url ending in storelinkr/v1> <key> <secret>\n");
	exit(2);
}
list(, $base, $key, $secret) = $argv;
$base   = rtrim($base, '/');
$run    = substr((string) time(), -6);
$failed = 0;
$passed = 0;

function call($method, $path, $body = null, $auth = true) {
	global $base, $key, $secret;
	$url = $base . '/' . $path;
	if (strpos($base, 'rest_route=') !== false) {
		$url = $base . '/' . $path;
	}
	$h = array('Content-Type: application/json', 'Accept: application/json');
	if ($auth) {
		$h[] = 'X-Api-Key: ' . $key;
		$h[] = 'X-Api-Secret: ' . $secret;
	}
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60));
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	}
	$raw    = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	return array($status, json_decode((string) $raw, true), (string) $raw);
}

function ok($name, $cond, $detail = '') {
	global $failed, $passed;
	if ($cond) {
		$passed++;
		echo "  PASS $name\n";
	} else {
		$failed++;
		echo "  FAIL $name  " . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . "\n";
	}
}

echo "auth\n";
list($s) = call('GET', 'test-connection', null, false);
ok('no key is refused (401)', $s === 401, $s);
list($s, $j) = call('GET', 'test-connection');
ok('test-connection', $s === 200 && $j['status'] === 'success' && $j['writable'] === true, $j);
list($s, $j) = call('GET', 'nowhere');
ok('an unknown route is a 404', $s === 404, $s);

echo "categories\n";
list($s, $j, $raw) = call('POST', 'categories/create', array('import_source' => 'storelinkr', 'name' => "Smoke $run", 'parentId' => 0));
$cat = isset($j['category']['id']) ? (int) $j['category']['id'] : 0;
ok('create a category', $s === 200 && $cat > 0, $raw);
list($s, $j, $raw) = call('POST', 'categories/create', array('import_source' => 'storelinkr', 'name' => "Sub $run", 'parentId' => $cat));
$sub = isset($j['category']['id']) ? (int) $j['category']['id'] : 0;
ok('create a sub-category', $s === 200 && $sub > 0 && $j['category']['parent'] === $cat, $raw);
list($s, $j, $raw) = call('POST', "categories/update/$sub", array('name' => "Sub $run renamed", 'parentId' => $cat));
ok('rename it', $s === 200 && $j['status'] === 'success', $raw);
list($s, $j, $raw) = call('GET', 'categories');
$names = array();
foreach ((array) $j['categories'] as $c) {
	$names[] = $c['categorySub'];
}
ok('list them, with their path', $s === 200 && in_array("Sub $run renamed", $names, true), $raw);
list($s, $j, $raw) = call('POST', 'categories/create', array('import_source' => 'storelinkr', 'name' => "Smoke $run", 'parentId' => 0));
ok('creating it again gives the same one', isset($j['category']['id']) && (int) $j['category']['id'] === $cat, $raw);

echo "images\n";
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';
list($s, $j, $raw) = call('POST', 'images/create', array('alt' => "Pixel $run", 'imageContent' => $png));
$img1 = isset($j['image_id']) ? (int) $j['image_id'] : 0;
ok('an image sent as base64', $s === 200 && $img1 > 0 && $j['image_url'] !== '', $raw);
list($s, $j, $raw) = call('POST', 'images/create', array('alt' => "Logo $run", 'cdn_url' => 'https://raw.githubusercontent.com/hikashop-nicolas/freescout-hikashop/main/marketplace/logo_freescout.png'));
$img2 = isset($j['image_id']) ? (int) $j['image_id'] : 0;
ok('an image downloaded from its address', $s === 200 && $img2 > 0, $raw);
list($s, $j, $raw) = call('POST', 'images/create', array('alt' => 'local', 'cdn_url' => 'http://127.0.0.1/x.png'));
ok('a local address is refused', $s === 400, $raw);
list($s, $j, $raw) = call('POST', 'images/create', array('alt' => 'html', 'imageContent' => base64_encode('<html>not an image</html>')));
ok('what is not an image is refused', $s === 400, $raw);

echo "products\n";
$ean  = '871' . str_pad($run, 10, '0', STR_PAD_LEFT);
$prod = array('name' => "Smoke mug $run", 'ean' => $ean, 'sku' => "SMK-$run", 'site' => 'default', 'importSource' => 'storelinkr',
	'salesPrice' => 1295, 'promoSalesPrice' => 0, 'advisedPrice' => 1500, 'inStock' => 4, 'stockSupplier' => 2, 'hasStock' => true,
	'categoryId' => $sub, 'brand' => "Brand $run", 'longDescription' => '<p>A mug.</p>', 'images' => array($img1, $img2));
list($s, $j, $raw) = call('POST', 'products/create', $prod);
$pid = isset($j['product_id']) ? (int) $j['product_id'] : 0;
ok('create a product', $s === 200 && $pid > 0 && $j['type'] === 'main' && $j['url'] !== '', $raw);
$page = curl_init($j['url'] ?? '');
curl_setopt_array($page, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));
$html = (string) curl_exec($page);
ok('its url opens its page in the shop', curl_getinfo($page, CURLINFO_RESPONSE_CODE) === 200 && strpos($html, "Smoke mug $run") !== false, $j['url'] ?? '');
list($s, $j, $raw) = call('POST', 'products/create', $prod);
ok('the same EAN and SKU give the same product', isset($j['product_id']) && (int) $j['product_id'] === $pid, $raw);
$prod['id']         = $pid;
$prod['salesPrice'] = 1395;
$prod['images']     = array($img2, $img1, 999999999);
list($s, $j, $raw) = call('POST', "products/$pid/update", $prod);
ok('update it', $s === 200 && (int) $j['product_id'] === $pid, $raw);
ok('a missing image is reported', isset($j['invalid_media']) && in_array(999999999, $j['invalid_media'], true), $raw);
list($s, $j, $raw) = call('POST', "products/$pid/image", array('id' => $pid, 'alt' => 'Third', 'imageContent' => $png, 'imageGallery' => array($img2, $img1)));
ok('add an image to its gallery', $s === 200 && count($j['product']['gallery']) === 3 && $j['product']['gallery'][0] === $img2, $raw);
list($s, $j, $raw) = call('POST', 'products/upsert-bulk', array('products' => array(
	"uuid-a-$run" => array('name' => "Bulk A $run", 'ean' => '872' . str_pad($run, 10, '0', STR_PAD_LEFT), 'sku' => "BLK-A-$run", 'salesPrice' => 500, 'inStock' => 1, 'stockSupplier' => 0, 'hasStock' => true),
	"uuid-b-$run" => array('name' => "Bulk B $run", 'ean' => '873' . str_pad($run, 10, '0', STR_PAD_LEFT), 'sku' => "BLK-B-$run", 'salesPrice' => 600, 'inStock' => 0, 'stockSupplier' => 0, 'hasStock' => false),
)));
ok('upsert in bulk', $s === 200 && !empty($j['products']["uuid-a-$run"]['product_id']) && !empty($j['products']["uuid-b-$run"]['product_id']), $raw);

echo "variants\n";
$variable = array('name' => "Smoke shirt $run", 'importSource' => 'storelinkr', 'categories' => array($sub), 'options' => array('Size', 'Colour'),
	'variant' => array('name' => "Smoke shirt $run", 'description' => '<p>A shirt.</p>', 'brand' => "Brand $run"),
	'products' => array(
		array('name' => "Shirt S red $run", 'sku' => "SHR-S-R-$run", 'ean' => '874' . str_pad($run, 10, '0', STR_PAD_LEFT), 'uuid' => "v1-$run", 'salesPrice' => 2000, 'inStock' => 3, 'stockSupplier' => 0, 'hasStock' => true, 'options' => array('Size' => 'S', 'Colour' => 'Red')),
		array('name' => "Shirt M red $run", 'sku' => "SHR-M-R-$run", 'ean' => '875' . str_pad($run, 10, '0', STR_PAD_LEFT), 'uuid' => "v2-$run", 'salesPrice' => 2000, 'inStock' => 0, 'stockSupplier' => 5, 'hasStock' => true, 'options' => array('Size' => 'M', 'Colour' => 'Red')),
	));
list($s, $j, $raw) = call('POST', 'products/create-variant', $variable);
$vid = isset($j['product_id']) ? (int) $j['product_id'] : 0;
ok('create a product with variants', $s === 200 && $vid > 0 && count($j['variant_map']) === 2, $raw);
$variable['products'][] = array('name' => "Shirt L blue $run", 'sku' => "SHR-L-B-$run", 'ean' => '876' . str_pad($run, 10, '0', STR_PAD_LEFT), 'uuid' => "v3-$run", 'salesPrice' => 2100, 'inStock' => 1, 'stockSupplier' => 0, 'hasStock' => true, 'options' => array('Size' => 'L', 'Colour' => 'Blue'));
list($s, $j, $raw) = call('POST', "products/$vid/update-variant", $variable);
ok('add a variant to it', $s === 200 && (int) $j['product_id'] === $vid && count($j['variant_map']) === 3, $raw);

echo "listing\n";
list($s, $j, $raw) = call('GET', 'products?page=1');
if (!empty($j['total_pages']) && $j['total_pages'] > 1) {
	list($s, $j, $raw) = call('GET', 'products?page=' . $j['total_pages']);
}
$found = null;
foreach ((array) $j['products'] as $p) {
	if ((int) $p['id'] === $pid) {
		$found = $p;
	}
}
ok('list products', $s === 200 && $j['total_records'] >= 1, $raw);
ok('with the product as sent', $found && $found['ean'] === $ean && $found['price_cents'] === 1395 && $found['stock']['quantity'] === 6 && $found['brand'] === "Brand $run" && count($found['images']) === 3, $found);

echo "stock locations\n";
list($s, $j, $raw) = call('POST', 'stock-locations/create', array('name' => "Warehouse $run", 'city' => 'Lyon', 'country_code' => 'FR'));
$loc = isset($j['location']['id']) ? (int) $j['location']['id'] : 0;
ok('create a stock location', $s === 200 && $loc > 0, $raw);
list($s, $j, $raw) = call('PUT', "stock-locations/$loc/update", array('id' => $loc, 'name' => "Warehouse $run B"));
ok('update it', $s === 200 && (int) $j['location']['id'] === $loc, $raw);

echo "orders\n";
list($s, $j, $raw) = call('POST', 'orders/create', array('uuid' => "o-$run", 'first_name' => 'Ada', 'last_name' => 'Smoke', 'currency' => 'EUR', 'mail_address' => "ada.$run@example.test",
	'billing_address' => 'Rue de Test 1', 'billing_postal_code' => '69001', 'billing_city' => 'Lyon', 'billing_country_code' => 'FR',
	'shipping_address' => 'Rue de Test 1', 'shipping_postal_code' => '69001', 'shipping_city' => 'Lyon', 'shipping_country_code' => 'FR',
	'order_number' => "SL-$run", 'total_amount_cents' => 2790, 'items' => array(array('sku' => "SMK-$run", 'name' => 'Smoke mug', 'quantity' => 2, 'price' => 1395))));
$oid = isset($j['id']) ? (int) $j['id'] : 0;
ok('create an order', $s === 200 && $oid > 0, $raw);
list($s, $j, $raw) = call('GET', 'orders');
$order = null;
foreach ((array) $j['orders'] as $o) {
	if ((int) $o['order_id'] === $oid) {
		$order = $o;
	}
}
ok('read it back, with its line and address', $order && $order['order_lines'][0]['sku'] === "SMK-$run" && $order['order_lines'][0]['quantity'] === 2 && $order['customer']['shippingAddress']['country'] === 'FR' && $order['currency'] === 'EUR', $order);

echo "removal\n";
list($s, $j, $raw) = call('POST', 'products/offline', array('gtins' => array('872' . str_pad($run, 10, '0', STR_PAD_LEFT), '0000000000000')));
ok('take a product offline by EAN', $s === 200 && count($j['results']['success']) === 1 && count($j['results']['not_found']) === 1, $raw);
list($s, $j, $raw) = call('POST', "products/$pid/archive", array('id' => $pid));
ok('archive a product', $s === 200, $raw);
list($s, $j, $raw) = call('POST', 'products/archive/validate', array('products' => array(array('sku' => "BLK-B-$run"), array('sku' => 'NOPE-' . $run))));
ok('validate an archive list', $s === 200 && count($j['archived']) === 1 && count($j['not_found']) === 1, $raw);
list($s, $j, $raw) = call('GET', 'products?page=1');
if (!empty($j['total_pages']) && $j['total_pages'] > 1) {
	list($s, $j, $raw) = call('GET', 'products?page=' . $j['total_pages']);
}
$ids = array();
foreach ((array) $j['products'] as $p) {
	$ids[] = (int) $p['id'];
}
ok('archived products leave the listing', !in_array($pid, $ids, true), $ids);

echo "sent again\n";
$bulkA = array('name' => "Bulk A $run", 'ean' => '872' . str_pad($run, 10, '0', STR_PAD_LEFT), 'sku' => "BLK-A-$run", 'site' => 'default', 'importSource' => 'storelinkr',
	'salesPrice' => 500, 'inStock' => 1, 'stockSupplier' => 0, 'hasStock' => true, 'condition' => 'refurbished', 'conditionDescription' => 'Tested and cleaned',
	'metafields' => array(array('name' => 'OE number', 'type' => 'single_text', 'value' => 'OE-' . $run)));
list($s, $j, $raw) = call('POST', 'products/create', $bulkA);
$back = isset($j['product_id']) ? (int) $j['product_id'] : 0;
list($s, $j) = call('GET', 'products?page=1');
if (!empty($j['total_pages']) && $j['total_pages'] > 1) {
	list($s, $j) = call('GET', 'products?page=' . $j['total_pages']);
}
$ids = array();
foreach ((array) $j['products'] as $p) {
	$ids[] = (int) $p['id'];
}
ok('a product taken offline comes back when StoreLinkr sends it again', $back > 0 && in_array($back, $ids, true), $raw);
$prod['images'] = array(999999998, $img1);
list($s, $j, $raw) = call('POST', "products/$pid/update", $prod);
ok('a missing main image keeps the current images, and is reported', $s === 200 && in_array(999999998, $j['invalid_media'], true) && !empty($j['warnings']), $raw);
$variable['products'][0]['images'] = array($img2);
list($s, $j, $raw) = call('POST', "products/$vid/update-variant", $variable);
ok('a variant gets its own image', $s === 200 && count($j['variant_map']) === 3, $raw);

echo "products/bulk (StoreLinkr's HikaShop integration)\n";
$sent = array('uuid' => "u-$run", 'ean' => '877' . str_pad($run, 10, '0', STR_PAD_LEFT), 'sku' => "DIY-$run", 'name' => "DIY blanket $run",
	'importSource' => 'STORELINKR', 'brand' => 'DIY', 'currency' => 'EUR', 'advisedPrice' => null, 'salesPrice' => 2495, 'promoSalesPrice' => null,
	'images' => array(), 'isUsed' => false, 'longDescription' => '', 'metadata' => '[]', 'inStock' => 0, 'stockSupplier' => 0, 'hasStock' => false,
	'updateStock' => true, 'id' => (string) $pid, 'site' => 'x', 'categoryId' => null,
	'category_path' => array('main' => "Home $run", 'sub' => 'Blankets', 'subSub' => null, 'subSubSub' => null, 'subSubSubSub' => null),
	'updatePrice' => true, 'is_variant' => false, 'variants' => array());
list($s, $j, $raw) = call('POST', 'products/bulk', array('settings' => array('publish_new_products' => true), 'products' => array("u-$run" => $sent)));
$bulk = isset($j['products']["u-$run"]['product_id']) ? (int) $j['products']["u-$run"]['product_id'] : 0;
ok('a product sent to products/bulk is created', $s === 200 && $bulk > 0, $raw);
ok('an id naming another product of the shop is not taken over', $bulk !== $pid && !empty($j['warnings']), $raw);
list($s, $j, $raw) = call('POST', 'products/bulk', array('products' => array("u-$run" => array_merge($sent, array('id' => (string) $bulk, 'salesPrice' => 2595)))));
ok('sent again with its own id, it is updated', isset($j['products']["u-$run"]['product_id']) && (int) $j['products']["u-$run"]['product_id'] === $bulk, $raw);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
