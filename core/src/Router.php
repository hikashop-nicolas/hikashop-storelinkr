<?php
namespace HikashopStorelinkr;

/**
 * Maps (HTTP method, path) to a controller action, replicating the routes
 * StoreLinkr's WooCommerce connector registers under storelinkr/v1. A numeric
 * {id} segment is captured into the request as 'id'. Literal paths come before
 * their {id} counterparts. handle() never exits: it returns the HTTP status and
 * the JSON body, and the platform sends them.
 */
class Router {

	private static function routes() {
		return array(
			array( 'GET', '#^test-connection$#', 'Test', 'connection' ),
			array( 'GET', '#^categories$#', 'Categories', 'index' ),
			array( 'POST', '#^categories/create$#', 'Categories', 'create' ),
			array( 'POST', '#^categories/update/(\d+)$#', 'Categories', 'update' ),
			array( 'GET', '#^orders$#', 'Orders', 'index' ),
			array( 'POST', '#^orders/create$#', 'Orders', 'create' ),
			array( 'GET', '#^products$#', 'Products', 'index' ),
			array( 'POST', '#^products/create$#', 'Products', 'create' ),
			array( 'POST', '#^products/create-variant$#', 'Variants', 'create' ),
			array( 'POST', '#^products/link-variants$#', 'Variants', 'link' ),
			array( 'POST', '#^products/upsert-bulk$#', 'Products', 'upsertBulk' ),
			// What StoreLinkr's HikaShop integration calls for a product export: upsert-bulk's input and answer.
			array( 'POST', '#^products/bulk$#', 'Products', 'upsertBulk' ),
			array( 'POST', '#^products/offline$#', 'Products', 'offline' ),
			array( 'POST', '#^products/archive/validate$#', 'Products', 'archiveValidate' ),
			array( 'POST', '#^products/(\d+)/update-variant$#', 'Variants', 'update' ),
			array( 'POST', '#^products/(\d+)/update$#', 'Products', 'update' ),
			array( 'POST', '#^products/(\d+)/image$#', 'Products', 'image' ),
			array( 'POST', '#^products/(\d+)/archive$#', 'Products', 'archive' ),
			array( 'POST', '#^images/create$#', 'Images', 'create' ),
			// StoreLinkr's HikaShop integration uploads an image there (form fields cdn_url and alt), then names it in products/{id}/update.
			array( 'POST', '#^products/push-image$#', 'Images', 'create' ),
			array( 'POST', '#^stock-locations/create$#', 'StockLocations', 'create' ),
			array( 'PUT', '#^stock-locations/(\d+)/update$#', 'StockLocations', 'update' ),
		);
	}

	/**
	 * Authenticate and dispatch one request.
	 *
	 * @return array{0:int,1:array} The HTTP status and the JSON body.
	 */
	public static function handle(Platform $platform, Request $request) {
		try {
			Auth::authenticate($request, $platform);
			return array( 200, self::dispatch($platform, $request) );
		} catch (ApiError $e) {
			return array( $e->status(), $e->body() );
		} catch (\Throwable $e) {
			$error = ApiError::because($e->getMessage());
			return array( $error->status(), $error->body() );
		}
	}

	private static function dispatch(Platform $platform, Request $request) {
		foreach (self::routes() as $route) {
			list($method, $pattern, $name, $action) = $route;
			if ($request->method !== $method || ! preg_match($pattern, $request->path, $m)) {
				continue;
			}
			if (isset($m[1])) {
				$request->setParam('id', (int) $m[1]);
			}
			$class   = __NAMESPACE__ . '\\Controller\\' . $name;
			$handler = new $class($request, $platform);
			return $handler->$action();
		}
		ApiError::raise('No route for ' . $request->method . ' ' . $request->path, 404);
	}
}
