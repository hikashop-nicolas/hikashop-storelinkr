<?php
/**
 * Plugin Name:       HikaShop StoreLinkr Connector
 * Plugin URI:        https://github.com/hikashop-nicolas/hikashop-storelinkr
 * Description:       Connects HikaShop to StoreLinkr: StoreLinkr sends your catalogue, prices, stock and images to the shop and reads its orders back.
 * Version:           {__VERSION__}
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Hikari Software
 * Author URI:        https://www.hikashop.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hikashop-storelinkr
 *
 * @package HikashopStorelinkr
 */

define( 'HIKASHOP_STORELINKR_VERSION', '{__VERSION__}' );
define( 'HIKASHOP_STORELINKR_FILE', __FILE__ );

require_once __DIR__ . '/core/autoload.php';
require_once __DIR__ . '/includes/class-wordpress-platform.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-settings-page.php';

HikashopStorelinkr\WordPress\Plugin::boot();
HikashopStorelinkr\WordPress\Settings_Page::boot();

register_activation_hook( __FILE__, array( 'HikashopStorelinkr\WordPress\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HikashopStorelinkr\WordPress\Plugin', 'deactivate' ) );
