=== HikaShop StoreLinkr Connector ===
Contributors: hikarisoftware
Tags: hikashop, storelinkr, pim, marketplaces, sync
Requires at least: 6.5
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: {__VERSION__}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects HikaShop to StoreLinkr: your catalogue, prices, stock and images come from StoreLinkr, and your orders go back to it.

== Description ==

[StoreLinkr](https://storelinkr.com) manages product information and sales channels in one place. This plugin connects it to a HikaShop shop on WordPress, the same way StoreLinkr's WooCommerce connector connects a WooCommerce shop:

* StoreLinkr creates and updates your products, matched by id, SKU or EAN so nothing is duplicated.
* Categories, up to five levels deep, and brands.
* Prices and stock.
* Images, downloaded from StoreLinkr or sent as files, in the order StoreLinkr gives them.
* Products with variants (sizes, colours and so on) become HikaShop products with characteristics.
* StoreLinkr reads your orders, and creates the orders of your other sales channels in HikaShop.
* Stock locations become HikaShop warehouses.

With StoreLinkr, your catalogue is also published on the channels you choose, with the same prices and stock, and their orders come into HikaShop: bol, Kaufland, eBay, Allegro and more than 60 Mirakl marketplaces (MediaMarkt, Decathlon, Leroy Merlin...), product feeds for Google Merchant Center, Meta, TikTok and Bing, and Amazon, in beta on request from StoreLinkr. StoreLinkr is a paid service with a free trial: see its [integrations](https://storelinkr.com/en/integrations).

StoreLinkr only gets in with the API key and secret of the plugin's settings. The plugin needs [HikaShop](https://www.hikashop.com) for WordPress.

A Joomla version of the same connector is available too. The source code is on [GitHub](https://github.com/hikashop-nicolas/hikashop-storelinkr).

== Installation ==

1. Install and activate HikaShop for WordPress.
2. Install and activate this plugin.
3. Go to Settings > StoreLinkr. The plugin made an API key and an API secret of its own when it was activated.
4. In StoreLinkr, add a HikaShop integration with your site's address as its domain, and the same API key and API secret.
5. Use StoreLinkr's "Test connection".

StoreLinkr's [HikaShop setup guide](https://help.storelinkr.com/docs/integrations/hikashop/setup) shows each step on its side.

== Frequently Asked Questions ==

= Which address does StoreLinkr call? =

https://your-site/wp-json/storelinkr/v1/ (also https://your-site/storelinkr/v1/ with pretty permalinks). The settings page shows it.

= Are the products removed when I uninstall the plugin? =

No. Uninstalling removes the plugin's settings; the products, categories and orders StoreLinkr created stay in HikaShop.

== Screenshots ==

1. The settings page: the address StoreLinkr calls, the API key and secret to enter in StoreLinkr, and the import settings.

== External services ==

This plugin is the shop's side of a connection to StoreLinkr (https://storelinkr.com), a product information and sales channel platform run by Sitepack. It does not call StoreLinkr by itself: StoreLinkr calls the shop.

* What StoreLinkr sends to the shop: products (names, descriptions, SKUs, EANs, prices, stock, categories, brands), images, stock locations, and orders from other sales channels with their customers' names and addresses.
* What the shop sends to StoreLinkr, when StoreLinkr asks: its products and categories, and its orders with their customers' names, email addresses and addresses.
* When it happens: on each sync StoreLinkr runs, as set up in your StoreLinkr account.
* Images: when StoreLinkr sends the address of an image, the plugin downloads it from that address, usually StoreLinkr's image server.

StoreLinkr's terms of service: https://storelinkr.com/en/legal/terms-conditions
StoreLinkr's privacy policy: https://storelinkr.com/en/legal/privacy-policy

== Changelog ==

= 1.1.3 =

* Ready for PHP 8.6 and tested with WordPress 7.2: on PHP 8.6, the plugin no longer adds a deprecation notice to the site's PHP log on every page.

= 1.1.2 =

* Ready for wordpress.org and the Joomla Extensions Directory; the connector works as in 1.1.1.

= 1.1.1 =

* The connector answers only the calls of StoreLinkr's WooCommerce connector, which StoreLinkr's HikaShop integration now sends: the products/bulk and products/push-image addresses it used before are gone.
* The description links StoreLinkr's HikaShop setup guide.

= 1.1.0 =

* First version.
