=== USD / Toman Pricing for WooCommerce ===
Contributors: hamidnoshady
Tags: woocommerce, currency, toman, usd, pricing
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely manage Iranian Toman source prices and derived USD prices in WooCommerce.

== Description ==

This plugin is being developed around a safe, manual USD/Toman workflow. Source Toman prices remain canonical; derived WooCommerce prices are recalculated from that source rather than from previously rounded values.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate WooCommerce first, then activate this plugin.

== Development ==

The release ZIP is produced by `bin/build-release.sh`. It contains only runtime plugin files and is intentionally separate from the repository checkout.

== Changelog ==

= 0.1.0 =
* Initial release foundation.
