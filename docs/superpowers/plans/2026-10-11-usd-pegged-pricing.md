# USD-pegged pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the plugin's stored Toman price with a per-product USD anchor generated from the WooCommerce price, so the WooCommerce price follows the USD rate.

**Architecture:** Keep the job engine (queue, preview, locks, rate guard, REST, rollback). Add two small units, `Store_Currency` (unit from WooCommerce) and `Price_Planner` (pure per-field decision), and rebuild `Product_Pricing`, `Recorder`, settings and screens around them. Delete the Toman-source, currency-override and display code.

**Tech Stack:** PHP 7.4+ WordPress plugin, WooCommerce 7.0+, custom integration suite (`tests/integration`), phpcs (WordPress standards), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-11-usd-pegged-pricing-design.md`

## Spec amendments found while planning (confirm at review)

- **A1. Products with no pricing mode are Managed.** Today a product without `_usdtf_mode` counts as "native" (unmanaged), which would leave an existing catalogue untouched until each product is switched. The setting `auto_manage_new_products` is removed. Only an explicit `excluded` mode opts a product out. A stored `native` is read as `excluded`.
- **A2. Obsolete database columns stay in place, unused.** `dbDelta` never drops columns and the SQLite test driver differs from MySQL, so the upgrade adds the new columns and leaves `source_*`, `toman_*`, `expected_revision` and `jobs.currency_mode` unused. Obsolete post meta and settings are still deleted.

## Global Constraints

- PHP 7.4 syntax only (no typed properties, `match`, named arguments, union types, `str_contains`); the code base uses docblock types, not type hints.
- WordPress 6.2+, WooCommerce 7.0+; the plugin works only when `get_woocommerce_currency()` is `IRT` or `IRR`.
- Text domain `usd-to-toman-price-sync-for-woocommerce`; every user-visible string goes through `__()` with a `/* translators */` comment where it has placeholders; output escaped, nonces and capability checks unchanged.
- USD anchor: string with exactly 2 decimals, half up. Rate: stored as `(string) (float) $rate`, compared at 6 decimals. Woo price strings use `wc_get_price_decimals()`.
- Rounding increments: Toman `1, 100, 1000, 10000` (default `1000`); Rial `10, 1000, 10000, 100000` (default `10000`). Rounding mode default `up`.
- Meta keys: `_usdtf_usd_regular`, `_usdtf_usd_sale`, `_usdtf_seen_regular`, `_usdtf_seen_sale`, `_usdtf_rate`, `_usdtf_mode` (`managed`|`excluded`), `_usdtf_synced_at`, `_usdtf_last_error`, `_usdtf_last_job`, `_usdtf_conflict`.
- Item statuses: `changed` (shown "Updated"), `generated`, `rebased`, `unchanged`, `skipped`, `failed`, `conflict`.
- Coding standards: `vendor/bin/phpcs` must pass on every task's files; `php bin/lint.php` and `php bin/check-versions.php` must stay green.
- Never skip or weaken a legacy test: port it with the same name and intent (Task 11).
- Every merge to `main` publishes a release: all work stays on branch `claude/blissful-lovelace-6ez56w` in draft PR #17 until Task 13.

## Review Focus

Failure modes the spec implies that no single task's happy path covers (each gets a test in the owning task):

1. A Woo price edited between Preview and Update must give a conflict and write nothing (Task 7).
2. A scheduled sale that ends clears the stored USD sale price and never feeds the next update (Tasks 4, 7).
3. A tiny price at a high rate (USD below 0.01) must be skipped with a reason, never written as 0 (Task 4).
4. Woo prices with decimals (`12.50`) must round-trip without drifting (Tasks 4, 7).
5. Running Update twice at the same rate writes nothing the second time; rollback to the previous rate restores prices within rounding (Tasks 7, 8).
6. Switching the store currency to USD after USD prices exist refuses jobs and writes nothing (Task 7).

## Environment (used by every task)

```bash
S=/tmp/claude-0/-home-user-woocammerce-usd-to-toman/b9262a59-d29c-59ef-92e5-cc6ea4af828a/scratchpad
export USDTF_WP="$S/wp"                                   # created once, ~6 s:
bash tests/integration/setup.sh "$USDTF_WP" --url=http://127.0.0.1:8888
php tests/integration/run.php "$USDTF_WP"                 # full suite, ~50 s (baseline: 54 scenarios green)
USDTF_IT_ONLY=pegged php tests/integration/run.php "$USDTF_WP"   # new-model scenarios only (added in Task 1)
composer install --no-interaction -q && vendor/bin/phpcs --config-set installed_paths "$(find vendor -name ruleset.xml -not -path '*/Tests/*' -maxdepth 4 -printf '%h\n' | sort -u | paste -sd, -)" && vendor/bin/phpcs
php bin/lint.php && php bin/check-versions.php && php bin/make-pot.php --check && php bin/make-mo.php --check
```

The suite stops at the first failed assertion. **From Task 3 on, the legacy scenarios are expected to fail until Task 11 ports them; use `USDTF_IT_ONLY=pegged` for tasks 3 to 10.** Remove `vendor/` before building a zip.

## File structure

| File | Responsibility |
|---|---|
| `includes/class-store-currency.php` (new) | Read the unit (Toman/Rial) from the WooCommerce currency. |
| `includes/class-price-planner.php` (new) | Pure per-field decision: generate / rebase / update / clear / unchanged / skip. |
| `includes/class-calculator.php` | USD and store-price maths, unit-aware increments, number formatting. |
| `includes/class-product-pricing.php` | Product meta: USD anchor, seen price, mode, snapshot. |
| `includes/class-recorder.php` | Apply a plan to one product: conflict check, Woo write, meta. |
| `includes/class-sync-runner.php`, `class-job*.php`, `class-database.php`, `class-installer.php` | Jobs engine, new statuses/counters/columns, upgrade step. |
| `includes/class-settings.php`, `class-product-repository.php`, `class-rate-repository.php` | Settings, scopes/counts, impact estimate and rollback. |
| `includes/admin/*`, `assets/js/admin.js` | REST payloads, product panel, settings/dashboard/jobs/health screens, CSV. |
| `includes/frontend/*` (deleted in Task 3) | Old currency override and Toman display. |
| `tests/integration/pegged.php` (new) | New-model scenarios; legacy files ported in Task 11. |

---

### Task 1: Test harness for the new model

**Files:**
- Modify: `tests/integration/run.php` (after the helper functions, before section 1), `tests/integration/lib.php`
- Create: `tests/integration/pegged.php`

**Interfaces:**
- Produces: `USDTF_IT_ONLY=pegged` runs only `pegged.php` then exits 0; `usdtf_it_set_currency( $code )` (adds a `pre_option_woocommerce_currency` filter returning `$code`; `null` removes it); `usdtf_it_make_priced_product( $name, $regular, $sale = '', $mode = '' )` returns a `WC_Product_Simple` with Woo prices set, no plugin meta, and `_usdtf_mode` set only when `$mode` is not empty.

- [ ] **Step 1: Write the failing scenario** in `pegged.php`: `usdtf_it_set_currency( 'IRT' ); $p = usdtf_it_make_priced_product( 'Harness', 5000000 ); usdtf_it_assert_same( '5000000', usdtf_it_price( $p->get_id() ), 'the fixture must hold the Woo price' ); usdtf_it_assert_same( '', usdtf_it_meta( $p->get_id(), '_usdtf_usd_regular' ), 'the fixture must carry no plugin meta' ); usdtf_it_pass( 'the new-model harness runs' );`
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged php tests/integration/run.php "$USDTF_WP"`. Expected: FAIL (helper not defined).
- [ ] **Step 3: Implement** the two helpers in `lib.php` and the `USDTF_IT_ONLY` gate in `run.php` (`require __DIR__ . '/pegged.php'`, then `exit( 0 )` when set; otherwise `pegged.php` is also required before the legacy sections). Track created products with the existing `usdtf_it_track_product`.
- [ ] **Step 4: Run** both `USDTF_IT_ONLY=pegged` and the full suite. Expected: PASS and 55 scenarios.
- [ ] **Step 5: Commit** `test: harness for the USD-pegged model`.

### Task 2: Calculator additions

**Files:** Modify `includes/class-calculator.php`; Test `tests/integration/pegged.php`.

**Interfaces:**
- Produces: `Calculator::usd_from_price( $price, $rate )` → `float|null` (null when `$price <= 0` or `$rate <= 0`; else `$price / $rate` rounded to 2 decimals half up); `Calculator::price_from_usd( $usd, $rate, $args = array() )` → `float|null` (`$args`: `rounding`, `increment`, `decimals`; uses existing `round_amount` and `trim_to_decimals`); `Calculator::increments_for_unit( $unit )` → `float[]` and `Calculator::default_increment( $unit )` → `float` per Global Constraints; `Calculator::format_price( $amount, $decimals = 0 )` → string via `number_format_i18n`.

- [ ] **Step 1: Write failing scenarios**: `usd_from_price( 5000000, 270000 ) === 18.52`; `( 43890000, 270000 ) === 162.56`; `( 0, 270000 )`, `( 100, 0 )` and `( -1, 1 )` are `null`; `price_from_usd( 18.52, 300000, up/1000/0 ) === 5556000.0`; `price_from_usd( 18.52, 297000, … )` is `5501000.0` (up), `5500000.0` (nearest), `5500000.0` (down); `increments_for_unit( 'toman' ) === array( 1.0, 100.0, 1000.0, 10000.0 )`, `( 'rial' ) === array( 10.0, 1000.0, 10000.0, 100000.0 )`, `default_increment( 'toman' ) === 1000.0`, `( 'rial' ) === 10000.0`.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL (methods missing).
- [ ] **Step 3: Implement** the four methods with the signatures above.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …` and phpcs on the file. Expected: PASS.
- [ ] **Step 5: Commit** `feat: USD and store-price maths for the pegged model`.

### Task 3: Store currency, and remove the old currency override

**Files:** Create `includes/class-store-currency.php`; Delete `includes/frontend/class-currency.php`, `includes/frontend/class-display.php`; Modify `includes/class-plugin.php` (remove `display()`, `currency()` and their `hooks()` calls), `includes/class-autoloader.php` only if it lists them; Test `pegged.php`. (The override must go first: `Frontend\Currency` forces `IRT` while the old mode is Toman, which would make `get_woocommerce_currency()` ignore the store setting these tests switch.)

**Interfaces:**
- Produces: `final class Store_Currency` with `const UNIT_TOMAN = 'toman'; const UNIT_RIAL = 'rial';` and static `code()` → string (`get_woocommerce_currency()`), `unit()` → `'toman'` for `IRT`, `'rial'` for `IRR`, `''` otherwise, `is_supported()` → bool, `unit_label()` → translated `Toman`/`Rial`/`''`.

- [ ] **Step 1: Write failing scenarios** using `usdtf_it_set_currency`: `IRT` gives unit `toman`, supported, label `Toman`; `IRR` gives `rial`, supported, `Rial`; `USD` gives `''`, not supported, label `''`; removing the filter restores the stored currency; `class_exists( '\\USDTF\\Frontend\\Currency' )` and `…\\Display` are false; with `USD` stored `get_woocommerce_currency()` is `USD` (the plugin no longer overrides it); the storefront price HTML of a managed product equals WooCommerce's own `wc_price()` output.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL (class missing, override still active).
- [ ] **Step 3: Implement** `Store_Currency` (static methods only; no state, so tests can switch currency by filter), then delete the two frontend classes and unwire them in `Plugin`.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …`, phpcs and `php bin/lint.php`; `grep -rn "Frontend\\\\\|->display()\|->currency()" includes`. Expected: PASS, no output (legacy tests are fixed in Task 11).
- [ ] **Step 5: Commit** `feat: read the store unit from the WooCommerce currency; drop the override`.

### Task 4: Price planner (pure decision unit)

**Files:** Create `includes/class-price-planner.php`; Test `pegged.php`.

**Interfaces:**
- Consumes: `Calculator::usd_from_price`, `Calculator::price_from_usd` (Task 2).
- Produces: `final class Price_Planner` with constants `ACTION_GENERATE='generate'`, `ACTION_REBASE='rebase'`, `ACTION_UPDATE='update'`, `ACTION_CLEAR='clear'`, `ACTION_UNCHANGED='unchanged'`, `ACTION_SKIP='skip'`, and `public static function plan( $current, $seen, $usd, $stored_rate, $rate, array $rounding )` → `array{action:string, code:string, usd:string, price:string, seen:string}`. `$current`, `$seen`, `$usd` are strings (`''` = none); `$stored_rate` and `$rate` are floats; `$rounding` has `rounding`, `increment`, `decimals`. `price` is the Woo price after the action (equal to `$current` unless `update`); `seen`/`usd` are the values to store.

Decision order: `rate <= 0` → skip `no_rate`; `current === ''` and `usd === ''` and `seen === ''` → skip `no_price`; `current === ''` otherwise → clear (`usd = ''`, `seen = ''`); `current` not a positive number → skip `invalid_price`; `usd === ''` → generate; `current !== seen` (compared at the Woo decimals) → rebase; `stored_rate == rate` (6 decimals) → unchanged; else update. **Generate and rebase** first compute the new USD and skip with `too_low` when it is below `0.01` (an update never needs this check: it starts from a stored USD of at least `0.01`).

- [ ] **Step 1: Write failing table-driven scenarios** (rounding `up`, increment `1000`, decimals `0`, unless stated):

| current | seen | usd | stored | rate | action | usd out | price out | code |
|---|---|---|---|---|---|---|---|---|
| `5000000` | `` | `` | 0 | 270000 | generate | `18.52` | `5000000` | |
| `5000000` | `5000000` | `18.52` | 270000 | 270000 | unchanged | `18.52` | `5000000` | |
| `5000000` | `5000000` | `18.52` | 270000 | 300000 | update | `18.52` | `5556000` | |
| `6000000` | `5556000` | `18.52` | 300000 | 300000 | rebase | `20.00` | `6000000` | |
| `` | `1000` | `3.70` | 270000 | 270000 | clear | `` | `` | |
| `` | `` | `` | 0 | 270000 | skip | | | `no_price` |
| `abc` / `0` / `-5` | | | | 270000 | skip | | | `invalid_price` |
| `1` | `` | `` | 0 | 270000 | skip | | | `too_low` |
| `5000000` | | | | 0 | skip | | | `no_rate` |
| `12.50` (Woo decimals 2) | `12.50` | `0.50` | 25 | 25 | unchanged | `0.50` | `12.50` | |

  Also assert `seen` equals `price` for every non-skip action.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement** `Price_Planner::plan` following the decision order above; no WordPress calls except `Calculator`.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …` and phpcs. Expected: PASS.
- [ ] **Step 5: Commit** `feat: pure per-field price planner`.

### Task 5: Data layer: settings, schema, upgrade

**Files:** Modify `includes/class-settings.php`, `includes/class-database.php`, `includes/class-installer.php`, `includes/class-job-repository.php` (columns and constants), `includes/class-job.php` (counters), `uninstall.php`; Test `pegged.php`.

**Interfaces:**
- Consumes: `Store_Currency::unit()`, `Calculator::increments_for_unit/default_increment`.
- Produces: `Settings::allowed_increments( $unit = null )` (defaults to `Store_Currency::unit()`); `Settings::rounding_args()` → `array{rounding:string,increment:float,decimals:int}` where `decimals = wc_get_price_decimals()` and an increment that is not allowed for the unit falls back to `default_increment`; `Job_Repository::ITEM_GENERATED = 'generated'`, `ITEM_REBASED = 'rebased'`; items columns `usd_regular`, `usd_sale` (varchar(32) NULL, added to `ITEM_COLUMNS`/`ITEM_FORMATS`, the old `source_*`, `toman_*`, `expected_revision` removed from those lists); jobs columns `generated`, `rebased` (bigint unsigned, default 0) and `Job::counters()` returns them; `Installer::migrate_to_pegged_model()` (runs once from `maybe_upgrade()` when `Database::SCHEMA_VERSION` is bumped).
- Removed settings keys: `currency_mode`, `display_toman`, `display_toman_cart`, `persian_digits`, `toman_suffix`, `decimals`, `auto_manage_new_products`; option `usdtf_synced_currency_mode`; methods `is_usd_mode`, `is_toman_mode`, `synced_currency_mode`, `set_synced_currency_mode`, `currency_mode_is_stale`, `MODE_USD`, `MODE_TOMAN`.

- [ ] **Step 1: Write failing scenarios**: with `IRT`, `Settings::sanitize( array( 'increment' => 5 ) )['increment'] === 1000.0`; `increment 100` is kept; with `IRR`, `100` falls back to `10000.0` and `1000` is kept; `sanitize()` output has none of the removed keys; after `Installer::maybe_upgrade()` on a database seeded with old meta (`_usdtf_source_regular`, `_usdtf_derived_regular`, `_usdtf_revision`, `_usdtf_mode = native`, an old settings array with `currency_mode`) the old meta is gone, the mode reads `excluded`, the settings lack the removed keys, and the items table has `usd_regular`; running `maybe_upgrade()` twice changes nothing.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Implement** the settings changes (defaults: `rounding up`, increment from the unit), the schema additions (bump `Database::SCHEMA_VERSION`), and `migrate_to_pegged_model()`: delete the obsolete post meta with one prepared `DELETE … WHERE meta_key IN (…)`, rewrite `_usdtf_mode = native` to `excluded`, drop obsolete settings keys and the obsolete option. Update `uninstall.php` to list the new meta keys.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …` and phpcs. Expected: PASS. Run `grep -rn "currency_mode\|MODE_TOMAN\|MODE_USD\|display_toman\|toman_suffix\|persian_digits\|auto_manage_new_products" includes/class-settings.php includes/class-installer.php uninstall.php`. Expected: no output.
- [ ] **Step 5: Commit** `feat: unit-aware settings, new item/counter columns, upgrade step`.

### Task 6: Product pricing model and scopes

**Files:** Modify `includes/class-product-pricing.php`, `includes/class-product-repository.php`; Test `pegged.php`.

**Interfaces:**
- Consumes: Task 5 constants.
- Produces on `Product_Pricing`: constants `META_USD_REGULAR`, `META_USD_SALE`, `META_SEEN_REGULAR`, `META_SEEN_SALE`; `modes()` → `array( 'managed', 'excluded' )`; `get_mode( $product )` → `'excluded'` when stored `excluded` or `native`, else `'managed'` (amendment A1); `get_snapshot( $product )` → array with the existing non-pricing keys plus `mode`, `managed` (bool), `current_regular`, `current_sale` (strings from Woo, `''` when empty), `seen_regular`, `seen_sale`, `usd_regular`, `usd_sale` (strings), `rate` (float), `synced_at` (int), `conflict`, `last_error`; `save_state( $product_id, array $state )` writing `usd_regular`, `usd_sale`, `seen_regular`, `seen_sale`, `rate`, `synced_at` inside `without_hooks`. Removed: `get_source`, `validate_source_pair`, `set_source`, `source_error_message`, `get_revision`, `bump_revision`, `import_current_price_as_source`, `ingest_woocommerce_prices`, the revision hooks (`on_meta_written`, `on_meta_deleted`), `on_admin_process_product`, `on_admin_process_variation`, `MODE_NATIVE`, and the removed meta constants.
- On `Product_Repository`: the `mode` scope matches `excluded` by `_usdtf_mode = excluded` or `native`, and `managed` by `NOT EXISTS` or `= managed`; `only_outdated` matches products with no `_usdtf_usd_regular`, or `_usdtf_rate` different from the rate; `mode_counts()` returns `managed`, `excluded`, `products`, `variations`, `managed_variations` (no `native`, no `unset`).

- [ ] **Step 1: Write failing scenarios**: a product with no meta is managed; `save_state` then `get_snapshot` round-trips every field; `native` reads `excluded`; the `managed` scope contains the unset product and not the excluded one; `only_outdated` contains a product with no USD price and one with a different `_usdtf_rate`, and not one whose rate matches; `mode_counts()` has no `native`/`unset` keys.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Implement** the model and query changes; keep `on_before_delete`, `on_new_product` (sets nothing now) and the conflict/error helpers.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …` and phpcs; `grep -rn "get_source\|set_source\|META_SOURCE\|META_DERIVED\|META_REVISION\|META_MODE_SYNCED\|MODE_NATIVE" includes/class-product-pricing.php includes/class-product-repository.php`. Expected: PASS, no output.
- [ ] **Step 5: Commit** `feat: USD anchor model on products; unset products are managed`.

### Task 7: Recorder and sync runner

**Files:** Modify `includes/class-recorder.php`, `includes/class-sync-runner.php`; Test `pegged.php`.

**Interfaces:**
- Consumes: `Price_Planner::plan`, `Product_Pricing::get_snapshot/save_state`, `Settings::rounding_args`, `Store_Currency`.
- Produces: `Recorder::apply( $product, array $args )` with `$args` keys `dry_run` (bool), `rate` (float), `rounding` (array), `job_id` (int), `sync_parent` (bool); returns `array{status, code, message, old:{regular,sale}, new:{regular,sale}, usd:{regular,sale}, wrote:bool, parent_id:int, object_type:string}`. Status rule: any field `update` → `changed`; else any `generate` → `generated`; else any `rebase`/`clear` → `rebased`; else all fields `unchanged` → `unchanged`; a regular price with `invalid_price`/`too_low`/`no_rate`/`no_price` skips the whole product with that `code`. The computed sale above the regular price → `failed`, code `sale_above_regular`, nothing written. Just before saving, re-read the Woo prices with a fresh `wc_get_product`; if they differ from the snapshot → `conflict`, nothing written (`mark_conflict` when not a dry run). Excluded products return `skipped`, code `not_managed`. `Sync_Runner::create_job` returns `WP_Error( 'usdtf_unsupported_currency', …, array( 'status' => 400 ) )` when `! Store_Currency::is_supported()`; `Sync_Runner::job_fingerprint( $rate, array $rounding, array $scope )` (no currency mode); the runner counts the new statuses into the job's `generated`/`rebased` counters and stores `usd_regular`/`usd_sale` on the item row. Removed: `calculate_and_write`'s source/revision logic, `mark_mode_synced`, the forced full-scope on a mode change.

- [ ] **Step 1: Write failing scenarios** (currency `IRT`, rate `270000`, fixtures from Task 1, jobs through `usdtf_it_create_job`/`usdtf_it_run_job`):
  1. *first run only generates*: product `5000000`; Update (after a matching Preview) leaves the Woo price `5000000`, `_usdtf_usd_regular === '18.52'`, item status `generated`, job counter `generated === 1`, `changed === 0`.
  2. *second run unchanged*: Update again at the same rate: status `unchanged`, no price write.
  3. *rate change follows*: save rate `300000`, Preview then Update: Woo price `5556000`, status `changed`, `_usdtf_rate === '300000'`, `_usdtf_seen_regular === '5556000'`.
  4. *hand edit re-bases*: set Woo price `6000000`, Update: status `rebased`, `_usdtf_usd_regular === '20.00'`, Woo price still `6000000`.
  5. *sale price*: product `5000000`/sale `4000000` generates both; after the rate change both follow and the sale stays below the regular; an ended sale (sale cleared in Woo) gives `rebased` and an empty `_usdtf_usd_sale`; scheduled-sale dates survive.
  6. *variable product*: variations are processed by the same rules and the parent range is recalculated.
  7. *skips*: excluded product `not_managed`; price `0` `invalid_price`; price `1` `too_low` and no meta written; none of them changes a Woo price.
  8. *conflict*: a filter on `woocommerce_product_object_updated_props` or a direct `update_post_meta` of `_regular_price` between planning and saving yields `conflict` and the Woo price keeps the edited value (use a test hook in the Recorder: `do_action( 'usdtf_before_price_write', $product_id )`).
  9. *Preview writes nothing*: a dry run changes no price and no plugin meta.
  10. *unsupported currency*: `usdtf_it_set_currency( 'USD' )` then `create_job` returns the `usdtf_unsupported_currency` error and nothing is written.
  11. *Woo decimals*: with `woocommerce_price_num_decimals = 2`, rate `25` and price `12.50` the first and second run give `generated` (`_usdtf_usd_regular === '0.50'`) then `unchanged`, and a rate of `30` writes `15.00`.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Implement** `Recorder::apply` (plan regular and sale independently, combine per the status rule, validate, conflict-check, write only the Woo fields whose action is `update`, then `save_state`) and the `Sync_Runner` changes. The `usdtf_before_price_write` action fires once per product just before the re-read.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …` and phpcs; `grep -rn "currency_mode\|expected_revision\|source_regular\|toman_regular" includes/class-recorder.php includes/class-sync-runner.php`. Expected: PASS, no output.
- [ ] **Step 5: Commit** `feat: pegged pricing in the recorder and sync runner`.

### Task 8: Rate impact estimate and rollback

**Files:** Modify `includes/class-rate-repository.php` (the impact estimate loop around line 430 and the rollback path); Test `pegged.php`.

**Interfaces:**
- Consumes: `Price_Planner::plan`, `Product_Pricing::get_snapshot`.
- Produces: the impact estimate counts a product as affected when its `regular` or `sale` plan is `update`, `generate`, `rebase` or `clear` at the candidate rate (same rounding args as a job); rollback restores `previous_rate` and recalculates `USD × previous_rate` through the normal job path (type `rollback`), skipping products whose Woo price differs from `seen`.

- [ ] **Step 1: Write failing scenarios**: after Task 7 scenario 3 (rate `300000`), the estimate for rate `300000` is `0` affected for products already synced and `>0` for rate `330000`; rollback to `270000` restores the original price `5000000` within the rounding increment for the generated product; a product hand-edited after the update is reported skipped by rollback and keeps its edit; the typo guard still requires confirmation for a move above the threshold and not for one below (existing behaviour, asserted again).
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Implement** the estimate and rollback changes with the signatures above (no new public methods).
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …`, phpcs, `grep -rn "from_toman\|source_regular\|derived_regular" includes/class-rate-repository.php`. Expected: PASS, no output.
- [ ] **Step 5: Commit** `feat: impact estimate and rollback for the pegged model`.

### Task 9: Admin screens, REST and CSV

**Files:** Modify `includes/admin/class-rest-controller.php`, `class-admin.php`, `class-product-panel.php`, `views/{dashboard,jobs,settings,product-panel,page}.php`, `includes/class-health.php`, `assets/js/admin.js`, `assets/css/admin.css`; Test `pegged.php`.

**Interfaces:**
- Consumes: Tasks 5 to 8.
- Produces: REST item payload keys `usd_regular`, `usd_sale` (replacing `toman_regular`, `toman_sale`), `old_regular`, `old_sale`, `new_regular`, `new_sale`, `status`; job payload counters `generated`, `rebased`; settings/state payload `store_currency` = `array{code:string, unit:string, label:string, supported:bool}`; the rate field label is `Toman per 1 USD` or `Rial per 1 USD` from `Store_Currency::unit_label()`; CSV header `product_id,parent_id,object_type,status,usd_regular,usd_sale,old_price,new_price,attempts,message`; the Settings screen shows the read-only row `Store currency` with a link to WooCommerce → Settings → General and no currency-mode radios; the product panel offers only Managed/Excluded and shows read-only Woo price, USD price, rate used and last sync; an admin notice `Set the store currency to Iranian Toman or Rial` when unsupported; Diagnostics shows the detected currency.

- [ ] **Step 1: Write failing scenarios**: the state endpoint returns `store_currency` with `unit === 'toman'` for `IRT` and `supported === false` for `USD`; an item payload has `usd_regular` and no `toman_regular`; the CSV header equals the string above; the rendered Settings view contains `Store currency`, no `currency_mode` input, and contains the Rial label when the currency is `IRR`; the product-panel save refuses any mode other than `managed`/`excluded` and accepts both; the unsupported-currency notice renders for `USD`.
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Implement** the payload, view, JS (status tabs: All, Updated, USD set, Re-based, Unchanged, Skipped, Failed, Conflicts; columns Product, Status, Current Woo price, USD price, New Woo price, Details), CSS and notice changes.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …`, phpcs; `grep -rn "toman_regular\|toman_sale\|currency_mode\|format_toman\|persian_digits\|reference_regular" includes assets`. Expected: PASS, no output.
- [ ] **Step 5: Commit** `feat: pegged pricing screens, REST payloads and CSV`.

### Task 10: Remove obsolete calculator code and refresh translations

**Files:** Modify `includes/class-calculator.php` (remove `from_toman`, `from_usd`, `parse_toman`, `format_toman`, `to_persian_digits`), `languages/*.pot|*-fa_IR.po|*.mo`; Test: the Translations check and a `pegged.php` scenario. (Translations come before the test port because the legacy Persian-catalogue scenario compares the template with the catalogue and the suite stops at the first failure.)

- [ ] **Step 1: Write a failing scenario**: none of the five removed `Calculator` methods exists (`method_exists` false), and the Persian catalogue (`languages/…-fa_IR.mo` loaded with `load_textdomain`) translates the new rate-field label `Toman per 1 USD` (Persian text from the catalogue, not the English).
- [ ] **Step 2: Run** `USDTF_IT_ONLY=pegged …`. Expected: FAIL.
- [ ] **Step 3: Remove** the five methods; run `php bin/make-pot.php`; write the Persian entry for every new or changed string in `…-fa_IR.po` (formal Persian in the style of the existing catalogue, "تومان"/"ریال", "پیش‌نمایش"), delete entries the template dropped, then `php bin/make-mo.php`.
- [ ] **Step 4: Run** `USDTF_IT_ONLY=pegged …`, phpcs, `php bin/make-pot.php --check && php bin/make-mo.php --check`; `grep -rn "from_toman\|parse_toman\|format_toman\|to_persian_digits" includes`. Expected: PASS, no output.
- [ ] **Step 5: Commit** `refactor: drop the old Toman helpers; refresh the translations`.

### Task 11: Port the legacy integration suite

**Files:** Modify `tests/integration/{lib,run,extra,admin,ui,audit,regression,crossrequest,http,http-autonomous,prepare,worker}.php`.

Port by keeping every scenario's **name and intent** and changing only the model it exercises. Rules: `usdtf_it_make_simple_product( $name, $regular, … )` now creates the product through `usdtf_it_make_priced_product` (Woo price = `$regular`, store currency `IRT`); the first sync of a fixture generates its USD price, so scenarios that asserted a USD Woo price after one sync now run two syncs (generate, then a changed rate) and assert the Woo Toman price. Scenarios whose subject no longer exists are removed and replaced by the matching new scenario, listed here:

| Legacy scenario | Action |
|---|---|
| `Toman transaction mode stores and charges…`, `switching the transaction currency back rewrites…`, `the storefront shows Toman for managed products only`, `a fresh install transacts in Toman and an upgrade keeps the stored mode` | Remove (Tasks 7, 10 and 5 cover the replacements). |
| `an existing Toman catalog can be onboarded without data loss`, `the worker never rewrites the canonical Toman source meta`, `a zero source price is refused at write time`, `a scheduled sale that ended must not feed the Toman range` | Replace by Task 7 scenarios 1, 4, 7, 5. |
| `invalid, native and excluded products are skipped safely`, `uninstall keeps the Toman prices unless the store owner opts in`, `the bundled Persian catalogue covers the template and loads` | Port (managed/excluded only; uninstall keeps WooCommerce prices; catalogue count equals the template). |
| every other legacy scenario (jobs, locks, scopes, retries, cron, REST permissions, HTTP and cross-request suites) | Port with the fixture rules above. |

- [ ] **Step 1: Run** `php tests/integration/run.php "$USDTF_WP"`. Expected: FAIL at the first legacy scenario.
- [ ] **Step 2: Port the files one at a time** in the order `lib`, `run`, `extra`, `admin`, `ui`, `audit`, `regression`, `crossrequest`, `http`, `http-autonomous`, running the full suite after each and fixing only the first failure.
- [ ] **Step 3: Verify** `php tests/integration/run.php "$USDTF_WP"` passes with no skipped scenario other than the by-design `requires USDTF_CONCURRENT` one, then run the HTTP suites exactly as CI does (`bash tests/integration/server.sh start "$USDTF_WP" tests/integration/router.php 8888` then `bash tests/integration/ci-run.sh "$USDTF_WP" local`; stop the server afterwards), and the concurrent variant with `start-concurrent` and `USDTF_CONCURRENT=1` if `socat` is available; otherwise rely on CI for it.
- [ ] **Step 4: Grep** `grep -rn "source_regular\|toman_regular\|currency_mode\|META_SOURCE\|display_toman\|format_toman" tests`. Expected: no output.
- [ ] **Step 5: Commit** `test: port the integration suite to the pegged model`.

### Task 12: Docs and version

**Files:** Modify `readme.txt`, `README.md`, `CHANGELOG.md`, `usd-to-toman-price-sync-for-woocommerce.php`, `uninstall.php`; Test: the version and archive checks.

- [ ] **Step 1: Update docs**: `readme.txt` (description, FAQ on the dollar-pegged behaviour and the Toman/Rial unit, upgrade notice, changelog `= 1.2.0 =`), `README.md` (feature description, remove the old two-mode text), `CHANGELOG.md` section `## [1.2.0] - <date>`; if any text you wrote is a new translatable string, add its Persian entry and rebuild `.mo`.
- [ ] **Step 2: Raise the version** to `1.2.0` in the plugin header, `USDTF_VERSION`, the `uninstall.php` fallback and the readme `Stable tag`.
- [ ] **Step 3: Verify** `php bin/check-versions.php && php bin/make-pot.php --check && php bin/make-mo.php --check`, build and verify the archive (`php bin/build-dist.php --output=dist --quiet && php bin/verify-archive.php --zip=dist/usd-to-toman-price-sync-for-woocommerce.1.2.0.zip --version=1.2.0`; remove `dist/` and `vendor/` afterwards). Expected: all `ok`.
- [ ] **Step 4: Commit** `docs: readme and version 1.2.0 for pegged pricing`.

### Task 13: Final verification and hand-over

- [ ] **Step 1: Run the whole local gate**: full suite, HTTP suites, phpcs, lint, version/pot/mo checks (commands in Environment). Expected: all green.
- [ ] **Step 2: Manual check on the local WordPress** (`$USDTF_WP`, currency `IRT`, then `IRR`): create a product at `21879000`, set rate `270000`, Preview, Update (status Generated, price unchanged), change the rate to `300000`, Preview, Update (price follows), edit the price by hand and Update (Re-based), Rollback. Record the observed numbers in the PR description.
- [ ] **Step 3: Browser check of the admin screens** (the JS is not covered by the suite): start the server (`bash tests/integration/server.sh start "$USDTF_WP" tests/integration/router.php 8888`), log in as the admin user created by `setup.sh`, open the plugin screen with Playwright using `executablePath: '/opt/pw-browsers/chromium'`, run a Preview, and read a screenshot of the rate screen, the preview table and a product panel. Expected: columns and tabs as in Task 9, no JavaScript console errors, the Rial label under `IRR`. Stop the server afterwards.
- [ ] **Step 4: Push** the branch, wait for CI (all 11 checks) and fix any failure at its root cause.
- [ ] **Step 5: Mark PR #17 ready**, retitle it `feat: USD-pegged pricing (1.2.0)`, and ask the owner to merge. The merge publishes 1.2.0 through Auto release; check that run and Verify release.
