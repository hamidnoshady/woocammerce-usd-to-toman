=== USD to Toman Price Sync for WooCommerce ===
Contributors: hamidnoshady
Tags: woocommerce, toman, iran, currency, price sync
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep product prices in Iranian Toman and derive the WooCommerce USD price from a manual USD/Toman rate, with a dry run and safe background updates.

== Description ==

Many Iranian stores think in Toman but sell in USD. Doing that conversion by hand is slow, and doing it badly is expensive: when you recalculate a price from the previously rounded dollar value, rounding errors pile up on every rate change and your margins quietly shrink.

This plugin takes the opposite approach. The Toman price is the source of truth and it is stored separately, so it is never lost. The WooCommerce price is a derived value:

`USD price = ceil( Toman price / USD_Toman rate )`

A 5,000,000 Toman product with a rate of 270,000 Toman per dollar becomes $19 (18.52 rounded up). Every update recalculates from the stored Toman value, never from the old dollar value, so a price can never drift.

**The workflow**

1. Enter the current USD/Toman rate on **WooCommerce → USD / Toman Pricing** and save it. Saving the rate does **not** touch a single product price.
2. Run **Preview Changes** (a dry run). You get the exact numbers: products scanned, products that will change, products already correct, skipped products, invalid prices, conflicts and how many variations are affected. Nothing is written.
3. Confirm the update. Prices are recalculated in the background through the WooCommerce Action Scheduler queue, in small batches, and you can watch the progress, pause it or cancel it. You can close the browser: the queue keeps working.

**Why it is safe**

* **No surprises from a typo.** A rate move of about 15% (configurable) is blocked until you type `UPDATE` to confirm. It shows the old rate, the new rate, the percentage change and how many products are affected. Zero, negative and non-numeric rates are rejected.
* **No half-updated catalog.** Only one synchronization can run at a time. Every job stores its own cursor, so an interrupted job resumes where it stopped instead of starting over. Each product is written once, and retries cannot apply a change twice.
* **No lost edits.** If a Toman price is edited while the job is running, that product is skipped and recorded as a conflict. You can review the conflicts at the end of the job and recalculate them.
* **No broken prices.** Empty, zero, negative, malformed prices and a sale price above the regular price are reported instead of being written, and they never crash the job.
* **No unnecessary writes.** If the rounded dollar price does not change, the product is not saved at all. A rate change that does not move any price writes nothing and reports "No synchronization required".

**Manual and automatic at the same time**

Every product has a pricing mode:

* **Toman managed** – the Toman price is the source, the dollar price is derived.
* **Native USD** – the product keeps its own dollar price and is never touched.
* **Excluded** – the product is ignored by every job.

New products are managed by default (configurable). The product editor and the products list have a panel that shows the pricing mode, the stored Toman regular and sale price, the derived dollar price, the rate that was used, the last sync time, a "Recalculate this product" button and bulk actions to switch the mode.

**Rounding**

The default is always rounding **up** to the next whole dollar, which protects your margin. Advanced settings also offer rounding to the nearest value and rounding down, with $1, $5 or $10 price steps.

**Two ways to charge**

* **Mode B – Toman transactions (the default).** The store really transacts in Toman: the price fields hold the canonical Toman price, so the cart, the checkout, the order currency, WooCommerce Blocks, the Store API and the amount sent to the gateway are all in Toman, and the derived dollar price is kept as a reference. This is usually the safest option with Iranian payment gateways, and switching to it is what a fresh install does.
* **Mode A – USD transactions with a Toman display.** WooCommerce stores and charges dollars, orders and gateways work in USD, and the storefront shows the stored Toman price: the shop and archive pages, single product pages, variable price ranges, related products, upsells and cross-sells, the mini cart, the classic cart and checkout, and the `price_html` field of the Store API (so WooCommerce Blocks product grids and headless front ends show it too). The charged amount stays in USD, and the Toman number shown is the stored source value, never a value rebuilt from the rounded dollar price.

Mode A shows Toman next to USD prices; it never fakes a Toman amount for a dollar total. Switching the mode always shows the change as pending and requires a full update, so an existing store keeps the mode it was already using until you change it yourself.

**Scopes**

You can update everything, selected products, a product category, a product type, only products whose price looks outdated, or only products changed since the last sync.

**Audit trail**

Every rate change is stored permanently with the time and the administrator who made it, together with the results of the update it triggered. Each job keeps a per product log with the old price, the new price and the Toman source, exportable as CSV. Logs are kept for 30 days by default (configurable). "Rollback last rate update" restores the previous rate as a normal queued job, so it is just as safe and resumable as any other update.

**Server friendly**

Jobs run in small batches (10 products per run by default, 5 for low resource hosting, up to 25) with a fresh queued action per batch, a time budget, a lock and a heartbeat. Nothing depends on the browser being open, and the Diagnostics section reports the queue health, the failed actions, the current lock and the configured batch size.

**Privacy**

This plugin does not send any data anywhere. There is no external exchange rate API in this version: you enter the rate yourself. The plugin runs entirely inside your own WordPress installation.

== Installation ==

1. Install WooCommerce and make sure it is active.
2. Upload the plugin zip through **Plugins → Add New → Upload Plugin**, or copy the plugin folder to `wp-content/plugins/`.
3. Activate **USD to Toman Price Sync for WooCommerce**.
4. Open **WooCommerce → USD / Toman Pricing** and enter your current USD/Toman rate.
5. Run **Preview Changes**, review the numbers, then confirm the update.

Before you start, it is worth knowing how your Toman prices are stored today. If your products already contain Toman prices in the WooCommerce price fields (for example because you typed them by hand or imported them), the "Import current price as source" action stores those values as the Toman source before the first conversion.

== Frequently Asked Questions ==

= Does saving the rate change my prices? =

No. Saving the rate only records it. Prices change after you run the update, and only if you confirmed the preview first.

= What happens to my existing prices when I activate the plugin? =

Nothing is changed automatically. Products become managed either when you switch them on (individually or with a bulk action) or when the "manage new products automatically" setting applies. Products that are not managed keep their price exactly as it is.

= Can I keep some products priced in dollars? =

Yes. Set those products to **Native USD** and no job will ever write to them.

= Can I undo a rate update? =

Yes. **Rollback last rate update** restores the previous rate and runs a queued synchronization that recalculates every price from the stored Toman source.

= What if the queue stalls? =

Open the Diagnostics tab. It shows whether Action Scheduler is available, how many actions are pending or failed, whether a job holds the lock and the last error. If your host disabled WP-Cron, the plugin can fall back to a token protected loopback request.

= Why does the cart total show dollars while the product shows Toman? =

Because the store still transacts in USD (mode A). Product prices display the stored Toman value, but cart, checkout and order totals are the real USD amounts that the gateway will charge, so they are never converted on screen. If the totals themselves should be in Toman, choose **Toman transactions** in the settings and run a full update.

= Are prices written with SQL? =

No. Every price is written with the WooCommerce CRUD API (`wc_get_product()`, `set_regular_price()`, `set_sale_price()`, `save()`), so caches, lookups and third party integrations stay consistent.

= Which capability is required? =

`manage_woocommerce` by default, so shop managers can use the screen. The requirement appears in the settings and can be filtered for stricter setups.

= Is the plugin translatable? =

Yes. Every string uses the `usd-to-toman-price-sync-for-woocommerce` text domain, a `.pot` template ships with the plugin, and a complete **Persian (`fa_IR`)** translation is bundled, so an Iranian store gets a Persian admin and storefront without installing a language pack.

== Screenshots ==

1. The rate screen: the manual rate, the typo warning, the rate history and the background queue diagnostics.
2. Preview changes (dry run): what would change, what is unchanged, what is skipped, which variations are affected — with no write performed.
3. Jobs & history: the running job with its progress and the per product log with the old price, the new price, the status and the Toman source.
4. The same screen in Persian, with the bundled `fa_IR` translation.
5. The product panel: the pricing mode, the canonical Toman regular and sale price, the derived dollar price, the rate used and the bulk actions.

== Changelog ==

= 1.1.0 =
Production audit release: every finding of the production audit is fixed and covered by the integration suite, which now runs the worker phases in separate PHP processes and the admin REST API over real HTTP.
* Fixed: the admin screen called the REST API without the plugin namespace, so every action failed with the REST `rest_no_route` error. Requests now address `usdtf/v1`, a failing request logs its exact method and path, and the diagnostics name missing routes after a broken upgrade.
* Fixed: the synchronization lease was owned by a per-request token, so the background worker — a different PHP process — could not continue the job it was running. The lease now belongs to the job and survives request boundaries.
* Fixed: the worker rewrote the canonical Toman source price from its snapshot, so an edit saved during a synchronization could be overwritten. The source price is read only during a sync.
* Fixed: variable product failures disappeared between worker slices; the per-item counters now persist across slices and an early failure finishes the item as failed.
* Fixed: the retry delay now applies to pending items as well, so a failed item is retried after its backoff and not immediately.
* Fixed: when queueing a worker step fails, the job is paused with a clear message, the lock is released and the REST call returns an actionable error — instead of looking active forever.
* Fixed: the per-variation mode select of the product panel is saved; the include-variations scope flag is honored; managed variations under unmanaged parents are discovered; variable products with more than 2000 variations are no longer cut off.
* Fixed: the Toman price range follows scheduled sale dates, the Toman reference price follows the store's tax display settings, the loopback verifies TLS certificates and no longer duplicates the cron trigger, and uninstall removes pending worker actions.
* New: dry run first is enforced by default — an update only starts after a completed dry run with the same exchange rate, transaction currency, rounding settings and scope. It is a setting.
* New: the required capability only accepts the documented allowlist, and saving the settings screen requires the plugin capability instead of manage_options.
* Every admin script string is translatable, and product names from the product search are rendered as plain text.
* The integration suite grew to 48 scenario groups, including the REST API over real HTTP and worker steps in separate PHP processes.

= 1.0.3 =
* New: a complete Persian (`fa_IR`) translation ships with the plugin, as a `.po` source and a compiled `.mo` catalogue that loads on `init`. A language pack installed on the site still takes precedence.
* New: `bin/make-mo.php` compiles and verifies the catalogues, and CI fails when a `.mo` file is missing or does not match its `.po` source. The release archive is checked for the shipped catalogues too.
* Changed: a fresh install transacts in **Toman** (mode B) by default, which is the safer choice for Iranian payment gateways. Existing stores keep the currency mode they already had; the default only applies where no mode was ever stored.
* The integration suite grew to 37 scenario groups, including the fresh install default, the upgrade behaviour and the bundled catalogue.

= 1.0.2 =
* The diagnostics count the background queue exactly. Listing actions is capped, so a busy store saw a queue depth of 500 whatever the real number was.
* Integration coverage for the worker queue, the loopback diagnostics, the admin screens and the product panel.

= 1.0.1 =
* Fixed: the REST API routes the admin screen uses (rate, preview, update, jobs, health, product search) were never registered, so every action on the admin screen failed.
* Fixed: the rate change percentage was always computed as -100%, which made the typo guard ask for confirmation on any rate change.
* Fixed: a pending Action Scheduler action without a date could break the diagnostics section.
* Fixed: API errors now carry proper HTTP status codes (409 for a duplicate update, 400 for validation failures).

= 1.0.0 =
* First release.
* Toman source prices with derived USD prices: `ceil( Toman / rate )`.
* Dry run preview before every write.
* Queued background synchronization through Action Scheduler with cursor based resume, locking, retries and conflict detection.
* Rate typo protection with a configurable threshold and a typed confirmation.
* Per product pricing modes, bulk actions and a product panel with per product recalculation.
* Toman display for the storefront, the cart, the checkout, WooCommerce Blocks and the Store API.
* USD or Toman transaction currency, selected explicitly.
* Permanent rate history, per product job logs with retention, CSV export and rate rollback.
* Selective scopes: everything, selected products, category, product type, outdated only, changed since.
* Diagnostics for the queue, the lock and the configured batch size.

== Upgrade Notice ==

= 1.1.0 =
Fixes the admin REST errors (rest_no_route), makes background updates survive request boundaries, protects the canonical Toman price from being overwritten during a sync, and enforces a dry run before every update. Update as usual; no action is needed after upgrading.

= 1.0.3 =
Bundles a complete Persian translation and makes Toman transactions the default for new installs. Existing stores keep their currency mode.

= 1.0.2 =
Reports the real size of the background queue in the diagnostics.

= 1.0.1 =
Fixes the admin screen: the REST API it uses was not registered in 1.0.0. Also fixes the rate change percentage used by the typo guard and the diagnostics section.

= 1.0.0 =
First release.
