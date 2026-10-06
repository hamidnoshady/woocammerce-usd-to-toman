# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-10-06

### Fixed

- Live synchronization no longer waits for the administrator to refresh the page on hosts where
  Action Scheduler does not dispatch its async runner immediately. Due-now worker actions receive a
  token-protected loopback wake-up; the queued Action Scheduler/WP-Cron action remains the fallback,
  and the loopback claims that exact action before running it.
- The dashboard no longer polls the heavyweight job-details endpoint every 2.5 seconds. A new
  `/jobs/<id>/status` endpoint returns only the job summary, sends explicit no-store headers, and
  the client adds a cache-busting token so proxy/admin caches cannot freeze the visible progress.
- Catalog discovery no longer looks like a dead `0 / 0 (0%)` job. The live card displays an
  animated indeterminate bar until discovery determines the product count, then switches to exact
  processed/total progress.
- Dynamic progress labels and buttons now use a PHP-localized lookup table before falling back to
  WordPress JavaScript i18n. This fixes Persian screens showing English labels such as Changed,
  Failed, Pause and View details when no generated Jed JSON catalogue is installed.
- Delayed retries are no longer shortened to at most ten seconds by the loopback fallback. Delayed
  work stays in the scheduler until its real due time.
- Action Scheduler and WP-Cron scheduling return values are checked; a failed enqueue is no longer
  treated as a successful background job.
- A newly-created job that loses the exclusive lock race is finalized as failed before any catalog
  write instead of remaining as an orphaned active job.
- Job-item updates are constrained to the known job-item table columns.

### Added

- Integration coverage for the live status route over HTTP, cache-safe polling wiring and
  indeterminate discovery progress.

## [1.1.0] - 2026-10-06

Production audit release: every finding of the production audit (issue #4) is fixed and covered by
the integration suite, which now runs the worker phases in separate PHP processes and the admin REST
API over real HTTP.

### Fixed

- The admin screen talked to `/wp-json/state`, `/wp-json/rate`, … instead of the plugin namespace,
  so every action failed with the REST `rest_no_route` error ("هیچ مسیری…"). The admin script now
  prefixes every request with the registered namespace, logs the failing method and path for any
  request that errors, and explains a `rest_no_route` response on screen. The diagnostics gained a
  REST route check that names the missing routes, so a broken upgrade cannot hide behind a generic
  error. CI now verifies the built zip ships the REST controller and an admin script that addresses
  the `usdtf/v1` namespace.
- The synchronization lease was owned by a per-request random token, so the Action Scheduler worker
  — a different PHP process — could never heartbeat a job it was legitimately continuing. The lease
  is now owned by the job ID with compare-and-swap writes, heartbeats work across request
  boundaries, and releases are scoped to the owning job. Only write jobs hold the exclusive lease:
  a dry run never blocks a real update. The integration suite runs a job step by step through
  separate PHP processes to keep it that way.
- The worker rewrote the canonical Toman source meta from its snapshot during every refresh, which
  could overwrite an admin edit that landed mid-synchronization. The source meta is now read only
  during a sync: only derived, rate and sync markers are written, so a concurrent edit survives and
  the next run adopts it.
- Variable product failures disappeared between slices: every slice reset the per-item counters,
  so an invalid variation in the first slice was forgotten by the last. The counters now persist in
  the item row across slices (a `child_stats` column, schema version 5) and the item finishes as
  failed or conflicted whenever any slice hit a problem.
- A failed item was retried immediately because the retry delay only gated `processing` rows. It now
  gates pending rows too, and the worker schedules the next batch no earlier than the earliest due
  retry instead of spinning.
- Jobs were marked running even when their first worker action never reached the queue. Every
  enqueue result is now checked; on failure the job is paused with a clear message, the lease is
  released, the error is logged and the REST call returns an actionable `usdtf_queue_failed`
  response. A `usdtf_scheduler_enqueue_blocked` filter makes the failure path testable.
- The per-variation mode select of the product panel was submitted but never saved. The mode is now
  validated and persisted per variation, both from the variations screen and from the product save.
- The `include_variations` scope flag was ignored by the worker: a variable product walked its
  variations even when the scope excluded them. The flag is now honored; the item is skipped with an
  explanatory message. Selecting variations explicitly still updates them.
- The manual-price notice was written to a WooCommerce customer session (which does not exist in
  `wp-admin`) under a key the admin notice never read. It now uses the per-user transient the
  renderer reads.
- The loopback fallback disabled TLS certificate verification. It now verifies certificates like
  every other request; hosts whose loopback fails keep the WP-Cron twin of the action.
- The loopback and its WP-Cron twin could both trigger the same worker step. The loopback now owns
  its run: when it arrives it unschedules the matching cron event, and the cron event only survives
  as the safety net when the loopback never makes it.
- Managed variations under a non-managed parent were invisible to full-catalog updates, because
  discovery only walked parents. A second discovery stream now finds managed variations whose
  parent is not managed, in pages, and queues them on their own.
- Variable products with more than 2000 variations silently lost everything beyond the first 2000.
  Variation IDs are paginated from the database per slice, so a product is synchronized completely
  no matter how many variations it has.
- The Toman price range of a variable product used the stored sale source even when the scheduled
  sale was over. It now mirrors WooCommerce's sale-date state: an expired sale shows the regular
  Toman price, an active sale shows the sale price.
- The Toman reference price was formatted from the raw canonical value, bypassing the store's tax
  display configuration. It now goes through `wc_get_price_to_display()`, so the reference follows
  the same including/excluding tax treatment as every other displayed price (a pass-through when no
  tax rates are configured).
- `uninstall.php` still declared version 1.0.2. All shipped version markers (plugin header,
  `USDTF_VERSION`, the uninstall fallback and the readme stable tag) now agree, and
  `bin/check-versions.php` fails the build — for the working copy and for the built zip — when they
  do not.
- The uninstall left pending worker actions and the `usdtf_stale_resumes` bookkeeping behind. It now
  unschedules every worker action of every backend, removes the stale-resume option and clears the
  plugin's transients, including the per-user notices.

### Added

- **Dry run first** is now enforced by default: an update only starts after a completed dry run that
  used the same exchange rate, transaction currency, rounding settings and normalized scope. The
  fingerprint match covers rate, mode, rounding, increment, decimals and scope; the REST `/update`
  endpoint accepts a `preview` job ID and answers 428 `usdtf_preview_required` with the expected
  fingerprint otherwise. The rule is a setting and can be turned off; the dashboard says so before
  the button is clicked.
- Integration coverage for the audit: live REST route registration and version marker agreement,
  retry timing, cross-slice variation failures, variation mode persistence, the `include_variations`
  flag, the read-only source meta rule under a mid-write edit, queue failures, the dry-run gate,
  variation discovery under unmanaged parents, variation pagination, the zero source rule, the
  capability allowlist, scheduled sales, worker phases in separate PHP processes
  (`tests/integration/worker.php`) and the admin REST API over real HTTP
  (`tests/integration/http.php`, with a `php -S` server in CI). 48 scenario groups in total.

### Changed

- The `required_capability` setting only accepts the documented allowlist
  (`manage_woocommerce`, `manage_options`) and the settings screen offers a select instead of a
  free text field. Weaker setups remain possible for developers through the `usdtf_required_capability`
  filter.
- Saving the settings screen through `options.php` now requires the plugin capability instead of
  `manage_options`, aligned with the capability that opens the screen — shop managers can save what
  they can edit.
- A zero price is refused as a Toman source at write time (like negative values), so a source that
  can never synchronize cannot be saved.
- All admin script strings are translatable through `wp.i18n` (the Persian catalogue ships them),
  and product names from the product search are rendered as text nodes instead of HTML, so nothing
  can inject markup through a product name.

## [1.0.3] - 2026-10-05

### Added

- A complete Persian (`fa_IR`) translation of all 368 strings, shipped as a `.po` source and a
  compiled `.mo` catalogue. The plugin loads its own text domain on `init` and a language pack
  installed in `wp-content/languages/plugins` still wins.
- The compiled catalogue carries the standard hash-less MO layout, including the hash table fields
  WordPress derives its read offsets from (`MO::import_from_file()` refuses a catalogue that leaves
  them empty), and `bin/make-mo.php` now verifies that layout before writing anything.
- `bin/make-mo.php` compiles the catalogues without any dependency, refuses a translation that drops
  or invents a placeholder, and refuses to write a file that does not read back entry for entry. CI
  runs it with `--check` and the release workflow fails when the shipped catalogues are missing from
  the archive.
- Integration coverage for the fresh install default and the upgrade behaviour, and for the bundled
  catalogue (template coverage, readability, loading and a translated string coming back in
  Persian). 37 scenario groups in total.
- The WordPress.org listing artwork is generated from source instead of being drawn by hand:
  `wordpress-org/make-artwork.py` writes the icon, the banner and five screenshots and refuses a file
  with the wrong dimensions. It never ships in the zip; the CI and release checks now also refuse a
  distribution that contains the folder.

### Changed

- A fresh install transacts in **Toman** (mode B) by default, the safer choice for Iranian payment
  gateways. Existing stores keep the mode they stored; the new default only applies while no mode has
  ever been saved. The readme no longer calls mode A the recommended one.

## [1.0.2] - 2026-10-05

### Fixed

- The diagnostics counted the background queue by listing actions, which Action Scheduler caps.
  A store with a longer queue always saw 500. The counts now ask the store for an exact count and
  fall back to the listing when the store cannot answer.

### Added

- Integration coverage for the worker queue (the action, its hook, its group and the job id it
  carries, plus the async actions that carry no date), a job that was cancelled ignoring a leftover
  worker action while a paused job resumes, the loopback diagnostic against a reachable, a failing
  and an unreachable site, every admin tab including output escaping and the capability gate, the
  script wiring, and the product panel (meta box, saving, refusals, bulk actions, list column).
  35 scenario groups in total.

## [1.0.1] - 2026-10-05

### Fixed

- **The REST API the admin screen uses was never registered.** The controller had no
  `rest_api_init` hook, so saving the rate, previewing, starting an update, pausing,
  cancelling, exporting state, the diagnostics and the product search all answered `404`.
  The controller is now initialized for every request (a REST request is neither an admin
  nor a frontend screen), and the integration suite asserts the routes answer with `200`.
- The rate change percentage was always computed as `-100%` because of an undefined
  variable, so the typo guard demanded the typed confirmation even for a `0.37%` move.
- A pending Action Scheduler action without a date could fatal the diagnostics section and
  the state endpoint that embeds it.
- Validation failures and the duplicate job refusal now carry proper HTTP status codes
  (`400` and `409`) instead of `500`.
- Uninstalling purges only the meta keys the plugin owns.

### Added

- Integration coverage for the REST API surface, the token protected worker endpoint, the
  cron maintenance pass and the uninstall behaviour (30 scenario groups in total).
- A `Verify release` workflow that fetches the published archive back from GitHub Releases,
  checks it against the published checksum, inspects its contents and runs the whole suite
  against it.

### Changed

- The reproducible build documentation is precise about what is reproducible: entry timestamps
  and the archive comment are fixed, so the same sources built with the same PHP and zlib version
  produce the same bytes, while deflate output may differ between environments. Releases are
  therefore verified by checksum and by running the suite against the published archive.

## [1.0.0] - 2026-10-05

First release, implementing the specification in
[issue #1](https://github.com/hamidnoshady/woocammerce-usd-to-toman/issues/1).

### Added

- Canonical Toman source prices per product and variation, stored separately from the WooCommerce
  price fields and never overwritten by a synchronization.
- Derived USD prices from a manual USD/Toman rate: `ceil( Toman / rate )`, recalculated from the
  Toman source on every run so rounding can never drift.
- Admin screen under **WooCommerce → USD / Toman Pricing** with the current rate, the previous
  rate, the last update, catalog counters, Preview Changes, Update Prices and live job progress.
- Mandatory dry run: products scanned, will change, unchanged, skipped, invalid, conflicts and
  affected variations, with zero writes.
- Queued background synchronization on Action Scheduler (WP-Cron and a token protected loopback
  fallback), small batches with a configurable size (5–25, default 10), a time budget, and variable
  products processed in slices before their parent range is recalculated.
- Job persistence with counters, cursor, heartbeat, phase, initiating user and scope, resumable
  after a crash without restarting the catalog, with retries and duplicate run protection.
- Per product conflict detection through a revision marker, with a conflict review and a
  recalculation action at the end of a job.
- Rate typo protection: a configurable percentage threshold, a typed confirmation, an estimate of
  affected products and rejection of zero, negative, non-numeric and malformed rates.
- Per product pricing modes (Toman managed, Native USD, Excluded), a product editor panel with the
  source and derived prices, the rate used, the last sync time and a per product recalculation,
  plus bulk actions to switch the mode.
- Rounding modes (up, nearest, down) with $1, $5 and $10 steps; the default is rounding up.
- Selective scopes: every managed product, selected products, a category, a product type, outdated
  only and changed since a date.
- Permanent rate history with the acting administrator and the results of the update, per product
  job logs with configurable retention, CSV export and a "rollback last rate update" action that
  runs as a normal queued job.
- Storefront Toman display for the shop and archives, single product pages, variable ranges,
  related products, upsells and cross-sells, the mini cart, the cart, the checkout, WooCommerce
  Blocks and the Store API, with optional Persian digits.
- Explicit transaction currency modes: USD transactions with a Toman display, or Toman transactions
  where the gateway receives Toman and the USD price is kept as a reference.
- Diagnostics for Action Scheduler availability, pending and failed actions, queue health, the
  current lock, the last error and the configured batch size.
- Capability checks on every mutation, nonces, sanitized settings, escaped output and an audit log
  that records who changed the rate and who started a job.
- Build tooling: a clean distribution zip with checksums (`bin/build-dist.php`), an in-process PHP
  linter (`bin/lint.php`) and a translation template generator (`bin/make-pot.php`).
- Continuous integration for syntax across six PHP versions, coding standards, the translation
  template, the distribution archive and a WordPress + WooCommerce integration suite that also runs
  against the built zip. Tagging a version publishes the zip and its checksum to a GitHub release.

[1.1.0]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.1.0
[1.0.3]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.3
[1.0.2]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.2
[1.0.1]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.1
[1.0.0]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.0

[1.1.1]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.1.1
