<?php
namespace HikashopStorelinkr;

/**
 * Header authentication, identical to the WooCommerce connector: the request
 * must carry x-api-key and x-api-secret matching the settings, compared in
 * constant time. Nothing is accepted while either setting is empty.
 */
class Auth {

	public static function authenticate(Request $request, Platform $platform) {
		$key            = (string) $request->header('X-Api-Key');
		$secret         = (string) $request->header('X-Api-Secret');
		$expectedKey    = (string) $platform->setting('api_key', '');
		$expectedSecret = (string) $platform->setting('api_secret', '');

		if ('' === $expectedKey || '' === $expectedSecret) {
			throw ApiError::unauthorized();
		}
		if ('' === $key || ! hash_equals($expectedKey, $key)) {
			throw ApiError::unauthorized();
		}
		if ('' === $secret || ! hash_equals($expectedSecret, $secret)) {
			throw ApiError::unauthorized();
		}
	}
}
