<?php
/**
 * The REST route StoreLinkr calls, and the plugin's lifecycle.
 *
 * @package HikashopStorelinkr
 */

namespace HikashopStorelinkr\WordPress;

use HikashopStorelinkr\Request;
use HikashopStorelinkr\Router;

/**
 * StoreLinkr calls https://<site>/wp-json/storelinkr/v1/... with the paths of
 * its WooCommerce connector; /storelinkr/v1/... is the same route through a
 * rewrite rule, as the Joomla plugin answers it.
 */
class Plugin {

	const OPTION    = 'hikashop_storelinkr_settings';
	const NAMESPACE = 'storelinkr/v1';

	/** Hook the plugin into WordPress. */
	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
	}

	/** The route under storelinkr/v1; the core sorts the paths out. */
	public static function register_route() {
		register_rest_route(
			self::NAMESPACE,
			'/(?P<slr>.+)',
			array(
				'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	/** /storelinkr/v1/... reaches the same route. */
	public static function add_rewrite_rule() {
		add_rewrite_rule( '^storelinkr/v1/(.+)$', 'index.php?rest_route=/' . self::NAMESPACE . '/$matches[1]', 'top' );
	}

	/**
	 * Only StoreLinkr, with the key and secret of the settings.
	 *
	 * @param \WP_REST_Request $request The call.
	 * @return true|\WP_Error
	 */
	public static function authorize( $request ) {
		$settings = get_option( self::OPTION, array() );
		$key      = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		$secret   = isset( $settings['api_secret'] ) ? (string) $settings['api_secret'] : '';
		$sent_key = (string) $request->get_header( 'x_api_key' );
		$sent_sec = (string) $request->get_header( 'x_api_secret' );
		if ( '' !== $key && '' !== $secret && hash_equals( $key, $sent_key ) && hash_equals( $secret, $sent_sec ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
	}

	/**
	 * Hand the call to the core.
	 *
	 * @param \WP_REST_Request $request The call.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function serve( $request ) {
		if ( ! function_exists( 'hikashop_get' ) || ! class_exists( 'WP_HikaFactory' ) ) {
			return new \WP_Error( 'hikashop_missing', 'HikaShop is not active on this site.', array( 'status' => 503 ) );
		}
		$body   = $request->get_json_params();
		$params = array_merge( (array) $request->get_query_params(), is_array( $body ) ? $body : (array) $request->get_body_params() );
		$call   = new Request( $request->get_method(), (string) $request['slr'], $request->get_headers(), $params );

		// What HikaShop prints until WordPress answers (a mail in debug mode) would break the JSON.
		$level = ob_get_level();
		ob_start();
		add_filter(
			'rest_pre_serve_request',
			function ( $served ) use ( $level ) {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
				return $served;
			},
			0
		);
		list( $status, $answer ) = Router::handle( new WordPress_Platform(), $call );
		return new \WP_REST_Response( $answer, $status );
	}

	/** First activation: a key and a secret of its own, and the rewrite rule. */
	public static function activate() {
		$settings = get_option( self::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings += array(
			'api_key'               => wp_generate_password( 32, false ),
			'api_secret'            => wp_generate_password( 32, false ),
			'import_source'         => 'storelinkr',
			'default_currency_id'   => '',
			'imported_order_status' => 'created',
			'publish_new_products'  => '1',
			'image_host_allowlist'  => '',
		);
		update_option( self::OPTION, $settings, false );
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/** The rewrite rule goes with the plugin. */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
