# Full Code Audit & Prioritized Fix Plan — 2026-10-12

**Repository:** [hamidnoshady/woocammerce-usd-to-toman](https://github.com/hamidnoshady/woocammerce-usd-to-toman)  
**Reviewed ref:** `main` at `4b90df4fa41fe551686b8c82d2e7cfc71323460a`; plugin header version `1.2.1`  
**Audit basis:** connected GitHub source review across pricing, rates, jobs, locks, cron, scheduler, product discovery, admin/REST, PHP/JS, tests, build/release workflow; and the user-supplied WordPress Plugin Check report dated **2026-10-12 06:44:53**.  
**Verification level:** Static code/path review, not a freshly executed WordPress/WooCommerce runtime or penetration test. **Confirmed logic defect** means the problematic control flow is visible in source; the associated outcome still needs an integration regression test. **Risk** means a plausible edge case requiring proof. **Scanner** means independently flagged in the attached Plugin Check output.  
**CI snapshot:** Most recent visible CI on 2026-10-12 at 06:19 UTC was **green** ([run 38211930642](https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38211930642)); green CI is not evidence that the cases below are covered.

> **Architecture note:** Current `1.2.1` intentionally uses a **USD-pegged** model, not the older USD-store mode. WooCommerce catalog prices stay in **Toman (IRT)** or **Rial (IRR)**. The plugin stores two-decimal USD anchors and derives later WooCommerce prices using the saved rate and rounding settings. Preserve that contract when fixing bugs. Do not accidentally switch the store to USD or treat hidden old Toman meta as the live source.

## Executive priority index

| ID | Priority | Status | Area | Finding |
| --- | --- | --- | --- | --- |
| F01 | **P0 / release blocker** | Confirmed logic defect | Cron/worker | Stale `running` jobs cannot resume through recovery |
| F02 | **P1** | Confirmed logic defect | Rollback | Previous rate is persisted before rollback job eligibility/queue succeeds |
| F03 | **P1** | Confirmed missing guard | Pricing safety | Already-running job can continue across an IRT/IRR unit change |
| F04 | **P1** | Risk needing concurrency test | Queue | Job-owned lock does not independently claim individual item rows |
| F05 | **P1** | Confirmed pagination fragility | Discovery | Offset pagination skips/duplicates items when catalog changes |
| F06 | **P1** | Confirmed logic defect | Job lifecycle | Cancel accepts completed or previously cancelled jobs |
| F07 | **P2** | Confirmed limit | Conflicts | Recalculate-conflicts handles at most 500 items per request |
| F08 | **P2** | Confirmed logic defect | Retry/requeue | API can report retry success although job was not requeued |
| F09 | **P2** | Confirmed retention bug | Data | Retention deletes items from paused/active jobs |
| F10 | **P2** | Conditional logic defect | Currency switch | Saving an identical numerical rate cannot rebind its unit |
| F11 | **P2** | Confirmed implementation | Queue health | Loopback accepts HTTP error responses as successful dispatch |
| F12 | **P2** | Scanner + deployment risk | Worker | Unbounded `set_time_limit(0)` is used in public worker flow |
| F13 | **P2** | Scanner | SQL/WP.org compliance | Two Plugin Check SQL errors and ten warnings remain |
| F14 | **P3** | Confirmed implementation | Logging | Cron recovery emits unconditional `error_log` noise |
| F15 | **P3** | Hardening | Admin/export | CSV formula escaping and variation-specific authorization |
| F16 | **P3** | Coverage gap | CI/release | Plugin Check is not a dedicated CI gate; compatibility matrix needs review |
| INC-01–04 | **P1 (incident)** | Observed stalled preview + confirmed missing watchdog | Runtime/UI | 160/2,533 (6%) frozen for 20 min; investigate worker and recovery |

## Detailed findings, fixes and regression criteria

### F01 — P0 — Stale worker auto-recovery is broken

**Evidence:** `includes/class-sync-runner.php:1934-1998` (`recover_stale_jobs`), `1568-1577` (`resume`), `1869-1902` (`resume_orphaned_jobs`); `includes/class-job.php:278-285`; `includes/class-cron.php:110-143`.

**Confirmed path:** `recover_stale_jobs()` loops over `STATUS_RUNNING` jobs classified as stale and calls `$this->resume($job_id)` after releasing the lock. But `resume()` accepts only `paused`, `failed` or `queued`; for `running` it returns `false`. Recovery still increments `$recovered`. Meanwhile `resume_orphaned_jobs()` explicitly skips stale jobs. Thus a stale running job can remain stranded, while diagnostics report a recovery attempt.

**Fix:** Implement an explicit, atomic stale-worker transition (`running -> paused/queued -> running` or a dedicated `resume_stale` path). Revalidate job status and lease ownership in the update condition, requeue the correct saved phase, and increment recovery counters only after a scheduled action exists. Do not start a second worker if the old worker is actually still live.

**Regression:** Start a real queued write job; stop its worker after an action is claimed; advance heartbeat and lease beyond expiry; run real maintenance; assert the same job ID resumes and **completes exactly once**, with no duplicate product writes. Exercise Action Scheduler and WP-Cron backends, and a concurrent healthy worker.

### F02 — P1 — Rollback changes saved rate even when scheduling fails

**Evidence:** `includes/class-sync-runner.php:1779-1828` saves previous rate *before* `create_job`; `177-247` can reject creation due to a live writer, missing preview/currency guard; `572-663` can fail worker enqueue.

**Confirmed path:** Start an ordinary write job, then invoke rollback. `rollback()` calls `rates->save_rate(previous)` first. `create_job(TYPE_ROLLBACK)` can then return `usdtf_job_running`. The stored global rate and history have nevertheless changed, without a rollback price update. The same mismatch is possible when queueing fails.

**Fix:** Check writer slot and prerequisites **before** persisting the rate, then use an atomic/compensating workflow for saving rate + creating and dispatching rollback. Prefer a transaction or staged rate linked to a job; if dispatch cannot start, preserve/restore the old active rate and mark any aborted history accurately. Make retries idempotent.

**Regression:** Rollback attempted during a live job or forced enqueue failure leaves current rate, rate unit, previous-rate pointer and history consistent; a successful rollback updates rate and prices once. Include an interrupted rollback and restart.

### F03 — P1 — Currency-unit guard is only checked when creating a job

**Evidence:** `includes/class-sync-runner.php:177-190, 676-750, 933-1015`; `includes/class-recorder.php:120-134`; `includes/class-store-currency.php:45-79`. Job rows store the unit in `includes/class-job-repository.php:112-150`.

**Confirmed missing guard:** A job can be queued while the store is IRT, then an administrator or currency plugin changes WooCommerce to IRR before a later batch. `Recorder::apply` tests only that the *current* currency is one of IRT/IRR, not that it matches the job's recorded unit or the rate's unit. A Toman rate applied to Rial prices risks a 10x misprice. The initial `unit_changed_error()` check is not enough for a background job.

**Fix:** Before **each** discovery/process/finalize step and before an actual product write, require `job.unit === Store_Currency::unit() === rates.rate_unit()` (and a coherent rate snapshot). On mismatch, pause safely with an actionable error; require explicit rate reconfirmation and a fresh preview/job. Never mix units inside one completed job.

**Regression:** Switch IRT→IRR and IRR→IRT between batches. Assert no subsequent price writes and a clear paused error. Confirm unsupported currency transitions behave safely too.

### F04 — P1 — Per-job locking is not a per-item atomic claim (concurrency risk)

**Evidence:** `includes/class-job-repository.php:526-548` returns `pending` **or** `processing` rows without setting a claim token; `includes/class-sync-runner.php:933-999` calls `next_items` then processes them; `includes/class-lock.php:195-254` permits any worker for the same job ID to renew the lease. `includes/class-scheduler.php:602-660` tries to claim queue actions, but there is no independent item-claim invariant.

**Risk:** If two worker callbacks for a single job overlap (Action Scheduler/native runner, loopback recovery, or retry timing), they may both read and write the same item. The product-level re-read checks **current prices** but do not atomically compare USD-anchor/seen-price state, so a duplicate step may double-count or rebase from a stale state.

**Fix:** Add an atomic per-item lease/claim (worker token, expiry and compare-and-set), or enforce truly exclusive execution for **all** phases of a job, including same-job duplicate workers. Make completion and counter changes idempotent, and distinguish a crashed claim from a live claim.

**Regression:** Force two simultaneous callbacks for the same job/batch over real HTTP and assert exactly-once item completion, counters, price changes and parent sync. Repeat after crashing one claimed worker.

### F05 — P1 — Mutable-catalog offset paging can omit products/variations

**Evidence:** `includes/class-product-repository.php:177-204, 312-378` uses `paged`/OFFSET ordered by ID; `includes/class-sync-runner.php:774-834, 867-928` increments page numbers across requests; `1212-1355` uses persisted numeric variation cursor.

**Confirmed design fragility:** A catalog mutation that removes an earlier row between pages shifts later results. Example: query page 1 (IDs 1–50), then delete ID 1; page 2 starts at the new offset 50 and can omit original ID 51. Changing scope membership/status/managed mode can produce the same effect. Child variations can likewise shift when added/deleted/reordered during a multi-slice parent run.

**Fix:** Prefer keyset pagination (`ID > last_seen_id` with fixed upper watermark/selection snapshot), persisted by product/variation stream. Make item insertion duplicate-safe with an index on (`job_id`, `object_id`, object type), and explicitly define behavior for mid-job catalog changes. If direct WP_Query keyset filtering is difficult, build a stable ID worklist at job creation.

**Regression:** During discovery and during variation slicing, add/delete/reclassify objects from earlier pages; verify every in-scope object from the defined snapshot is processed **once**. Test 1, 50, 501, 10,000 items.

### F06 — P1 — Completed jobs can be cancelled and their history overwritten

**Evidence:** `includes/class-sync-runner.php:1621-1659`: `cancel()` checks only existence; it never checks `is_active()` or allowed statuses. Public action at `includes/admin/class-rest-controller.php:814-860`.

**Confirmed path:** `POST /jobs/{completed_id}/cancel` overwrites a completed job as `cancelled`, updates `finished_at` and message, and may append another rate summary. Repeating cancel has the same effect. This corrupts audit history and misrepresents whether prices were updated.

**Fix:** Enforce a job state transition table. Only queued/running/paused are cancelable; finalized completed/failed/cancelled states are immutable except an explicit retry workflow. Use conditional DB update on the expected current status to prevent races.

**Regression:** Cancel completed and cancelled jobs returns `409`, leaves counters, timestamp and rate history untouched. A real running job cancels safely between items.

### F07 — P2 — Conflict recalculation truncates at 500

**Evidence:** `includes/class-sync-runner.php:1735-1775` calls `jobs->items(... limit => 500)` once and queues only those rows.

**Confirmed behavior:** With >500 conflicts, the remaining conflicts receive no retry in that operation, even though UI may say conflicts were queued again.

**Fix:** Requeue all conflicts in bounded SQL batches or use a set-based bulk status transition. Return requested/queued/remaining counts and surface partial progress. Avoid holding huge objects in PHP memory.

**Regression:** With 1,201 conflict rows, action eventually queues 1,201 exactly once (or explicitly paginates with honest remaining counts); counters stay accurate.

### F08 — P2 — Retry endpoints can report success after requeue failed

**Evidence:** `includes/class-sync-runner.php:1664-1705, 1708-1775`; `includes/admin/class-rest-controller.php:814-861`.

**Confirmed path:** `retry_failed()` and `recalculate_conflicts()` first reset rows to `pending`, then call `requeue_job()` but ignore its return. The REST controller interprets any positive reset count as success. `requeue_job()` also ignores whether `resume()` failed before enqueueing `HOOK_PROCESS` on an active but paused job.

**Fix:** Obtain job execution lease/eligibility first; perform state transition and scheduling as one recoverable operation. Return distinct `requeued`, `scheduled`, `blocked`/`remaining` results. When scheduling fails, retain a resumable explicit job state and return a clear error, never `Done`.

**Regression:** Force active-job lock contention and enqueue failure, call both routes, and verify the response represents **actual** job state; successful retries process all requested items.

### F09 — P2 — Retention purges pending work of old paused jobs

**Evidence:** `includes/class-job-repository.php:867-901` runs `DELETE ... WHERE updated_at < cutoff` across **all job items**, without checking parent job status. `includes/class-cron.php:146-157` invokes it daily.

**Confirmed path:** A paused job left untouched beyond the retention window loses its pending/processing item rows even though it remains resumable. Resuming may falsely finish with missing work.

**Fix:** Purge only items belonging to terminal jobs older than retention, using a bounded join/query and an index suited to retention. Preserve all items for queued/running/paused jobs. Document historical-detail expiry separately.

**Regression:** A paused job older than 30 days retains its rows; finished-job rows expire; repeated cron passes do not alter active counters.

### F10 — P2 — Identical rate cannot be re-saved to acknowledge a unit switch

**Evidence:** `includes/class-rate-repository.php:343-369` passes the stored rate into `Rate_Guard::assess` and returns early on `CODE_UNCHANGED`; `includes/class-rate-repository.php:170-188` saves the rate's unit only on `save_rate`.

**Conditional path:** After switching IRT↔IRR, jobs correctly refuse the old unit. If an administrator wishes to save the **same numeric rate** in the new unit, `submit_rate` returns “unchanged” without persisting the new unit, leaving the store blocked. Usually the numeric rate must also change by 10x, so this is an edge case, not a general inability to switch units.

**Fix:** Treat the unit as part of rate identity (`rate value, currency unit`). A unit change must require an explicit confirmation regardless of numerical equality and persist unit + history as a new entry; do not silently skip it.

**Regression:** Save rate N in IRT; switch to IRR; re-save N with acknowledgement; verify unit changes and a new preview can run. Test reverse transition.

### F11 — P2 — HTTP errors count as successful loopback dispatch

**Evidence:** `includes/class-scheduler.php:494-539`: `fire_loopback()` returns `! is_wp_error($result)`, without checking HTTP response code or response body.

**Confirmed behavior:** HTTP 403, 404, 429 or 500 produces an HTTP response, not necessarily `WP_Error`, and is treated as success. The fallback dispatcher is not poked immediately, which can delay a job when the token endpoint or web server fails.

**Fix:** Accept only an expected 2xx response/worker acknowledgement; log sanitized status + minimal context; dispatch fallback on bad status, with bounded retries/backoff. Distinguish “already claimed” from server failure.

**Regression:** Simulate HTTP 403/500 and valid 200; assert fallback, health indicator and no duplicate processing.

### F12 — P2 — Unbounded loopback execution time

**Evidence:** `includes/class-scheduler.php:585-598` uses `@set_time_limit(0)` in `handle_loopback()`. The attached Plugin Check report flags line 597.

**Risk:** Unlimited execution on shared hosting can consume a worker indefinitely if a WooCommerce save/hook blocks. The general worker budget is checked **between** products, not within a hanging product write. Removing the unlimited time limit without adjusting recovery could also create regressions.

**Fix:** Replace indefinite execution with a bounded worker limit compatible with `Settings::time_budget()`, protect expensive single-product calls with a watchdog/lease design, and handle host-enforced limits. Prefer small batches and a clear timeout recovery policy.

**Regression:** Slow product hooks, host 30-second limit, blocked HTTP worker and worker crash must yield a recoverable job, not an infinite request or double write.

### F13 — P2 — Resolve every Plugin Check diagnostic, without mislabeling scanner errors as SQL injection

The user-supplied Plugin Check report contains **12 entries: 2 ERROR + 10 WARNING**. Both ERROR findings use dynamic SQL text but appear to pass through `$wpdb->prepare()`; the column names are allowlisted and values/counters are cast. **No SQL injection exploit is established solely by those two scanner findings.** They still need refactoring/annotation and a passing official scanner run.

| File and source line | Plugin Check finding | Specific remediation |
| --- | --- | --- |
| `includes/class-job-repository.php:414` | **ERROR** `WordPress.DB.PreparedSQL.NotPrepared` for `$sets` | Build counter fragments from explicit allowlist + placeholders, document identifier safety, avoid unprepared-looking interpolation; assert affected counters |
| `includes/class-job-repository.php:789` | **ERROR** `WordPress.DB.PreparedSQL.NotPrepared` for dynamic `$sql` | Build separate allowlisted query variants with literal templates and `prepare` for status/limit/offset values |
| `includes/class-plugin.php:74` | Warning: `load_plugin_textdomain()` discouraged for WP.org-hosted plugin | Verify bundled fa_IR vs WP language-pack resolution; use just-in-time WordPress translations where possible without breaking bundled Persian |
| `includes/class-scheduler.php:597` | Warning: `set_time_limit()` | Address in F12 |
| `includes/class-lock.php:287` | Warning: direct DB query | Intentional CAS on options table; validate SQL/affected row count and explain the exception at the narrow call site |
| `includes/class-product-repository.php:203` | Warning: large `post__not_in` | Avoid unbounded excluded-ID lists or replace with indexed/keyset filtering |
| `includes/class-database.php:138,153,168,183,222` | Five warnings: unescaped SQL parameter | Document narrowly that helpers accept **prepared SQL only**, audit *all* call sites, preferably expose typed query helpers or accept template + args and prepare centrally. Never “fix” by escaping an entire SQL query with `esc_sql` |
| `includes/class-installer.php:452` | Warning: dynamic table name in DROP | Restrict to fixed plugin tables, use safe identifier formatting/allowlist, retain documented uninstall behavior |

**Scanner acceptance:** Run official WordPress Plugin Check on a freshly built distributable ZIP using current target WordPress/WooCommerce; record exact tool versions, publish results as CI artifact, and clear all ERROR findings. For deliberate exceptions, document justification and do not suppress genuine unsafe SQL.

### F14 — P3 — Unconditional debug output on every orphan scan

**Evidence:** `includes/class-sync-runner.php:1893` writes an unconditional `error_log('usdtf resume_orphaned check ...')` for each candidate running/queued job during regular cron maintenance.

**Fix:** Route diagnostic tracing through the plugin's own logger at debug level, controlled by settings/WP_DEBUG; cap per-job verbosity and prevent production PHP error-log flooding. Keep important recovery errors visible.

**Regression:** Routine cron pass without orphan yields no PHP error-log line; an actual recovery failure is recorded once with useful context.

### F15 — P3 — Admin and CSV defensive hardening

**Evidence:** `includes/admin/class-admin.php:691-804` exports free-text notes/messages directly with `fputcsv`; `includes/admin/class-product-panel.php:201-225` variation save checks the plugin-wide capability and nonce but lacks the same object-specific `edit_product(variation_id)` guard used at `151-193`.

**Risk/condition:** A spreadsheet may interpret a note/error message beginning with `=`, `+`, `-`, `@` or control characters as a formula when CSV is opened. Plugin-wide admin permissions may be intentionally sufficient, but custom product editing restrictions should be honored consistently.

**Fix:** Apply spreadsheet-safe export neutralization to any nonnumeric free-text CSV cell, verify formula-exploit fixtures in Excel/LibreOffice, and check object-level authorization for every variation save. Do not alter raw stored text; sanitize only exported spreadsheet representation.

### F16 — P3 — Coverage and release hygiene

**Evidence:** `.github/workflows/ci.yml` runs PHPCS, translation checks, distribution validation and substantial integration suites, but no dedicated official Plugin Check gate is visible. `usd-to-toman-price-sync-for-woocommerce.php:7-18` advertises WooCommerce tested up to 9.9; check current compatibility instead of assuming support for newer releases.

**Fixes:**
1. Add a reproducible Plugin Check CI job and attach HTML/JSON/text report to PR builds.
2. Add the F01–F11 targeted regression scenarios to integration tests; include **fault injection and two concurrent real HTTP workers**, not just sequential PHP calls.
3. Test native WordPress currency handling (IRT/IRR registration), WC product CRUD, sale scheduling, variable-parent cache, HPOS compatibility, cross-request recovery, and real build ZIP—not just repository source.
4. Test WordPress 6.2 minimum and current stable; PHP 7.4 through 8.4/8.5 where available; current WooCommerce with explicit compatibility results. Upgrade `WC tested up to` only after verified.
5. Check rollback, download, and upgrade paths from v1.1.x and v1.2.1 with a store backup.
6. Treat docs-only changes as **non-release**: the repository auto-releases on pushes to `main`; review whether release automation should skip a docs-only change rather than bumping a patch version.

## Scanner-vs-security distinction

- **Not proven exploitable:** The `$sets` and `$sql` scanner errors are not proof of SQL injection: `$sets` is built from hardcoded counter allowlist and cast integers; `items()` uses `prepare` for values. Refactor so scanner can see safety instead of merely suppressing rules.
- **Worth proving:** SQL helper calls in `class-database.php` accept finished query strings and therefore cannot enforce prepared values themselves; scan all callers and add central contracts/tests.
- **Existing good controls found:** REST management routes have a central capability callback; product edit flow uses nonce validation; background test REST routes are behind `USDTF_ENABLE_TEST_ROUTES`; WooCommerce CRUD is used for price writes; preview and lock structures are present. Preserve these safeguards.

## Required regression test matrix

| Scenario | Expected invariant | Primary IDs |
| --- | --- | --- |
| Worker exits after queue claim and lease expires | Same job resumes once; no stale `running` forever; counts correct | F01, F04 |
| Rollback requested while writer active | 409; **rate and rate history unchanged** | F02 |
| Rollback enqueue failure | Recoverable state; rate and prices never diverge silently | F02, F08 |
| Switch IRT↔IRR while batch is running | Remaining writes stop; explicit unit mismatch | F03 |
| Concurrent same-job workers | No duplicate item writes or counter increments | F04 |
| Delete ID on preceding product discovery page | No skipped eligible product | F05 |
| Add/delete variation between slices | Defined snapshot semantics; no silent skip | F05 |
| Cancel completed job twice | 409; immutable terminal history | F06 |
| 1,201 conflict items | All can be requeued; honest counts | F07 |
| Retry with lock contention | API does not report success without scheduled retry | F08 |
| Paused job older than retention | Pending items intact | F09 |
| Re-save identical numeric rate in new currency unit | New unit recorded after confirmation | F10 |
| 403/500 from worker loopback | HTTP failure recognized; native queue fallback | F11 |
| Host kills slow/hanging worker | No infinite resource consumption; controlled recovery | F01, F12 |
| Official Plugin Check against build ZIP | Zero errors, warnings triaged and documented | F13, F16 |
| CSV exported with formula-leading text | Treated as literal text, not executable formula | F15 |

## Recommended implementation order

**Stage A — correctness blockers:** F01 → F02 → F03 → F06; then deploy to a staging WooCommerce catalog and prove every failure state. No production release until those regression tests pass.

**Stage B — high-volume resilience:** F04 → F05 → F07 → F08 → F09. Use fault injection, ≥1,000 objects and parallel requests. Verify exact totals and no duplicates.

**Stage C — compatibility & operational quality:** F10 → F11 → F12 → F13 → F14 → F15 → F16. Preserve small batches, logging, translations, the Toman/Rial contract and automatic rollback eligibility.

**Release acceptance:** all P0/P1 defects addressed; P2s closed or explicitly accepted with rationale; every Plugin Check ERROR removed; target WP/Woo/PHP matrix green; production-like dry-run + full update + partial failure + retry + rollback verified from the **built ZIP**; documented store backup and rollback procedure. Avoid asserting “fully secure/bug-free” from static review or passing CI.

---

*Prepared as a fix specification only. No production plugin code or WooCommerce prices were modified as part of this review.*


## Production incident addendum — 2026-10-12 — Dry-run stuck at 6% (P1 to investigate)

**Evidence received:** User-provided screenshot of the Persian admin screen plus reported observation: preview was unchanged at **160 / 2,533 products (~6%) for approximately 20 minutes**, at **260,000 Toman per USD**. The screenshot's current product was a Danish Design watch, item/product label beginning **#2885**. The counters showed **USD set: 160**, and **Updated: 0, Re-based: 0, Unchanged: 0, Skipped: 0, Failed: 0, Conflicts: 0, Variations: 0**. The job was labelled **Running / Preview / Dry Run**.

**Impact:** Admin sees no way to distinguish genuinely slow processing, an unreachable worker, a stale job, or a UI polling problem. A preview is expected to be read-only for WooCommerce prices and stock; the screenshot does **not** establish that stock quantities or prices changed. Do not convert the preview result into an update while the job is stalled. This is an **observed UI symptom** but **not a runtime-proven cause**: production server logs, the job's `heartbeat_at`, queue state and job ID were not available in the screenshot.

### Related code evidence and plausible root causes

1. **Likely high-impact cause — broken stale-worker recovery (F01):** `includes/class-sync-runner.php:1934-1998` sends a stale `running` job to `resume()`, which rejects `running` at lines 1568-1577; recovery increments its count regardless. This affects previews too. `resume_orphaned_jobs()` (1869-1933) excludes stale jobs. This is a confirmed code defect, though it is **not proven to be the cause of this specific server incident**.
2. **Missing UI stalled-state detection:** `assets/js/admin.js:421-468` continues polling `/jobs/{id}/status` every 2.5 seconds whenever `job.is_active` is true, without comparing `heartbeat_at`, `processed`, `updated_at` and previous responses over time. Its catch branch silently retries after 5 seconds. `renderProgress()` (271-419) always renders “Currently processing” for an active job even when that item label and heartbeat are stale.
3. **Per-item progress granularity:** `includes/class-sync-runner.php:933-1049` recalculates aggregate counters only in `after_run()`; `process_item()` (1153-1212) updates `current_item` before calling WooCommerce product/variation logic. One extremely slow product, a stuck `wc_get_product`, plugin hook or query, or a crashed request could leave the same product name and 160-count displayed. A fixed product name **alone** does not prove that product is faulty.
4. **Queue dependency:** `includes/class-scheduler.php:111-223, 494-539, 563-660` relies on Action Scheduler/cron and a token-protected loopback. A host that blocks loopbacks or has stopped cron runners can leave jobs waiting. `fire_loopback()` treats HTTP 403/500 as success (F11), so fallback may be delayed.
5. **Potential scheduling/recovery phase omission:** `resume_orphaned_jobs()` switches only on `PHASE_DISCOVER` and `PHASE_FINALIZE`; `PHASE_DISCOVER_VARIATIONS` falls through to `HOOK_PROCESS`. `handle_batch()` handles discovering phases by re-queueing `HOOK_DISCOVER`, so it may recover indirectly but should schedule the appropriate hook directly and prove behavior in tests.

### Immediate triage on the affected WordPress installation

- Record the **job ID** from **View details**, the exact `status`, `type`, `phase`, `processed`, `total`, `heartbeat_at`, `updated_at`, `current_item` and `message` from authenticated `GET /wp-json/usdtf/v1/jobs/{id}/status`; take two samples 60 seconds apart. **Do not share REST nonces or worker tokens.**
- Compare browser Network requests for `/jobs/{id}/status` (200/403/404/5xx, status body), WordPress/PHP server error logs, WooCommerce **Status → Scheduled Actions** filtered by `usdtf`, and cron maintenance execution. Note whether `DISABLE_WP_CRON` is enabled without a real system cron and whether loopbacks are blocked.
- Inspect the current item's product type and variation count, plus related extensions/hooks. A slow third-party integration or a variable product with many children may block one worker.
- For this **preview only**, use **Pause**, then **Resume** from Jobs if the job is no longer actually running; if it still hangs, **Cancel** and try a new preview on a small selected scope after collecting logs. Do not run an actual price update or switch WooCommerce currency as a troubleshooting tactic.
- Avoid direct deletion of job table rows, options, queued actions or locks on a production site. If needed, snapshot database/plugin tables first.

### Required fixes for the observed symptom

**INC-01 / P0 cross-reference F01:** Implement correct atomic stale-job state recovery for preview and write jobs; count recovery only on a successful requeue. Make restart respect the saved phase and avoid duplicate workers.

**INC-02 / P1 — UI watchdog:** Add `last_heartbeat_age_seconds`, `last_progress_at` or equivalent server-derived fields; display **Stalled / Worker unavailable** once heartbeat exceeds a documented threshold, with elapsed stall time. Preserve separate statuses for **Discovering**, **Processing**, **Waiting for retry**, **Paused**, and **Completed**. If only the count freezes but heartbeat is fresh, display **Long-running item** instead of falsely declaring the worker dead.

**INC-03 / P1 — diagnostic visibility:** Surface queue backend, next pending action due time, last worker dispatch/HTTP status, last successful heartbeat, and item ID in a restricted “View details / Health” diagnostic section. Show poll request errors instead of silently swallowing them. Never display shared tokens, secrets or private product data to unauthorized users.

**INC-04 / P1 — worker resilience:** Enforce a bounded product execution budget where practical, isolate a pathological product without stopping the remaining preview, record skip/fail reason and allow safe retry for that product. Treat a crashed request as orphaned work and restore it automatically with exactly-once item semantics.

### Incident-specific acceptance tests

1. A preview of **2,533** products completes on a representative shared-hosting configuration, with progress increments and accurate counts, or explicitly enters a recoverable failure state; no indefinite silent `running` state.
2. Simulate worker termination immediately after 160 products; after heartbeat + lease expiry, maintenance requeues and completes the **same job ID** without administrator reload or a duplicate item.
3. Stop Action Scheduler or block loopback; UI reports missing worker/queue health and a safe retry/diagnostic path, not a frozen percentage forever.
4. Stall processing of a particular product (e.g., #2885 only in the test fixture), then exercise timeout/skip behavior and verify the remaining products finish; no hardcoded special case for the real product.
5. Disconnect browser/network while workers continue: progress resumes rendering on reconnect, and if the worker stays alive, the UI does not label the job as dead.
6. Assert that **preview creates zero WooCommerce price writes and zero stock-quantity writes**, even after retries, interruptions, or cancellation.

**Status:** Report amended from observed screenshot + source investigation. **Production cause remains unconfirmed pending live job state and logs.**
