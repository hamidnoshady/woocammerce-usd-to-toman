=== USD to Toman Price Sync for WooCommerce ===
Contributors: hamidnoshady
Tags: woocommerce, toman, rial, iran, currency
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.2.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Peg your Toman or Rial prices to the dollar: enter the USD rate and WooCommerce prices follow it, with a dry run, rollback and safe queued updates.

== Description ==

If your store is in Toman or Rial but your costs follow the dollar, every change of the exchange rate means editing prices by hand. This plugin does it for you, and it does it safely.

**How it works**

1. The plugin reads the price already in your WooCommerce price fields. Your store currency must be Iranian Toman (IRT) or Iranian Rial (IRR); the plugin takes the unit from the WooCommerce currency setting and does not change it.
2. You enter the current rate, for example 270,000 Toman per dollar. The plugin generates a **USD price** for every product from its current price: 5,000,000 Toman at 270,000 is $18.52.
3. From then on the **USD price is the anchor**. When you enter a new rate, the WooCommerce price becomes `USD price × new rate`, rounded the way you chose. At 300,000 the same product moves to 5,556,000 Toman.

The first run only records the USD prices. It never changes a WooCommerce price: prices change when the rate changes.

**If you edit a price yourself**

If you type a new price into WooCommerce by hand, the plugin notices on the next run and re-bases that product: its USD price is recalculated from the price you entered at the current rate, and your price is kept. Nothing you type is overwritten.

**The workflow**

1. Enter the rate on **WooCommerce → USD / Toman Pricing** and save it. Saving the rate does **not** touch a single product price.
2. Run a **Dry run**. You see exactly what would happen, product by product: which USD prices would be set, which prices would change and to what, which products are unchanged, skipped or in conflict. Nothing is written.
3. Confirm with **Update prices now**. Prices are written in the background through the WooCommerce Action Scheduler queue, in small batches. You can watch the progress, pause or cancel it, and close the browser: the queue keeps working.

**Why it is safe**

* **Preview before every update.** An update only starts after a completed dry run with the same rate, rounding settings and scope.
* **No surprises from a typo.** A rate move of about 15% (configurable) is blocked until you type `UPDATE` to confirm. It shows the old rate, the new rate and the percentage change. Zero, negative and non-numeric rates are rejected.
* **Rollback.** "Roll back last rate change" restores the previous rate and recalculates the prices from the stored USD values as a normal queued job. A product whose price you edited by hand keeps your edit and is re-based on it.
* **No lost edits.** If a price is edited while a job is running, that product is skipped and recorded as a conflict, and you can review it at the end of the job.
* **No broken prices.** Empty, zero, negative and malformed prices, and a sale price that is not below the regular price, are reported instead of being written, and they never crash the job.
* **No unnecessary writes.** If the rounded new price equals the current price, nothing is saved. A failed or skipped product keeps its old price.
* **One job at a time.** Every job stores its own cursor, so an interrupted job resumes where it stopped instead of starting over, and retries cannot apply a change twice.

**Excluded products**

Every product is managed by default, so an existing catalog is picked up without any per-product work. To keep a product (or a single variation) out of every job, set it to **Excluded** in the product panel, or use the bulk actions in the products list (set Managed, set Excluded, Recalculate selected). Excluded products are never changed. The product panel also shows the current WooCommerce price, the USD price, the rate used and the last sync time, and lets you correct the USD price of a product or of each variation by hand.

**Sale prices and variations**

Regular and sale prices each have their own USD price and follow the same rules. A product without a sale price keeps an empty sale price, and scheduled sale dates are never touched. Variable products are processed variation by variation.

**Rounding**

New prices are rounded **up** by default, which protects your margin. You can also round to the nearest value or down, in steps that depend on the unit: 1, 100, 1,000, 10,000 or 100,000 for Toman (default 1,000), and 10, 1,000, 10,000, 100,000 or 1,000,000 for Rial (default 10,000).

**Scopes**

You can update every managed product, selected products, selected categories, selected product types, or only products whose price may be outdated (no USD price yet, or last synchronized at a different rate).

**Audit trail**

Every rate change is stored permanently with the time and the administrator who made it, together with the results of the update it triggered. Each job keeps a per product log with the current price, the USD price and the new price, exportable as CSV. Logs are kept for 30 days by default (configurable).

**Server friendly**

Jobs run in small batches (10 products per run by default, 5 for low resource hosting, up to 25) with a fresh queued action per batch, a time budget, a lock and a heartbeat. Nothing depends on the browser being open, and the Diagnostics section reports the queue health, the failed actions, the current lock, the configured batch size and the detected store currency.

**Privacy**

This plugin does not send any data anywhere. There is no external exchange rate API in this version: you enter the rate yourself. The plugin runs entirely inside your own WordPress installation.

== Installation ==

1. Install WooCommerce and make sure it is active.
2. In **WooCommerce → Settings → General**, set the currency to **Iranian toman** or **Iranian rial**. The plugin does not work with any other currency and shows a notice until you do.
3. Upload the plugin zip through **Plugins → Add New → Upload Plugin**, or copy the plugin folder to `wp-content/plugins/`.
4. Activate **USD to Toman Price Sync for WooCommerce**.
5. Open **WooCommerce → USD / Toman Pricing** and enter your current rate (Toman or Rial per 1 USD).
6. Run a **Dry run** and review the numbers. The first update only records the USD prices and does not change any WooCommerce price.

== Frequently Asked Questions ==

= Does saving the rate change my prices? =

No. Saving the rate only records it. Prices change after you run the update, and only if you ran a dry run first.

= What happens to my existing prices when I activate the plugin? =

Nothing is changed. The first update only records a USD price for each product, calculated from its current price and the rate. A WooCommerce price is rewritten only on a later update with a different rate.

= Which store currencies are supported? =

Iranian Toman (IRT) and Iranian Rial (IRR). The plugin reads the unit from the WooCommerce currency setting, shows it on its settings screen and uses it for the labels and the rounding steps. It never changes the store currency, its position or its decimals, except once when a 1.1.x store in Toman mode is upgraded (see "Upgrading from 1.1.x"). With any other currency it shows a notice and does not start jobs. The rate remembers the unit it was saved in: after a move between Toman and Rial, save the rate again before the next job.

= Upgrading from 1.1.x =

In the old Toman mode (the default) the plugin switched WooCommerce to Toman only while it ran. The 1.2.0 upgrade sets the WooCommerce currency to Iranian Toman (IRT, no decimals, symbol after the price) once, so the storefront stays as it was, and shows a notice. In the old USD-store mode no WooCommerce setting is changed: set the store currency to Toman or Rial and review your prices; the plugin starts no job until then, and the old Toman prices stay in hidden product meta. A store that was in Toman mode is set to Toman once (the old plugin forced it); a store you move to Rial after the upgrade stays in Rial. A customised Toman suffix or Persian-digit display setting from 1.1.x is no longer used: WooCommerce's own currency symbol and formatting apply. If you later move the store between Toman and Rial, save the rate again in the new unit: until then every job is refused.

= Does the plugin show prices in dollars to my customers? =

No. Customers see the normal WooCommerce price in Toman or Rial. The USD price is only the anchor the plugin uses to recalculate that price when the rate changes.

= What if I change a price by hand? =

On the next run the plugin sees that the price is not the one it last wrote, treats your price as the new truth and recalculates that product's USD price from it at the current rate. Your price is not overwritten.

= Can I correct the USD price of a product? =

Yes. Type the new USD price into the product panel (each variation has its own field) and save the product. The next update, or **Recalculate this product**, rewrites the WooCommerce price from it at the current rate. A USD price typed by hand wins over a WooCommerce price edited in the same save. Empty the field to have the USD price recorded again from the WooCommerce price.

= Can I keep some products out of the updates? =

Yes. Set those products or variations to **Excluded** and no job will ever write to them.

= Can I undo a rate update? =

Yes. **Roll back last rate change** restores the previous rate and runs a queued update that recalculates the prices from the stored USD values. Products you edited by hand in the meantime keep their edited price.

= What if the queue stalls? =

Open the Diagnostics tab. It shows whether Action Scheduler is available, how many actions are pending or failed, whether a job holds the lock and the last error. If your host disabled WP-Cron, the plugin can fall back to a token protected loopback request.

= Are prices written with SQL? =

No. Every price is written with the WooCommerce CRUD API (`wc_get_product()`, `set_regular_price()`, `set_sale_price()`, `save()`), so caches, lookups and third party integrations stay consistent.

= Which capability is required? =

`manage_woocommerce` by default, so shop managers can use the screen. The requirement appears in the settings and can be filtered for stricter setups.

= Is the plugin translatable? =

Yes. Every string uses the `usd-to-toman-price-sync-for-woocommerce` text domain, a `.pot` template ships with the plugin, and a complete **Persian (`fa_IR`)** translation is bundled, so an Iranian store gets a Persian admin without installing a language pack.

== Screenshots ==

1. The rate screen: the manual rate, the typo warning, the rate history and the background queue diagnostics.
2. Dry run: what would change, what is unchanged, what is skipped, which variations are affected, with no write performed.
3. Jobs & history: the running job with its progress and the per product log with the current price, the USD price, the new price and the status.
4. The same screen in Persian, with the bundled `fa_IR` translation.
5. The product panel: the pricing mode (Managed or Excluded), the WooCommerce price, the USD price, the rate used and the bulk actions.

== Changelog ==

= 1.2.9 =
* Fixed: Stop the product count query from slowing down product screens

= 1.2.8 =
* Fixed: Add a 100,000 Toman (1,000,000 Rial) rounding step

= 1.2.7 =
* Fixed: Let the USD price be corrected by hand in the product panel

= 1.2.6 =
* Fixed: Recover from a dead worker and skip a product that keeps killing it

= 1.2.5 =
* Fixed: Fix bulk update stalling at a random percentage

= 1.2.4 =
* Fixed: I18n: translate the eight missing Persian strings

= 1.2.3 =
* Fixed: Fix stalled preview worker recovery and diagnostics

= 1.2.2 =
* Fixed: Full WooCommerce USD/Toman plugin audit and prioritized fix plan (2026-10-12)

= 1.2.1 =
* Fixed: Fix Markdown lint issues

= 1.2.0 =
* Changed: the plugin is dollar-pegged. It reads each product's price from the WooCommerce price fields (Toman or Rial, taken from the WooCommerce currency), generates a USD price from it and the rate, and from then on recalculates the WooCommerce price as USD price x rate when the rate changes.
* Changed: the first run only records the USD prices and never changes a WooCommerce price. A price edited by hand is re-based: its USD price is recalculated from the edited price.
* Changed: products are managed by default; set a product to Excluded to keep it out of every job. Rollback re-bases hand-edited products instead of overwriting them.
* Changed: rounding steps depend on the unit (Toman 1, 100, 1,000, 10,000; Rial 10, 1,000, 10,000, 100,000). Previews, jobs and CSV exports show the current price, the USD price and the new price, with new statuses "Generated" and "Re-based".
* Added: the plugin requires the store currency to be Toman (IRT) or Rial (IRR) and shows a notice, without starting jobs, otherwise. The detected currency is shown in the settings and the diagnostics.
* Removed: the Toman-store and USD-store modes, the reference Toman price fields, the currency override, the Toman display on the storefront, the Persian digits and Toman suffix options, and the "manage new products automatically" setting.
* Changed: upgrading a 1.1.x store in Toman mode sets the WooCommerce currency to Toman (IRT, no decimals, symbol after the price) once, the value the old plugin applied at runtime, and shows a notice. A store in the removed USD-store mode keeps its WooCommerce settings and gets a notice to set the currency to Toman or Rial and review its prices.
* Added: the rate remembers its unit. After the store moves between Toman and Rial every job is refused until the rate is saved again in the new unit.
* Changed: the conflict check before a write reads the price again past the request's caches, so a price changed meanwhile by another request is never overwritten.
* Removed: the upgrade deletes the obsolete settings and product data. The old Toman source prices are kept as hidden product meta (removed by "Delete data on uninstall"). Old job history rows are kept.

= 1.1.5 =
* Fixed: Declare compatibility with WordPress 7.1

= 1.1.4 =
* Fixed: Tolerate the by-design concurrent-only skip

= 1.1.3 =
* Fixed: Translate the one-minute cron schedule label

= 1.1.2 =
* Fixed: Publish a patch release automatically on every merge to main

= 1.1.1 =
* Fixed: live jobs no longer appear frozen at 0/0 until the next page refresh on hosts where Action Scheduler waits for another WordPress request. Immediate worker steps receive a token-protected loopback wake-up while the native queue remains the fallback.
* Fixed: the progress card polls a lightweight, explicitly uncached job-status endpoint instead of rebuilding product/item details every 2.5 seconds. A cache-busting request token protects sites behind aggressive admin/proxy caching.
* Changed: the discovery phase now uses an animated indeterminate progress bar instead of presenting 0/0 as if no work were happening, and worker messages are shown directly in the live card.
* Fixed: Persian live progress labels/buttons no longer fall back to English when JavaScript translation JSON is unavailable; dynamic strings are also localized through the bundled PHP MO catalogue.
* Fixed: delayed retry actions are never executed early by the loopback fallback; the configured retry backoff is left to the scheduler.
* Fixed: Action Scheduler and WP-Cron enqueue failures are checked instead of being reported as successfully queued.
* Fixed: a job that loses the exclusive-start race is closed as failed before processing instead of becoming an orphan that can occupy the update slot.
* Hardened: job-item updates accept only known table columns.
* Added: integration coverage for the lightweight live-status route, cache-safe polling wiring and discovery progress state.
* Fixed: when the loopback fallback is disabled, REST-started Action Scheduler jobs explicitly wake Action Scheduler's own async runner instead of waiting for another wp-admin request.
* Fixed: the custom five-minute recovery recurrence is registered before activation/upgrade attempts to schedule it.
* Fixed: bulk mode changes now cover every variation, complete variation IDs are paginated for admin/bulk work, all categories are available to scope selection, and job CSV export no longer truncates at 20,000 rows.
* Tests: the real HTTP suite now starts jobs over REST and waits for the background queue to finish them without manually stepping the worker or refreshing an admin page.

= 1.1.0 =
Production audit release: every finding of the production audit is fixed and covered by the integration suite, which now runs the worker phases in separate PHP processes and the admin REST API over real HTTP.
* Fixed: the admin screen called the REST API without the plugin namespace, so every action failed with the REST `rest_no_route` error. Requests now address `usdtf/v1`, a failing request logs its exact method and path, and the diagnostics name missing routes after a broken upgrade.
* Fixed: the synchronization lease was owned by a per-request token, so the background worker — a different PHP process — could not continue the job it was running. The lease now belongs to the job, survives request boundaries, and is only held by write jobs — a dry run never blocks a real update.
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

= 1.2.9 =
Stop the product count query from slowing down product screens

= 1.2.8 =
Add a 100,000 Toman (1,000,000 Rial) rounding step

= 1.2.7 =
Let the USD price be corrected by hand in the product panel

= 1.2.6 =
Recover from a dead worker and skip a product that keeps killing it

= 1.2.5 =
Fix bulk update stalling at a random percentage

= 1.2.4 =
I18n: translate the eight missing Persian strings

= 1.2.3 =
Fix stalled preview worker recovery and diagnostics

= 1.2.2 =
Full WooCommerce USD/Toman plugin audit and prioritized fix plan (2026-10-12)

= 1.2.1 =
Fix Markdown lint issues

= 1.2.0 =
Behaviour change: pricing is now dollar-pegged. The store currency must be Iranian Toman (IRT) or Rial (IRR). Stores in Toman mode are set to Toman once. Stores in USD mode must set the currency and review their prices. The first run only records USD prices and changes no price.

= 1.1.5 =
Declare compatibility with WordPress 7.1

= 1.1.4 =
Tolerate the by-design concurrent-only skip

= 1.1.3 =
Translate the one-minute cron schedule label

= 1.1.2 =
Publish a patch release automatically on every merge to main

= 1.1.1 =
Fixes live progress that could remain at 0/0 until a refresh, hardens background worker wake-ups and queue failure handling, and makes progress polling lightweight and cache-safe.

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
