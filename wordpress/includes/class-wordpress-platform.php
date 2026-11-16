<?php
/**
 * The core's view of WordPress.
 *
 * @package HikashopStorelinkr
 */

namespace HikashopStorelinkr\WordPress;

use HikashopStorelinkr\Platform;

/**
 * HikaShop for WordPress brings its own database object and plugin events;
 * the rest goes through WordPress's own functions.
 */
class WordPress_Platform implements Platform {

	/** HikaShop's database object. */
	public function db() {
		return \WP_HikaFactory::getDbo();
	}

	/**
	 * A setting of the connector.
	 *
	 * @param string $key      Its name.
	 * @param mixed  $fallback When it is not set.
	 */
	public function setting( $key, $fallback = null ) {
		$settings = get_option( Plugin::OPTION, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
	}

	/** The plugin's version. */
	public function version() {
		return HIKASHOP_STORELINKR_VERSION;
	}

	/**
	 * Fire a HikaShop event.
	 *
	 * @param string $event Its name.
	 * @param array  $args  Its arguments.
	 */
	public function triggerEvent( $event, array $args ) {
		\WP_HikaPluginHelper::importPlugin( 'hikashop' );
		\WP_HikaFactory::getApplication()->triggerEvent( $event, $args );
	}

	/**
	 * GET a remote URL; WordPress refuses private and local addresses itself.
	 *
	 * @param string $url     The address.
	 * @param array  $headers Request headers.
	 * @param int    $timeout Seconds.
	 */
	public function httpGet( $url, array $headers, $timeout ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 0, '' );
		}
		return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Whether a path can be written to.
	 *
	 * @param string $path The path.
	 */
	public function isWritable( $path ) {
		return wp_is_writable( $path );
	}

	/**
	 * Write a file through the WordPress filesystem.
	 *
	 * @param string $path  The file.
	 * @param string $bytes Its content.
	 */
	public function writeFile( $path, $bytes ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return ! empty( $wp_filesystem ) && $wp_filesystem->put_contents( $path, $bytes, FS_CHMOD_FILE );
	}

	/**
	 * Split a URL.
	 *
	 * @param string $url The address.
	 */
	public function parseUrl( $url ) {
		return wp_parse_url( $url );
	}

	/**
	 * Letters and digits.
	 *
	 * @param int $length How many.
	 */
	public function randomString( $length ) {
		return wp_generate_password( (int) $length, false );
	}
}
