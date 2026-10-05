# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/hamidnoshady/woocammerce-usd-to-toman/releases/tag/1.0.0
