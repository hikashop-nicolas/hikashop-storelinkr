<?php
/**
 * Settings > StoreLinkr.
 *
 * @package HikashopStorelinkr
 */

namespace HikashopStorelinkr\WordPress;

/**
 * The connector's settings, through the Settings API: the key and secret
 * StoreLinkr sends, and how what it sends is imported.
 */
class Settings_Page {

	const PAGE = 'hikashop-storelinkr';

	/** Hook the page into the admin. */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_without_hikashop' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HIKASHOP_STORELINKR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Under Settings. */
	public static function add_page() {
		add_options_page(
			__( 'HikaShop StoreLinkr Connector', 'hikashop-storelinkr' ),
			__( 'StoreLinkr', 'hikashop-storelinkr' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A Settings link on the Plugins page.
	 *
	 * @param array $links The plugin's links.
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'hikashop-storelinkr' ) . '</a>' );
		return $links;
	}

	/** The fields. */
	public static function register() {
		register_setting(
			self::PAGE,
			Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
		add_settings_section( 'connection', __( 'Connection', 'hikashop-storelinkr' ), array( __CLASS__, 'connection_help' ), self::PAGE );
		add_settings_section( 'import', __( 'Import', 'hikashop-storelinkr' ), '__return_false', self::PAGE );

		$fields = array(
			'api_key'               => array( 'connection', __( 'API key', 'hikashop-storelinkr' ), 'text', __( 'StoreLinkr sends it with every call. Enter the same value in StoreLinkr.', 'hikashop-storelinkr' ) ),
			'api_secret'            => array( 'connection', __( 'API secret', 'hikashop-storelinkr' ), 'text', __( 'StoreLinkr sends it with every call. Enter the same value in StoreLinkr.', 'hikashop-storelinkr' ) ),
			'publish_new_products'  => array( 'import', __( 'Publish new products', 'hikashop-storelinkr' ), 'checkbox', __( 'Publish the products StoreLinkr creates at once. Untick it to review them before they show in the shop.', 'hikashop-storelinkr' ) ),
			'imported_order_status' => array( 'import', __( 'Status of orders from StoreLinkr', 'hikashop-storelinkr' ), 'text', __( 'The HikaShop order status given to the orders StoreLinkr creates, for example created or confirmed.', 'hikashop-storelinkr' ) ),
			'default_currency_id'   => array( 'import', __( 'Currency id', 'hikashop-storelinkr' ), 'number', __( 'The id of the HikaShop currency of the prices StoreLinkr sends. Leave it empty to use the shop\'s main currency.', 'hikashop-storelinkr' ) ),
			'import_source'         => array( 'import', __( 'Import source', 'hikashop-storelinkr' ), 'text', __( 'The label stored on the records StoreLinkr creates. Leave it at storelinkr unless StoreLinkr asks otherwise.', 'hikashop-storelinkr' ) ),
			'image_host_allowlist'  => array( 'import', __( 'Image hosts', 'hikashop-storelinkr' ), 'textarea', __( 'Optional: one host name per line. When set, images are only downloaded from these hosts. Images are never downloaded from private or local addresses.', 'hikashop-storelinkr' ) ),
		);
		foreach ( $fields as $name => $field ) {
			add_settings_field(
				$name,
				$field[1],
				array( __CLASS__, 'field' ),
				self::PAGE,
				$field[0],
				array(
					'name'      => $name,
					'type'      => $field[2],
					'help'      => $field[3],
					'label_for' => 'hikashop-storelinkr-' . $name,
				)
			);
		}
	}

	/** Where StoreLinkr calls. */
	public static function connection_help() {
		echo '<p>' . esc_html__( 'In StoreLinkr, add a HikaShop integration with this site\'s address as its domain, then enter the same API key and API secret as below.', 'hikashop-storelinkr' ) . '</p>';
		echo '<p>' . esc_html__( 'StoreLinkr calls:', 'hikashop-storelinkr' ) . ' <code>' . esc_html( rest_url( Plugin::NAMESPACE . '/' ) ) . '</code></p>';
	}

	/**
	 * One field.
	 *
	 * @param array $args name, type, help, label_for.
	 */
	public static function field( $args ) {
		$settings = get_option( Plugin::OPTION, array() );
		$value    = isset( $settings[ $args['name'] ] ) ? (string) $settings[ $args['name'] ] : '';
		$name     = Plugin::OPTION . '[' . $args['name'] . ']';
		$id       = $args['label_for'];
		if ( 'textarea' === $args['type'] ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'checkbox' === $args['type'] ) {
			echo '<input type="checkbox" value="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" ' . checked( '1', $value, false ) . ' />';
		} else {
			$class = 'number' === $args['type'] ? 'small-text' : 'regular-text code';
			echo '<input type="' . esc_attr( $args['type'] ) . '" class="' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}

	/**
	 * Clean what the form sent.
	 *
	 * @param mixed $input The submitted values.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$hosts = isset( $input['image_host_allowlist'] ) ? explode( "\n", (string) $input['image_host_allowlist'] ) : array();
		$hosts = array_filter( array_map( 'sanitize_text_field', $hosts ) );
		return array(
			'api_key'               => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'api_secret'            => isset( $input['api_secret'] ) ? sanitize_text_field( $input['api_secret'] ) : '',
			'publish_new_products'  => empty( $input['publish_new_products'] ) ? '0' : '1',
			'imported_order_status' => isset( $input['imported_order_status'] ) ? sanitize_key( $input['imported_order_status'] ) : 'created',
			'default_currency_id'   => isset( $input['default_currency_id'] ) && '' !== $input['default_currency_id'] ? (string) absint( $input['default_currency_id'] ) : '',
			'import_source'         => isset( $input['import_source'] ) ? sanitize_text_field( $input['import_source'] ) : 'storelinkr',
			'image_host_allowlist'  => implode( "\n", $hosts ),
		);
	}

	/** The page. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1><form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
	}

	/** HikaShop is required. */
	public static function notice_without_hikashop() {
		if ( function_exists( 'hikashop_get' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'HikaShop StoreLinkr Connector needs HikaShop for WordPress: install and activate it so StoreLinkr can sync your shop.', 'hikashop-storelinkr' ) . '</p></div>';
	}
}
