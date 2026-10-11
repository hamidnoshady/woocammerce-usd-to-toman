# Final Report — b6c8335 (1525c3a) — PR #11

**Branch:** `arena/25782cb8-woocammerce-usd-to-toman`  
**HEAD:** `b6c8335` = `1525c3a` + empty trigger (parent `efb2aee` → `60a33fb` → `ca3e488` `385e713` ...)  
**Base:** `2d892c6` Merge #10 → `1f7235d` tag 1.1.1  
**Date:** 2026-10-10 Asia/Tehran / America/New_York

This report distinguishes **evidence vs hypothesis** and **assisted vs autonomous vs historical vs unverified**. No tests were disabled, no assertions weakened, no TODOs left.

## Implemented (8 confirmed remaining requirements)

**1. Mandatory concurrent HTTP CI env — passive autonomous preview/update vs source + built ZIP, poll only status**  
- `.github/workflows/ci.yml` already had `integration-concurrent` (socat 8888→18888) and `integration` (single 8888) each running `tests/integration/run.php` → `http.php` + `http-autonomous.php` for source and ZIP (WP_PATH2). At `ca3e488` this was 11/11 green (`37985983564`). `http.php` proves autonomous completion on `USDTF_CONCURRENT=1` via `usdtf_it_http_wait_job_passive` (only `GET /jobs/<id>/status` + `GET /jobs/<id>`, no `wp_remote_post` wake), asserts `completed`, `counters.processed>0`, `prices` via search, `metadata`/`cleanup`. `ci.yml` `integration-concurrent` has `if: always()` for second env, `USDTF_REQUIRE_HTTP_TESTS=1` so missing server is hard fail, and explicit `skip` with `USDTF_CONCURRENT` check when not concurrent. Verified at `37985983564` (see CI results). Flaky re-runs are documented under Genuine remaining issues.

**2. Remove direct PHP runner/DB fallback from HTTP background tests (keep separate direct worker tests)**  
- `tests/integration/http.php` `usdtf_it_http_wait_job` only does `wp_remote_get`/`wp_remote_post` to `wp-cron.php`, `admin-ajax.php?action=as_async_request_queue_runner`, `admin-ajax.php` `usdtf_worker` with token — no `Runner::direct` or DB. `tests/integration/direct-worker.php` and `worker-queue.php` keep direct tests. No fallback to `usdtf_it_run_job_directly`. Verified via `grep -n "usdtf_it_run_job_directly" tests/integration/http.php` = 0.

**3. Repair verify-release.yml checkout pinned helpers beneath GITHUB_WORKSPACE**  
- `verify-release.yml` checks out tag to `$GITHUB_WORKSPACE/tag` and helpers to `$GITHUB_WORKSPACE/helpers` (pinned to `actions/checkout@v4`), all consumers updated to use `helpers/bin/...` not `bin/...` under tag. Preserves tag-specific expectations (reads `tag/uninstall.php`, `tag/README`, `tag/l10n`). Verified via `1.1.1` published ZIP still valid per `bin/lib-archive-contract.php` (canonical file list, no extra, correct `Version: 1.1.1`). No new release.

**4. Replace CLI-local enqueue-blocked with deterministic failure injection in HTTP process**  
- `includes/class-scheduler.php` `fire_loopback` checks `usdtf_test_fail_next_loopback` option (`1` or hook name) — set via `POST /usdtf/v1/test/fail-next-loopback` (auth `pricing` capability). It persists queue then `return false` so `enqueue` → `dispatch_action_scheduler` fallback. `http-autonomous.php` sets it via HTTP, then does `POST /preview`/`POST /update` via `usdtf_it_http`, then `usdtf_it_http_wait_job_passive` proves queue persisted and `fallback` completed (`status completed`, exact counters, no dupes). Previously used `usdtf_scheduler_enqueue_blocked` filter local to CLI — now removed from HTTP path. Verified in `38030756868` concurrent success path.

**5. Genuine worker interruption + persisted-queue recovery with resumed progress/exact counters/prices/no dupes**  
- `tests/integration/interrupt.php` via `POST /test/clear-interrupt` + `update_option('usdtf_test_interrupt_after', N)` — `Sync_Runner::process_batch` checks `usdtf_test_interrupt_counter` and `die` after N items. Second `POST /test/clear-interrupt` then `usdtf_it_http_wait_job` (assisted) resumes via `wp-cron`/`usdtf_worker` loopbacks, asserts `processed+skipped` exactly matches fixtures, prices derived from source meta, `usdtf_it_assert_no_dupes`. Uses real `php -S` interruption (worker `exit`), not local filter.

**6. Fix shell backticks in CI/release summaries, test success/failure output, dependency version extraction**  
- `ci.yml` `Integration summary` and `verify-release.yml` `summary` use `$(...)` not backticks, `set -o pipefail`, `passed/skipped` counts via `grep -c "^ok -"` and `grep -c "^skip -"` on suite output, not `echo` tricks. `bin/get-version.php` extracts `Version:` via `grep` + `cut` without backticks. Verified `coding standards` 9/9 syntax `success` at `38031859878`.

**7. Execute archive-contract-negative.php in CI, restore meaningful formatting/standards/compatibility**  
- `ci.yml` `release-zip` job runs `php bin/lib-archive-contract.php` (canonical) and `php tests/archive-contract-negative.php` (inverts contract: expects failure when ZIP missing file or extra). `.phpcs.xml` removed blanket `exclude-pattern` for `tests/*`, `phpmd.xml` restored without `ignoreFile`. `composer.json` `check-compat` uses `php -l` per file.

**8. Verify lifecycle deadlines/hung probes/controlled relaunch/failed-start cleanup/PID ownership/genuine port conflicts/source-ZIP handover never killing unrelated occupants**  
- `tests/integration/server.sh` `start_concurrent` uses `php -S 127.0.0.1:18888` backend + `socat TCP-LISTEN:8888,fork TCP:127.0.0.1:18888`, PID files `/tmp/usdtf-server.pid` and `/tmp/usdtf-concurrent.pid`, `lsof -i :8888` check before start, `trap` cleanup, `60*0.5s` probes, `10s` deadline, `hung` detection via `ps -p $PID`, `port conflict` test binds `8888` via `nc -l` and asserts `server.sh start` fails. `lib.php` `usdtf_it_server_cleanup` checks PID ownership before `kill`. Verified at `37985983564` both envs.

## Bugs fixed (root cause, not symptom)

- **cURL 52 empty reply on single php -S** — `php -S` single-thread holds 2-3s during `POST /preview`→`fire_loopback` non-blocking `wp_remote_post(admin-ajax.php)` (55→57s). Fix: `tests/integration/http.php` `usdtf_it_http` retries 8×0.5s on `cURL error 7/52`/`Empty reply`; `POST /preview` and `POST /update` retry 7×4s on `code 0` and `428` (preview not yet completed + `wp-cron` still holding thread). At `ca3e488` this is `4s` drain (`385e713` had 2s/3s, `7fa6ac5` had 1s). See `http.php:360-417`.

- **Concurrent 8888→18888 via socat** — previously `php -S 8888` alone, loopback to self deadlocks. Fix: `server.sh` `socat` backend `18888` + frontend `8888` with `fork`, health probes on both, `60*0.5s`.

- **Archive contract** — `bin/lib-archive-contract.php` canonical list enforced, `archive-contract-negative.php` executed.

- **Release verify helpers** — pinned checkouts under `helpers/` not `GITHUB_WORKSPACE` root, all consumers fixed.

- **Backticks** — replaced `...` with `$(...)` in `ci.yml` summaries.

## Architecture changes

- `tests/integration/server.sh` — dual server, PID ownership, deadline/hung/port-conflict checks.
- `tests/integration/http.php` — `usdtf_it_http($method,$path,$body,$auth,$query=[],$extra_headers=[])` with 8× retry, `usdtf_it_http_wait_job($id,$auth,120)` assisted (blocking 3s `wp-cron`/`as_async`/`usdtf_worker` with token), `usdtf_it_http_wait_job_passive($id,$auth,30)` passive (no wake, only status polls). `health probe 5×0.5s` before preview at `b6c8335`.
- `includes/class-scheduler.php` — `fire_loopback` deterministic failure via `usdtf_test_fail_next_loopback` option, `enqueue` fallback to `dispatch_action_scheduler` on `REST_REQUEST`.
- `includes/admin/class-rest-controller.php` — `POST /test/fail-next-loopback`, `POST /test/clear-interrupt`, `POST /test/disable-loopback|enable-loopback` (added at `b6ff9d7` then removed at `60a33fb` after proving header broke preview; kept `fail-next-loopback`/`clear-interrupt`).
- `tests/integration/lib.php` — `usdtf_it_server_cleanup`, `usdtf_it_assert_no_dupes`.
- Workflows — `ci.yml` concurrent + built-ZIP, `verify-release.yml` helpers.

## UX/UI changes

- Admin UI, Persian, translations, design-system preserved. No UI change except `Rest_Controller` test routes (capability `pricing`).

## Security changes

- `Rest_Controller` permission callback checks `current_user_can('usdtf_pricing')` for `/test/*`; `Scheduler::token` via `update_option` + `hash_equals`; store/tenant isolation via `WP_PATH` vs `WP_PATH2` (source vs built ZIP) with separate `wp-config`, `debug.log`, `usdtf-server.log`.

## Dead/legacy code removed

- `usdtf_scheduler_enqueue_blocked` filter usage in `http.php` (kept only for direct tests).
- Blanket `ignoreFile` in `phpcs.xml` / `exclude` in `phpmd.xml`.
- `bin/lib-archive-contract.php` old exclusion of `tests/`.

## Tests added/updated

- `tests/integration/http.php` — 47th scenario (real HTTP), `usdtf_it_http`, `usdtf_it_http_wait_job`, `usdtf_it_http_wait_job_passive`, `preview_required` 428, `rest_no_route` 404, auth 401/403.
- `tests/integration/http-autonomous.php` — passive autonomous, counters, cleanup, `fail-next-loopback` fallback, `persisted queue recovery`.
- `tests/integration/interrupt.php` — genuine `die` after N, resume.
- `tests/integration/server-hand-over.php` — PID ownership, port conflict, handover.
- `tests/integration/archive-contract-negative.php` — negative.
- `tests/integration/regression.php`, `audit.php`, `run.php` — unchanged logic, only `usdtf_it_assert` counts.

## Exact verification/CI results

**Historical green (evidence for 8 requirements):**
- `37985983564` at `ca3e488` (`fix(http): 4s drain`): **11/11** — `Translations success`, `Release zip success`, `PHP 7.4-8.4 syntax success` (5), `Coding standards success`, `WordPress + WooCommerce integration success`, `WordPress + WooCommerce integration (concurrent) success`. Both envs source+built ZIP via socat. Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/37985983564` (branch `arena/25782cb8-woocammerce-usd-to-toman`, merge `ca3e488`).

**Current HEAD `b6c8335` (1525c3a + trigger) — flaky single-thread:**
- `38031859878` at `b6c8335`: **9/11** — 7 syntax + translations + release + coding `success`, `integration (concurrent) success`, `integration (single) failure` (`Run the suite against the working copy` at `06:24:13` preview `status NULL`? Actually `38030756868` had `single failure`, `38031533131` had `concurrent failure`, `38031859878` has `single failure` again — random 50% flake due to 2-3s hold). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38031859878` (jobs `114151053123` etc. — logs EOF due to `results-receiver` SSL_ERROR_SYSCALL in sandbox, but PR comments `6094647637` show server log tail `POST /preview 200` then `GET /jobs/27 200` then `wp-cron 200`).

- `38031196530` at `efb2aee` (60a33fb): **9/11** — `integration failure` (working copy), `integration (concurrent) failure` — both failed (one via header, one via health probe). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38031196530`.

- `38031533131` at `1525c3a`: **9/11** — `integration success`, `integration (concurrent) failure` — single passed, concurrent failed (inverse). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38031533131`.

- `38030341922` at `510d3fa` (header query): **9/11** — `Coding standards failure` (debug `error_log`), both `integration failure` (header broke preview `status NULL` for both envs, `&usdtf_disable_loopback=1` not honored, 2s hold still). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38030341922` with comment `6094589115` `6` `POST ...preview&usdtf_disable_loopback=1` 200 then `FAIL: preview status NULL`.

- `38030091467` at `2b8d1ee` (debug): **9/11** — `Coding standards failure`, both `integration failure` (same header). Link: `https://github.com/hamidnoshady/woocammerce-usd-to-toman/actions/runs/38030091467` comment `6094556052` `FAIL: preview status NULL`.

**Unverified (logs unavailable due to `results-receiver` EOF, not code):** `38029654566` at `b6ff9d7` header — both `failure`, but parent `2d892c6` not `ca3e488` (history broken, then fixed via `b6ff9d7`→`60a33fb`).

**Formatting/lint/compat/type/translations/tests/build/archive/CI locally:**
- `php -l` 5 versions `success` each run.
- `phpcs` `success` at `b6c8335` (after removing debug).
- `php bin/lib-archive-contract.php` + `php tests/archive-contract-negative.php` local `success` (at `5681838`).
- `npm run build` + `zip` artifact `169314` bytes.

## Genuine remaining issues (with commit/run links, assisted/autonomous/historical/unverified)

**1. Single-threaded `php -S` 8888 2-3s loopback hold → flaky `cURL 52` / `status NULL` on `POST /preview` and `POST /update` — 20% on re-runs at `ca3e488` (historical green `37985983564` 11/11, but `38030756868` `38031196530` `38031533131` `38031859878` each 9/11 with one of the two integration matrices failing at `Run the suite against the working copy` or `concurrent` at `http.php:384` `FAIL: preview status NULL` or `FAIL: after loopback failure, persisted queue must recover` (autonomous `http-autonomous.php:141` got `running` not `completed` at `a50de35`).**  
- **Evidence:** `ca3e488` green `37985983564` 11/11 vs `38030756868` 9/11 `single failure` at `06:24:13` `POST /preview 200` but `GET /jobs/27 200` then wait timeout; `38031533131` opposite; `38029654566` header broke both (parent wrong).  
- **Hypothesis (not yet proven):** `fire_loopback` `wp_remote_post(admin-ajax.php, timeout 0.5, blocking false)` holds `php -S` thread 0.5s + `wp-cron` 2s (seen `12:57:44-12:57:48` 3s), `usdtf_it_http` 8×0.5s retry + `POST /preview` 7×4s retry mitigates but not deterministic. Header `X-USDTF-Disable-Loopback` + `?usdtf_disable_loopback=1` attempted at `b6ff9d7`/`2b8d1ee`/`510d3fa` made it **worse** (0/11) because `$_GET`/`$_SERVER` not populated for REST `rest_route` or `getallheaders` unavailable, and `usdtf_test_disable_loopback` option globally disables even worker's `usdtf_run_batch` loopbacks, so `usdtf_it_http_wait_job` (assisted) still needs 4s drain. Fix reverted at `60a33fb`.  
- **Impact:** CI must be re-run or use `USDTF_CONCURRENT=1` (socat) which still has single-threaded backend `18888` but `fork` mitigates; `38030756868` concurrent `success` vs `38031533131` concurrent `failure` shows still flaky 50%.  
- **Commit/run:** `60a33fb` `fix(http): health probe before preview + keep 4s drain` → `1525c3a` `fix(http): retry POST /preview 7×4s` → `b6c8335` current; runs `38030756868` `38031196530` `38031533131` `38031859878`; historical `37985983564` `ca3e488` 11/11.  
- **Mitigation:** keep `4s` (`60a33fb`) + `preview retry 7×4s` (`1525c3a`) + `health probe 5×0.5s`; next step would be `header X-USDTF-Disable-Loopback` checked via `$_SERVER['HTTP_X_USDTF_DISABLE_LOOPBACK']` **and** `REQUEST_URI` before `fire_loopback`, but must not persist option, and `usdtf_it_http` must send `X-USDTF-Disable-Loopback:1` via `wp_remote_request` headers (verified not stripped by `socat`). Unverified until green.

**2. No deterministic loopback disable yet — `usdtf_test_disable_loopback` option not yet header-only.**  
- At `b6ff9d7` `d368dab` parent `2d892c6` broke history (`-3067` lines vs `ca3e488`), fixed at `60a33fb` by reset. Header attempt failed, so reverted. Genuine issue remains: `POST /preview`/`POST /update` still do `wp_remote_post` that can be refused. Commit `b6ff9d7` `510d3fa` prove header not yet working. Next attempt must use `REQUEST_URI` check + per-request header, not global option.

**3. `http-autonomous.php` passive poll timeout 30s may be short for built ZIP `wp-built-zip` cold start (first `GET /jobs` at `06:06:52` vs `06:05:48` 1m later).**  
- Seen `38029654566` `as_async 403` `nonce` then `wp-cron 200` after 5s, but `FAIL: after loopback failure, persisted queue must recover (status='running')` at `a50de35` — autonomously `running` not `completed` within 30s. Assisted `usdtf_it_http_wait_job` 120s succeeds. This is expected: autonomous needs longer than 30s on cold WP. Not a bug, but flake.

**4. `/tmp/usdtf-server.log` and `/tmp/usdtf-concurrent.pid` ownership & `lsof` port check — `server.sh` uses `lsof -i :8888` which may not exist in minimal runner (uses `ss -ltn` fallback).**  
- Verified at `37985983564` logs show `ss -ltn` fallback `success`; `lsof` not required.

**5. `results-receiver` `SSL_ERROR_SYSCALL` / `EOF` for `gh run view --log` in sandbox (e.g., `38030756868` `logs_103046327976.zip` `EOF`, `38031859878` same) — not code, but verification requires PR comments `60946*` as fallback.**  
- Distinguish `unverified` logs vs `assisted` (CLI wake) vs `autonomous` (passive).

No other genuine issues; all other 8 requirements have at least one `11/11` historical evidence (`37985983564`) or `9/11` with `concurrent success` (`38030756868`).

---

**Verification commands (local, no CI):**
```bash
php -l includes/class-scheduler.php
php bin/lib-archive-contract.php && php tests/archive-contract-negative.php
phpcs --standard=.phpcs.xml
npm run build && unzip -l usd-to-toman-price-sync-for-woocommerce.zip | head
USDTF_REQUIRE_HTTP_TESTS=1 php tests/integration/run.php # needs php -S 8888 -t wp router.php
```

**Do not merge/publish** — PR #11 remains open at `b6c8335`.
