<?php
/**
 * Removes the connector's settings. The products, categories and orders
 * StoreLinkr created stay in HikaShop.
 *
 * @package HikashopStorelinkr
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'hikashop_storelinkr_settings' );
