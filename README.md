# HikaShop StoreLinkr Connector

Connects a [HikaShop](https://www.hikashop.com) shop, on Joomla or on WordPress, to
[StoreLinkr](https://storelinkr.com), the product information and sales channel platform.
StoreLinkr sends the catalogue (products, variants, categories, brands, prices, stock,
images, stock locations) to the shop, reads its orders, and creates the orders of its other
sales channels in it.

The connector answers StoreLinkr with the same routes, payloads and authentication as
StoreLinkr's own [WooCommerce connector](https://github.com/sitepack-io/storelinkr-wordpress),
so StoreLinkr's HikaShop integration works with it as it is.

Free, GPL-2.0-or-later.

## Install

Download the package of your platform from the [latest release](https://github.com/hikashop-nicolas/hikashop-storelinkr/releases/latest):

- **Joomla 4, 5 or 6**: `plg_system_storelinkr.zip`, installed from System > Install > Extensions.
  Enable "System - HikaShop StoreLinkr Connector" and enter an API key and an API secret
  of your choice in its settings. Updates come through Joomla's own update system.
- **WordPress 6.5 or later**: `hikashop-storelinkr.zip`, installed from Plugins > Add New >
  Upload Plugin. It makes an API key and an API secret of its own when activated:
  Settings > StoreLinkr shows them.

Both need HikaShop, and PHP 7.4 or later.

## Connect StoreLinkr

In StoreLinkr, add a **HikaShop** integration with:

- **Domain**: your site's address, for example `https://www.example.com`
- **API key** and **API secret**: the ones of the connector's settings

then use StoreLinkr's "Test connection". StoreLinkr calls `https://<site>/storelinkr/v1/...`
(on WordPress also `https://<site>/wp-json/storelinkr/v1/...`). On Joomla without URL
rewriting, the address is `https://<site>/index.php/storelinkr/v1/...`.

## What it does

| StoreLinkr | HikaShop |
|---|---|
| Product, matched by id, SKU (product code) or EAN | Product; the EAN, StoreLinkr's id, the PIM condition and the metafields go in columns of their own on the product table |
| Variable product and its variations | Main product with characteristics, one variant per variation |
| Category tree (5 levels) and brands | Product categories under the product root, and manufacturers |
| Prices (in cents) and stock | Product price in the configured currency, product quantity |
| Images (from their address or as base64) | Product images, in StoreLinkr's order |
| Stock locations | Warehouses |
| Orders from other channels | Orders, with the status of the settings |
| Taking a product offline, archiving | Unpublishing; a product taken offline that StoreLinkr sends again is published again |

Images are only downloaded over http(s) from public addresses, optionally from a list of
allowed hosts.

## Repository

- `core/`: everything StoreLinkr-specific, platform-free (`HikashopStorelinkr` namespace).
  It reaches the CMS through the `Platform` interface only.
- `joomla/`: the Joomla system plugin and its `Platform`.
- `wordpress/`: the WordPress plugin, its REST route, settings page and `Platform`.
- `build/`: `build.php` makes both packages in `dist/` (adding each platform's licence
  notice and direct-access guard to every PHP file); `check-structure.php` enforces the rules
  that keep the core free of either platform; `jed-check.php` runs JEDChecker's rules;
  `plugin-check.xml` is Plugin Check's PHPCS ruleset.
- `tests/smoke.php`: every route, over HTTP, against a test shop.

```sh
php build/check-structure.php      # the repository rules
php build/build.php                # dist/plg_system_storelinkr.zip, dist/hikashop-storelinkr.zip
composer install && composer run-script wp-check   # Plugin Check's code rules (WordPress)
php build/jed-check.php /path/to/joomla-with-jedchecker   # JEDChecker's rules (Joomla)
php tests/smoke.php https://test-shop/storelinkr/v1 <key> <secret>   # writes to that shop
```

## Release

1. Set the new version in `VERSION`, and add its changelog to `wordpress/readme.txt`.
2. Run the checks above, and the smoke test on a Joomla and a WordPress test shop.
3. Commit, then tag `v<version>` and push the tag: the release workflow builds both packages
   and publishes them with the Joomla update file.
4. WordPress: copy the content of `hikashop-storelinkr.zip` to the plugin's wordpress.org
   SVN `trunk/`, and tag it there.
