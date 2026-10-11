# USD-pegged pricing: design

Status: **implemented in 1.2.0** · Date: 2026-10-11 · Target version: 1.2.0

## 1. Summary

Today the plugin keeps its own canonical Toman price per product (entered by hand, or imported) and
derives a USD price from it. Most products in a real store have no such price stored, so a preview
reports them as "Toman price not yet stored" and skips them.

This change replaces that model. The plugin no longer stores or asks for a Toman/Rial base price:

1. It **reads the base price from the WooCommerce price fields**, which are already in the store
   currency (Toman or Rial).
2. It **generates a USD price** per product from that price and the rate the store owner entered.
3. From then on the **USD price is the anchor**. When the rate changes, the plugin recalculates the
   main WooCommerce price: `new Woo price = USD price × new rate`. The store is dollar-pegged.
4. The currency unit (**Toman or Rial**) comes from the WooCommerce currency setting. The plugin only
   shows it; it cannot be changed in the plugin.

## 2. Decisions already made with the store owner

| # | Question | Decision |
|---|---|---|
| D1 | After the first USD price exists, what does a rate change do? | The USD price stays fixed and the **WooCommerce price follows the rate**. |
| D2 | A product's Woo price is edited by hand later | **Re-base**: recalculate that product's USD price from the edited price at the current rate. |
| D3 | The old "USD store" mode and the forced Toman currency | **Removed.** The plugin works only when the WooCommerce currency is Toman (`IRT`) or Rial (`IRR`). |
| D4 | First generation of USD prices | **Only records USD.** Woo prices are not changed until the rate changes. |
| D5 | Unit (Toman/Rial) | Read from the WooCommerce currency, shown read-only in Settings. |

## 3. Goals and non-goals

Goals: no separate base price to maintain; one source of truth per product (its USD price, once
generated); every price-changing run is previewable and reversible; works for simple products,
variations, sale prices and scheduled sales.

Non-goals: showing USD prices to customers; multi-currency checkout; fetching the rate from an API;
keeping the old modes working.

## 4. Data model

Versions 1.0.0 to 1.1.5 were released, so the upgrade carries a store over once (ruling R16, section
9): an old Toman-mode store keeps the storefront currency the old plugin forced at runtime, the inert
Toman source and derived prices are kept, and the rest of the obsolete data is removed.

### 4.1 Product and variation meta

| Meta key | Meaning |
|---|---|
| `_usdtf_usd_regular` | USD regular price (the anchor), string with 2 decimals. |
| `_usdtf_usd_sale` | USD sale price, same format, empty when there is no sale. |
| `_usdtf_rate` | Rate (store-unit per 1 USD) used at the product's last sync. Existing key, same meaning. |
| `_usdtf_seen_regular` | Woo regular price the plugin last wrote or accepted. Used to detect hand edits. |
| `_usdtf_seen_sale` | Same for the sale price. |
| `_usdtf_mode` | `managed` or `excluded`. A product with no value is **managed** (so an existing catalogue is picked up without per-product work); a stored `native` is treated as `excluded`. |
| `_usdtf_synced_at`, `_usdtf_last_error`, `_usdtf_last_job`, `_usdtf_conflict` | Unchanged. |

Removed: `_usdtf_mode_synced`, `_usdtf_external_source`, `_usdtf_revision`.

Kept, inert (never read): `_usdtf_source_regular`, `_usdtf_source_sale`, `_usdtf_derived_regular`,
`_usdtf_derived_sale`. For a store in the removed USD-store mode they are the only copy of its Toman
prices; only the opt-in "Delete data on uninstall" purge removes them.

### 4.2 Unit and rate

- `get_woocommerce_currency()` returning `IRT` means **Toman**, `IRR` means **Rial**. Any other code
  makes the plugin show an admin notice ("Set the store currency to Iranian Toman or Rial") and
  refuse to start jobs.
- The rate is entered in the store unit: "Toman per 1 USD" or "Rial per 1 USD". Labels follow the unit.
- The unit is stored with the rate (`usdtf_rate_unit`). While the store unit differs from it (the
  store moved between IRT and IRR after the rate was saved), every job is refused with
  `usdtf_unit_changed` and the dashboard says so next to the rate; saving the rate again in the new
  unit clears it, and a rate of the other unit is no previous rate for a rollback. Jobs record their
  unit (`jobs.unit`) and the unit is part of the dry-run fingerprint.
- Without WooCommerce loaded the currency is read from the `woocommerce_currency` option.
- The plugin does not touch the store currency, its position or its decimals, except once on the
  upgrade of an old Toman-mode store (section 9).

## 5. Per-product algorithm

Used identically by Preview (dry run) and Update. Regular and sale price are handled independently
by the same rules; the pair is validated afterwards (section 5.2).

Inputs: current Woo price `P` (regular or sale), stored USD `U`, stored `seen` value `S`, stored rate
`R_p`, job rate `R`.

```
if product excluded                        -> skipped   (reason shown)
if P missing, zero, negative or not a number -> skipped (reason shown)
if U is empty                              -> GENERATED: U = round2(P / R); seen = P; R_p = R; Woo price untouched
elif P != S (hand edit)                    -> REBASED:   U = round2(P / R); seen = P; R_p = R; Woo price untouched
elif R_p == R                              -> unchanged
else                                       -> UPDATED:   P' = round_store(U * R); write P'; seen = P'; R_p = R
```

- `round2` rounds to 2 decimals (half up). `round_store` uses the existing rounding mode
  (up / nearest / down) with the increment in the store unit (section 7).
- Woo price strings use `wc_get_price_decimals()` (0 for Toman and Rial by default).
- When the rounded new price equals the current Woo price, the product is unchanged: the rate is recorded and nothing is written.
- Comparisons `P != S` and `R_p == R` use the Woo decimals and 6 decimals respectively.
- A product with an empty sale price keeps an empty sale price. Scheduled-sale dates are never touched.

Worked example, rate 270,000: price 5,000,000 gives U = 18.52. First Update: status Generated, Woo
price stays 5,000,000. Rate becomes 300,000: P' = 18.52 × 300,000 = 5,556,000, rounded by the setting.
If someone then types 6,000,000 in Woo, the next run re-bases: U = 20.00 at the current rate.

### 5.2 Validation and conflicts

- If the computed sale price is greater than the regular price, the item is **failed** with the
  existing "sale above regular" message and nothing is written.
- Just before saving, the runner re-reads the Woo regular and sale price, past the request's meta
  and product caches. If they differ from the values used in the calculation, the item is a
  **conflict** (the existing status) and nothing is written. This replaces the old revision counter.
- Variable products: each variation is processed by these rules (existing slice mechanism); the
  parent price range is recalculated by WooCommerce, as today.
- Excluded products and variations are never changed.

## 6. Jobs engine

- **Item statuses:** add `generated` and `rebased` next to `changed` (shown as "Updated"),
  `unchanged`, `skipped`, `failed`, `conflict`. Job counters gain `generated` and `rebased`.
- **Items table:** `source_regular`, `source_sale`, `toman_regular`, `toman_sale` and
  `expected_revision` are replaced by `usd_regular` and `usd_sale`. `old_*` and `new_*` keep meaning
  "Woo price before / after". A database version bump migrates the table (section 9).
- **Rollback:** restoring the previous rate recalculates `U × previous rate` for managed products
  whose Woo price still equals `seen`. A product whose Woo price was edited by hand is re-based on its
  edit (decision D2) and keeps the edited price; it is listed as re-based.
- **Preview before Update, the 15 % rate-typo guard, the single active job lock, pause/resume/
  cancel, retries and the Action Scheduler worker are unchanged.**
- **Scopes** (everything, selected products, category, type, outdated only, changed since) are
  unchanged. "Outdated only" now means: no USD price yet, or `_usdtf_rate` differs from the rate.

## 7. Settings

Removed: `currency_mode`, `display_toman`, `display_toman_cart`, `persian_digits`, `toman_suffix`,
`decimals`, `auto_manage_new_products` (products are managed unless excluded), and the option
`usdtf_synced_currency_mode`.

Changed:
- **Store currency** (new, read-only row): shows "Toman (IRT)" or "Rial (IRR)" with a link to
  WooCommerce → Settings → General.
- **Rounding mode:** up / nearest / down (unchanged). Default stays *up*.
- **Rounding increment**, in the store unit: Toman 1, 100, 1,000, 10,000; Rial 10, 1,000, 10,000,
  100,000. Default 1,000 Toman / 10,000 Rial.

Unchanged: batch size, time budget, retry limit, retention, loopback
fallback, require preview, capability, rate-change threshold, credit line, delete data on uninstall.

## 8. Screens

- **Rate screen / dashboard:** rate input labelled with the unit; counters updated (Generated,
  Re-based, Updated, Unchanged, Skipped, Failed, Conflicts).
- **Preview and jobs table:** columns *Product · Status · Current Woo price · USD price · New Woo
  price · Details*, with filter tabs for each status. Prices on the plugin's screens use the WordPress
  locale number format (`number_format_i18n`) through `Calculator::format_price`; the plugin's
  Persian-digit option is removed.
- **Product panel:** pricing mode (Managed / Excluded) plus read-only *Woo price*, *USD price*,
  *Rate used* and *Last sync*. The reference price fields and the Toman/USD mode selector are gone.
  Bulk actions: set Managed / Excluded and "Recalculate selected".
- **Diagnostics:** the currency-mode lines are replaced by the detected store currency.
- **CSV export:** columns become `usd_regular`, `usd_sale`, `old_price`, `new_price`.
- **Removed code:** `includes/frontend/class-currency.php`, `includes/frontend/class-display.php`, the
  `Product_Pricing` revision/source/ingest logic and their hooks.

## 9. Upgrade and uninstall

- Installer bumps the database version (7) and, once: deletes the removed meta keys and settings
  keys, rewrites stored mode `native` to `excluded`, and extends the items and jobs tables (add
  `usd_*`, the `generated`/`rebased` counters and `jobs.unit`). The obsolete columns stay in place,
  unused: `dbDelta` never drops columns and the SQLite test driver differs from MySQL. Old job
  history rows keep their status and message.
- An old install (its settings still hold keys of the old model, or `usdtf_synced_currency_mode`
  exists) is carried over before the settings keys are dropped (ruling R16). In Toman mode (also when
  no mode was stored) the old plugin forced `woocommerce_currency` = `IRT`,
  `woocommerce_price_num_decimals` = `0` and `woocommerce_currency_pos` = `right_space` at runtime
  only; the upgrade writes those of the three that differ, once, and leaves a one-time notice. In
  USD-store mode no WooCommerce option changes; the notice says the mode was removed, that the store
  currency must be set to Toman or Rial and the prices reviewed, and that the old Toman prices are
  kept in hidden meta. The notice shows on every admin screen to users with the plugin capability
  until it is dismissed (nonce and capability checked). A fresh install changes nothing.
- The inert legacy meta of section 4.1 is kept. An existing rate is recorded as a Toman rate (the
  old plugin's rate was always Toman per 1 USD); a rate saved before the unit was stored adopts the
  store's current unit.
- `uninstall.php` lists the new meta keys. "Delete data on uninstall" behaves as today and also
  purges the inert legacy meta. The plugin never deletes WooCommerce prices on uninstall.

## 10. Safety

This is the first version in which the plugin rewrites the prices customers pay on every rate
change, so: Update stays blocked until a matching Preview exists (existing `require_preview`); the
rate-typo guard asks for confirmation on moves larger than the threshold; rollback exists (section
6); nothing is written for a product in conflict; and a failed or skipped product keeps its old price.

## 11. Testing

- Rewrite the integration scenarios (`tests/integration/*`) around the new model. Required cases:
  generate / unchanged / update / re-base; sale price and scheduled sale; variable products in
  slices; excluded and invalid prices; sale above regular; conflict on a mid-job edit; rate typo
  guard; rollback; Rial store (IRR) and unsupported currency notice; upgrade step from the old
  schema; REST permissions; uninstall.
- Rounding unit cases (up / nearest / down, each increment, both units).
- CI is unchanged: syntax matrix, coding standards, translations, release zip, both integration
  suites, then Release and Verify release.
- Manual check before release: a Toman store and a Rial store on the latest WordPress and WooCommerce.

## 12. Delivery

- Work lands in one feature PR in reviewable commits (model → engine → screens → removal of old code
  → tests → docs and translations). **Do not merge it before it is complete: every merge to `main`
  publishes a release.**
- The version is raised by hand to **1.2.0** in that PR (all four markers and the changelog), because
  this is a breaking change. The release workflow publishes an untagged version as it is.
- Update `readme.txt`, `README.md`, `CHANGELOG.md`, the Persian translation for every new or changed
  string, and the screenshots that show the old product panel.
- `.distignore` gains `/docs` so internal documents do not ship inside the plugin zip.

## 13. Risks

| Risk | Mitigation |
|---|---|
| A rate update rewrites live prices | Mandatory preview, typo guard, rollback, per-item conflict check. |
| Rounding drift between USD (2 decimals) and the store price | The first run never writes; later runs derive from the stored USD, never from the previous Woo price. |
| Hand-edit detection depends on equality | Compare at Woo's decimals; any difference re-bases, which is the safe direction. |
| Size of the change (most files, about half of the tests) | Phased commits; CI runs the full integration suites on every push. |
| Stores on a non-Toman/Rial currency | Clear notice, no writes. |

## 14. Open points for review

1. Default rounding increment (proposed 1,000 Toman / 10,000 Rial) and default mode *up*.
2. Version number 1.2.0 versus 2.0.0.
3. Removing the Persian-digit and Toman-suffix display options entirely (the storefront then uses
   WooCommerce's own price display).
