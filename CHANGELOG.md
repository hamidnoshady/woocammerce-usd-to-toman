# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-10-05

### Added

- A complete Persian (`fa_IR`) translation of all 368 strings, shipped as a `.po` source and a
  compiled `.mo` catalogue. The plugin loads its own text domain on `init` and a language pack
  installed in `wp-content/languages/plugins` still wins.
- `bin/make-mo.php` compiles the catalogues without any dependency, refuses a translation that drops
  or invents a placeholder, and refuses to write a file that does not read back entry for entry. CI
  runs it with `--check` and the release workflow fails when the shipped catalogues are missing from
  the archive.
- Integration coverage for the fresh install default and the upgrade behaviour, and for the bundled
  catalogue (template coverage, readability, loading and a translated string coming back in
  Persian). 37 scenario groups in total.

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

[1.0.2]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.2
[1.0.1]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.1
[1.0.0]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.0
